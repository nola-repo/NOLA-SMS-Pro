# Backend Handoff — Admin Username to Email Migration

This handoff details the backend changes required to transition the **Admin Portal** from a username-based identifier (e.g., `admin`, `admin_rae`) to an email-based identifier (e.g., `rae@company.com`), matching the structure used in the Agency portal.

---

## 1. Firestore Database Schema Updates

### Current Structure:
* **Collection**: `admins`
* **Document ID**: The admin's `username` (e.g., `admin_rae`).
* **Fields**:
  * `username` (string)
  * `email` (string)
  * `hashed_password` (string)
  * `role` (string)
  * `active` (boolean)

### Proposed Structure:
* **Collection**: `admins`
* **Document ID**: The admin's `email` (lowercase, e.g., `rae@company.com`).
* **Fields**:
  * `email` (string)
  * `hashed_password` (string)
  * `role` (string)
  * `active` (boolean)

> [!IMPORTANT]
> **Migration Script Recommendation**:
> A CLI script should read all documents in the `admins` collection and copy them to new documents where the `documentID` is equal to the `email` field (lowercased), then delete the old username-based documents.

---

## 2. API Endpoint Changes

### 2.1 `api/admin_auth.php` (Admin Login)

Modify to lookup by email. Support both JSON keys `email` and `username` to prevent breaking older frontend requests.

```diff
- $username = $input['username'] ?? '';
+ $email = strtolower(trim($input['email'] ?? $input['username'] ?? ''));
  $password = $input['password'] ?? '';
  
- if (empty($username) || empty($password)) {
+ if (empty($email) || empty($password)) {
      http_response_code(400);
-     echo json_encode(['status' => 'error', 'message' => 'Username and password are required']);
+     echo json_encode(['status' => 'error', 'message' => 'Email and password are required']);
      exit;
  }
  
  $db = get_firestore();
  
  try {
-     $adminRef = $db->collection('admins')->document($username);
+     $adminRef = $db->collection('admins')->document($email);
      $snapshot = $adminRef->snapshot();
```

---

### 2.2 `api/admin_users.php` (Admin Management)

Update CRUD operations to use the email address as the document identifier.

#### Listing Admins (GET)
Ensure the listing returns `email` as the primary key identifier:

```diff
          foreach ($snapshot as $doc) {
              if (!$doc->exists()) continue;
              $d = $doc->data();
  
              $admins[] = [
-                 'username'   => $doc->id(),
+                 'email'      => $doc->id(),
                  'role'       => $d['role']       ?? 'viewer',
                  'active'     => (bool)($d['active'] ?? false),
                  'created_at' => format_ts($d['created_at'] ?? null),
                  'last_login' => format_ts($d['last_login']  ?? null),
              ];
          }
```

#### Creating Admin (POST action: `create`)
Expect `email` in the body payload:

```diff
      if ($action === 'create') {
-         $username = trim($input['username'] ?? '');
+         $email    = strtolower(trim($input['email'] ?? $input['username'] ?? ''));
          $password = $input['password']      ?? '';
          $role     = $input['role']          ?? 'viewer';
  
-         if (empty($username) || empty($password)) {
+         if (empty($email) || empty($password)) {
              http_response_code(400);
-             echo json_encode(['status' => 'error', 'message' => 'username and password are required']);
+             echo json_encode(['status' => 'error', 'message' => 'email and password are required']);
              exit;
          }
  ...
          try {
-             $docRef  = $db->collection('admins')->document($username);
+             $docRef  = $db->collection('admins')->document($email);
              $snap    = $docRef->snapshot();
  
              if ($snap->exists()) {
                  http_response_code(409);
-                 echo json_encode(['status' => 'error', 'message' => "Admin '{$username}' already exists"]);
+                 echo json_encode(['status' => 'error', 'message' => "Admin '{$email}' already exists"]);
                  exit;
              }
  
              $docRef->set([
-                 'username'        => $username,
+                 'email'           => $email,
                  'role'            => $role,
                  'active'          => true,
                  'hashed_password' => password_hash($password, PASSWORD_BCRYPT),
```

#### Other POST Actions (`reset_password`, `toggle_status`) and DELETE
Modify document reference lookups to point to `email` instead of `username`:

```php
// reset_password
$email  = strtolower(trim($input['email'] ?? $input['username'] ?? ''));
$docRef = $db->collection('admins')->document($email);

// toggle_status
$email  = strtolower(trim($input['email'] ?? $input['username'] ?? ''));
$docRef = $db->collection('admins')->document($email);

// DELETE
$email  = strtolower(trim($input['email'] ?? $input['username'] ?? ''));
$docRef = $db->collection('admins')->document($email);
```

---

### 2.3 `api/auth/forgot_password_otp.php` (OTP Generation)

Since the document ID for the `admins` collection is updated to the email address, we can optimize the lookup from a query to a direct document fetch:

```diff
-     // 1. Search in admins collection (query by email)
-     $results = $db->collection('admins')
-         ->where('email', '=', $email)
-         ->limit(1)
-         ->documents();
-     foreach ($results as $doc) {
-         if ($doc->exists()) {
-             $userDoc = $doc;
-             $userCollection = 'admins';
-             break;
-         }
-     }
+     // 1. Direct fetch from admins collection
+     $adminRef = $db->collection('admins')->document($email);
+     $adminSnap = $adminRef->snapshot();
+     if ($adminSnap->exists()) {
+         $userDoc = $adminSnap;
+         $userCollection = 'admins';
+     }
```
