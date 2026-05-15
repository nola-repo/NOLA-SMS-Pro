<?php

require __DIR__ . '/api/webhook/firestore_client.php';
require_once __DIR__ . '/api/install_helpers.php';

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

        .selection-container {
            margin-top: 10px;
            max-height: 80vh;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            text-align: left;
            padding-right: 4px;
        }
        
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
    global $locationIdSafe;

    $msg_safe = htmlspecialchars($message);
    $details_html = '';
    if (!empty($details)) {
        $json = htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT));
        $details_html = "<pre class=\"error-pre\">{$json}</pre>";
    }

    $reinstall_url = 'https://marketplace.leadconnectorhq.com/v2/oauth/chooselocation?response_type=code&redirect_uri=https%3A%2F%2Fsmspro-api.nolacrm.io%2Foauth%2Fcallback&client_id=6999da2b8f278296d95f7274-mmn30t4f&scope=workflows.readonly+conversations%2Fmessage.readonly+conversations.readonly+conversations.write+contacts.readonly+contacts.write+conversations%2Fmessage.write+saas%2Flocation.read+locations.readonly+locations%2Ftags.readonly+locations%2Ftags.write&version_id=6999da2b8f278296d95f7274';
    $stateLocationId = install_clean_location_id($locationIdSafe);
    if ($stateLocationId !== null) {
        $reinstall_url .= '&state=' . urlencode(json_encode(['selected_location_id' => $stateLocationId]));
    }

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

function create_install_selection_session(
    $db,
    string $jwtSecret,
    string $companyId,
    string $companyName,
    array $candidateIds,
    array $locationNames,
    string $reason,
    array $debug = [],
    string $selectionState = INSTALL_STATE_AMBIGUOUS
): array {
    $candidateIds = install_unique_ids($candidateIds);
    $rows = [];
    foreach ($candidateIds as $candidateId) {
        $rows[] = [
            'location_id' => $candidateId,
            'location_name' => trim((string)($locationNames[$candidateId] ?? '')),
        ];
    }

    $now = new DateTimeImmutable();
    $sessionRef = $db->collection('install_sessions')->newDocument();
    $sessionId = $sessionRef->id();
    $sessionRef->set([
        'type' => 'ambiguous_install_selection',
        'session_id' => $sessionId,
        'company_id' => $companyId,
        'company_name' => $companyName,
        'state' => $selectionState,
        'status' => 'needs_selection',
        'reason' => $reason,
        'candidate_locations' => $rows,
        'debug' => $debug,
        'created_at' => new \Google\Cloud\Core\Timestamp($now),
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        'expires_at' => new \Google\Cloud\Core\Timestamp((new DateTimeImmutable('+15 minutes'))),
    ], ['merge' => true]);

    $sessionToken = jwt_sign([
        'type' => 'install_selection_session',
        'session_id' => $sessionId,
        'company_id' => $companyId,
        'company_name' => $companyName,
        'candidate_hash' => hash('sha256', implode('|', $candidateIds)),
    ], $jwtSecret, 900);

    return [
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'candidate_locations' => $rows,
    ];
}

/**
 * Fetch available company locations only so the user can choose one explicitly.
 * This function never exchanges tokens, saves subaccount tokens, or provisions.
 *
 * @return array{ok:bool, ids:array<int,string>, names:array<string,string>, failures:array<int,array<string,mixed>>}
 */
function fetch_company_locations_for_selection(string $companyId, string $companyToken): array
{
    $ids = [];
    $names = [];
    $failures = [];
    $skip = 0;
    $limit = 100;

    do {
        $url = 'https://services.leadconnectorhq.com/locations/search?companyId='
            . urlencode($companyId)
            . '&skip=' . urlencode((string)$skip)
            . '&limit=' . urlencode((string)$limit);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $companyToken,
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            $failures[] = [
                'code' => $code,
                'skip' => $skip,
                'error' => $curlError,
                'raw' => substr((string)$raw, 0, 400),
            ];
            break;
        }

        $body = json_decode((string)$raw, true);
        if (!is_array($body)) {
            $failures[] = [
                'code' => $code,
                'skip' => $skip,
                'error' => 'invalid_json',
                'raw' => substr((string)$raw, 0, 400),
            ];
            break;
        }

        $batch = $body['locations'] ?? [];
        if (!is_array($batch)) {
            $batch = [];
        }

        foreach ($batch as $loc) {
            if (!is_array($loc)) {
                continue;
            }
            $id = install_clean_location_id($loc['id'] ?? $loc['locationId'] ?? $loc['location_id'] ?? null);
            if ($id === null || in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            $name = trim((string)($loc['name'] ?? $loc['location_name'] ?? $loc['locationName'] ?? ''));
            if ($name !== '') {
                $names[$id] = $name;
            }
        }

        $skip += $limit;
    } while (!empty($batch) && count($batch) === $limit && count($ids) < 1000);

    return [
        'ok' => empty($failures) && !empty($ids),
        'ids' => $ids,
        'names' => $names,
        'failures' => $failures,
    ];
}

