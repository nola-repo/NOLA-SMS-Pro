# Backend Handoff: Agency Subscription Plans

**Scope:** Agency-only subscription tier system (Starter / Growth / Agency / Enterprise).  
**Stack:** PHP + Firestore (same patterns as existing `api/billing/` files)  
**Auth pattern:** Follow `api/agency/auth_helper.php` → JWT Bearer or X-Webhook-Secret + X-Agency-ID  

> [!IMPORTANT]
> Subscription fields go on the **`agency_users`** collection document, NOT `ghl_tokens`.  
> The `agency_users` collection is already used by `profile.php` and `ghl_agency_callback.php` as the source-of-truth for agency accounts.

---

## 1. Subscription Tiers

| Plan | Price/mo | Subaccount Limit | Key Features |
|------|:---:|:---:|---|
| `starter` | Free | 1 | SMS send, credit top-up |
| `growth` | ₱1,499 | 5 | + Auto-recharge, credit requests, transaction history |
| `agency` | ₱3,499 | 25 | + Master balance lock, bulk credit gifting, priority support |
| `enterprise` | ₱7,999 | -1 *(unlimited)* | + Dedicated onboarding, white-label, SLA |

**Rules:**
- Adding a subaccount when at the limit is a **hard block** (HTTP 403).
- Existing agencies get a **30-day grace period** on the `agency` plan at migration.
- **No downgrade path** — agencies can upgrade or cancel only.
- Cancellation keeps access until `subscription_expires_at`.

---

## 2. Firestore Changes

### 2a. `agency_users/{user_id}` — Add subscription fields

Add these fields to the existing agency user document (use `merge: true` — do not overwrite existing data):

```
subscription_plan:       "starter" | "growth" | "agency" | "enterprise"
subscription_status:     "active" | "cancelled" | "past_due"
subscription_expires_at: timestamp | null
plan_subaccount_limit:   1 | 5 | 25 | -1
```

> **Why `agency_users`?**  
> `profile.php` (line 129) reads `agency_users/{userId}` as the authoritative agency profile.  
> `ghl_agency_callback.php` (line 167) queries `agency_users` where `company_id = X` to detect existing agencies.  
> Subscription is a property of the agency account — it belongs here.

**Do NOT add subscription fields to `ghl_tokens`.** The `ghl_tokens` collection stores OAuth tokens only.

### 2b. Migration — Set grace period for existing agencies

Run this **one-time** migration script on all existing `agency_users` docs:

```php
// Pseudocode — adapt to your migration runner style
$now = new DateTimeImmutable();
$graceExpiry = $now->modify('+30 days');

$agencyDocs = $db->collection('agency_users')->where('role', '=', 'agency')->documents();
foreach ($agencyDocs as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    // Only set if not already present
    if (!isset($data['subscription_plan'])) {
        $doc->reference()->set([
            'subscription_plan'       => 'agency',
            'subscription_status'     => 'active',
            'subscription_expires_at' => new \Google\Cloud\Core\Timestamp($graceExpiry),
            'plan_subaccount_limit'   => 25,
        ], ['merge' => true]);
    }
}
```

### 2c. `subscription_plans/{plan_id}` — New static config collection

These are read-only config documents. Create them once manually or via a seed script.

```
subscription_plans/starter
  name: "Starter"
  price_monthly: 0
  subaccount_limit: 1
  ghl_funnel_slug: null

subscription_plans/growth
  name: "Growth"
  price_monthly: 1499
  subaccount_limit: 5
  ghl_funnel_slug: "nola-sms-pro-growth-plan"

subscription_plans/agency
  name: "Agency"
  price_monthly: 3499
  subaccount_limit: 25
  ghl_funnel_slug: "nola-sms-pro-agency-plan"

subscription_plans/enterprise
  name: "Enterprise"
  price_monthly: 7999
  subaccount_limit: -1
  ghl_funnel_slug: "nola-sms-pro-enterprise-plan"
```

### 2d. `subscription_events/{event_id}` — New audit log collection

```
agency_id:      string           // company_id of the agency
user_id:        string           // agency_users doc ID
event_type:     "subscribed" | "upgraded" | "renewed" | "cancelled" | "expired"
from_plan:      string | null
to_plan:        string
triggered_by:   "ghl_webhook" | "admin" | "grace_period"
ghl_order_id:   string | null
created_at:     timestamp
```

---

## 3. New API Files

All files go in `api/billing/`. Follow the CORS/auth pattern of `agency_wallet.php`.

---

### `api/billing/subscription.php`

**Purpose:** Frontend fetches this on load to get the agency's current plan state.

