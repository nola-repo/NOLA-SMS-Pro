<?php
/**
 * oauth_debug.php — GHL OAuth Live-Exchange Diagnostic Tool
 *
 * Usage:
 *   https://smspro-api.nolacrm.io/oauth_debug.php?key=nola_debug_2026&code=PASTE_CODE
 *
 * IMPORTANT: Protected by a static key. Remove or disable this file once
 * the OAuth issue is resolved.
 */

// ── Auth guard ──────────────────────────────────────────────────────────────
define('DEBUG_KEY', 'nola_debug_2026');

if (($_GET['key'] ?? '') !== DEBUG_KEY) {
    http_response_code(403);
    die('Forbidden');
}

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

// ── Collect inputs ───────────────────────────────────────────────────────────
$code = trim($_GET['code'] ?? '');
$forceApp = $_GET['app'] ?? '';   // optional: ?app=subaccount | ?app=agency
$redirectUri = $_GET['redirect_uri'] ?? 'https://smspro-api.nolacrm.io/oauth/callback';

// ── Credential sets ──────────────────────────────────────────────────────────
$apps = [
    'subaccount' => [
        'label' => 'Sub-account App (6999da…mmn30t4f)',
        'client_id' => getenv('GHL_CLIENT_ID') ?: '6999da2b8f278296d95f7274-mmn30t4f',
        'client_secret' => getenv('GHL_CLIENT_SECRET') ?: 'd91017ad-f4eb-461f-8967-b1d51cd1c1eb',
        'user_type' => 'Location',
    ],
    'agency' => [
        'label' => 'Agency App (69d31f…mnqxvtt3)',
        'client_id' => getenv('GHL_AGENCY_CLIENT_ID') ?: '69d31f33b3071b25dbcc5656-mnqxvtt3',
        'client_secret' => getenv('GHL_AGENCY_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322',
        'user_type' => 'Company',
    ],
];

// ── Helper: attempt token exchange ───────────────────────────────────────────
function exchangeCode(array $app, string $code, string $redirectUri): array
{
    $ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'grant_type' => 'authorization_code',
            'code' => $code,
            'user_type' => $app['user_type'],
            'redirect_uri' => $redirectUri,
        ]),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'Version: 2021-07-28',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'curl_error' => $curlErr,
        'raw_body' => $body,
        'parsed' => json_decode($body, true),
    ];
}

// ── Helper: Firestore lookup ─────────────────────────────────────────────────
function firestoreTokenInfo(string $locationId): ?array
{
    try {
        require_once __DIR__ . '/api/webhook/firestore_client.php';
        $db = get_firestore();
        $doc = $db->collection('ghl_tokens')->document($locationId)->snapshot();
        if (!$doc->exists()) {
            return null;
        }
        $d = $doc->data();
        // Redact secrets for display
        $redact = ['access_token', 'refresh_token', 'client_secret'];
        foreach ($redact as $k) {
            if (isset($d[$k])) {
                $d[$k] = substr($d[$k], 0, 10) . '…[redacted]';
            }
        }
        return $d;
    } catch (\Exception $e) {
        return ['firestore_error' => $e->getMessage()];
    }
}

// ── Run exchanges ────────────────────────────────────────────────────────────
$results = [];
if ($code) {
    foreach ($apps as $key => $app) {
        if ($forceApp && $forceApp !== $key)
            continue;
        $results[$key] = ['app' => $app] + exchangeCode($app, $code, $redirectUri);
    }
}

// ── Derive location ID from first success ─────────────────────────────────────
$locationId = null;
$firestoreRow = null;
foreach ($results as $r) {
    $p = $r['parsed'] ?? [];
    $lid = $p['locationId'] ?? $p['location_id'] ?? null;
    if ($lid) {
        $locationId = $lid;
        $firestoreRow = firestoreTokenInfo($lid);
        break;
    }
}

