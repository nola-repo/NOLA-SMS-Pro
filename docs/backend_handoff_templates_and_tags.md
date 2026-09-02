# Backend Handoff: Templates & GHL Tags

## 1. Templates Management API

We need a new RESTful endpoint to manage SMS templates scoped to the user's GHL Location ID.

**Endpoint**: `/api/templates.php` (or routed via `/api/templates`)

### Requirements:
- **Location Scoping**: All operations must be scoped via the `X-GHL-Location-ID` header. Users should only see and modify templates for their specific sub-account.
- **Database Table**: `system_templates` (or similar)
  - `id` (VARCHAR/UUID/INT)
  - `location_id` (VARCHAR)
  - `name` (VARCHAR)
  - `content` (TEXT)
  - `created_at` (TIMESTAMP)
  - `updated_at` (TIMESTAMP)

### Supported Operations:

1. **GET `/api/templates`**
   - Retrieves all templates for the given `location_id` (from header).
   - Response: `[{ id: "1", name: "Welcome", content: "Hi {{name}}...", ... }]`

2. **POST `/api/templates`**
   - Creates a new template.
   - Body: `{ name: "Appointment Reminder", content: "Your appt is..." }`

3. **PUT `/api/templates`**
   - Updates an existing template.
   - Body: `{ id: "1", name: "Updated Name", content: "Updated content" }`

4. **DELETE `/api/templates?id=<template_id>`**
   - Deletes the specified template.

---

## 2. GHL Contacts Tags

Currently, the frontend relies on `/api/ghl-contacts` (which proxies to the GHL API) to fetch a list of contacts. 

### Frontend Changes Being Made:
The frontend will begin reading the `tags` array from the JSON response provided by `/api/ghl-contacts`.

**Backend Action Required (If any):**
Please ensure that the `/api/ghl-contacts` proxy correctly forwards the `tags` array from the GHL API response to the frontend without stripping it out. (If it already passes the raw data through, no action is needed).

Additionally, if there's a need to fetch *all available tags* in the sub-account independently of contacts, an endpoint like `/api/ghl-tags` would be beneficial in the future, but for now, the frontend will derive the available tags from the currently loaded contacts.

## 3. GHL Marketplace Scopes

To support these features, please verify your GHL App has the following scopes in the Marketplace:
- `contacts.readonly` and `contacts.write` (to fetch contacts which inherently contain their tags).
- `locations/tags.readonly` and `locations/tags.write` (Recommended, if we later decide to manage tags independently of contacts).
- **Templates**: No new scopes needed. The templates are stored locally in the Nola SMS DB.
