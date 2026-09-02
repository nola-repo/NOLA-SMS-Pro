# Mobile OAuth Handoff: Replicating the Web UI REST Flow

## The Goal
The goal is to update the mobile app's OAuth flow to match the REST API pattern used by the Web UI, **but keeping all functionality entirely within the mobile repository and mobile backend**. 

Currently, the mobile app relies on a server-side browser redirect (`oauth_start.php`), which then redirects to `oauth_callback.php`, forcing a deep link back. We are changing this so the mobile frontend constructs the URL, intercepts the code itself, and passes it via a simple POST request to a new mobile backend endpoint. This new endpoint will perform all the token exchange and data syncing exactly like the old callback did.

---

## Part 1: Mobile Frontend (Flutter) Instructions

You will no longer direct the user to `oauth_start.php`. Instead, the Flutter app will build the GHL URL, open the browser, and intercept the deep link.

### 1. Construct the OAuth URL and Open Browser
In your `ConnectGhlScreen` or Auth service:

```dart
import 'package:flutter_web_auth_2/flutter_web_auth_2.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';

// The redirect URI must be a deep link registered exactly like this in the GHL Developer Portal
final String redirectUri = 'nolasms://oauth-callback'; 
final String clientId = AppConfig.ghlClientId; // from your environment

Future<void> startGhlOAuth(String uid) async {
  final Uri oauthUri = Uri.parse('https://marketplace.leadconnectorhq.com/oauth/chooselocation').replace(
    queryParameters: {
      'response_type': 'code',
      'redirect_uri': redirectUri,
      'client_id': clientId,
      'scope': 'contacts.readonly contacts.write', // match your required scopes
    }
  );

  try {
    // 1. Open the secure browser. The app will pause here until the user approves or cancels.
    final result = await FlutterWebAuth2.authenticate(
      url: oauthUri.toString(),
      callbackUrlScheme: 'nolasms', 
    );

    // 2. Extract the Code from the resulting deep link ("nolasms://oauth-callback?code=abc123xyz")
    final Uri resultUri = Uri.parse(result);
    final String? code = resultUri.queryParameters['code'];

    if (code == null) throw Exception("No code returned from GHL");

    // 3. POST the code to your NEW mobile backend REST endpoint
    final response = await http.post(
      Uri.parse('${AppConfig.mobileBackendUrl}/api/ghl_oauth.php'), 
      headers: { 'Content-Type': 'application/json' },
      body: jsonEncode({
        'code': code,
        'redirectUri': redirectUri, // Must exactly match the one used above
        'uid': uid 
      }),
    );

    final data = jsonDecode(response.body);
    if (response.statusCode == 200 && data['status'] == 'success') {
      // 4. Update mobile UI and save preferences
      final locationName = data['location_name'];
      final contactCount = data['contact_count'];
      print("Successfully connected $locationName and synced $contactCount contacts");
      // TODO: Save state to SharedPreferences and close the screen
    } else {
      throw Exception(data['error'] ?? "Failed to connect");
    }
  } catch (e) {
    print("OAuth Error: $e");
    // TODO: Show error Snackbar to user
  }
}
```

---

## Part 2: Mobile Backend (PHP) Instructions

Deprecate the old `ghl/oauth_start.php` and `ghl/oauth_callback.php` files. 
Create a **new** file at `api/ghl_oauth.php`. This file accepts JSON POST data from the mobile app, exchanges the token, syncs all contacts, and returns JSON (replacing the old HTML redirect).