function render_company_location_recovery_selection(
    $db,
    string $jwtSecret,
    string $companyId,
    string $companyName,
    string $companyToken,
    array $resolution,
    array $debug = []
): void {
    $available = fetch_company_locations_for_selection($companyId, $companyToken);
    error_log('[GHL_CALLBACK] selection_required_locations_fetch=' . json_encode([
        'companyId' => $companyId,
        'ok' => $available['ok'],
        'count' => count($available['ids']),
        'failures' => $available['failures'],
    ]));

    if (!$available['ok']) {
        render_error('GoHighLevel did not identify the selected sub-account, and NOLA SMS Pro could not load the company sub-account list for explicit selection. Please try the Marketplace install again.', [
            'state' => INSTALL_STATE_SELECTION_REQUIRED,
            'companyId' => $companyId,
            'resolution' => $resolution,
            'location_fetch_failures' => $available['failures'],
        ]);
    }

    $selectionSession = create_install_selection_session(
        $db,
        $jwtSecret,
        $companyId,
        $companyName,
        $available['ids'],
        $available['names'],
        (string)($resolution['reason'] ?? 'no_location_signal'),
        $debug + [
            'resolution_source' => $resolution['source'] ?? 'unresolved',
            'recovery' => 'company_locations_search',
        ],
        INSTALL_STATE_SELECTION_REQUIRED
    );

    error_log('[GHL_CALLBACK] Selection-required install recovery session=' . $selectionSession['session_id']);
    render_ambiguous_selection(
        (string)$selectionSession['session_token'],
        $selectionSession['candidate_locations'],
        $companyName
    );
}

function render_ambiguous_selection(string $sessionToken, array $candidateLocations, string $companyName = ''): void
{
    $sessionTokenJson = json_encode($sessionToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $buttons = '';
    foreach ($candidateLocations as $row) {
        $id = install_clean_location_id($row['location_id'] ?? null);
        if ($id === null) {
            continue;
        }
        $name = trim((string)($row['location_name'] ?? ''));
        $label = $name !== '' ? $name : 'Unnamed Sub-account';
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $buttons .= <<<HTML
            <button type="button" class="btn-submit" style="margin:8px 0 0; text-align:left; display:block;" data-location-id="{$safeId}" onclick="selectLocation(this)">
                <span style="display:block; font-weight:800;">{$safeLabel}</span>
                <span style="display:block; font-size:11px; opacity:.85; margin-top:2px; word-break:break-all;">{$safeId}</span>
            </button>
HTML;
    }

    $companyLine = $companyName !== ''
        ? '<p class="subtitle" style="margin-bottom:18px;">Choose the exact subaccount for ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '.</p>'
        : '<p class="subtitle" style="margin-bottom:18px;">Choose the exact subaccount to continue installation.</p>';

    $body = <<<HTML
        <h1>Select Sub-account</h1>
        {$companyLine}
        <div id="selection-error" class="error-pre hidden"></div>
        <div class="selection-container">{$buttons}</div>
        <p style="font-size:11px;color:#6e6e73;line-height:1.45;margin-top:18px;">We could not verify which subaccount GoHighLevel selected, so NOLA SMS Pro needs one explicit confirmation before continuing.</p>
        <script>
          const INSTALL_SELECTION_TOKEN = {$sessionTokenJson};
          async function selectLocation(btn) {
            const locationId = btn.getAttribute('data-location-id');
            const err = document.getElementById('selection-error');
            document.querySelectorAll('[data-location-id]').forEach(b => { b.disabled = true; b.style.opacity = '.65'; });
            btn.textContent = 'Continuing...';
            err.classList.add('hidden');
            try {
              const res = await fetch('/api/auth/resolve-install-selection', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ session_token: INSTALL_SELECTION_TOKEN, location_id: locationId })
              });
              const data = await res.json().catch(() => ({}));
              if (!res.ok || !data.url) throw new Error(data.error || 'Could not continue installation.');
              window.location.assign(data.url);
            } catch (e) {
              err.textContent = e.message || 'Could not continue installation.';
              err.classList.remove('hidden');
              document.querySelectorAll('[data-location-id]').forEach(b => { b.disabled = false; b.style.opacity = '1'; });
              btn.innerHTML = '<span style="display:block; font-weight:800;">Try again</span><span style="display:block; font-size:11px; opacity:.85; margin-top:2px; word-break:break-all;">' + locationId + '</span>';
            }
          }
        </script>