// ── HTML Output ──────────────────────────────────────────────────────────────
function statusBadge(int $code): string
{
    $color = $code === 200 ? '#16a34a' : '#dc2626';
    return "<span style='background:{$color};color:#fff;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:700'>HTTP {$code}</span>";
}
function jsonBlock(mixed $data): string
{
    $j = is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return "<pre style='background:#0f172a;color:#94a3b8;padding:16px;border-radius:12px;overflow:auto;font-size:12px;line-height:1.6;max-height:360px;'>"
        . htmlspecialchars((string) $j) . "</pre>";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>OAuth Debug — NOLA SMS Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            padding: 32px 16px
        }

        .wrap {
            max-width: 860px;
            margin: 0 auto
        }

        h1 {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #f8fafc;
            margin-bottom: 4px
        }

        .badge-env {
            display: inline-block;
            background: #7c3aed;
            color: #fff;
            padding: 2px 10px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 10px
        }

        .subtitle {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 32px
        }

        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px
        }

        .card-title {
            font-size: 15px;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px
        }

        .form-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 8px
        }

        input[type=text] {
            flex: 1;
            min-width: 200px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 10px 14px;
            color: #f1f5f9;
            font-family: inherit;
            font-size: 13px;
            outline: none
        }

        input[type=text]:focus {
            border-color: #6366f1
        }

        select {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 10px 14px;
            color: #f1f5f9;
            font-size: 13px
        }

        button {
            background: #6366f1;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer
        }

        button:hover {
            background: #4f46e5
        }

        label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: block;
            margin-bottom: 6px
        }

        .result-block {
            margin-top: 20px
        }

        .result-title {
            font-size: 13px;
            font-weight: 700;
            color: #94a3b8;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .note {
            font-size: 12px;
            color: #475569;
            margin-top: 8px;
            line-height: 1.5
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px
        }

        @media(max-width:600px) {
            .grid {
                grid-template-columns: 1fr
            }
        }

        .kv {
            font-size: 12px;
            margin-bottom: 8px
        }

        .kv strong {
            color: #7dd3fc;
            display: block;
            margin-bottom: 2px
        }

        .kv span {
            color: #e2e8f0;
            word-break: break-all
        }

        .warn {
            background: #7c1d1d;
            border: 1px solid #ef4444;
            color: #fca5a5;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px
        }

        .ok {
            background: #14532d;
            border: 1px solid #22c55e;
            color: #86efac;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px
        }
    </style>
</head>