**Auth:** JWT Bearer Token (`Authorization: Bearer <token>`) — use `validate_agency_request()` from `api/agency/auth_helper.php`.

#### GET `?agency_id={company_id}`

**Logic:**
1. Validate agency auth, resolve `$agencyId`.
2. Query `agency_users` where `company_id = $agencyId`, limit 1 — get the user doc.
3. Read subscription fields from the doc.
4. Count current subaccounts: query `agency_subaccounts` where `agency_id = $agencyId`.
5. Return the response below.

```json
{
  "plan": "growth",
  "status": "active",
  "subaccount_limit": 5,
  "subaccounts_used": 3,
  "expires_at": "2026-06-12T00:00:00Z"
}
```

If no subscription fields exist yet (pre-migration agency), default to:
```json
{ "plan": "starter", "status": "active", "subaccount_limit": 1, "subaccounts_used": 0, "expires_at": null }
```

---

#### GET `?agency_id={company_id}&action=plans`

Returns the full plan catalog from `subscription_plans` collection. Used to render the plan comparison table.

```json
{
  "plans": [
    { "id": "starter",    "name": "Starter",    "price_monthly": 0,    "subaccount_limit": 1,  "ghl_funnel_slug": null },
    { "id": "growth",     "name": "Growth",     "price_monthly": 1499, "subaccount_limit": 5,  "ghl_funnel_slug": "nola-sms-pro-growth-plan" },
    { "id": "agency",     "name": "Agency",     "price_monthly": 3499, "subaccount_limit": 25, "ghl_funnel_slug": "nola-sms-pro-agency-plan" },
    { "id": "enterprise", "name": "Enterprise", "price_monthly": 7999, "subaccount_limit": -1, "ghl_funnel_slug": "nola-sms-pro-enterprise-plan" }
  ]
}
```

---

#### GET `?agency_id={company_id}&action=events`

Returns the subscription event history (audit log) for this agency.

```json
{
  "events": [
    {
      "event_type": "upgraded",
      "from_plan": "starter",
      "to_plan": "growth",
      "triggered_by": "ghl_webhook",
      "ghl_order_id": "ghl_order_abc123",
      "created_at": "2026-05-12T03:00:00Z"
    }
  ]
}
```

---

#### POST `action=cancel`

```json
// Request
{ "agency_id": "X" }
// Response
{ "success": true, "access_until": "2026-06-12T00:00:00Z" }
```

**Logic:**
1. Find `agency_users` doc where `company_id = X`.
2. Set `subscription_status = "cancelled"`. Do NOT change `expires_at`.
3. Log event `{ event_type: "cancelled", from_plan: <current>, to_plan: <current> }`.

---

### `api/billing/plan_webhook.php`

**Purpose:** Called by GHL Workflow after a successful subscription checkout. This is the equivalent of how credits are added via GHL → `api/credits`.

**Auth:** `X-Webhook-Secret` header only (this is a server-to-server call from GHL, no JWT).

```php
validate_api_request(); // from api/auth_helpers.php — checks X-Webhook-Secret
```

#### POST

**Incoming payload (sent by GHL Workflow):**
```json
{
  "agency_id":  "0OYXPGWM9ep2l37dgxAo",
  "plan":       "growth",
  "order_id":   "ghl_order_abc123"
}
```

**Logic:**
1. Validate `X-Webhook-Secret`.
2. Read `subscription_plans/{plan}` → get `subaccount_limit`.
3. Query `agency_users` where `company_id = $agencyId`, limit 1 → get user doc.
4. If not found → return 404.
5. Read current `subscription_plan` from the user doc.
6. Determine `event_type`:
   - No plan yet or was `starter` → `"subscribed"`
   - New plan is higher tier than current → `"upgraded"`
   - Same plan → `"renewed"`
7. Atomically update the `agency_users` doc:
   ```
   subscription_plan:       {new plan}
   subscription_status:     "active"
   plan_subaccount_limit:   {limit from subscription_plans}
   subscription_expires_at: now() + 30 days
   ```
8. Write to `subscription_events`.
9. Return `{ "success": true, "plan": "growth", "subaccount_limit": 5 }`.

**Error responses:**
- `400` — Missing `agency_id` or `plan`.
- `400` — Unknown plan (not in `subscription_plans`).
- `404` — Agency not found in `agency_users`.
- `403` — Invalid webhook secret.

---

## 4. Modify Existing Files

### `api/agency/get_subaccounts.php` — Hard-block when at limit

When the frontend calls this endpoint with an "add" intent (or when you build a dedicated add endpoint), check the plan limit before allowing a new subaccount to be registered.

Add this check early in the add flow:

