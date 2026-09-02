# Backend Handoff — Support Ticket Pipeline

**Date:** 2026-06-04
**Status:** Proposed (Frontend design implemented with mock/skeleton view)

---

## Overview

We are introducing a **Support Ticket Pipeline** in the user dashboard. Users will be able to:
1. Submit support tickets via the existing GHL form (embedded in an iframe).
2. View and track the status of their submitted tickets on a new "My Tickets" pipeline tab.

The frontend is already designed to support this structure. The backend team needs to implement the Firestore collection and API endpoints described below.

---

## 1. Firestore Schema: `support_tickets`

We propose storing tickets in a Firestore collection named `support_tickets`.

### Document Schema
```typescript
interface TicketItem {
  ticket_id: string;      // Document ID
  location_id: string;    // Subaccount GHL Location ID (for scoping)
  subject: string;        // Brief ticket subject
  description: string;    // Detailed problem description
  status: 'open' | 'in_progress' | 'waiting' | 'resolved' | 'closed';
  priority: 'low' | 'medium' | 'high' | 'urgent';
  contact_name: string;   // Subaccount contact/user name
  contact_email: string;  // Subaccount contact/user email
  contact_phone?: string; // Subaccount contact/user phone
  assigned_agent?: string;// Name of the agency admin/agent assigned to this ticket
  assigned_agent_id?: string; // ID of the agency admin/agent
  created_at: string;     // ISO timestamp
  updated_at: string;     // ISO timestamp
}
```

### Proposed Indexes
To support efficient querying by `location_id` and optional sorting or filtering by status/date:
1. **Single Field Indexes**: Automatically created by Firestore.
2. **Composite Index** (if filtering by `status` and sorting by `created_at` or querying by `location_id` + `status`):
   - Collection: `support_tickets`
   - Fields:
     - `location_id` (ASC)
     - `status` (ASC)
     - `created_at` (DESC)

---

## 2. API Endpoints: `/api/tickets.php`

All endpoints should be handled by a single file: `api/tickets.php` (or `/api/tickets/index.php`).

### A. List Tickets
* **Method:** `GET`
* **Query Parameters:**
  - `location_id` (required): Filter tickets by GHL subaccount.
  - `status` (optional): Filter by specific status (`open`, `in_progress`, etc.).
  - `page` (optional, default: 1): For pagination.
  - `limit` (optional, default: 20): For pagination.
* **Headers:**
  - `Authorization: Bearer <Token>`
* **Success Response (200 OK):**
  ```json
  {
    "success": true,
    "data": [
      {
        "ticket_id": "tkt_abc123",
        "location_id": "loc_xyz789",
        "subject": "SMS not sending to Canadian numbers",
        "description": "I tried sending messages to +1416... and they are stuck in sending status.",
        "status": "in_progress",
        "priority": "high",
        "contact_name": "John Doe",
        "contact_email": "john@example.com",
        "contact_phone": "+1234567890",
        "assigned_agent": "Support Agent Sarah",
        "assigned_agent_id": "agent_098",
        "created_at": "2026-06-04T12:00:00Z",
        "updated_at": "2026-06-04T12:30:00Z"
      }
    ],
    "total": 1,
    "page": 1,
    "limit": 20
  }
  ```

### B. Create Ticket
* **Method:** `POST`
* **Headers:**
  - `Authorization: Bearer <Token>`
  - `Content-Type: application/json`
* **Request Body:**
  ```json
  {
    "location_id": "loc_xyz789",
    "subject": "SMS not sending to Canadian numbers",
    "description": "I tried sending messages to +1416... and they are stuck in sending status.",
    "priority": "high",
    "contact_name": "John Doe",
    "contact_email": "john@example.com",
    "contact_phone": "+1234567890"
  }
  ```
* **Success Response (201 Created):**
  ```json
  {
    "success": true,
    "ticket_id": "tkt_abc123",
    "status": "open",
    "message": "Ticket created successfully"
  }
  ```

### C. Update Ticket (For Assignment, Status Changes, and Agent Notes)
* **Method:** `PUT`
* **Headers:**
  - `Authorization: Bearer <Token>`
  - `Content-Type: application/json`
* **Request Body:**
  ```json
  {
    "ticket_id": "tkt_abc123",
    "status": "in_progress",
    "assigned_agent_id": "agent_098",
    "assigned_agent": "Support Agent Sarah",
    "note": "Assigned to Sarah for further investigation."
  }
  ```
* **Success Response (200 OK):**
  ```json
  {
    "success": true,
    "message": "Ticket updated successfully"
  }
  ```

---

## 3. Recommended Assignment & Notification Flows

1. **Submission**:
   - When a ticket is created via `POST`, backend should notify the support channel (e.g. Slack webhook or internal agency email) with the ticket details.
2. **Assignment**:
   - Agency administrator assigns an agent via the agency portal (sends `PUT /api/tickets.php` with `assigned_agent_id`).
   - Change ticket status to `in_progress`.
3. **Resolution**:
   - When status changes to `resolved`, backend should trigger an email to the client (`contact_email`) notifying them that the ticket is resolved.
4. **Auto-Closure**:
   - Tickets marked as `resolved` should auto-transition to `closed` after 72 hours of inactivity.
