# Backend Handoff: GHL Funnel Page Auto-Fill from URL Parameters

**To:** GHL Funnel / Backend Team  
**Topic:** Add URL param auto-fill script to all NOLA SMS Pro credit checkout funnels  
**Affected Pages:**  
- 10 Credits: `https://sms.nolawebsolutions.com/nola-sms-pro---500-credits-page-8465-657955`  
- 500 Credits: `https://sms.nolawebsolutions.com/nola-sms-pro---500-credits-page-8465`  
- *(Apply to all other credit package funnels too)*

---

## Background

The NOLA SMS Pro frontend already appends user profile data as URL query parameters when opening the checkout popup. Example URL:

```
https://sms.nolawebsolutions.com/nola-sms-pro---500-credits-page-8465?location_id=V52Lp7YQo1ISiSf907Lu&name=Raely+Ivan&full_name=Raely+Ivan&first_name=Raely&last_name=Ivan&email=raely%40gmail.com&phone=%2B639707567469
```

**Current state:**
- The 500 credits page reads `location_id` and fills "Location Id" ✅  
- The 10 credits page reads NO params → all fields empty ❌  
- **Neither** page reads `name`, `email`, or `phone` → those fields always empty ❌

---

## Required Changes

### Add Auto-Fill Script to Every Credit Checkout Funnel Page

In GHL Funnel Builder → **Custom Code** section (or the existing custom JS block), add or update the following JavaScript snippet. This script reads URL parameters and fills in the corresponding form fields.

```javascript
(function () {
  function getParam(name) {
    return new URLSearchParams(window.location.search).get(name) || '';
  }

  function fillField(selector, value) {
    if (!value) return;
    const el = document.querySelector(selector);
    if (!el) return;
    const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
      window.HTMLInputElement.prototype, 'value'
    )?.set;
    if (nativeInputValueSetter) {
      nativeInputValueSetter.call(el, value);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    } else {
      el.value = value;
    }
  }

  function tryFill() {
    const locationId = getParam('location_id');
    const fullName   = getParam('name') || getParam('full_name');
    const email      = getParam('email');
    const phone      = getParam('phone');

    // Adjust selectors below to match the actual input field names/IDs in your funnel
    if (locationId) fillField('input[name="companyname"], input[placeholder*="Location"], input[id*="location"]', locationId);
    if (fullName)   fillField('input[name="fullname"], input[placeholder*="Full Name"], input[id*="name"]', fullName);
    if (email)      fillField('input[name="email"], input[type="email"], input[placeholder*="Email"]', email);
    if (phone)      fillField('input[name="phone"], input[type="tel"], input[placeholder*="Phone"]', phone);
  }

  // Run after DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryFill);
  } else {
    tryFill();
  }

  // Also run after a short delay in case of dynamic rendering
  setTimeout(tryFill, 800);
  setTimeout(tryFill, 2000);
})();
```

---

## Important: Selector Verification

The `fillField` selectors above use common patterns. You MUST verify the **actual** input field attributes in each GHL funnel page (use browser DevTools → Inspect the form fields) and update the selectors accordingly.

For example, inspect the "Full Name" input and check its `name`, `id`, or `placeholder` attribute — then update the selector string in the script.

---

## Verification

1. Open any credit package checkout link with test params appended:
   ```
   https://sms.nolawebsolutions.com/nola-sms-pro---500-credits-page-8465-657955?location_id=TEST123&name=John+Doe&email=john%40test.com&phone=%2B63912345678
   ```
2. All four fields (Location Id, Full Name, Email, Phone) should be pre-filled automatically on page load.
3. Apply the same script to **all** credit package funnel pages for consistency.
