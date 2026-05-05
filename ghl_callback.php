<?php

require __DIR__ . '/api/webhook/firestore_client.php';

// ─── Global Context ────────────────────────────────────────────────────────────
$locationIdSafe = '';
$backendApiUrl = 'https://smspro-api.nolacrm.io';

/**
 * Send a full HTML page and exit.
 */
function render_page(string $title, string $body_html): void
{
    global $locationIdSafe, $backendApiUrl;

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — NOLA SMS Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-family: 'Poppins', system-ui, -apple-system, sans-serif; background: #f9fafb; color: #1a1a1a; -webkit-font-smoothing: antialiased; }
        body { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 20px; overflow: hidden; background: #f9fafb; }

        .blob { position: fixed; border-radius: 50%; background: #2b83fa; filter: blur(120px); opacity: 0.15; pointer-events: none; z-index: 0; }
        .blob-tl { top: -10%; left: -10%; width: 50vw; height: 50vw; }
        .blob-br { bottom: -10%; right: -10%; width: 50vw; height: 50vw; }

        .unified-card { 
            max-width: 440px; width: 100%; 
            background: rgba(255, 255, 255, 0.72); 
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-radius: 36px; padding: 44px 32px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.06), inset 0 0 0 1px rgba(255,255,255,0.4); 
            border: 1px solid rgba(43,131,250,0.1); text-align: center;
            animation: card-in 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
            z-index: 10;
        }
        @keyframes card-in { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }

        .success-ring { position: relative; display: inline-flex; margin: 0 auto 31px; }
        .success-ring::before { 
            content: ''; position: absolute; inset: -10px; border-radius: 50%; 
            border: 3px solid #2b83fa; opacity: 0.4; 
            animation: pulse-ring 2.5s cubic-bezier(0.4, 0, 0.6, 1) infinite; 
        }
        @keyframes pulse-ring { 
            0% { transform: scale(0.95); opacity: 0.6; } 70% { transform: scale(1.35); opacity: 0; } 100% { transform: scale(1.35); opacity: 0; } 
        }

        .success-icon { 
            width: 72px; height: 72px; border-radius: 50%; 
            background: #2b83fa; display: flex; align-items: center; justify-content: center; 
            z-index: 10; position: relative; box-shadow: 0 10px 24px rgba(43,131,250,0.4);
        }

        .error-icon { 
            width: 72px; height: 72px; border-radius: 50%; 
            background: #fef2f2; display: flex; align-items: center; justify-content: center; 
            z-index: 10; position: relative; box-shadow: 0 10px 24px rgba(220,38,38,0.1);
            border: 1px solid #fee2e2;
        }

        h1 { font-size: 30px; font-weight: 800; letter-spacing: -1.4px; margin-bottom: 6px; color: #111; line-height: 1; }
        p.subtitle { font-size: 16px; color: #6e6e73; margin-bottom: 36px; line-height: 1.4; font-weight: 500; }

        .btn-primary { 
            display: inline-flex; align-items: center; justify-content: center; gap: 10px; 
            width: auto; padding: 14px 44px; border-radius: 99px; 
            background: #2b83fa; color: #fff; font-size: 16px; font-weight: 700; 
            text-decoration: none; transition: all 0.23s cubic-bezier(0.4, 0, 0.2, 1); 
            box-shadow: 0 6px 16px rgba(43,131,250,0.35); border: none; cursor: pointer;
            margin: 0 auto;
        }
        .btn-primary:hover { background: #1d6bd4; transform: translateY(-3px); box-shadow: 0 12px 32px rgba(43,131,250,0.45); }
        .btn-primary:active { transform: scale(0.97); }

        .sender-toggle { 
            font-size: 13px; font-weight: 700; color: #6e6e73; 
            cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 99px; transition: all 0.2s;
            background: #f8f8f8; border: 1px solid rgba(0,0,0,0.03);
            margin: 0 auto;
        }
        .sender-toggle:hover { background: #f0f0f0; color: #111; transform: translateY(-1px); }

        /* MODAL STYLES */
        .modal-overlay { 
            position: fixed; inset: 0; background: rgba(0,0,0,0.5); 
            backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; 
            z-index: 1000; animation: fade-in 0.3s ease; 
        }
        .modal-content { 
            background: #fff; width: 92%; max-width: 440px; border-radius: 28px; 
            padding: 32px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); 
            animation: modal-up 0.4s cubic-bezier(0.16, 1, 0.3, 1) both;
            text-align: left; position: relative;
        }
        @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
        @keyframes modal-up { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .modal-title { font-size: 18px; font-weight: 800; color: #111; letter-spacing: -0.4px; }
        .modal-close { cursor: pointer; color: #aaa; transition: 0.2s; padding: 4px; }
        .modal-close:hover { color: #111; transform: scale(1.1); }

        label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #9aa0a6; margin-bottom: 8px; letter-spacing: 0.05em; }
        input, textarea { 
            width: 100%; padding: 14px; border-radius: 14px; border: 1px solid #e0e0e0; 
            background: #fafafa; font-family: inherit; font-size: 14px; margin-bottom: 16px; 
            outline: none; transition: all 0.2s;
        }
        input:focus, textarea:focus { border-color: #2b83fa; background: #fff; box-shadow: 0 0 0 4px rgba(43,131,250,0.1); }

        .btn-submit { 
            background: #2b83fa; color: #fff; 
            border: none; padding: 16px; border-radius: 18px; font-weight: 700; 
            cursor: pointer; width: 100%; font-size: 14px; transition: 0.2s; 
            box-shadow: 0 4px 12px rgba(43,131,250,0.2); margin-top: 8px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(43,131,250,0.3); }

        .tutorial-link { 
            font-size: 11px; color: #a1a1aa; text-decoration: underline; 
            cursor: pointer; margin-top: 16px; display: inline-block;
            transition: color 0.15s; font-weight: 600; text-underline-offset: 4px;
        }
        .tutorial-link:hover { color: #6e6e73; }
        
        .hidden { display: none !important; }
        
        .error-pre { margin-top: 12px; font-size: 10px; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 10px; overflow: auto; max-height: 120px; text-align: left; white-space: pre-wrap; word-break: break-all; font-family: monospace; }
    </style>
</head>
<body>
    <div class="blob blob-tl"></div>
    <div class="blob blob-br"></div>

    <div class="unified-card">
        {$body_html}
    </div>

    <!-- SENDER ID MODAL -->
    <div id="sender-modal" class="modal-overlay" onclick="if(event.target === this) toggleModal('sender-modal')">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Request Sender ID</h3>
                <div class="modal-close" onclick="toggleModal('sender-modal')">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </div>
            </div>

            <form id="sender-form" onsubmit="handleSenderSubmit(event)">
                <div id="sender-success" class="hidden" style="text-align:center; padding: 20px 0;">
                    <div style="width:48px; height:48px; background:#f0fdf4; color:#16a34a; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <p style="color: #111; font-weight: 800; font-size: 16px; margin-bottom: 4px;">Request Submitted!</p>
                    <p style="font-size: 13px; color: #6e6e73;">Review takes about 5 business days.</p>
                </div>

                <div id="form-fields">
                    <div id="sender-error" class="hidden" style="color:#ef4444; font-size:12px; font-weight:600; margin-bottom:16px;"></div>
                    
                    <label>Sender Name</label>
                    <input id="sender-name" type="text" maxlength="11" placeholder="ex. MyBrand" required>
                    
                    <label>Purpose</label>
                    <textarea id="sender-purpose" rows="2" placeholder="What will you be using it for?" required></textarea>
                    
                    <label>Sample Message</label>
                    <textarea id="sender-sample" rows="2" placeholder="Specific example of messages you'll send." required></textarea>
                    
                    <button type="submit" id="submit-btn" class="btn-submit">Submit Request</button>

                    <div style="margin-top: 20px; border-radius: 12px; background: #fffcf0; border: 1px solid #fdf2c2; padding: 12px; text-align: center;">
                        <p style="font-size: 11px; color: #856404; font-weight: 500; line-height: 1.4;">
                            <strong>Note:</strong> It may take a few business days for your sender name to be approved by the carrier network.
                        </p>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- HOW IT WORKS MODAL -->
    <div id="how-modal" class="modal-overlay" onclick="if(event.target === this) toggleModal('how-modal')">
        <div class="modal-content" style="text-align: center;">
            <div class="modal-header">
                <div style="width:24px;"></div>
                <h3 class="modal-title">How it Works</h3>
                <div class="modal-close" onclick="toggleModal('how-modal')">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </div>
            </div>

            <div id="tutorial-step-1" class="tutorial-step">
                <div style="width:56px; height:56px; background:#f0f7ff; color:#2b83fa; font-size:24px; font-weight:900; border-radius:18px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">1</div>
                <h4 style="font-size:16px; font-weight:800; margin-bottom:8px; color:#111;">Open Nola SMS Pro</h4>
                <p style="font-size:13px; color:#444; line-height:1.5; margin-bottom:24px;">Open the Nola SMS Pro application from your GoHighLevel sidebar menu.</p>
                <div style="display:flex; justify-content:center;">
                    <button class="btn-primary" onclick="nextStep(2)" style="width:auto; padding:14px 24px; font-size:14px; border-radius:12px;">Next Step &rarr;</button>
                </div>
            </div>

            <div id="tutorial-step-2" class="tutorial-step hidden">
                <div style="width:56px; height:56px; background:#f0f7ff; color:#2b83fa; font-size:24px; font-weight:900; border-radius:18px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">2</div>
                <h4 style="font-size:16px; font-weight:800; margin-bottom:8px; color:#111;">Select a Contact</h4>
                <p style="font-size:13px; color:#444; line-height:1.5; margin-bottom:24px;">Choose any contact from your GHL CRM to start a message thread.</p>
                <div style="display:flex; justify-content:center;">
                    <button class="btn-primary" onclick="nextStep(3)" style="width:auto; padding:14px 24px; font-size:14px; border-radius:12px;">Next Step &rarr;</button>
                </div>
            </div>

            <div id="tutorial-step-3" class="tutorial-step hidden">
                <div style="width:56px; height:56px; background:#f0fdf4; color:#16a34a; font-size:24px; font-weight:900; border-radius:18px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">3</div>
                <h4 style="font-size:16px; font-weight:800; margin-bottom:8px; color:#111;">Ready to Send!</h4>
                <p style="font-size:13px; color:#444; line-height:1.5; margin-bottom:24px;">Your account starts with 10 free credits to get you up and running immediately.</p>
                <div style="display:flex; justify-content:center;">
                    <button class="btn-primary" onclick="toggleModal('how-modal')" style="width:auto; padding:14px 24px; font-size:14px; border-radius:12px;">Got it!</button>
                </div>
            </div>
        </div>
    </div>

    <script>
      const LOCATION_ID = '{$locationIdSafe}';
      const API_BASE    = '{$backendApiUrl}';

      function toggleModal(id) {
        const el = document.getElementById(id);
        if (el && el.style.display === 'flex') {
          el.style.display = 'none';
          if (id === 'how-modal') resetTutorial();
        } else if (el) {
          el.style.display = 'flex';
        }
      }

      function nextStep(step) {
        document.querySelectorAll('.tutorial-step').forEach(s => s.classList.add('hidden'));
        document.getElementById('tutorial-step-' + step).classList.remove('hidden');
      }

      function resetTutorial() {
        document.querySelectorAll('.tutorial-step').forEach(s => s.classList.add('hidden'));
        document.getElementById('tutorial-step-1').classList.remove('hidden');
      }

      async function handleSenderSubmit(e) {
        e.preventDefault();
        const btn = document.getElementById('submit-btn');
        btn.disabled = true;
        btn.innerHTML = 'Submitting...';

        try {
          const res = await fetch(API_BASE + '/api/sender-requests', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-GHL-Location-ID': LOCATION_ID },
            body: JSON.stringify({
              location_id:    LOCATION_ID,
              requested_id:   document.getElementById('sender-name').value.trim(),
              purpose:        document.getElementById('sender-purpose').value.trim(),
              sample_message: document.getElementById('sender-sample').value.trim()
            }),
          });
          if (!res.ok) throw new Error();
          document.getElementById('form-fields').classList.add('hidden');
          document.getElementById('sender-success').classList.remove('hidden');
        } catch {
          document.getElementById('sender-error').textContent = "Failed to submit. Try again.";
          document.getElementById('sender-error').classList.remove('hidden');
          btn.disabled = false;
          btn.innerHTML = 'Submit Request';
        }
      }
    </script>
</body>
</html>
HTML;
}

/**
 * Stop early with a styled error page.
 */
function render_error(string $message, array $details = []): void
{
    $msg_safe = htmlspecialchars($message);
    $details_html = '';
    if (!empty($details)) {
        $json = htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT));
        $details_html = "<pre class=\"error-pre\">{$json}</pre>";
    }

    $reinstall_url = 'https://marketplace.leadconnectorhq.com/v2/oauth/chooselocation?response_type=code&redirect_uri=https%3A%2F%2Fsmspro-api.nolacrm.io%2Foauth%2Fcallback&client_id=6999da2b8f278296d95f7274-mmn30t4f&scope=workflows.readonly+conversations%2Fmessage.readonly+conversations.readonly+conversations.write+contacts.readonly+contacts.write+conversations%2Fmessage.write+saas%2Flocation.read+locations.readonly+locations%2Ftags.readonly+locations%2Ftags.write&version_id=6999da2b8f278296d95f7274';

    $body = <<<HTML
        <div class="error-icon" style="margin: 0 auto 32px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <h1>Installation Failed</h1>
        <p class="subtitle" style="margin-bottom: 24px;">{$msg_safe}</p>
        {$details_html}
        <div style="margin-top: 32px;">
            <a href="{$reinstall_url}" class="btn-primary">Try Again</a>
        </div>
HTML;
    render_page('Installation Failed', $body);
    exit;
}

/**
 * Pull a GHL location id from an associative JSON-decoded payload (OAuth state body).
 */
function location_id_from_assoc_json(?array $json): ?string
{
    if (!$json) {
        return null;
    }
    foreach (['locationId', 'location_id', 'selectedLocationId'] as $key) {
        $v = $json[$key] ?? null;
        if (is_string($v) && $v !== '') {
            return $v;
        }
    }
    $locIds = $json['location_ids'] ?? null;
    if (is_array($locIds) && count($locIds) === 1) {
        $only = $locIds[0];
        if (is_string($only) && $only !== '') {
            return $only;
        }
    }
    $nested = $json['locations'] ?? null;
    if (is_array($nested) && count($nested) === 1 && is_array($nested[0])) {
        $row = $nested[0];
        $lid = $row['id'] ?? $row['locationId'] ?? null;
        if (is_string($lid) && $lid !== '') {
            return $lid;
        }
    }
    return null;
}

/**
 * Best-effort extraction of a locationId from OAuth state.
 * Supports plain IDs and common encoded JSON wrapper formats.
 */
function extract_location_id_from_state(?string $state): ?string
{
    if (!$state) {
        return null;
    }

    $candidates = [];
    $candidates[] = $state;
    $decoded = urldecode($state);
    if ($decoded !== $state) {
        $candidates[] = $decoded;
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $trimmed = trim($candidate);

        // Case 0: query-string-like state payload
        $parsed = [];
        parse_str($trimmed, $parsed);
        $fromQuery = $parsed['locationId'] ?? $parsed['location_id'] ?? $parsed['selectedLocationId'] ?? null;
        if (is_string($fromQuery) && $fromQuery !== '') {
            return $fromQuery;
        }

        // Case 1: JSON payload in state (direct)
        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            $fromJson = location_id_from_assoc_json($json);
            if (is_string($fromJson) && $fromJson !== '') {
                return $fromJson;
            }
        }

        // Case 2: base64-encoded JSON payload
        $b64 = base64_decode($trimmed, true);
        if ($b64 !== false && $b64 !== '') {
            $jsonB64 = json_decode($b64, true);
            if (is_array($jsonB64)) {
                $fromB64Json = location_id_from_assoc_json($jsonB64);
                if (is_string($fromB64Json) && $fromB64Json !== '') {
                    return $fromB64Json;
                }
            }
        }

        // Case 2b: base64url-encoded JSON payload
        $b64Url = strtr($trimmed, '-_', '+/');
        $padLen = strlen($b64Url) % 4;
        if ($padLen > 0) {
            $b64Url .= str_repeat('=', 4 - $padLen);
        }
        $b64u = base64_decode($b64Url, true);
        if ($b64u !== false && $b64u !== '') {
            $jsonB64u = json_decode($b64u, true);
            if (is_array($jsonB64u)) {
                $fromB64uJson = location_id_from_assoc_json($jsonB64u);
                if (is_string($fromB64uJson) && $fromB64uJson !== '') {
                    return $fromB64uJson;
                }
            }
        }

        // Case 2c: locationId embedded in a larger plain string
        if (preg_match('/(?:locationId|location_id)["=: ]+([A-Za-z0-9_-]{12,})/i', $trimmed, $m)) {
            return $m[1];
        }

        // Case 3: plain location-like ID (guard against obvious non-IDs)
        if (preg_match('/^[A-Za-z0-9_-]{12,}$/', $trimmed)) {
            return $trimmed;
        }
    }

    return null;
}

/**
 * Exchange a company-scoped token into a location-scoped token.
 * Tries both payload formats because GHL behavior varies by app/runtime.
 *
 * @return array{ok:bool, code:int, data:array, raw:string, format:string, failures:array}
 */
function exchange_location_token(string $companyToken, string $companyId, string $locationId): array
{
    $attempts = [
        [
            'format' => 'form',
            'body' => http_build_query(['companyId' => $companyId, 'locationId' => $locationId]),
            'headers' => [
                'Authorization: Bearer ' . $companyToken,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ],
        [
            'format' => 'json',
            'body' => json_encode(['companyId' => $companyId, 'locationId' => $locationId]),
            'headers' => [
                'Authorization: Bearer ' . $companyToken,
                'Content-Type: application/json',
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ],
        [
            'format' => 'query',
            'url' => 'https://services.leadconnectorhq.com/oauth/locationToken?companyId=' . urlencode($companyId) . '&locationId=' . urlencode($locationId),
            'body' => '',
            'headers' => [
                'Authorization: Bearer ' . $companyToken,
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ],
    ];

    $failures = [];
    foreach ($attempts as $attempt) {
        $ltCurl = curl_init($attempt['url'] ?? 'https://services.leadconnectorhq.com/oauth/locationToken');
        curl_setopt_array($ltCurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $attempt['body'],
            CURLOPT_HTTPHEADER     => $attempt['headers'],
        ]);
        $raw = curl_exec($ltCurl);
        $code = curl_getinfo($ltCurl, CURLINFO_HTTP_CODE);
        curl_close($ltCurl);
        $data = json_decode($raw ?: '', true);
        $jsonDecodeOk = is_array($data);
        if (!$jsonDecodeOk) {
            $data = [];
        }

        // Some GHL responses occasionally arrive in a form that json_decode fails to parse
        // even though the payload clearly contains access_token. Recover minimal fields.
        if (empty($data['access_token']) && is_string($raw) && $raw !== '') {
            if (preg_match('/"access_token"\s*:\s*"([^"]+)"/', $raw, $m)) {
                $data['access_token'] = stripcslashes($m[1]);
            }
            if (preg_match('/"refresh_token"\s*:\s*"([^"]+)"/', $raw, $m)) {
                $data['refresh_token'] = stripcslashes($m[1]);
            }
            if (preg_match('/"expires_in"\s*:\s*(\d+)/', $raw, $m)) {
                $data['expires_in'] = (int)$m[1];
            }
        }

        if (($code === 200 || $code === 201) && !empty($data['access_token'])) {
            return [
                'ok' => true,
                'code' => $code,
                'data' => $data,
                'raw' => (string)$raw,
                'format' => $attempt['format'],
                'failures' => $failures,
            ];
        }

        $failures[] = [
            'format' => $attempt['format'],
            'code' => $code,
            'json_decode_ok' => $jsonDecodeOk,
            'has_access_token_field' => !empty($data['access_token']) || (is_string($raw) && strpos($raw, '"access_token"') !== false),
            'raw' => is_string($raw) ? substr($raw, 0, 400) : '',
        ];
        error_log("[GHL_CALLBACK] locationToken {$attempt['format']} failed for {$locationId}: HTTP {$code} — {$raw}");
    }

    return [
        'ok' => false,
        'code' => 0,
        'data' => [],
        'raw' => '',
        'format' => 'none',
        'failures' => $failures,
    ];
}

// ─── OAuth Config ──────────────────────────────────────────────────────────────
// Subaccount app only — NO agency fallback.
// Agency installs use /oauth/agency-callback → ghl_agency_callback.php
$clientId     = getenv('GHL_CLIENT_ID')     ?: '6999da2b8f278296d95f7274-mmn30t4f';
$clientSecret = getenv('GHL_CLIENT_SECRET') ?: 'd91017ad-f4eb-461f-8967-b1d51cd1c1eb';
$redirectUri  = 'https://smspro-api.nolacrm.io/oauth/callback'; // HARDCODED

if (!$clientId || !$clientSecret)
    render_error('Server configuration error: GHL credentials are not set up.');
if (!isset($_GET['code']))
    render_error('No authorization code was received.');

$code  = $_GET['code'];
$state = $_GET['state'] ?? null;
$debugTrace = [
    'source' => 'ghl_callback',
    'has_code' => !empty($code),
    'state_present' => $state !== null && $state !== '',
    'state_preview' => $state ? substr((string)$state, 0, 180) : null,
];

// ─── Token Exchange ────────────────────────────────────────────────────────────
$ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'user_type'     => 'Location',
    'redirect_uri'  => $redirectUri,
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Version: 2021-07-28']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);
if ($httpCode !== 200 || !is_array($data))
    render_error('Authorization failed.', $data ?: []);

$debugTrace['token_http'] = $httpCode;
$debugTrace['token_userType'] = $data['userType'] ?? null;
$debugTrace['token_isBulkInstallation'] = $data['isBulkInstallation'] ?? null;
$debugTrace['token_companyId'] = $data['companyId'] ?? null;
$debugTrace['token_locationId'] = $data['locationId'] ?? ($data['location_id'] ?? null);
error_log('[GHL_CALLBACK_DEBUG] token_exchange=' . json_encode($debugTrace));

// Subaccount-only — $usedAppType is always 'subaccount'
$usedAppType = 'subaccount';

// ─── App credentials map (mirrors oauth_exchange.php) ────────────────────────
$ghlApps = [
    'subaccount' => [
        'clientId'     => getenv('GHL_CLIENT_ID')     ?: '6999da2b8f278296d95f7274-mmn30t4f',
        'clientSecret' => getenv('GHL_CLIENT_SECRET') ?: 'd91017ad-f4eb-461f-8967-b1d51cd1c1eb',
        'userType'     => 'Location',
    ],
    'agency' => [
        'clientId'     => getenv('GHL_AGENCY_CLIENT_ID')     ?: '69d31f33b3071b25dbcc5656-mnqxvtt3',
        'clientSecret' => getenv('GHL_AGENCY_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322',
        'userType'     => 'Company',
    ],
];

// ─── Bulk-install guard ───────────────────────────────────────────────────────
// When an agency admin installs the sub-account app in bulk, GHL sends a
// Company-scoped token (isBulkInstallation=true, userType=Company) with NO
// locationId. We exchange it for per-location tokens and redirect to app.nolacrm.io.
if (!empty($data['isBulkInstallation']) && ($data['userType'] ?? '') === 'Company') {
    error_log('[GHL_CALLBACK_DEBUG] branch=bulk_guard_entered companyId=' . ($data['companyId'] ?? ''));
    // This is the sub-account app bulk install — use sub-account credentials throughout.
    $companyId          = $data['companyId'] ?? null;
    $subaccountClientId = $ghlApps['subaccount']['clientId'];
    $companyToken       = $data['access_token'];
    $companyRefresh     = $data['refresh_token'] ?? null;
    $expiresIn          = (int)($data['expires_in'] ?? 86400);
    $companyExpiresAt   = time() + $expiresIn;

    if (!$companyId) {
        render_error('Bulk install received but no companyId in token response.', $data);
    }

    error_log("[GHL_CALLBACK] Sub-account bulk install detected — companyId={$companyId}. Provisioning location tokens.");

    $db  = get_firestore();
    $now = new DateTimeImmutable();

    // Save Company-level token for future reference
    try {
        $db->collection('ghl_tokens')->document($companyId)->set([
            'access_token'  => $companyToken,
            'refresh_token' => $companyRefresh,
            'expires_at'    => $companyExpiresAt,
            'client_id'     => $subaccountClientId,
            'appId'         => $subaccountClientId,
            'appType'       => 'subaccount',
            'userType'      => 'Company',
            'companyId'     => $companyId,
            'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
        ], ['merge' => true]);
    } catch (Exception $e) {
        error_log('[GHL_CALLBACK] Failed to save company token: ' . $e->getMessage());
    }

    // ── CASE A: Single-location install from agency view ─────────────────────
    // GHL includes locationId when the agency admin installs for one sub-account.
    // Treat this exactly like a normal sub-account install.
    $singleLocationId = $data['locationId'] ?? $data['location_id'] ?? null;

    // Agency view sometimes sends the selected sub-account inside the locations[]
    // array rather than at the root locationId field. Detect that here.
    if (!$singleLocationId && !empty($data['locations']) && is_array($data['locations'])) {
        $selectedLocs = array_values(array_filter($data['locations']));
        if (count($selectedLocs) === 1) {
            $singleLocationId = $selectedLocs[0]['id'] ?? $selectedLocs[0]['locationId'] ?? null;
            error_log("[GHL_CALLBACK] Case A: single location found in locations[] array — {$singleLocationId}");
        }
    }

    // When locations[] has multiple entries, OAuth state may still identify the sub-account
    // that initiated install — prefer that row so we do not fall through to company-wide bulk.
    if (!$singleLocationId && !empty($data['locations']) && is_array($data['locations'])) {
        $statePin = extract_location_id_from_state($state);
        if ($statePin) {
            foreach ($data['locations'] as $loc) {
                if (!is_array($loc)) {
                    continue;
                }
                $lid = $loc['id'] ?? $loc['locationId'] ?? null;
                if ($lid !== null && (string) $lid === (string) $statePin) {
                    $singleLocationId = (string) $lid;
                    error_log("[GHL_CALLBACK] Case A: OAuth state matched locations[] entry — {$singleLocationId}");
                    break;
                }
            }
        }
    }

    // Final fallback for single-location installs where GHL puts selected location in state.
    if (!$singleLocationId) {
        $singleLocationId = extract_location_id_from_state($state);
        if ($singleLocationId) {
            error_log("[GHL_CALLBACK] Case A: single location recovered from state — {$singleLocationId}");
        }
    }
    error_log('[GHL_CALLBACK_DEBUG] bulk_caseA_candidate=' . json_encode([
        'singleLocationId' => $singleLocationId,
        'state_present' => $state !== null && $state !== '',
    ]));

    if ($singleLocationId) {
        error_log("[GHL_CALLBACK] Case A: single-location agency install — locationId={$singleLocationId}");

        // Exchange company token -> location-scoped token
        $ltResult2 = exchange_location_token($companyToken, $companyId, $singleLocationId);
        $ltData2   = $ltResult2['data'];

        if (!$ltResult2['ok']) {
            error_log('[GHL_CALLBACK_DEBUG] caseA_locationToken_failures=' . json_encode([
                'locationId' => $singleLocationId,
                'companyId' => $companyId,
                'failures' => $ltResult2['failures'] ?? [],
            ]));
            render_error('Failed to exchange company token for location token.', [
                'locationId' => $singleLocationId,
                'companyId'  => $companyId,
                'hint'       => 'Tried both form and JSON payload formats for /oauth/locationToken.',
            ]);
        }
        error_log("[GHL_CALLBACK] Case A: locationToken succeeded for {$singleLocationId} via {$ltResult2['format']} format.");

        $ltExpires2 = time() + (int)($ltData2['expires_in'] ?? 86400);

        // Fetch location name
        $lnCurl2 = curl_init('https://services.leadconnectorhq.com/locations/' . $singleLocationId);
        curl_setopt_array($lnCurl2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $ltData2['access_token'], 'Accept: application/json', 'Version: 2021-07-28'],
        ]);
        $lnResp2       = curl_exec($lnCurl2); curl_close($lnCurl2);
        $singleLocName = json_decode($lnResp2, true)['location']['name'] ?? '';

        // Save Location-scoped token
        $db->collection('ghl_tokens')->document($singleLocationId)->set([
            'access_token'          => $ltData2['access_token'],
            'refresh_token'         => $ltData2['refresh_token'] ?? $companyRefresh,
            'expires_at'            => $ltExpires2,
            'client_id'             => $subaccountClientId,
            'appId'                 => $subaccountClientId,
            'appType'               => 'subaccount',
            'userType'              => 'Location',
            'location_id'           => $singleLocationId,
            'location_name'         => $singleLocName,
            'companyId'             => $companyId,
            'is_live'               => true,
            'toggle_enabled'        => true,
            'provisioned_from_bulk' => true,
            'updated_at'            => new \Google\Cloud\Core\Timestamp($now),
        ], ['merge' => true]);

        // Provision integrations doc
        $intDocId2 = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $singleLocationId);
        $intRef2   = $db->collection('integrations')->document($intDocId2);
        $intSnap2  = $intRef2->snapshot();
        if (!$intSnap2->exists()) {
            $intRef2->set([
                'location_id'           => $singleLocationId,
                'location_name'         => $singleLocName,
                'companyId'             => $companyId,
                'free_credits_total'    => 10,
                'free_usage_count'      => 0,
                'credit_balance'        => 0,
                'system_default_sender' => 'NOLASMSPro',
                'installed_at'          => new \Google\Cloud\Core\Timestamp($now),
                'updated_at'            => new \Google\Cloud\Core\Timestamp($now),
            ]);
        } else {
            $intRef2->set([
                'access_token'  => $ltData2['access_token'],
                'expires_at'    => $ltExpires2,
                'location_name' => $singleLocName,
                'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
            ], ['merge' => true]);
        }

        error_log("[GHL_CALLBACK] Case A: provisioned {$singleLocationId} ({$singleLocName})");

        // Redirect through normal sub-account registration/welcome-back flow
        require_once __DIR__ . '/api/jwt_helper.php';
        $jwtSecret2  = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
        $reactAppUrl2 = 'https://app.nolacrm.io';
        $locNameEnc2  = urlencode($singleLocName ?: 'Your Sub-Account');

        $hasMembers2 = false;
        try {
            foreach ($db->collection('location_members')->document($singleLocationId)->collection('members')->limit(1)->documents() as $m) {
                if ($m->exists()) { $hasMembers2 = true; break; }
            }
        } catch (Exception $e) {
            error_log('[GHL_CALLBACK] Could not check location_members: ' . $e->getMessage());
        }

        $installToken2 = jwt_sign([
            'type'          => 'install',
            'location_id'   => $singleLocationId,
            'location_name' => $singleLocName ?: '',
            'company_id'    => $companyId,
        ], $jwtSecret2, 900);
        header('Location: https://smspro-api.nolacrm.io/install-register.php?install_token=' . urlencode($installToken2), true, 302);
        exit;
    }

    // ── CASE B: True bulk install — provision ALL company locations ───────────
    error_log("[GHL_CALLBACK] Case B: true bulk install — companyId={$companyId}");
    $allLocationIds = [];
    if (!empty($data['locations']) && is_array($data['locations'])) {
        foreach ($data['locations'] as $loc) {
            $lid = $loc['id'] ?? $loc['locationId'] ?? null;
            if ($lid) $allLocationIds[] = $lid;
        }
    }
    if (empty($allLocationIds)) {
        $skip = 0; $limit = 100;
        do {
            $locCurl = curl_init("https://services.leadconnectorhq.com/locations/search?companyId={$companyId}&skip={$skip}&limit={$limit}");
            curl_setopt_array($locCurl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $companyToken, 'Accept: application/json', 'Version: 2021-07-28'],
            ]);
            $locResp  = curl_exec($locCurl);
            curl_close($locCurl);
            $fetched  = json_decode($locResp, true)['locations'] ?? [];
            foreach ($fetched as $loc) { if ($loc['id'] ?? null) $allLocationIds[] = $loc['id']; }
            $skip += $limit;
        } while (count($fetched) === $limit && count($allLocationIds) < 500);
    }

    // If GHL search returns zero, fall back to location context from callback state.
    // This prevents false "bulk_install count=0" outcomes for single-location installs.
    if (empty($allLocationIds)) {
        $stateLocId = extract_location_id_from_state($state);
        if ($stateLocId) {
            $allLocationIds[] = $stateLocId;
            error_log("[GHL_CALLBACK] Case B fallback: using locationId from state — {$stateLocId}");
        }
    }

    $allLocationIds = array_values(array_unique($allLocationIds));

    // If GHL gave multiple candidate locations but OAuth state pins exactly one of them,
    // treat this as a single-sub-account install (registration + install_token), not bulk login.
    $statePinned = extract_location_id_from_state($state);
    if ($statePinned !== null && count($allLocationIds) > 1 && in_array($statePinned, $allLocationIds, true)) {
        error_log('[GHL_CALLBACK] Case B: state pins ' . $statePinned . ' among ' . count($allLocationIds) . ' candidates — narrowing to single-location flow.');
        $allLocationIds = [$statePinned];
    }

    error_log('[GHL_CALLBACK_DEBUG] bulk_caseB_location_candidates=' . json_encode([
        'count' => count($allLocationIds),
        'first' => $allLocationIds[0] ?? null,
    ]));

    $successfulLocIds = [];
    $successfulLocNames = [];

    // Provision per-location tokens
    foreach ($allLocationIds as $locId) {
        try {
            $ltResult = exchange_location_token($companyToken, $companyId, $locId);
            $ltData   = $ltResult['data'];

            if ($ltResult['ok']) {
                $successfulLocIds[] = $locId;
                $ltExpires = time() + (int)($ltData['expires_in'] ?? 86400);

                // Fetch location name
                $lnCurl = curl_init('https://services.leadconnectorhq.com/locations/' . $locId);
                curl_setopt_array($lnCurl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $ltData['access_token'], 'Accept: application/json', 'Version: 2021-07-28'],
                ]);
                $lnResp  = curl_exec($lnCurl); curl_close($lnCurl);
                $locName = json_decode($lnResp, true)['location']['name'] ?? '';
                $successfulLocNames[$locId] = $locName;

                $db->collection('ghl_tokens')->document($locId)->set([
                    'access_token'          => $ltData['access_token'],
                    'refresh_token'         => $ltData['refresh_token'] ?? $companyRefresh,
                    'expires_at'            => $ltExpires,
                    'client_id'             => $subaccountClientId,
                    'appId'                 => $subaccountClientId,
                    'appType'               => 'subaccount',
                    'userType'              => 'Location',
                    'location_id'           => $locId,
                    'location_name'         => $locName,
                    'companyId'             => $companyId,
                    'is_live'               => true,
                    'toggle_enabled'        => true,
                    'provisioned_from_bulk' => true,
                    'updated_at'            => new \Google\Cloud\Core\Timestamp($now),
                ], ['merge' => true]);

                // Provision integrations doc (credits etc.)
                $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locId);
                $intRef   = $db->collection('integrations')->document($intDocId);
                $intSnap  = $intRef->snapshot();
                if (!$intSnap->exists()) {
                    $intRef->set([
                        'location_id'           => $locId,
                        'location_name'         => $locName,
                        'companyId'             => $companyId,
                        'free_credits_total'    => 10,
                        'free_usage_count'      => 0,
                        'credit_balance'        => 0,
                        'system_default_sender' => 'NOLASMSPro',
                        'installed_at'          => new \Google\Cloud\Core\Timestamp($now),
                        'updated_at'            => new \Google\Cloud\Core\Timestamp($now),
                    ]);
                } else {
                    $intRef->set([
                        'access_token'  => $ltData['access_token'],
                        'expires_at'    => $ltExpires,
                        'location_name' => $locName,
                        'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
                    ], ['merge' => true]);
                }

                error_log("[GHL_CALLBACK] Bulk: provisioned location token for {$locId} ({$locName}) via {$ltResult['format']} format");
            } else {
                error_log('[GHL_CALLBACK_DEBUG] caseB_locationToken_fail=' . json_encode([
                    'locationId' => $locId,
                    'failures' => $ltResult['failures'] ?? [],
                ]));
                error_log("[GHL_CALLBACK] Bulk: locationToken failed for {$locId} after trying all formats.");
            }
        } catch (Exception $e) {
            error_log("[GHL_CALLBACK] Bulk: exception for {$locId}: " . $e->getMessage());
        }
    }

    // ── Redirect to React app after bulk provisioning ────────────────────────
    // Classify every provisioned location into one of three tiers using two
    // Firestore checks:
    //
    //   Tier 1 — Brand new: no ghl_tokens/{locationId} doc exists at all
    //             → Highest priority for registration
    //   Tier 2 — Installed before but never registered: token doc exists,
    //             location_members has no members
    //             → Also needs registration
    //   Tier 3 — Fully set up: token doc exists AND members exist
    //             → Redirect to welcome_back login
    //
    // Tier 1 + Tier 2 → needsRegistration[]
    // Tier 3          → alreadyRegistered[]
    error_log("[GHL_CALLBACK] Case B: provisioned " . count($successfulLocIds) . " of " . count($allLocationIds) . " locations.");
    error_log('[GHL_CALLBACK_DEBUG] bulk_caseB_provision_result=' . json_encode([
        'successful_count' => count($successfulLocIds),
        'candidate_count'  => count($allLocationIds),
        'successful_first' => $successfulLocIds[0] ?? null,
    ]));

    require_once __DIR__ . '/api/jwt_helper.php';
    $jwtSecretB   = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
    $reactAppUrlB = 'https://app.nolacrm.io';

    if (count($successfulLocIds) === 0) {
        render_error('Installation completed, but no location tokens were provisioned. Please reinstall from a specific sub-account.', [
            'companyId'          => $companyId,
            'hint'               => 'No location-scoped token could be created from this callback context.',
            'isBulkInstallation' => $data['isBulkInstallation'] ?? null,
            'state'              => $state,
        ]);
    }

    $needsRegistration = [];  // [ locationId => locationName ]
    $alreadyRegistered = [];  // [ locationId => locationName ]

    foreach ($successfulLocIds as $locId) {
        $locName = $successfulLocNames[$locId] ?? '';

        // ── Tier 1 check: does ghl_tokens/{locationId} doc exist? ────────────
        $tokenDocExists = false;
        try {
            $tokenSnap      = $db->collection('ghl_tokens')->document($locId)->snapshot();
            $tokenDocExists = $tokenSnap->exists();
        } catch (Exception $e) {
            error_log("[GHL_CALLBACK] Case B: could not check ghl_tokens for {$locId}: " . $e->getMessage());
        }

        if (!$tokenDocExists) {
            // Tier 1 — brand new location, never installed before
            error_log("[GHL_CALLBACK] Case B: {$locId} is Tier 1 (no ghl_tokens doc) — needs registration.");
            $needsRegistration[$locId] = $locName;
            continue;
        }

        // ── Tier 2 / Tier 3 check: does location_members have any member? ────
        $hasMembers = false;
        try {
            foreach (
                $db->collection('location_members')
                   ->document($locId)
                   ->collection('members')
                   ->limit(1)
                   ->documents()
                as $m
            ) {
                if ($m->exists()) {
                    $hasMembers = true;
                    break;
                }
            }
        } catch (Exception $e) {
            error_log("[GHL_CALLBACK] Case B: could not check location_members for {$locId}: " . $e->getMessage());
        }

        if ($hasMembers) {
            // Tier 3 — fully registered
            error_log("[GHL_CALLBACK] Case B: {$locId} is Tier 3 (has members) — welcome_back.");
            $alreadyRegistered[$locId] = $locName;
        } else {
            // Tier 2 — token doc exists but registration never completed
            error_log("[GHL_CALLBACK] Case B: {$locId} is Tier 2 (token exists, no members) — needs registration.");
            $needsRegistration[$locId] = $locName;
        }
    }

    error_log('[GHL_CALLBACK_DEBUG] bulk_caseB_registration_split=' . json_encode([
        'needs_registration' => array_keys($needsRegistration),
        'already_registered' => array_keys($alreadyRegistered),
    ]));

    // ── Route based on registration tier ─────────────────────────────────────
    if (count($needsRegistration) === 0) {
        // All locations are Tier 3 — everyone already registered
        $onlyLocId   = array_key_first($alreadyRegistered);
        $onlyLocName = $alreadyRegistered[$onlyLocId];

        $tokenB = jwt_sign([
            'type'          => 'install',
            'location_id'   => $onlyLocId,
            'location_name' => $onlyLocName,
            'company_id'    => $companyId,
        ], $jwtSecretB, 900);

        error_log("[GHL_CALLBACK] Case B: all locations are Tier 3 — redirect to register-from-install.");
        header('Location: https://smspro-api.nolacrm.io/install-register.php?install_token=' . urlencode($tokenB), true, 302);

    } elseif (count($needsRegistration) === 1) {
        // Exactly one location needs registration — send directly to the form
        $onlyLocId   = array_key_first($needsRegistration);
        $onlyLocName = $needsRegistration[$onlyLocId];

        $tokenB = jwt_sign([
            'type'          => 'install',
            'location_id'   => $onlyLocId,
            'location_name' => $onlyLocName,
            'company_id'    => $companyId,
        ], $jwtSecretB, 900);

        error_log("[GHL_CALLBACK] Case B: 1 location needs registration ({$onlyLocId}) — redirect to register-from-install.");
        header('Location: https://smspro-api.nolacrm.io/install-register.php?install_token=' . urlencode($tokenB), true, 302);

    } else {
        // Multiple locations need registration — default to the first one for the initial registration form
        $needCount   = count($needsRegistration);
        $onlyLocId   = array_key_first($needsRegistration);
        $onlyLocName = $needsRegistration[$onlyLocId];

        $tokenB = jwt_sign([
            'type'          => 'install',
            'location_id'   => $onlyLocId,
            'location_name' => $onlyLocName,
            'company_id'    => $companyId,
        ], $jwtSecretB, 900);

        error_log("[GHL_CALLBACK] Case B: {$needCount} locations need registration — redirecting to registration form for first location.");
        header('Location: https://smspro-api.nolacrm.io/install-register.php?install_token=' . urlencode($tokenB), true, 302);
    }
    exit;
}

// ─── Determine Location ID ────────────────────────────────────────────────────
// Prefer explicit token response fields. state is fallback-only.
$locationId = $data['locationId'] ?? $data['location_id'] ?? extract_location_id_from_state($state);
error_log('[GHL_CALLBACK_DEBUG] final_location_resolution=' . json_encode([
    'resolved_locationId' => $locationId,
    'token_locationId' => $data['locationId'] ?? ($data['location_id'] ?? null),
    'state_present' => $state !== null && $state !== '',
]));
if (!$locationId)
    render_error('No Location ID returned.', $data);

$locationIdSafe = htmlspecialchars((string)$locationId, ENT_QUOTES, 'UTF-8');

// Alias for render_page() global
$id          = $locationId;
$idSafe      = $locationIdSafe;
$userType    = 'Location';
$usedAppType = 'subaccount';

// ─── Fetch Location Name ───────────────────────────────────────────────────────
$locationName = '';
try {
    $locCh = curl_init('https://services.leadconnectorhq.com/locations/' . $locationId);
    curl_setopt($locCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($locCh, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $data['access_token'],
        'Accept: application/json',
        'Version: 2021-07-28',
    ]);
    $locResp = curl_exec($locCh);
    $locCode = curl_getinfo($locCh, CURLINFO_HTTP_CODE);
    curl_close($locCh);

    if ($locCode === 200) {
        $locData      = json_decode($locResp, true);
        $locationName = $locData['location']['name'] ?? '';
    }
} catch (Exception $e) {
    error_log("Failed to fetch location name in callback for $locationId: " . $e->getMessage());
}

$displayName     = $locationName;
$displayNameSafe = $locationName ? htmlspecialchars($locationName, ENT_QUOTES, 'UTF-8') : 'Your Sub-Account';
$dashboardUrl    = 'https://app.nolacrm.io/v2/location/' . $locationIdSafe . '/custom-page-link/69a642aae76974824fd39bb6';

// ─── Save Tokens & Metadata to Firestore ──────────────────────────────────────
$db = get_firestore();
$now = new DateTimeImmutable();
$expiresAtUnix = time() + (int)($data['expires_in'] ?? 0);

try {
    // 1. Save main tokens
    $tokenPayload = [
        'access_token' => $data['access_token'] ?? null,
        'refresh_token' => $data['refresh_token'] ?? null,
        'scope' => $data['scope'] ?? null,
        'expires_at' => $expiresAtUnix,
        'userType' => $userType,
        'companyId' => $data['companyId'] ?? '',
        'hashed_companyId' => $data['hashedCompanyId'] ?? '',
        'userId' => $data['userId'] ?? '',
        'appId'     => $ghlApps[$usedAppType]['clientId'], // Store which app provided this token
        'client_id' => $ghlApps[$usedAppType]['clientId'], // Standard field name read by GhlClient::refreshToken()
        'appType'   => $usedAppType,
        'raw' => $data,
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ];

    if ($userType === 'Location') {
        $tokenPayload['location_id'] = $id;
        $tokenPayload['location_name'] = $displayName;
    }
    else {
        $tokenPayload['agency_name'] = $displayName;
    }

    $db->collection('ghl_tokens')->document((string)$id)->set($tokenPayload, ['merge' => true]);

    // (Removed legacy duplicate bulk install logic)

    // 2b. Register the subaccount in ghl_tokens for the Agency Dashboard to track
    if ($userType === 'Location') {
        $db->collection('ghl_tokens')->document((string)$id)->set([
            'location_id' => $id,
            'location_name' => $displayName,
            'companyId' => $data['companyId'] ?? '',
            'userType' => $data['userType'] ?? 'Location',
            'is_live' => true,
            'toggle_enabled' => true,
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        ], ['merge' => true]);
    }

    if ($userType === 'Location') {
        // 2. Provision Credits for new sub-account users
        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$id);
        $integrationRef = $db->collection('integrations')->document($intDocId);
        $integrationSnap = $integrationRef->snapshot();

        $integrationData = [
            'location_id' => $id,
            'location_name' => $displayName,
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'scope' => $data['scope'] ?? null,
            'expires_at' => $expiresAtUnix,
            'client_id' => $ghlApps[$usedAppType]['clientId'],
            'app_type' => $usedAppType,
        ];

        if (!$integrationSnap->exists()) {
            // First-time install — 10 free credits
            $integrationData['free_credits_total'] = 10;
            $integrationData['free_usage_count'] = 0;
            $integrationData['credit_balance'] = 0;
            $integrationData['installed_at'] = new \Google\Cloud\Core\Timestamp($now);
            $integrationRef->set($integrationData);
        }
        else {
            // Re-install: preserve credits, just update name & tokens
            $integrationRef->set($integrationData, ['merge' => true]);
        }
    }

    // 3. Update matching user docs with company_id and company_name
    if ($userType === 'Company' && $id) {
        try {
            $userQuery = $db->collection('users')
                ->where('agency_id', '=', (string)$id)
                ->documents();

            $updateFields = ['company_id' => (string)$id, 'updated_at' => new \Google\Cloud\Core\Timestamp($now)];
            if (!empty($displayName)) {
                $updateFields['company_name'] = $displayName;
            }

            foreach ($userQuery as $uDoc) {
                if ($uDoc->exists()) {
                    $uDoc->reference()->set($updateFields, ['merge' => true]);
                }
            }

            error_log(sprintf('[GHL_CALLBACK] Updated users collection with company_id=%s company_name="%s"', $id, $displayName ?: '(empty)'));
        }
        catch (Exception $ue) {
            error_log('GHL Callback - failed to update user docs: ' . $ue->getMessage());
        }
    }
}
catch (Exception $e) {
    render_error('Callback authorized, but failed to save tokens: ' . $e->getMessage());
}