### `api/ghl_oauth.php` (Complete Code)
```php
<?php
require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../api/firestore_helper.php';

header('Content-Type: application/json');

// Read JSON payload from Flutter
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$code = $payload['code'] ?? '';
$redirectUri = $payload['redirectUri'] ?? '';
$uid = $payload['uid'] ?? '';

if (!$code || !$redirectUri || !$uid) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing code, redirectUri, or uid']);
    exit;
}

$clientId = getenv('GHL_CLIENT_ID');
$clientSecret = getenv('GHL_CLIENT_SECRET');
if (!$clientId || !$clientSecret) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error']);
    exit;
}

// 1. Exchange Code for Tokens
$tokenPayload = http_build_query([
    'grant_type'    => 'authorization_code',
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'code'          => $code,
    'redirect_uri'  => $redirectUri,
    'user_type'     => 'Location',
]);

$ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $tokenPayload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'Token exchange failed', 'details' => json_decode($response)]);
    exit;
}

$data = json_decode($response, true);
$accessToken = $data['access_token'];
$refreshToken = $data['refresh_token'];
$locationId = $data['locationId'] ?? $data['location_id'] ?? '';
$companyId = $data['companyId'] ?? $data['company_id'] ?? '';
$expiresIn = (int)($data['expires_in'] ?? 86400);

// 2. Fetch Location Info
$locationName = ''; $locationEmail = ''; $locationPhone = '';
if ($locationId) {
    $chLoc = curl_init("https://services.leadconnectorhq.com/locations/$locationId");
    curl_setopt_array($chLoc, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken", "Version: 2021-07-28"],
    ]);
    $locResponse = curl_exec($chLoc);
    if (curl_getinfo($chLoc, CURLINFO_HTTP_CODE) === 200) {
        $locData = json_decode($locResponse, true);
        $locationName = $locData['name'] ?? $locData['location']['name'] ?? '';
        $locationEmail = $locData['email'] ?? $locData['location']['email'] ?? '';
        $locationPhone = $locData['phone'] ?? $locData['location']['phone'] ?? '';
    }
    curl_close($chLoc);
}

try {
    $nowMs = time() * 1000;
    $expiresAtMs = $nowMs + ($expiresIn * 1000);

    // 3. Save Integration Data to Firestore
    $connectionData = [
        'access_token'  => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_at'    => $expiresAtMs,
        'location_id'   => $locationId,
        'location_name' => $locationName,
        'company_id'    => $companyId,
        'connected_at'  => $nowMs,
        'updated_at'    => $nowMs,
    ];
    firestore_set_document("users/$uid/integrations/ghl", $connectionData, true);

    // Update main user document
    firestore_set_document("users/$uid", [
        'ghl_connected' => true,
        'active_location_id' => $locationId,
        'active_location_name' => $locationName,
    ], true);

    // 4. Fetch and Sync Contacts
    $allContacts = [];
    $page = 1;
    while ($page <= 10) {
        $contactsUrl = "https://services.leadconnectorhq.com/contacts/?" . http_build_query([
            "locationId" => $locationId, "limit" => 100, "page" => $page
        ]);
        $chC = curl_init($contactsUrl);
        curl_setopt_array($chC, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken", "Version: 2021-07-28"],
        ]);
        $cResp = curl_exec($chC);
        $cHttp = curl_getinfo($chC, CURLINFO_HTTP_CODE);
        curl_close($chC);
        
        if ($cHttp !== 200) break;
        
        $cData = json_decode($cResp, true);
        $contacts = $cData['contacts'] ?? [];
        if (empty($contacts)) break;
        
        $allContacts = array_merge($allContacts, $contacts);
        if (count($contacts) < 100) break;
        $page++;
    }

    // Save Contacts to Firestore
    $contactCount = 0;
    foreach ($allContacts as $contact) {
        $contactId = $contact['id'];
        firestore_set_document("users/$uid/ghl_contacts/$contactId", [
            'id' => $contactId,
            'firstName' => $contact['firstName'] ?? '',
            'lastName' => $contact['lastName'] ?? '',
            'email' => $contact['email'] ?? '',
            'phone' => $contact['phone'] ?? '',
            'synced_at' => $nowMs,
        ], true);
        $contactCount++;
    }

    firestore_set_document("users/$uid/ghl_sync/metadata", [
        'last_contact_sync' => $nowMs,
        'total_contacts' => $contactCount,
    ], true);

    // 5. Return Success JSON to Mobile App (NO REDIRECT!)
    echo json_encode([
        'status' => 'success',
        'location_id' => $locationId,
        'location_name' => $locationName,
        'contact_count' => $contactCount
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'message' => $e->getMessage()]);
}
```

---

## Part 3: How to Test It

To verify the flow works end-to-end, follow these testing steps:

### 1. Developer Portal Setup
* Log into the **GHL Marketplace Developer Portal**.
* Navigate to your mobile App.
* Under **Redirect URIs**, ensure `nolasms://oauth-callback` is added and saved. (If you use a different deep link scheme, add that instead).

### 2. Local/Emulator Testing
* Run the mobile backend locally or deploy it to your dev server.
* Open the mobile app in the Android/iOS Emulator.
* Tap the **Connect GHL** button.
* Verify that a system browser pops up directly on `marketplace.leadconnectorhq.com/oauth/chooselocation`.
* Log in with a test GHL account and click **Approve**.
* **Verify Handoff:** The browser should instantly close, returning you to the app. 
* **Verify API:** Check your mobile backend logs. You should see a successful `POST` request to `api/ghl_oauth.php` containing the `code`.
* **Verify Database:** Open your Firebase/Firestore Console. Navigate to `users/{your_test_uid}/integrations/ghl` and verify that `access_token` and `refresh_token` have been populated with fresh values. Furthermore, check `users/{your_test_uid}/ghl_contacts` to ensure the contacts were synced successfully.
