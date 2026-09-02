# Backend Handoff — Admin Portal Notifications Extension

**Date:** 2026-06-08  
**Priority:** High  
**Status:** Ready for Backend Implementation  

---

## Overview

To support a centralized, actionable notification feed in the Admin Portal, we have updated the frontend client to render, sort, and navigate a variety of admin-facing events. This document specifies the backend requirements to trigger these events in Firestore and serve them via `/api/admin_notifications.php`.

---

## 1. Firestore Schema updates

The `admin_notifications` collection should support a dynamic `metadata` map to store context-specific details for different alert types.

### Collection: `admin_notifications`
```typescript
interface AdminNotification {
  id: string;             // Firestore Document ID
  type: 'low_balance' | 'zero_balance' | 'sender_request' | 'new_subaccount' | 'new_agency';
  location_id: string;    // Identifier for the subaccount or agency
  location_name: string;  // Name of the workspace/subaccount/agency
  email?: string;         // Contact email associated with the event
  created_at: Timestamp;  // Firestore Server Timestamp
  read: boolean;          // Read status (default: false)
  balance?: number;       // Optional (used for low_balance)
  threshold?: number;     // Optional (used for low_balance)
  metadata?: {
    sender_id?: string;   // Required for 'sender_request'
    agency_name?: string; // Required for 'new_agency'
  };
}
```

---

## 2. Notification Triggers & Payloads

The backend must emit an `admin_notifications` document under the following conditions:

### A. New Custom Sender ID Request
*   **Trigger**: When a subaccount owner requests a new custom Sender ID in their Settings panel.
*   **Firestore Payload**:
    ```json
    {
      "type": "sender_request",
      "location_id": "loc_abc123",
      "location_name": "Acme Marketing",
      "email": "owner@acme.com",
      "created_at": "[ServerTimestamp]",
      "read": false,
      "metadata": {
        "sender_id": "ACME_SMS"
      }
    }
    ```

### B. New GHL Subaccount Connection
*   **Trigger**: When an agency registers/maps a new GHL subaccount onto the NOLA SMS platform.
*   **Firestore Payload**:
    ```json
    {
      "type": "new_subaccount",
      "location_id": "loc_xyz789",
      "location_name": "Wayne Enterprises",
      "email": "bruce@wayne.corp",
      "created_at": "[ServerTimestamp]",
      "read": false
    }
    ```

### C. New Agency Registration
*   **Trigger**: When a new agency signs up/registers a dashboard account.
*   **Firestore Payload**:
    ```json
    {
      "type": "new_agency",
      "location_id": "agency_456",
      "location_name": "Stark Industries Agency",
      "email": "tony@stark.com",
      "created_at": "[ServerTimestamp]",
      "read": false,
      "metadata": {
        "agency_name": "Stark Industries Agency"
      }
    }
    ```

---

## 3. API Endpoint Updates: `/api/admin_notifications.php`

Ensure the GET method for `/api/admin_notifications.php` retrieves these additional types and fields.

### Response Payload Example:
```json
{
  "status": "success",
  "data": [
    {
      "id": "notif_001",
      "type": "sender_request",
      "location_id": "loc_abc123",
      "location_name": "Acme Marketing",
      "email": "owner@acme.com",
      "created_at": "2026-06-08T13:30:00Z",
      "read": false,
      "metadata": {
        "sender_id": "ACME_SMS"
      }
    },
    {
      "id": "notif_002",
      "type": "new_agency",
      "location_id": "agency_456",
      "location_name": "Stark Industries Agency",
      "email": "tony@stark.com",
      "created_at": "2026-06-08T13:20:00Z",
      "read": false,
      "metadata": {
        "agency_name": "Stark Industries Agency"
      }
    }
  ]
}
```
*Note: Make sure that `metadata` is serialized properly as a JSON object, and all fields map exactly to the frontend types.*