<body>
    <div class="wrap">
        <h1>🔬 GHL OAuth Debug Tool <span class="badge-env">DIAGNOSTIC</span></h1>
        <p class="subtitle">Live token exchange test — NOLA SMS Pro backend · <?= date('Y-m-d H:i:s T') ?></p>

        <!-- ── Input form ── -->
        <div class="card">
            <div class="card-title">Run Live Exchange</div>
            <form method="GET">
                <input type="hidden" name="key" value="<?= htmlspecialchars(DEBUG_KEY) ?>">
                <div style="margin-bottom:14px">
                    <label>Authorization Code (from GHL callback URL)</label>
                    <div class="form-row">
                        <input type="text" name="code" value="<?= htmlspecialchars($code) ?>"
                            placeholder="Paste code=XXXXX value here" required>
                    </div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">
                    <div>
                        <label>Redirect URI (must match install link)</label>
                        <input type="text" name="redirect_uri" value="<?= htmlspecialchars($redirectUri) ?>"
                            style="width:340px">
                    </div>
                    <div>
                        <label>Force App (optional)</label>
                        <select name="app">
                            <option value="" <?= !$forceApp ? 'selected' : '' ?>>Try Both (recommended)</option>
                            <option value="subaccount" <?= $forceApp === 'subaccount' ? 'selected' : '' ?>>Sub-account only
                            </option>
                            <option value="agency" <?= $forceApp === 'agency' ? 'selected' : '' ?>>Agency only</option>
                        </select>
                    </div>
                </div>
                <button type="submit">▶ Run Exchange</button>
            </form>
        </div>

        <?php if (!$code): ?>
            <div class="card">
                <div class="card-title">📋 How to get a code</div>
                <ol style="font-size:13px;color:#94a3b8;line-height:2;padding-left:20px">
                    <li>Open the <strong>Sub-account install link</strong> below in your browser:</li>
                </ol>
                <div style="margin:12px 0"><?php
                $installUrl = 'https://marketplace.leadconnectorhq.com/v2/oauth/chooselocation?response_type=code'
                    . '&redirect_uri=' . urlencode('https://smspro-api.nolacrm.io/oauth/callback')
                    . '&client_id=6999da2b8f278296d95f7274-mmn30t4f'
                    . '&scope=' . urlencode('workflows.readonly conversations/message.readonly conversations.readonly conversations.write contacts.readonly contacts.write conversations/message.write saas/location.read locations.readonly locations/tags.readonly locations/tags.write')
                    . '&version_id=6999da2b8f278296d95f7274';
                ?>
                    <a href="<?= $installUrl ?>" target="_blank"
                        style="color:#6366f1;font-size:13px;word-break:break-all"><?= $installUrl ?></a>
                </div>
                <ol start="2" style="font-size:13px;color:#94a3b8;line-height:2;padding-left:20px">
                    <li>GHL will redirect back to <code>https://smspro-api.nolacrm.io/oauth/callback?code=XXXXX…</code></li>
                    <li><strong>Before the page finishes loading</strong>, copy the <code>code=XXXXX</code> value from your
                        browser URL bar</li>
                    <li>Paste it into the field above and click <em>Run Exchange</em></li>
                </ol>
                <p class="note" style="margin-top:12px">⚠️ Codes are single-use and expire in ~10 minutes.</p>
            </div>
        <?php endif; ?>

        <?php if ($code && $results): ?>

            <!-- ── Credential env check ── -->
            <div class="card">
                <div class="card-title">🔐 Server-Side Credentials (env vars)</div>
                <div class="grid">
                    <?php foreach ($apps as $key => $app): ?>
                        <div>
                            <p style="font-size:13px;font-weight:700;color:#f1f5f9;margin-bottom:8px">
                                <?= htmlspecialchars($app['label']) ?></p>
                            <div class="kv"><strong>client_id</strong><span><?= htmlspecialchars($app['client_id']) ?></span>
                            </div>
                            <div class="kv"><strong>client_secret
                                    (tail)</strong><span>…<?= htmlspecialchars(substr($app['client_secret'], -8)) ?></span>
                            </div>
                            <div class="kv"><strong>user_type</strong><span><?= $app['user_type'] ?></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="note">redirect_uri sent: <strong><?= htmlspecialchars($redirectUri) ?></strong></p>
            </div>

            <!-- ── Exchange results ── -->
            <?php foreach ($results as $key => $r): ?>
                <div class="card">
                    <div class="card-title">
                        <?= $key === 'subaccount' ? '🏢' : '🏛️' ?>
                        <?= htmlspecialchars($r['app']['label']) ?>
                        <?= statusBadge((int) $r['http_code']) ?>
                    </div>

                    <?php if ($r['curl_error']): ?>
                        <div class="warn">⚠️ cURL Error: <?= htmlspecialchars($r['curl_error']) ?></div>
                    <?php elseif ($r['http_code'] === 200): ?>
                        <div class="ok">✅ Token exchange SUCCEEDED with this credential set.</div>
                    <?php else: ?>
                        <div class="warn">❌ GHL rejected this exchange (HTTP <?= $r['http_code'] ?>). See raw body below.</div>
                    <?php endif; ?>

                    <div class="result-block">
                        <div class="result-title">GHL Raw Response</div>
                        <?= jsonBlock($r['raw_body']) ?>
                    </div>

                    <?php if ($r['http_code'] === 200 && is_array($r['parsed'])): ?>
                        <div class="result-block">
                            <div class="result-title">✅ Parsed Token Fields</div>
                            <div class="grid">
                                <?php
                                $show = ['userType', 'locationId', 'location_id', 'companyId', 'hashedCompanyId', 'userId', 'scope', 'expires_in'];
                                foreach ($show as $f):
                                    if (!array_key_exists($f, $r['parsed']))
                                        continue;
                                    ?>
                                    <div class="kv">
                                        <strong><?= $f ?></strong><span><?= htmlspecialchars((string) $r['parsed'][$f]) ?></span></div>
                                <?php endforeach; ?>
                                <div class="kv"><strong>access_token
                                        (head)</strong><span><?= htmlspecialchars(substr($r['parsed']['access_token'] ?? '', 0, 30)) ?>…</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- ── Firestore state ── -->
            <?php if ($locationId): ?>
                <div class="card">
                    <div class="card-title">📦 Firestore — ghl_tokens/<?= htmlspecialchars($locationId) ?></div>
                    <?php if ($firestoreRow): ?>
                        <?= jsonBlock($firestoreRow) ?>

                        <?php
                        // Highlight key diagnostic fields
                        $clientIdStored = $firestoreRow['client_id'] ?? $firestoreRow['appId'] ?? '(missing!)';
                        $appTypeStored = $firestoreRow['appType'] ?? $firestoreRow['app_type'] ?? '(missing!)';
                        $userTypeStored = $firestoreRow['userType'] ?? '(missing!)';
                        ?>
                        <div class="grid" style="margin-top:16px">
                            <div class="kv"><strong>client_id in Firestore</strong>
                                <span style="color:<?= str_contains($clientIdStored, 'mmn30t4f') ? '#86efac' : '#fca5a5' ?>">
                                    <?= htmlspecialchars($clientIdStored) ?>
                                </span>
                            </div>
                            <div class="kv"><strong>appType</strong><span><?= htmlspecialchars($appTypeStored) ?></span></div>
                            <div class="kv"><strong>userType</strong><span><?= htmlspecialchars($userTypeStored) ?></span></div>
                        </div>
                        <p class="note">
                            ✅ <code>client_id</code> should end in <strong>mmn30t4f</strong> (Sub-account app) for location
                            tokens.<br>
                            ❌ If it ends in <strong>mnqxvtt3</strong> the token was saved by the Agency app — refresh will use wrong
                            credentials.
                        </p>
                    <?php else: ?>
                        <div class="warn">⚠️ No Firestore document found for location
                            <code><?= htmlspecialchars($locationId) ?></code> in <code>ghl_tokens</code>.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- ── Summary & next steps ── -->
            <div class="card">
                <div class="card-title">🧭 Diagnosis & Next Steps</div>
                <div style="font-size:13px;line-height:1.8;color:#94a3b8">
                    <?php
                    $subOk = ($results['subaccount']['http_code'] ?? 0) === 200;
                    $agOk = ($results['agency']['http_code'] ?? 0) === 200;
                    $subErr = $results['subaccount']['parsed']['error'] ?? $results['subaccount']['parsed']['message'] ?? '';
                    if ($subOk): ?>
                        <div class="ok">✅ Sub-account app exchange succeeded — GHL credentials are correct. Re-install should
                            now write a location token.</div>
                    <?php elseif ($agOk): ?>
                        <div class="warn">
                            ⚠️ Sub-account exchange FAILED but Agency succeeded.<br><br>
                            GHL rejected the Sub-account code with: <strong><?= htmlspecialchars($subErr) ?></strong><br><br>
                            <strong>Most likely cause:</strong> The install link used the Agency app client_id, so GHL issued an
                            Agency-scoped code — it cannot be exchanged against the Sub-account credentials.<br><br>
                            <strong>Fix:</strong> Verify that the install link in your GHL Marketplace Subaccount App uses
                            <code>client_id=6999da2b8f278296d95f7274-mmn30t4f</code> and redirect_uri is whitelisted there.
                        </div>
                    <?php elseif (!$subOk && !$agOk): ?>
                        <div class="warn">
                            ❌ Both exchanges failed. GHL error for Sub-account:
                            <strong><?= htmlspecialchars($subErr) ?></strong><br><br>
                            Common causes:<br>
                            • Code already used (single-use) — generate a fresh one.<br>
                            • redirect_uri mismatch — GHL requires it to exactly match the Marketplace setting.<br>
                            • Wrong scopes on install link — confirm the Subaccount App has all required scopes approved.
                        </div>
                    <?php endif; ?>
                    <p style="margin-top:8px">
                        Redirect URI used in this test: <code><?= htmlspecialchars($redirectUri) ?></code><br>
                        Ensure this is listed verbatim under <em>Redirect URIs</em> in your
                        <a href="https://marketplace.leadconnectorhq.com/" target="_blank" style="color:#6366f1">GHL
                            Developer Portal</a>
                        for App ID <code>6999da2b8f278296d95f7274-mmn30t4f</code>.
                    </p>
                </div>
            </div>

        <?php endif; ?>

        <p style="font-size:11px;color:#334155;text-align:center;margin-top:24px">
            🔒 Protected endpoint — remove <code>oauth_debug.php</code> once the issue is resolved.
        </p>
    </div>
</body>

</html>