HTML;
    render_page('Select Sub-account', $body);
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
    foreach (['locationId', 'location_id', 'selected_location_id'] as $key) {
        $v = $json[$key] ?? null;
        $clean = install_clean_location_id($v);
        if ($clean !== null) {
            return $clean;
        }
    }
    foreach (['location_ids', 'locationIds', 'approvedLocations'] as $listKey) {
        $locIds = $json[$listKey] ?? null;
        $cleanListValue = install_clean_location_id($locIds);
        if ($cleanListValue !== null) {
            return $cleanListValue;
        }
        if (is_array($locIds) && count($locIds) === 1) {
            $clean = install_clean_location_id($locIds[0] ?? null);
            if ($clean !== null) {
                return $clean;
            }
        }
    }
    $nested = $json['locations'] ?? null;
    if (is_array($nested) && count($nested) === 1 && is_array($nested[0])) {
        $row = $nested[0];
        $clean = install_clean_location_id($row['id'] ?? $row['locationId'] ?? $row['location_id'] ?? null);
        if ($clean !== null) {
            return $clean;
        }
    }
    foreach (['location', 'selectedLocation', 'subaccount', 'subAccount', 'context'] as $nestedKey) {
        if (isset($json[$nestedKey]) && is_array($json[$nestedKey])) {
            $nestedLocation = location_id_from_assoc_json($json[$nestedKey]);
            if ($nestedLocation !== null) {
                return $nestedLocation;
            }
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
        $fromQuery = $parsed['locationId'] ?? $parsed['location_id'] ?? $parsed['selected_location_id'] ?? null;
        $fromQueryClean = install_clean_location_id($fromQuery);
        if ($fromQueryClean !== null) {
            return $fromQueryClean;
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

        // Case 2c: explicit location key embedded in a larger plain string
        if (preg_match('/(?:locationId|location_id|selected_location_id)["=: ]+([A-Za-z0-9_-]{8,})/i', $trimmed, $m)) {
            return $m[1];
        }
    }

    return null;
}

/**
 * Best-effort extraction of a locationId directly from callback query params.
 * Handles common variants seen in GHL redirects.
 */
function extract_location_id_from_query(array $query): ?string
{
    $directKeys = ['locationId', 'location_id', 'selected_location_id'];
    foreach ($directKeys as $key) {
        $val = $query[$key] ?? null;
        $clean = install_clean_location_id($val);
        if ($clean !== null) {
            return $clean;
        }
    }

    // approvedLocations can be an array or CSV with one selected sub-account.
    $approvedIds = install_unique_ids(array_merge(
        extract_location_ids_from_mixed($query['approvedLocations'] ?? null),
        extract_location_ids_from_mixed($query['approvedLocationIds'] ?? null)
    ));
    if (count($approvedIds) === 1) {
        return $approvedIds[0];
    }

    return null;
}

/**
 * Normalize location IDs from mixed callback payload formats.
 * Accepts array, CSV, or JSON string and returns unique IDs.
 *
 * @return array<int, string>
 */
function extract_location_ids_from_mixed($value): array
{
    $ids = [];
    $walk = null;
    $walk = static function ($candidate) use (&$ids, &$walk): void {
        $clean = install_clean_location_id($candidate);
        if ($clean !== null) {
            $ids[] = $clean;
            return;
        }

        if (!is_array($candidate)) {
            return;
        }

        foreach (['id', 'locationId', 'location_id', 'selected_location_id'] as $key) {
            if (!array_key_exists($key, $candidate)) {
                continue;
            }
            $clean = install_clean_location_id($candidate[$key]);
            if ($clean !== null) {
                $ids[] = $clean;
            }
        }

        foreach (['locations', 'approvedLocations', 'approvedLocationIds', 'locationIds', 'location_ids', 'location', 'selectedLocation', 'subaccount', 'subAccount', 'context'] as $key) {
            if (isset($candidate[$key]) && is_array($candidate[$key])) {
                foreach ($candidate[$key] as $nested) {
                    $walk($nested);
                }
            }
        }

        $keys = array_keys($candidate);
        if ($keys === range(0, count($candidate) - 1)) {
            foreach ($candidate as $item) {
                $walk($item);
            }
        }
    };

    if (is_array($value)) {
        $walk($value);
    } elseif (is_string($value) && trim($value) !== '') {
        $raw = trim($value);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $walk($decoded);
        } else {
            foreach (array_map('trim', explode(',', $raw)) as $item) {
                $walk($item);
            }
        }
    }

    return install_unique_ids($ids);
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
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
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

        $rawText = is_string($raw) ? $raw : '';
        $sanitizedRaw = preg_replace('/"(access_token|refresh_token)"\s*:\s*"[^"]*"/i', '"$1":"[REDACTED]"', $rawText);
        $failures[] = [
            'format' => $attempt['format'],
            'code' => $code,
            'json_decode_ok' => $jsonDecodeOk,
            'has_access_token_field' => !empty($data['access_token']) || (is_string($raw) && strpos($raw, '"access_token"') !== false),
            'raw' => substr($sanitizedRaw, 0, 400),
        ];
        error_log("[GHL_CALLBACK] locationToken {$attempt['format']} failed for {$locationId}: HTTP {$code} — {$sanitizedRaw}");
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

/**
 * Returns true if any user is already linked to this location.
 * Checks both root denormalized field and user-owned subaccounts model.
 */
function has_linked_user_for_location($db, string $locationId): bool
{
    return install_linked_account_for_location($db, $locationId) !== null;
}

// ─── OAuth Config ──────────────────────────────────────────────────────────────
// Subaccount app only — NO agency fallback.
// Agency installs use /oauth/agency-callback → ghl_agency_callback.php
$clientId     = getenv('GHL_CLIENT_ID');
$clientSecret = getenv('GHL_CLIENT_SECRET');
$redirectUri  = 'https://smspro-api.nolacrm.io/oauth/callback'; // HARDCODED

if (!$clientId || !$clientSecret) {
    error_log('[GHL_CALLBACK] Server configuration error: GHL client credentials are not set.');
    render_error('Server configuration error: GHL credentials are not set up.');
}
if (!isset($_GET['code']))
    render_error('No authorization code was received.');

$code  = $_GET['code'];
$state = $_GET['state'] ?? null;
$queryLocationId = extract_location_id_from_query($_GET);
$queryApprovedLocationIds = install_unique_ids(array_merge(
    install_extract_location_ids_from_mixed($_GET['approvedLocations'] ?? null),
    install_extract_location_ids_from_mixed($_GET['approvedLocationIds'] ?? null)
));
$debugTrace = [
    'source' => 'ghl_callback',
    'has_code' => !empty($code),
    'state_present' => $state !== null && $state !== '',
    'state_preview' => $state ? substr((string)$state, 0, 180) : null,
    'query_locationId' => $queryLocationId,
    'query_approved_location_ids' => $queryApprovedLocationIds,
];

// ─── Token Exchange ────────────────────────────────────────────────────────────
$ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
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

error_log('OAUTH RESPONSE: ' . json_encode(install_redact_oauth_token_log_payload($data), JSON_PRETTY_PRINT));

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
        'clientId'     => $clientId,
        'clientSecret' => $clientSecret,
        'userType'     => 'Location',
    ],
    'agency' => [
        'clientId'     => getenv('GHL_AGENCY_CLIENT_ID')     ?: '69d31f33b3071b25dbcc5656-mnqxvtt3',
        'clientSecret' => getenv('GHL_AGENCY_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322',
        'userType'     => 'Company',
    ],
];