```php
// Read plan limit from agency_users
$agencyUserSnap = $db->collection('agency_users')
    ->where('company_id', '=', $agencyId)
    ->limit(1)
    ->documents();

$planLimit = 1; // default starter
foreach ($agencyUserSnap as $doc) {
    if ($doc->exists()) {
        $planLimit = (int)($doc->data()['plan_subaccount_limit'] ?? 1);
    }
}

// Count current subaccounts
$currentCount = count(/* existing subaccounts query result */);

if ($planLimit !== -1 && $currentCount >= $planLimit) {
    http_response_code(403);
    echo json_encode([
        'error'            => 'subaccount_limit_reached',
        'limit'            => $planLimit,
        'upgrade_required' => true,
    ]);
    exit;
}
```

> **Note:** `-1` means unlimited (Enterprise plan) — always allow.

---

## 5. `.htaccess` — Add new routes

Add these lines in the `# ── Billing endpoints ──` section:

```apache
RewriteRule ^api/billing/subscription/?$    /api/billing/subscription.php    [NC,L,QSA]
RewriteRule ^api/billing/plan-webhook/?$    /api/billing/plan_webhook.php    [NC,L,QSA]
```

---

## 6. GHL Checkout Connection & Workflow Structure

The subscription checkout exactly mirrors the credit top-up flow: it opens a GHL checkout page in a centered popup window and polls the window until it closes. 

### Step 1: Create Products in GHL
1. Navigate to **Payments -> Products**.
2. Create three recurring subscription products:
   - **Growth Plan** (₱1,499 / month)
   - **Agency Plan** (₱3,499 / month)
   - **Enterprise Plan** (₱7,999 / month)

### Step 2: Create the Checkout Funnel Page
Create **1 GHL funnel page** that will handle all subscription checkouts: e.g., `agency-subscription-checkout`.
URL: `https://sms.nolawebsolutions.com/agency-subscription-checkout`

**On the funnel page:**
1. Add a **2-Step Order Form** element.
2. Ensure all three products (Growth, Agency, Enterprise) are attached to this page.
3. Add the **Autofill Custom Code script** (same as the credit top-up pages).
   - This script must map the `agency_id` URL parameter to a **hidden field** (e.g., mapping to `companyname`).
   - It should map `name`, `email`, and `phone` URL parameters to the form's contact fields.
   - *(Optional)* Map the `plan` URL parameter to automatically select the correct radio button on the order form using a custom Javascript snippet.
4. Set the hidden field's default value in GHL to `{{contact.companyname}}` as a fallback.

### Step 3: GHL Workflow for Subscriptions
Create a new GHL Workflow: **"App - Agency Subscription Upgrades & Renewals"**

**Trigger 1: Initial Purchase**
- **Trigger Type:** Order Submitted
- **Filters:** 
  - Funnel: `[Your Subscription Funnel]`
  - Page: `agency-subscription-checkout`
  - Submission Type: `Sale`

**Trigger 2: Recurring Payments (Renewals)**
- **Trigger Type:** Payment Received
- **Filters:**
  - Source: `[Your Payment Gateway / Stripe]`
  - Product: `[Growth/Agency/Enterprise Products]`

**Workflow Actions:**

1. **If/Else Condition (Check which plan was purchased):**
   - **Branch 1 (Growth):** Condition checking if product is "Growth Plan".
   - **Branch 2 (Agency):** Condition checking if product is "Agency Plan".
   - **Branch 3 (Enterprise):** Condition checking if product is "Enterprise Plan".

2. **Branch 1: Growth Plan actions:**
   - **Action: Webhook**
     - Method: `POST`
     - URL: `https://smspro-api.nolacrm.io/api/billing/plan-webhook`
     - Header: `X-Webhook-Secret: f7RkQ2pL9zV3tX8cB1nS4yW6`
     - Payload:
       ```json
       {
         "agency_id": "{{contact.companyname}}",
         "plan": "growth",
         "order_id": "{{order.id}}"
       }
       ```

3. **Branch 2 & 3: Agency & Enterprise Actions:**
   - Repeat the webhook action for each branch, changing only the `"plan"` value in the JSON payload to `"agency"` or `"enterprise"` respectively.

> **Note on Renewals:** When a recurring payment comes in, this workflow will fire. The backend sees the webhook, identifies that the new plan matches the existing plan, treats it as a `"renewed"` event, and securely pushes `subscription_expires_at` forward by 30 days.

---

## 7. Frontend UI Implementation (Agency App)

Align the Agency subscription checkout experience with the `Billing.tsx` credits flow.

