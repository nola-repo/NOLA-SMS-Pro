# Backend Handoff: Admin All Agencies API

This document specifies the new requirements for the Admin panel to display and manage parent agency accounts.

## 1. Get All Agencies
The admin panel needs a way to list all registered parent agency accounts.

**Endpoint:** `GET /api/admin_sender_requests.php?action=agencies`

**Response Structure (Success):**
```json
{
  "status": "success",
  "data": [
    {
      "id": "user_doc_id",
      "firstName": "John",
      "lastName": "Doe",
      "email": "john@agency.com",
      "phone": "09171234567",
      "company_id": "GHL_COMPANY_123", // null if not linked
      "active": true,
      "createdAt": "2024-04-20 14:00:00"
    }
  ]
}
```

## 2. Implementation Status
The initial implementation has been added to `backend/api/admin_sender_requests.php`.

### Data Source:
- **Collection**: `users`
- **Filter**: `role == 'agency'`

---
*Generated for NOLA SMS Pro Backend Integration.*