// Company-scoped selected-install guard.
// GHL may return a Company-scoped token even for a Marketplace install where
// the user selected one subaccount. This callback is still single-location
// only: resolve the selected location, exchange only that location token, or
// stop/ask for explicit selection. Never provision the whole company here.
if (($data['userType'] ?? '') === 'Company') {
    error_log('[GHL_CALLBACK_DEBUG] branch=company_scoped_selected_install companyId=' . ($data['companyId'] ?? ''));
    // This is the subaccount app; use subaccount credentials throughout.
    $companyId          = $data['companyId'] ?? null;
    $subaccountClientId = $ghlApps['subaccount']['clientId'];
    $companyToken       = (string)($data['access_token'] ?? '');
    $companyRefresh     = $data['refresh_token'] ?? null;
    $expiresIn          = (int)($data['expires_in'] ?? 86400);
    $companyExpiresAt   = time() + $expiresIn;
    $companyNameFromToken = install_extract_company_name($data);

    if (!$companyId) {
        render_error('Company-scoped install received no companyId in token response.', $data);
    }
    if ($companyToken === '') {
        render_error('Company-scoped install received no company access token.', $data);
    }

    error_log("[GHL_CALLBACK] Company-scoped selected install detected; resolving one selected location for companyId={$companyId}.");

    // ── CRITICAL DEBUG: log exactly what GHL sent us ──────────────────────────
    $locationsPreview = [];
    if (!empty($data['locations']) && is_array($data['locations'])) {
        foreach (array_slice($data['locations'], 0, 10) as $l) {
            $locationsPreview[] = [
                'id'         => $l['id'] ?? $l['locationId'] ?? 'N/A',
                'name'       => $l['name'] ?? $l['location_name'] ?? 'N/A',
            ];
        }
    }
    $debugPayload = [
        'ts'                 => date('Y-m-d H:i:s'),
        'get_params'         => $_GET,
        'data_keys'          => array_keys($data),
        'locationId_root'    => $data['locationId'] ?? null,
        'location_id_root'   => $data['location_id'] ?? null,
        'approvedLocations'  => $data['approvedLocations'] ?? null,
        'locations_count'    => isset($data['locations']) ? count($data['locations']) : 0,
        'locations_preview'  => $locationsPreview,
        'state_preview'      => $state ? substr($state, 0, 120) : null,
    ];
    error_log('[GHL_CALLBACK_DEBUG] ghl_token_response_structure=' . json_encode($debugPayload));

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
            'company_name'  => $companyNameFromToken,
            'agency_name'   => $companyNameFromToken,
            'install_state' => INSTALL_STATE_PENDING_OAUTH,
            'install_status' => INSTALL_STATE_INSTALL_PENDING,
            'oauth_pending_started_at' => new \Google\Cloud\Core\Timestamp($now),
            'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
        ], ['merge' => true]);
    } catch (Exception $e) {
        render_error('Callback authorized, but failed to save pending company token: ' . $e->getMessage(), [
            'companyId' => $companyId,
        ]);
    }

    // ── CASE A: Single-location install from agency view ─────────────────────
    // Resolve the selected sub-account once, using trusted picker signals. This
    // avoids stale token/state values selecting a different location.
    $hasLocationsArray = !empty($data['locations']) && is_array($data['locations']);
    $approvedLocationIds = install_unique_ids(array_merge(
        install_extract_location_ids_from_mixed($data['approvedLocations'] ?? null),
        install_extract_location_ids_from_mixed($data['approvedLocationIds'] ?? null)
    ));
    if (empty($approvedLocationIds) && !empty($queryApprovedLocationIds)) {
        $approvedLocationIds = $queryApprovedLocationIds;
    }
    $stateLocationId = extract_location_id_from_state($state);
    $caseAResolution = install_resolve_selected_location([
        'token_location_id' => $data['locationId'] ?? ($data['location_id'] ?? null),
        'query_location_id' => $queryLocationId,
        'approved_location_ids' => $approvedLocationIds,
        'query_approved_location_ids' => $queryApprovedLocationIds,
        'locations' => $data['locations'] ?? [],
        'state_location_id' => $stateLocationId,
    ]);
    $locationsArrayIds = $caseAResolution['candidate_ids'];
    $singleLocationId = $caseAResolution['ok'] ? $caseAResolution['location_id'] : null;
    $caseAResolutionMode = (string)($caseAResolution['resolutionMode'] ?? ($caseAResolution['status'] ?? ''));
    $caseACheckpoint = install_final_install_checkpoint($singleLocationId, $caseAResolutionMode);
    if (empty($caseACheckpoint['ok'])) {
        $singleLocationId = null;
        $caseAResolution['install_pending'] = $caseACheckpoint;
    }

    error_log('[GHL_CALLBACK_DEBUG] selected_location_candidate=' . json_encode([
        'singleLocationId' => $singleLocationId,
        'resolutionMode' => $caseAResolutionMode,
        'resolution' => $caseAResolution,
        'state_present' => $state !== null && $state !== '',
    ]));

    if ($singleLocationId) {
        $locationIdSafe = htmlspecialchars((string)$singleLocationId, ENT_QUOTES, 'UTF-8');
        error_log("[GHL_CALLBACK] Case A: single-location agency install — locationId={$singleLocationId}");
        $caseATokenExistedBefore = install_token_doc_exists($db, (string)$singleLocationId);
        if (install_location_company_mismatch($db, (string)$singleLocationId, (string)$companyId)) {
            render_error('The selected sub-account is already linked to a different GoHighLevel company. Please reinstall from the correct GHL sub-account.', [
                'locationId' => $singleLocationId,
                'companyId' => $companyId,
            ]);
        }

        // Exchange company token -> location-scoped token
        $ltResult2 = exchange_location_token($companyToken, $companyId, $singleLocationId);
        $ltData2   = $ltResult2['data'];

        if (!$ltResult2['ok']) {
            error_log('[GHL_CALLBACK_DEBUG] caseA_locationToken_failures=' . json_encode([
                'locationId' => $singleLocationId,
                'companyId'  => $companyId,
                'failures'   => $ltResult2['failures'] ?? [],
            ]));
            render_error('Failed to exchange company token for location token.', [
                'locationId' => $singleLocationId,
                'companyId'  => $companyId,
                'hint'       => 'Tried both form and JSON payload formats for /oauth/locationToken.',
            ]);
        }
        error_log("[GHL_CALLBACK] Case A: locationToken succeeded for {$singleLocationId} via {$ltResult2['format']} format.");

        $ltExpires2 = time() + (int)($ltData2['expires_in'] ?? 86400);

        // Try to get location name from the locations[] payload GHL already sent us
        // (free — avoids a round-trip to the GHL /locations/{id} API before redirect).
        $singleLocName = '';
        if ($hasLocationsArray) {
            foreach ($data['locations'] as $loc) {
                if (!is_array($loc)) continue;
                $lid = $loc['id'] ?? $loc['locationId'] ?? null;
                if ((string)$lid === (string)$singleLocationId) {
                    $singleLocName = $loc['name'] ?? $loc['location_name'] ?? '';
                    break;
                }
            }
        }

        // If the name is still unknown, do one quick lookup before signing token.
        if ($singleLocName === '') {
            $lnCurl2 = curl_init('https://services.leadconnectorhq.com/locations/' . $singleLocationId);
            curl_setopt_array($lnCurl2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $ltData2['access_token'], 'Accept: application/json', 'Version: 2021-07-28'],
            ]);
            $lnResp2 = curl_exec($lnCurl2);
            curl_close($lnCurl2);
            $singleLocName = json_decode($lnResp2 ?: '', true)['location']['name'] ?? '';
        }

        // Save selected-location tokens first; redirect is decided after classification below.

        // ── Background: Save Location-scoped token ────────────────────────────
        try {
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
                'company_name'          => $companyNameFromToken,
                'install_state'         => INSTALL_STATE_PENDING_OAUTH,
                'install_status'        => INSTALL_STATE_INSTALL_PENDING,
                'install_resolution_mode' => $caseAResolutionMode,
                'install_resolution_source' => (string)($caseAResolution['source'] ?? 'case_a_single_location'),
                'oauth_pending_started_at' => new \Google\Cloud\Core\Timestamp($now),
                'updated_at'            => new \Google\Cloud\Core\Timestamp($now),
            ], ['merge' => true]);
        } catch (Exception $e) {
            render_error('Callback authorized, but failed to save pending location token: ' . $e->getMessage(), [
                'locationId' => $singleLocationId,
                'companyId' => $companyId,
            ]);
        }

        require_once __DIR__ . '/api/jwt_helper.php';
        $jwtSecret2 = getenv('JWT_SECRET');
        if ($jwtSecret2 === false || trim((string)$jwtSecret2) === '') {
            error_log('[GHL_CALLBACK] JWT_SECRET missing; cannot generate install token.');
            render_error('Server configuration error: JWT secret missing.');
        }
        $companyNameCaseA = $companyNameFromToken;
        if ($companyNameCaseA === '') {
            try {
                $coSnapA = $db->collection('ghl_tokens')->document((string)$companyId)->snapshot();
                if ($coSnapA->exists()) {
                    $coDataA = $coSnapA->data();
                    $companyNameCaseA = (string)($coDataA['company_name'] ?? $coDataA['agency_name'] ?? $coDataA['location_name'] ?? '');
                }
            } catch (Exception $e) {
                error_log('[GHL_CALLBACK] Case A company name lookup failed: ' . $e->getMessage());
            }
        }

        $caseADecision = install_decide_location_redirect(
            $db,
            $jwtSecret2,
            (string)$singleLocationId,
            (string)$singleLocName,
            (string)$companyId,
            $companyNameCaseA,
            (string)($caseAResolution['source'] ?? 'case_a_single_location'),
            $caseATokenExistedBefore
        );

        error_log('[GHL_CALLBACK_DEBUG] caseA_install_decision=' . json_encode([
            'locationId' => $singleLocationId,
            'decision' => $caseADecision['kind'],
            'status' => $caseADecision['status'],
            'classification' => $caseADecision['classification'],
        ]));

        if ($caseADecision['kind'] === 'error' || empty($caseADecision['url'])) {
            render_error('The selected sub-account could not be finalized. Please reinstall from the selected GHL sub-account.', [
                'locationId' => $singleLocationId,
                'companyId' => $companyId,
                'classification' => $caseADecision['classification'],
            ]);
        }

        try {
            install_finalize_location_install(
                $db,
                (string)$singleLocationId,
                $caseADecision,
                $caseAResolutionMode,
                (string)($caseAResolution['source'] ?? 'case_a_single_location'),
                $now
            );
        } catch (Exception $e) {
            render_error('Callback authorized, but failed to finalize install: ' . $e->getMessage(), [
                'locationId' => $singleLocationId,
                'resolution' => $caseAResolution,
            ]);
        }

        header('Location: ' . $caseADecision['url'], true, 302);
        error_log("[GHL_CALLBACK] Single-location install done for {$singleLocationId} ({$singleLocName}); redirect={$caseADecision['kind']}.");
        exit;
    }

    if (count($locationsArrayIds) > 1) {
        require_once __DIR__ . '/api/jwt_helper.php';
        $jwtSecretSelect = getenv('JWT_SECRET');
        if ($jwtSecretSelect === false || trim((string)$jwtSecretSelect) === '') {
            error_log('[GHL_CALLBACK] JWT_SECRET missing; cannot generate ambiguous selection session.');
            render_error('Server configuration error: JWT secret missing.');
        }

        $selectionSession = create_install_selection_session(
            $db,
            (string)$jwtSecretSelect,
            (string)$companyId,
            $companyNameFromToken,
            $locationsArrayIds,
            $caseAResolution['location_names'] ?? [],
            (string)($caseAResolution['reason'] ?? 'ambiguous_location_candidates'),
            [
                'resolution_source' => $caseAResolution['source'] ?? 'unresolved',
                'token_userType' => $data['userType'] ?? null,
                'isBulkInstallation' => $data['isBulkInstallation'] ?? null,
            ]
        );
        error_log('[GHL_CALLBACK] Ambiguous install selection required session=' . $selectionSession['session_id']);
        render_ambiguous_selection(
            (string)$selectionSession['session_token'],
            $selectionSession['candidate_locations'],
            $companyNameFromToken
        );
    }

    if (empty($locationsArrayIds)) {
        require_once __DIR__ . '/api/jwt_helper.php';
        $jwtSecretSelect = getenv('JWT_SECRET');
        if ($jwtSecretSelect === false || trim((string)$jwtSecretSelect) === '') {
            error_log('[GHL_CALLBACK] JWT_SECRET missing; cannot generate selection-required recovery session.');
            render_error('Server configuration error: JWT secret missing.');
        }

        render_company_location_recovery_selection(
            $db,
            (string)$jwtSecretSelect,
            (string)$companyId,
            $companyNameFromToken,
            (string)$companyToken,
            $caseAResolution,
            [
                'token_userType' => $data['userType'] ?? null,
                'isBulkInstallation' => $data['isBulkInstallation'] ?? null,
                'state_present' => $state !== null && $state !== '',
            ]
        );
    }

    error_log('[GHL_CALLBACK] Subaccount callback refused bulk provisioning without an exact selected location.');
    render_error('No reliable selected sub-account was returned by GoHighLevel. Please reinstall and select exactly one sub-account from the Marketplace install modal.', [
        'state' => INSTALL_STATE_AMBIGUOUS,
        'companyId' => $companyId,
        'resolution' => $caseAResolution,
        'candidate_count' => count($locationsArrayIds),
    ]);
}