// ─── Redirect Logic (replaces the old static success page) ────────────────────

require_once __DIR__ . '/api/jwt_helper.php';

$jwtSecret       = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
$reactAppUrl     = 'https://app.nolacrm.io';
$locationNameEnc = urlencode($locationName ?: 'Your Sub-Account');

// Check if ANY user is already registered for this location via location_members subcollection.
// This is faster than scanning the full users collection and is the correct source of truth.
$hasExistingMembers = false;
try {
    $membersSnap = $db->collection('location_members')
        ->document($locationId)
        ->collection('members')
        ->limit(1)
        ->documents();

    foreach ($membersSnap as $m) {
        if ($m->exists()) {
            $hasExistingMembers = true;
            break;
        }
    }
} catch (Exception $e) {
    error_log('[GHL_CALLBACK] Could not check location_members: ' . $e->getMessage());
}

    $installToken = jwt_sign([
        'type'          => 'install',
        'location_id'   => $locationId,
        'location_name' => $locationName ?: '',
        'company_id'    => $data['companyId'] ?? '',
    ], $jwtSecret, 900); // 15 minutes

    $redirectUrl = 'https://smspro-api.nolacrm.io/install-register.php?install_token=' . urlencode($installToken);
    error_log("[GHL_CALLBACK] Redirecting {$locationId} to registration.");
error_log('[GHL_CALLBACK_DEBUG] final_redirect=' . json_encode([
    'locationId' => $locationId,
    'hasExistingMembers' => $hasExistingMembers,
    'redirectUrl' => $redirectUrl,
]));

header('Location: ' . $redirectUrl, true, 302);
exit;

