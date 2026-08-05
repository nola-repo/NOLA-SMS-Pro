# Backend Handoff: Admin Agencies Refactor

## Context & Objectives
The `Admin Agencies` component inside the Admin Panel previously fetched personal user data from the `users` collection to display Agency Admins. This has been refactored because "Agencies" logically correspond to app installations, not strictly individual user admins. 

The frontend and backend have now been updated to fetch directly from the `ghl_tokens` collection (which represents Agency Workspaces) rather than the `users` collection.

---

## Endpoint Details

### **1. Get All Agencies**
- **Endpoint:** `/api/admin_sender_requests.php`
- **Method:** `GET`
- **Query Parameter:** `?action=agencies`
- **Authentication:** Admin-only secret validation via `validate_api_request()`

#### **Data Source Change**
- **Previous:** `$db->collection('users')->where('role', '=', 'agency')->documents()`
- **Current:** `$db->collection('ghl_tokens')->where('appType', '=', 'agency')->documents()`

#### **Backend Response Mapping (PHP)**
The backend extracts and translates the raw `ghl_tokens` document into a strictly defined JSON array. 

```json
{
    "status": "success",
    "data": [
        {
            "id": "0OYXPGWM9ep2l37dgxAo",
            "company_name": "NOLA CRM",
            "company_id": "0OYXPGWM9ep2l37dgxAo",
            "active": true,
            "createdAt": "2026-04-14 17:27:06"
        }
    ]
}
```

*Note: Previously this endpoint returned `firstName`, `lastName`, `email`, and `phone`. These have been explicitly removed to align with the company-first data model.*

---

## Frontend Changes
The frontend table (`agency/src/pages/components/AdminAgencies.tsx`) was refactored significantly to reflect this lack of personal info. 

#### **Table Columns**
* **Before:** `Agency Name`, `Email`, `Phone`, `Company ID`, `Status`, `Actions`
* **After:** `Company Name`, `Company ID`, `Created At`, `Status`, `Actions`

#### **Search Formatting**
The search bar filter has also been adjusted to only cross-reference:
1. `acc.company_name` 
2. `acc.company_id`

---

## Developer Action Items
- **If replicating endpoints across microservices:** Ensure that any endpoint retrieving "Active Agencies" points at the `ghl_tokens` rather than treating individual `users` as agencies.
- **Credit Assignment:** Please note that if agency credits need to be manipulated via manual backend scripts, the balance modifications still map to the `users` or global `agency_wallet` document matching the `company_id`. The change implemented here only affects the tabular display of Agencies.
