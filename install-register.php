<?php
/**
 * install-register.php
 * Served at: https://smspro-api.nolacrm.io/install-register.php
 *
 * GET  ?install_token=<JWT>  → shows styled registration form
 * POST (form submit)         → calls register-from-install API, redirects to auth-handoff.html
 *
 * Drop this file in the repo root alongside ghl_callback.php.
 */

require_once __DIR__ . '/api/jwt_helper.php';

$jwtSecret   = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
$apiBase     = 'https://smspro-api.nolacrm.io';
$reactApp    = 'https://app.nolacrm.io';
$marketplace = 'https://marketplace.leadconnectorhq.com/apps/overview/68118e8f9f1bac2ffc84ed23';

// ── Shared page renderer (matches ghl_callback.php design) ───────────────────
function ir_page(string $title, string $body): void {
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
        html { font-family: 'Poppins', system-ui, sans-serif; background: #f9fafb; color: #1a1a1a; -webkit-font-smoothing: antialiased; }
        body { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 20px; background: #f9fafb; }
        .blob { position: fixed; border-radius: 50%; background: #2b83fa; filter: blur(120px); opacity: 0.15; pointer-events: none; z-index: 0; }
        .blob-tl { top: -10%; left: -10%; width: 50vw; height: 50vw; }
        .blob-br { bottom: -10%; right: -10%; width: 50vw; height: 50vw; }
        .card {
            max-width: 460px; width: 100%;
            background: rgba(255,255,255,0.82);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-radius: 32px; padding: 40px 36px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.07), inset 0 0 0 1px rgba(255,255,255,0.5);
            border: 1px solid rgba(43,131,250,0.1);
            animation: card-in 0.6s cubic-bezier(0.16,1,0.3,1) both;
            z-index: 10; text-align: left;
        }
        @keyframes card-in { from { opacity:0; transform:translateY(32px); } to { opacity:1; transform:translateY(0); } }
        .logo { display: flex; align-items: center; gap: 10px; margin-bottom: 28px; }
        .logo-icon { width: 40px; height: 40px; background: #2b83fa; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .logo-text { font-size: 17px; font-weight: 800; color: #111; letter-spacing: -0.4px; }
        h1 { font-size: 26px; font-weight: 800; letter-spacing: -1px; color: #111; margin-bottom: 4px; }
        .subtitle { font-size: 14px; color: #6e6e73; margin-bottom: 28px; font-weight: 500; }
        .location-badge {
            display: inline-flex; align-items: center; gap: 7px;
            background: #f0f7ff; border: 1px solid #c7deff;
            border-radius: 99px; padding: 6px 14px;
            font-size: 12px; font-weight: 700; color: #2b83fa;
            margin-bottom: 28px;
        }
        label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #9aa0a6; margin-bottom: 7px; letter-spacing: 0.05em; }
        .field { margin-bottom: 18px; }
        input[type=text], input[type=email], input[type=password], input[type=tel] {
            width: 100%; padding: 13px 16px; border-radius: 14px;
            border: 1px solid #e0e0e0; background: #fafafa;
            font-family: inherit; font-size: 14px; outline: none; transition: all 0.2s;
            color: #111;
        }
        input:focus { border-color: #2b83fa; background: #fff; box-shadow: 0 0 0 4px rgba(43,131,250,0.1); }
        .pw-wrap { position: relative; }
        .pw-toggle { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #9aa0a6; padding: 4px; }
        .pw-toggle:hover { color: #2b83fa; }
        .btn-submit {
            width: 100%; padding: 15px; border-radius: 16px;
            background: #2b83fa; color: #fff; font-size: 15px; font-weight: 700;
            border: none; cursor: pointer; margin-top: 6px;
            box-shadow: 0 6px 16px rgba(43,131,250,0.3);
            transition: all 0.2s; font-family: inherit;
        }
        .btn-submit:hover { background: #1d6bd4; transform: translateY(-2px); box-shadow: 0 10px 24px rgba(43,131,250,0.4); }
        .btn-submit:active { transform: scale(0.98); }
        .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
        .error-box {
            background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px;
            padding: 12px 16px; margin-bottom: 20px;
            font-size: 13px; color: #dc2626; font-weight: 600;
        }
        .help-text { font-size: 11px; color: #9aa0a6; margin-top: 5px; }
        .divider { height: 1px; background: #f0f0f0; margin: 24px 0; }
        .footer { font-size: 11px; color: #b0b0b0; text-align: center; margin-top: 20px; }
        .info-icon { width:16px; height:16px; }
        /* Success state */
        .success-wrap { text-align: center; }
        .success-ring { position: relative; display: inline-flex; margin: 0 auto 24px; }
        .success-ring::before { content:''; position:absolute; inset:-10px; border-radius:50%; border:3px solid #2b83fa; opacity:0.4; animation:pulse-ring 2.5s cubic-bezier(0.4,0,0.6,1) infinite; }
        @keyframes pulse-ring { 0%{transform:scale(0.95);opacity:0.6;} 70%{transform:scale(1.35);opacity:0;} 100%{transform:scale(1.35);opacity:0;} }
        .success-icon { width:72px; height:72px; border-radius:50%; background:#2b83fa; display:flex; align-items:center; justify-content:center; z-index:10; position:relative; box-shadow:0 10px 24px rgba(43,131,250,0.4); }
    </style>
</head>
<body>
    <div class="blob blob-tl"></div>
    <div class="blob blob-br"></div>
    <div class="card">
        <div class="logo">
            <div class="logo-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </div>
            <span class="logo-text">NOLA SMS Pro</span>
        </div>
        {$body}
    </div>
    <script>
      document.getElementById('toggle-pw') && document.getElementById('toggle-pw').addEventListener('click', function() {
        var inp = document.getElementById('password');
        inp.type = inp.type === 'password' ? 'text' : 'password';
      });
      document.getElementById('reg-form') && document.getElementById('reg-form').addEventListener('submit', function() {
        var btn = document.getElementById('submit-btn');
        btn.disabled = true;
        btn.textContent = 'Creating your account…';
      });
    </script>
</body>
</html>
HTML;
    exit;
}

// ── Verify install_token ──────────────────────────────────────────────────────
$installToken = trim($_GET['install_token'] ?? $_POST['install_token'] ?? '');

if (!$installToken) {
    ir_page('Invalid Link', <<<HTML
        <h1>Invalid Link</h1>
        <p class="subtitle">No installation token was provided.</p>
        <a href="{$marketplace}" style="display:inline-block;margin-top:8px;padding:13px 28px;background:#2b83fa;color:#fff;border-radius:14px;font-weight:700;text-decoration:none;font-size:14px;">
            Back to Marketplace
        </a>
HTML);
}

$payload = jwt_verify($installToken, $jwtSecret);

if (!$payload) {
    ir_page('Link Expired', <<<HTML
        <h1>Installation Link Expired</h1>
        <p class="subtitle" style="margin-bottom:20px;">
            This link is valid for 15 minutes and has expired.<br>Please reinstall the app to get a fresh link.
        </p>
        <a href="{$marketplace}" style="display:inline-block;padding:13px 28px;background:#2b83fa;color:#fff;border-radius:14px;font-weight:700;text-decoration:none;font-size:14px;">
            Reinstall from Marketplace
        </a>
HTML);
}

$tokenType    = $payload['type'] ?? '';
$locationId   = $payload['location_id'] ?? null;
$locationName = htmlspecialchars($payload['location_name'] ?? '', ENT_QUOTES, 'UTF-8');
$companyId    = $payload['company_id'] ?? null;

if ($tokenType !== 'install' && $tokenType !== 'agency_install') {
    ir_page('Invalid Token', '<h1>Invalid Token</h1><p class="subtitle">Unexpected token type. Please reinstall.</p>');
}

// ── Handle POST (form submission) ─────────────────────────────────────────────
$formError = null;
$fv = ['full_name' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fv['full_name'] = htmlspecialchars(trim($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $fv['email']     = htmlspecialchars(strtolower(trim($_POST['email'] ?? '')), ENT_QUOTES, 'UTF-8');
    $fv['phone']     = htmlspecialchars(preg_replace('/\s+/', '', trim($_POST['phone'] ?? '')), ENT_QUOTES, 'UTF-8');
    $password        = $_POST['password'] ?? '';

    // Call the register API
    $ch = curl_init($apiBase . '/api/auth/register-from-install');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'full_name'     => trim($_POST['full_name'] ?? ''),
            'email'         => strtolower(trim($_POST['email'] ?? '')),
            'password'      => $password,
            'phone'         => preg_replace('/\s+/', '', trim($_POST['phone'] ?? '')),
            'install_token' => $installToken,
        ]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($resp, true);

    if ($code === 200 || $code === 201) {
        $token    = $result['token'] ?? '';
        $userJson = base64_encode(json_encode($result['user'] ?? []));
        $dest     = $apiBase . '/auth-handoff.html'
            . '?token='    . urlencode($token)
            . '&user='     . urlencode($userJson)
            . '&redirect=' . urlencode($reactApp);
        header('Location: ' . $dest, true, 302);
        exit;
    } else {
        $formError = htmlspecialchars($result['error'] ?? 'Registration failed. Please try again.', ENT_QUOTES, 'UTF-8');
    }
}

// ── Render form ───────────────────────────────────────────────────────────────
$tokenSafe  = htmlspecialchars($installToken, ENT_QUOTES, 'UTF-8');
$errorHtml  = $formError
    ? "<div class=\"error-box\">{$formError}</div>"
    : '';

$locationBadge = $locationName
    ? <<<HTML
    <div class="location-badge">
        <svg class="info-icon" viewBox="0 0 24 24" fill="none" stroke="#2b83fa" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
        Installing for: <strong>{$locationName}</strong>
    </div>
HTML
    : '';

ir_page('Create Your Account', <<<HTML
    <h1>Create Your Account</h1>
    <p class="subtitle">Set up your NOLA SMS Pro account to get started.</p>
    {$locationBadge}
    {$errorHtml}
    <form id="reg-form" method="POST" action="/install-register.php?install_token={$tokenSafe}">
        <input type="hidden" name="install_token" value="{$tokenSafe}">

        <div class="field">
            <label for="full_name">Full Name</label>
            <input id="full_name" name="full_name" type="text" required
                placeholder="Jane Smith" value="{$fv['full_name']}" autocomplete="name">
        </div>
        <div class="field">
            <label for="email">Email Address</label>
            <input id="email" name="email" type="email" required
                placeholder="jane@company.com" value="{$fv['email']}" autocomplete="email">
        </div>
        <div class="field">
            <label for="phone">Phone Number</label>
            <input id="phone" name="phone" type="tel" required
                placeholder="+1 555 000 0000" value="{$fv['phone']}" autocomplete="tel">
            <p class="help-text">Include country code, e.g. +1 for US/Canada</p>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="pw-wrap">
                <input id="password" name="password" type="password" required
                    placeholder="Min. 8 characters" minlength="8" autocomplete="new-password">
                <button type="button" id="toggle-pw" class="pw-toggle" aria-label="Toggle password visibility">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
        </div>
        <button id="submit-btn" type="submit" class="btn-submit">Create Account</button>
    </form>
    <p class="footer" style="margin-top:16px;">Already have an account? <a href="/install-login.php" style="color:#2b83fa;font-weight:600;">Sign in</a></p>
HTML);