// ─── Determine Location ID ────────────────────────────────────────────────────────────────
// Resolve only from trusted OAuth/GHL selection signals; never infer from registration status.
$finalResolution = install_resolve_selected_location([
    'token_location_id' => $data['locationId'] ?? ($data['location_id'] ?? null),
    'query_location_id' => $queryLocationId,
    'approved_location_ids' => install_unique_ids(array_merge(
        install_extract_location_ids_from_mixed($data['approvedLocations'] ?? null),
        install_extract_location_ids_from_mixed($data['approvedLocationIds'] ?? null)
    )),
    'query_approved_location_ids' => $queryApprovedLocationIds,
    'locations' => $data['locations'] ?? [],
    'state_location_id' => extract_location_id_from_state($state),
]);
$finalResolutionMode = (string)($finalResolution['resolutionMode'] ?? ($finalResolution['status'] ?? ''));
$locationId = $finalResolution['ok'] ? $finalResolution['location_id'] : null;
$finalCheckpoint = install_final_install_checkpoint($locationId, $finalResolutionMode);
if (empty($finalCheckpoint['ok'])) {
    $locationId = null;
    $finalResolution['install_pending'] = $finalCheckpoint;
}
error_log('[GHL_CALLBACK_DEBUG] final_location_resolution=' . json_encode([
    'resolved_locationId' => $locationId,
    'resolutionMode' => $finalResolutionMode,
    'token_locationId' => $data['locationId'] ?? ($data['location_id'] ?? null),
    'query_locationId' => $queryLocationId,
    'resolution' => $finalResolution,
    'state_present' => $state !== null && $state !== '',
]));
if (!$locationId) {
    $finalCandidateIds = $finalResolution['candidate_ids'] ?? [];
    $finalCompanyId = trim((string)($data['companyId'] ?? ''));
    if ($finalCompanyId !== '' && ($data['userType'] ?? '') === 'Company') {
        require_once __DIR__ . '/api/jwt_helper.php';
        $jwtSecretSelect = getenv('JWT_SECRET');
        if ($jwtSecretSelect === false || trim((string)$jwtSecretSelect) === '') {
            error_log('[GHL_CALLBACK] JWT_SECRET missing; cannot generate final selection session.');
            render_error('Server configuration error: JWT secret missing.');
        }
        $db = get_firestore();
        $now = new DateTimeImmutable();
        $finalCompanyName = install_extract_company_name($data);
        try {
            $db->collection('ghl_tokens')->document($finalCompanyId)->set([
                'access_token' => $data['access_token'] ?? null,
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_at' => time() + (int)($data['expires_in'] ?? 86400),
                'client_id' => $ghlApps[$usedAppType]['clientId'],
                'appId' => $ghlApps[$usedAppType]['clientId'],
                'appType' => 'subaccount',
                'userType' => 'Company',
                'companyId' => $finalCompanyId,
                'company_name' => $finalCompanyName,
                'install_state' => INSTALL_STATE_PENDING_OAUTH,
                'install_status' => INSTALL_STATE_INSTALL_PENDING,
                'oauth_pending_started_at' => new \Google\Cloud\Core\Timestamp($now),
                'updated_at' => new \Google\Cloud\Core\Timestamp($now),
            ], ['merge' => true]);
        } catch (Exception $e) {
            render_error('Callback authorized, but failed to save pending company token: ' . $e->getMessage(), [
                'companyId' => $finalCompanyId,
            ]);
        }

        if (count($finalCandidateIds) > 1) {
            $selectionSession = create_install_selection_session(
                $db,
                (string)$jwtSecretSelect,
                $finalCompanyId,
                $finalCompanyName,
                $finalCandidateIds,
                $finalResolution['location_names'] ?? [],
                (string)($finalResolution['reason'] ?? 'ambiguous_location_candidates'),
                ['resolution_source' => $finalResolution['source'] ?? 'unresolved']
            );
            render_ambiguous_selection(
                (string)$selectionSession['session_token'],
                $selectionSession['candidate_locations'],
                $finalCompanyName
            );
        }

        if (empty($finalCandidateIds) && !empty($data['access_token'])) {
            render_company_location_recovery_selection(
                $db,
                (string)$jwtSecretSelect,
                $finalCompanyId,
                $finalCompanyName,
                (string)$data['access_token'],
                $finalResolution,
                [
                    'token_userType' => $data['userType'] ?? null,
                    'state_present' => $state !== null && $state !== '',
                ]
            );
        }
    }

    render_error('No reliable Location ID returned. Please reinstall from the selected GHL sub-account.', [
        'token_response' => $data,
        'resolution' => $finalResolution,
    ]);
}

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
    curl_setopt($locCh, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($locCh, CURLOPT_TIMEOUT, 6);
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
$tokenExistedBeforeDirect = install_token_doc_exists($db, (string)$locationId);
$companyIdForDirect = (string)($data['companyId'] ?? '');
$companyNameDirect = install_extract_company_name($data);
if (install_location_company_mismatch($db, (string)$locationId, $companyIdForDirect)) {
    render_error('The selected sub-account is already linked to a different GoHighLevel company. Please reinstall from the correct GHL sub-account.', [
        'locationId' => $locationId,
        'companyId' => $companyIdForDirect,
    ]);
}

try {
    // 1. Save main tokens
    $tokenPayload = [
        'access_token' => $data['access_token'] ?? null,
        'refresh_token' => $data['refresh_token'] ?? null,
        'scope' => $data['scope'] ?? null,
        'expires_at' => $expiresAtUnix,
        'userType' => $userType,
        'companyId' => $data['companyId'] ?? '',
        'company_name' => $companyNameDirect,
        'hashed_companyId' => $data['hashedCompanyId'] ?? '',
        'userId' => $data['userId'] ?? '',
        'appId'     => $ghlApps[$usedAppType]['clientId'], // Store which app provided this token
        'client_id' => $ghlApps[$usedAppType]['clientId'], // Standard field name read by GhlClient::refreshToken()
        'appType'   => $usedAppType,
        'install_state' => INSTALL_STATE_PENDING_OAUTH,
        'install_status' => INSTALL_STATE_INSTALL_PENDING,
        'install_resolution_mode' => $finalResolutionMode,
        'install_resolution_source' => (string)($finalResolution['source'] ?? 'direct_location_callback'),
        'oauth_pending_started_at' => new \Google\Cloud\Core\Timestamp($now),
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

$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    error_log('[GHL_CALLBACK] JWT_SECRET missing; cannot generate install token.');
    render_error('Server configuration error: JWT secret missing.');
}
$reactAppUrl     = 'https://app.nolacrm.io';
$locationNameEnc = urlencode($locationName ?: 'Your Sub-Account');

    $companyName = $companyNameDirect;
    $companyId = (string)($data['companyId'] ?? '');
    if ($companyId !== '') {
        try {
            $coSnap = $db->collection('ghl_tokens')->document($companyId)->snapshot();
            if ($coSnap->exists()) {
                $coData = $coSnap->data();
                $companyName = (string)($coData['company_name'] ?? $coData['agency_name'] ?? $coData['location_name'] ?? '');
            }
        } catch (Exception $e) {
            error_log('[GHL_CALLBACK] company name lookup failed: ' . $e->getMessage());
        }
    }

$directDecision = install_decide_location_redirect(
    $db,
    $jwtSecret,
    (string)$locationId,
    (string)$locationName,
    $companyId,
    $companyName,
    (string)($finalResolution['source'] ?? 'direct_location_callback'),
    $tokenExistedBeforeDirect
);

if ($directDecision['kind'] === 'error' || empty($directDecision['url'])) {
    render_error('The selected sub-account could not be finalized. Please reinstall from the selected GHL sub-account.', [
        'locationId' => $locationId,
        'companyId' => $companyId,
        'classification' => $directDecision['classification'],
    ]);
}

try {
    install_finalize_location_install(
        $db,
        (string)$locationId,
        $directDecision,
        $finalResolutionMode,
        (string)($finalResolution['source'] ?? 'direct_location_callback'),
        $now
    );
} catch (Exception $e) {
    render_error('Callback authorized, but failed to finalize install: ' . $e->getMessage(), [
        'locationId' => $locationId,
        'resolution' => $finalResolution,
    ]);
}

$redirectUrl = $directDecision['url'];
error_log("[GHL_CALLBACK] Redirecting {$locationId} via {$directDecision['kind']}.");
error_log('[GHL_CALLBACK_DEBUG] final_redirect=' . json_encode([
    'locationId' => $locationId,
    'decision' => $directDecision['kind'],
    'status' => $directDecision['status'],
    'classification' => $directDecision['classification'],
    'redirectUrl' => $redirectUrl,
]));

header('Location: ' . $redirectUrl, true, 302);
exit;