### 1. `Subscription.tsx` Page Layout
Create a dedicated Subscription page (or a tab) that:
1. Fetches the current plan using `GET /api/billing/subscription?agency_id=X`.
2. Displays the **Current Plan** banner, showing the name, status, subaccount limit, and usage (e.g., `3 / 5 subaccounts used`).
3. Renders a pricing table/grid mapping out the available plans (`Starter`, `Growth`, `Agency`, `Enterprise`).

### 2. The Popup Checkout Flow
When a user clicks "Upgrade" on a specific plan, execute the popup checkout logic:

```typescript
const handleUpgrade = (planId: string) => {
  // Construct URL with params for the GHL Funnel
  const checkoutUrl = `https://sms.nolawebsolutions.com/agency-subscription-checkout?agency_id=${encodeURIComponent(agencyId)}&plan=${planId}&name=${encodeURIComponent(userName)}&email=${encodeURIComponent(userEmail)}`;

  // Center popup calculation
  const width = 600, height = 850;
  const left = (window.screen.width / 2) - (width / 2);
  const top = (window.screen.height / 2) - (height / 2);

  // Open the popup window
  const popup = window.open(
    checkoutUrl,
    'SubscriptionCheckout',
    `width=${width},height=${height},left=${left},top=${top},scrollbars=yes,resizable=yes`
  );

  if (!popup) {
    alert("Popup blocked! Please allow popups for this site.");
    return;
  }

  // Poll for closure to refresh data (identical to Billing.tsx)
  const pollTimer = setInterval(() => {
    if (popup.closed) {
      clearInterval(pollTimer);
      showToast('Checkout window closed. Refreshing subscription...', 'info');
      fetchSubscription(); // Refresh the agency_users subscription data
    }
  }, 500);
};
```

### 3. Usage Blocking
In the "Add Subaccount" modal (where the agency generates an OAuth link), you must verify the `subaccounts_used` against the `subaccount_limit`.
- If `subaccounts_used >= plan_subaccount_limit` (and limit is not `-1`), disable the "Add Subaccount" button.
- Show an alert block: *"You have reached the limit of your current plan. Please upgrade to add more subaccounts."*

---

## 8. End-to-End Flow

```
① Agency on Starter clicks "Upgrade to Growth"
   └─ Frontend opens popup:
      https://sms.nolawebsolutions.com/agency-subscription-checkout?agency_id=X&name=...&plan=growth

② GHL funnel page loads → autofill script fills form from URL params

③ Agency pays → GHL Workflow fires:
   POST /api/billing/plan-webhook
   { "agency_id": "X", "plan": "growth", "order_id": "..." }

④ Backend:
   1. Validates X-Webhook-Secret
   2. Reads subscription_plans/growth → limit = 5
   3. Finds agency_users doc where company_id = X
   4. Updates: plan="growth", status="active", limit=5, expires_at=now+30d
   5. Writes subscription_events audit record
   6. Returns { "success": true }

⑤ Agency app detects popup close → refreshes GET /api/billing/subscription
   └─ Returns plan="growth", limit=5, used=1
   └─ Plan badge updates, subaccount limit bar updates
```

---

## 9. Backend Checklist

### Firestore
- [ ] Add `subscription_plan`, `subscription_status`, `subscription_expires_at`, `plan_subaccount_limit` fields to `agency_users` docs (migration script)
- [ ] Seed `subscription_plans` collection (4 docs: starter, growth, agency, enterprise)
- [ ] Create `subscription_events` collection (auto-created on first write)

### API Files
- [ ] `api/billing/subscription.php` — GET (plan state + plan catalog + events), POST cancel
- [ ] `api/billing/plan_webhook.php` — POST from GHL Workflow (subscribe / upgrade / renew)

### Existing File Changes
- [ ] `api/agency/get_subaccounts.php` — Hard-block add when `subaccounts_used >= plan_subaccount_limit`
- [ ] `.htaccess` — Add 2 rewrite rules for `subscription` and `plan-webhook`

### GHL Setup
- [ ] Create Subscription Products
- [ ] Ensure single funnel page `agency-subscription-checkout` exists with autofill Custom Code
- [ ] Create GHL Workflow triggered on order submit for this funnel AND payment received
- [ ] Add conditional branches in workflow based on product purchased
- [ ] Add Webhook action per branch firing to `plan-webhook` with hardcoded plan

### Testing
- [ ] POST `plan-webhook` with `plan=growth` → verify `agency_users` doc updates correctly
- [ ] GET `subscription?agency_id=X` → returns correct plan, limit, used count
- [ ] Add subaccount when at limit → verify `403 subaccount_limit_reached`
- [ ] Cancel → verify `status=cancelled`, `expires_at` unchanged
