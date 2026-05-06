<?php
/**
 * install-register.php (mapped to /register)
 * Served at: https://smspro-api.nolacrm.io/register
 */

require_once __DIR__ . '/api/jwt_helper.php';

$jwtSecret   = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
$apiBase     = 'https://smspro-api.nolacrm.io';
$reactApp    = 'https://app.nolasmspro.com';
$marketplace = 'https://marketplace.leadconnectorhq.com/apps/overview/68118e8f9f1bac2ffc84ed23';

/**
 * Render debug install-token banner only outside production.
 */
function ir_is_non_production(): bool {
    $appEnv = strtolower((string) (getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: ''));
    if ($appEnv !== '') {
        return !in_array($appEnv, ['prod', 'production'], true);
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return false;
    }

    return str_contains($host, 'localhost')
        || str_contains($host, '127.0.0.1')
        || str_contains($host, 'staging')
        || str_contains($host, 'dev')
        || str_contains($host, 'test');
}

// ── Verify install_token ──────────────────────────────────────────────────────
$installToken = trim($_GET['install_token'] ?? $_POST['install_token'] ?? '');

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
        html { font-family: 'Poppins', system-ui, sans-serif; background: #f7f8fc; color: #111; -webkit-font-smoothing: antialiased; }
        body { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 20px; overflow-x: hidden; }
        .blob { position: fixed; border-radius: 50%; filter: blur(120px); opacity: 0.12; pointer-events: none; z-index: 0; }
        .blob-tl { top: -15%; left: -10%; width: 55vw; height: 55vw; background: #2b83fa; }
        .blob-br { bottom: -15%; right: -10%; width: 50vw; height: 50vw; background: #7c3aed; }
        .card {
            max-width: 480px; width: 100%;
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border-radius: 32px; padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08), inset 0 0 0 1px rgba(255,255,255,0.5);
            border: 1px solid rgba(43,131,250,0.1);
            position: relative; z-index: 10;
            overflow: hidden;
        }
        .logo-wrap { display: flex; flex-direction: column; align-items: center; margin-bottom: 32px; }
        .logo-img { height: 60px; object-fit: contain; margin-bottom: 12px; }
        .badge { font-size: 11px; font-weight: 700; color: #2b83fa; text-transform: uppercase; letter-spacing: 1px; background: rgba(43,131,250,0.1); padding: 4px 12px; border-radius: 99px; }
        
        /* Step Indicator */
        .steps { display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 32px; }
        .step { display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 56px; }
        .step-circle { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; border: 2px solid #d1d5db; color: #9aa0a6; transition: all 0.3s; background: transparent; }
        .step.active .step-circle { background: #fff; border-color: #2b83fa; color: #2b83fa; }
        .step.done .step-circle { background: #2b83fa; border-color: #2b83fa; color: #fff; }
        .step-label { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #9aa0a6; text-align: center; }
        .step.active .step-label { color: #2b83fa; }
        .step-line { width: 48px; height: 2px; background: #e5e7eb; margin-top: -12px; transition: all 0.5s; border-radius: 2px; }
        .step-line.done { background: #2b83fa; }
        
        h1 { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; color: #111; margin-bottom: 4px; }
        p.subtitle { font-size: 13px; color: #6e6e73; margin-bottom: 24px; }
        
        /* Form fields */
        .field { margin-bottom: 20px; text-align: left; position: relative; }
        label { display: block; font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #6e6e73; letter-spacing: 1px; margin-bottom: 6px; }
        .input-wrap { position: relative; }
        .input-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: #9aa0a6; pointer-events: none; }
        input[type=text], input[type=email], input[type=password], input[type=tel] {
            width: 100%; padding: 12px 16px 12px 40px; border-radius: 12px;
            border: 1px solid rgba(0,0,0,0.08); background: #f7f7f7;
            font-family: inherit; font-size: 13.5px; outline: none; transition: all 0.2s; color: #111;
        }
        input:focus { border-color: #2b83fa; background: #fff; box-shadow: 0 0 0 3px rgba(43,131,250,0.15); }
        .pw-toggle { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #9aa0a6; }
        .pw-toggle:hover { color: #6e6e73; }
        .error-msg { font-size: 11.5px; color: #ef4444; font-weight: 600; margin-top: 4px; display: none; align-items: center; gap: 4px; }
        
        /* Strength meter */
        .strength-wrap { display: flex; gap: 6px; margin-bottom: 6px; margin-top: -10px; }
        .str-bar { height: 4px; flex: 1; border-radius: 2px; background: #e5e7eb; transition: all 0.3s; }
        .str-text { font-size: 11px; color: #9aa0a6; font-weight: 500; margin-bottom: 16px; }
        .str-1 { background: #ef4444; } .str-2 { background: #f59e0b; } .str-3 { background: #60a5fa; } .str-4 { background: #10b981; }
        
        /* Buttons */
        .btn-submit {
            width: 100%; padding: 14px; border-radius: 14px;
            background: #2b83fa; color: #fff; font-size: 14px; font-weight: 700;
            border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 6px 16px rgba(43,131,250,0.3); transition: all 0.2s;
        }
        .btn-submit:hover { box-shadow: 0 10px 24px rgba(43,131,250,0.4); transform: translateY(-1px); }
        .btn-submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-back { width: auto; padding: 14px 24px; border-radius: 14px; background: #fff; border: 1px solid #e5e7eb; color: #6e6e73; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-back:hover { background: #f7f7f7; }
        
        /* Step containers */
        .step-content { display: none; animation: fade-in 0.3s ease forwards; }
        .step-content.active { display: block; }
        @keyframes fade-in { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes fade-out { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(-20px); } }
        
        /* Review table */
        .review-box { background: #f7f7f7; border: 1px solid rgba(0,0,0,0.08); border-radius: 16px; padding: 20px; margin-bottom: 20px; }
        .review-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 13px; }
        .review-row:last-child { margin-bottom: 0; }
        .review-label { color: #6e6e73; font-weight: 600; }
        .review-val { color: #111; font-weight: 700; text-align: right; word-break: break-all; max-width: 60%; }
        .review-val.hl { color: #2b83fa; }
        
        .checkbox-wrap { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; margin-bottom: 24px; }
        .checkbox { width: 20px; height: 20px; border-radius: 6px; border: 2px solid #d1d5db; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: 0.2s; background: #fff; }
        .checkbox.checked { background: #2b83fa; border-color: #2b83fa; }
        .check-text { font-size: 12.5px; color: #6e6e73; line-height: 1.5; }
        .check-text a { color: #2b83fa; font-weight: 700; text-decoration: none; }
        
        .api-error { display: none; background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px; border-radius: 12px; font-size: 12.5px; font-weight: 600; margin-bottom: 20px; align-items: flex-start; gap: 8px; }
        
        /* Success */
        .success-circle { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #34d399, #059669); margin: 0 auto 24px; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 30px rgba(16,185,129,0.3); animation: scale-in 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) both; }
        @keyframes scale-in { 0% { transform: scale(0); } 100% { transform: scale(1); } }
        
        /* Non-production token debug */
        .debug-banner {
            margin-bottom: 18px;
            border-radius: 12px;
            border: 1px solid #fcd34d;
            background: #fffbeb;
            color: #92400e;
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
            line-height: 1.45;
        }
        .debug-banner strong { font-size: 10px; letter-spacing: 0.05em; text-transform: uppercase; display: inline-block; margin-bottom: 4px; }
        .debug-banner code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 10px; }
    </style>
</head>
<body>
    <div class="blob blob-tl"></div>
    <div class="blob blob-br"></div>
    <div class="card">
        <div class="logo-wrap">
            <img src="https://app.nolasmspro.com/assets/NOLA%20SMS%20PRO%20Logo.png" alt="NOLA SMS Pro" class="logo-img" onerror="this.style.display='none'">
            <span class="badge">Setup Sub-account</span>
        </div>
        {$body}
    </div>
</body>
</html>
HTML;
    exit;
}

if (!$installToken) {
    ir_page('Invalid Link', <<<HTML
        <div style="text-align: center;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <h1>Invalid Link</h1>
            <p class="subtitle">No installation token was provided.</p>
            <a href="{$marketplace}" class="btn-submit" style="display:inline-flex; width:auto; padding:12px 24px; text-decoration:none;">Go to GHL Marketplace</a>
        </div>
HTML);
}

$payload = jwt_verify($installToken, $jwtSecret);

if (!$payload) {
    ir_page('Link Expired', <<<HTML
        <div style="text-align: center;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 16px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <h1>Link Expired</h1>
            <p class="subtitle">This link is valid for 15 minutes and has expired.<br>Please reinstall the app to get a fresh link.</p>
            <a href="{$marketplace}" class="btn-submit" style="display:inline-flex; width:auto; padding:12px 24px; text-decoration:none;">Reinstall from Marketplace</a>
        </div>
HTML);
}

$tokenType    = $payload['type'] ?? '';
$locationId   = $payload['location_id'] ?? null;
$locationName = htmlspecialchars($payload['location_name'] ?? '', ENT_QUOTES, 'UTF-8');
$companyId    = $payload['company_id'] ?? null;

if ($tokenType !== 'install' && $tokenType !== 'agency_install') {
    ir_page('Invalid Token', '<div style="text-align:center;"><h1>Invalid Token</h1><p class="subtitle">Unexpected token type. Please reinstall.</p></div>');
}

$locDisplay = $locationName ?: $locationId ?: '—';
$locationIdSafe = htmlspecialchars((string) ($locationId ?? ''), ENT_QUOTES, 'UTF-8');
$companyIdSafe = htmlspecialchars((string) ($companyId ?? ''), ENT_QUOTES, 'UTF-8');
$tokenTypeSafe = htmlspecialchars((string) $tokenType, ENT_QUOTES, 'UTF-8');
$showDebugBanner = ir_is_non_production();
$debugBannerHtml = '';
if ($showDebugBanner) {
    $tokenExp = isset($payload['exp']) ? (int) $payload['exp'] : null;
    $ttlSeconds = $tokenExp ? ($tokenExp - time()) : null;
    $isExpired = $ttlSeconds !== null ? ($ttlSeconds <= 0) : null;
    $ttlLabel = 'unknown';
    if ($ttlSeconds !== null) {
        $abs = abs($ttlSeconds);
        $mins = intdiv($abs, 60);
        $secs = $abs % 60;
        $ttlLabel = sprintf('%s%02dm %02ds', $ttlSeconds < 0 ? '-' : '', $mins, $secs);
    }
    $expLabel = $tokenExp ? gmdate('Y-m-d H:i:s', $tokenExp) . ' UTC' : 'unknown';
    $statusLabel = $isExpired === null ? 'unknown' : ($isExpired ? 'expired' : 'valid');
    $expLabelSafe = htmlspecialchars($expLabel, ENT_QUOTES, 'UTF-8');
    $ttlLabelSafe = htmlspecialchars($ttlLabel, ENT_QUOTES, 'UTF-8');

    $debugBannerHtml = <<<HTML
    <div class="debug-banner">
        <strong>Debug Install Token (non-production)</strong><br>
        <code>type={$tokenTypeSafe}</code> |
        <code>location_id={$locationIdSafe}</code> |
        <code>location_name={$locationName}</code> |
        <code>company_id={$companyIdSafe}</code><br>
        <code>status={$statusLabel}</code> |
        <code>ttl={$ttlLabelSafe}</code> |
        <code>exp={$expLabelSafe}</code>
    </div>
HTML;
}

// Form UI with JS
ir_page('Create Your Account', <<<HTML
    {$debugBannerHtml}
    <div class="steps">
        <div class="step active" id="s-ind-1">
            <div class="step-circle">1</div>
            <div class="step-label">Details</div>
        </div>
        <div class="step-line" id="l-ind-1"></div>
        <div class="step" id="s-ind-2">
            <div class="step-circle">2</div>
            <div class="step-label">Review</div>
        </div>
        <div class="step-line" id="l-ind-2"></div>
        <div class="step" id="s-ind-3">
            <div class="step-circle">3</div>
            <div class="step-label">Done</div>
        </div>
    </div>

    <!-- STEP 1: Details -->
    <div id="step-1" class="step-content active">
        <h1>Your Information</h1>
        <p class="subtitle">Setting up for <strong style="color:#2b83fa;">{$locDisplay}</strong></p>
        
        <div class="field">
            <label>Full Name</label>
            <div class="input-wrap">
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <input type="text" id="fullName" placeholder="John Doe">
            </div>
            <div class="error-msg" id="err-fullName"></div>
        </div>

        <div class="field">
            <label>Email Address</label>
            <div class="input-wrap">
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                <input type="email" id="email" placeholder="you@company.com">
            </div>
            <div class="error-msg" id="err-email"></div>
        </div>

        <div class="field">
            <label>Phone Number</label>
            <div class="input-wrap">
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                <input type="tel" id="phone" placeholder="+1 555 000 0000">
            </div>
            <div class="error-msg" id="err-phone"></div>
        </div>

        <div class="field">
            <label>Password</label>
            <div class="input-wrap">
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                <input type="password" id="password" placeholder="At least 8 characters">
                <button type="button" class="pw-toggle" onclick="togglePw('password', this)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <div class="error-msg" id="err-password"></div>
        </div>
        
        <div class="strength-wrap" id="str-wrap" style="display:none;">
            <div class="str-bar" id="str-b1"></div><div class="str-bar" id="str-b2"></div><div class="str-bar" id="str-b3"></div><div class="str-bar" id="str-b4"></div>
        </div>
        <div class="str-text" id="str-text" style="display:none;">Password strength: <strong>Weak</strong></div>

        <div class="field">
            <label>Confirm Password</label>
            <div class="input-wrap">
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                <input type="password" id="confirm" placeholder="Re-enter password">
                <button type="button" class="pw-toggle" onclick="togglePw('confirm', this)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <div class="error-msg" id="err-confirm"></div>
        </div>

        <button class="btn-submit" onclick="goToStep2()">Review <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></button>
    </div>

    <!-- STEP 2: Review -->
    <div id="step-2" class="step-content">
        <h1>Review Details</h1>
        <p class="subtitle">Please confirm your information.</p>
        
        <div class="review-box">
            <div class="review-row"><span class="review-label">Name</span><span class="review-val" id="rev-name"></span></div>
            <div class="review-row"><span class="review-label">Email</span><span class="review-val" id="rev-email"></span></div>
            <div class="review-row"><span class="review-label">Phone</span><span class="review-val" id="rev-phone"></span></div>
            <div class="review-row"><span class="review-label">Subaccount</span><span class="review-val hl">{$locDisplay}</span></div>
            <div class="review-row"><span class="review-label">Location ID</span><span class="review-val hl">{$locationIdSafe}</span></div>
        </div>
        
        <div class="checkbox-wrap" onclick="toggleAgree()">
            <div class="checkbox" id="agree-box">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="display:none;" id="agree-chk"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <div class="check-text">I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>.</div>
        </div>
        
        <div class="api-error" id="api-error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <span id="api-error-text"></span>
        </div>

        <div style="display: flex; gap: 12px;">
            <button class="btn-back" onclick="goToStep1()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Edit</button>
            <button class="btn-submit" id="submit-btn" style="flex:1;" disabled onclick="submitForm()">Create Account <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></button>
        </div>
    </div>

    <!-- STEP 3: Done -->
    <div id="step-3" class="step-content" style="text-align: center; padding: 20px 0;">
        <div class="success-circle">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <h1>Account Created!</h1>
        <p class="subtitle" style="max-width: 280px; margin: 8px auto 24px;">Welcome to NOLA SMS Pro. Your account is ready for <strong>{$locDisplay}</strong>.</p>
        <button class="btn-submit" id="dash-btn" onclick="goDashboard()" disabled>Redirecting...</button>
    </div>

    <script>
        const installToken = "{$installToken}";
        const API_BASE = "{$apiBase}";
        const REACT_APP = "{$reactApp}";
        let agreed = false;
        let successData = null;

        function id(el) { return document.getElementById(el); }
        
        function togglePw(inputId, btn) {
            const el = id(inputId);
            if (el.type === 'password') {
                el.type = 'text';
                btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
            } else {
                el.type = 'password';
                btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
            }
        }

        // Strength meter
        id('password').addEventListener('input', function(e) {
            const p = e.target.value;
            id('str-wrap').style.display = p.length > 0 ? 'flex' : 'none';
            id('str-text').style.display = p.length > 0 ? 'block' : 'none';
            
            let score = 0;
            if (p.length >= 8) score++;
            if (/[A-Z]/.test(p)) score++;
            if (/[0-9]/.test(p)) score++;
            if (/[^A-Za-z0-9]/.test(p)) score++;
            
            const bars = [id('str-b1'), id('str-b2'), id('str-b3'), id('str-b4')];
            bars.forEach(b => { b.className = 'str-bar'; });
            
            let label = 'Weak', colClass = 'str-1';
            if (score === 2) { label = 'Fair'; colClass = 'str-2'; }
            if (score === 3) { label = 'Good'; colClass = 'str-3'; }
            if (score === 4) { label = 'Strong'; colClass = 'str-4'; }
            if (score === 0 && p.length > 0) score = 1;
            
            for(let i=0; i<score; i++) { bars[i].classList.add(colClass); }
            const colColors = ['', '#ef4444', '#f59e0b', '#60a5fa', '#10b981'];
            id('str-text').innerHTML = `Password strength: <strong style="color:\${colColors[score]};">\${label}</strong>`;
        });

        function clearErrors() {
            document.querySelectorAll('.error-msg').forEach(el => el.style.display = 'none');
        }

        function showError(f, msg) {
            const el = id('err-' + f);
            el.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg> \${msg}`;
            el.style.display = 'flex';
        }

        function goToStep2() {
            clearErrors();
            let ok = true;
            const fn = id('fullName').value.trim();
            const em = id('email').value.trim();
            const ph = id('phone').value.trim();
            const pw = id('password').value;
            const cp = id('confirm').value;

            if (!fn) { showError('fullName', 'Required'); ok = false; }
            if (!em) { showError('email', 'Required'); ok = false; }
            else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) { showError('email', 'Invalid email'); ok = false; }
            if (!ph) { showError('phone', 'Required'); ok = false; }
            if (!pw) { showError('password', 'Required'); ok = false; }
            else if (pw.length < 8) { showError('password', 'Min 8 chars'); ok = false; }
            if (!cp) { showError('confirm', 'Required'); ok = false; }
            else if (pw !== cp) { showError('confirm', 'Must match'); ok = false; }

            if (ok) {
                id('rev-name').textContent = fn;
                id('rev-email').textContent = em;
                id('rev-phone').textContent = ph;
                
                id('s-ind-1').classList.add('done');
                id('s-ind-1').classList.remove('active');
                id('l-ind-1').classList.add('done');
                id('s-ind-2').classList.add('active');
                
                id('step-1').classList.remove('active');
                id('step-2').classList.add('active');
            }
        }

        function goToStep1() {
            id('s-ind-2').classList.remove('active');
            id('l-ind-1').classList.remove('done');
            id('s-ind-1').classList.add('active');
            id('s-ind-1').classList.remove('done');
            
            id('step-2').classList.remove('active');
            id('step-1').classList.add('active');
        }

        function toggleAgree() {
            agreed = !agreed;
            id('agree-box').className = agreed ? 'checkbox checked' : 'checkbox';
            id('agree-chk').style.display = agreed ? 'block' : 'none';
            id('submit-btn').disabled = !agreed;
        }

        async function submitForm() {
            if (!agreed) return;
            const btn = id('submit-btn');
            btn.disabled = true;
            btn.innerHTML = `<svg class="animate-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg> Creating...`;
            id('api-error').style.display = 'none';

            try {
                const res = await fetch(API_BASE + '/api/auth/register-from-install', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        full_name: id('fullName').value.trim(),
                        email: id('email').value.trim(),
                        phone: id('phone').value.trim(),
                        password: id('password').value,
                        install_token: installToken
                    })
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Registration failed');
                
                successData = data;
                
                // Advance to step 3
                id('s-ind-2').classList.add('done');
                id('s-ind-2').classList.remove('active');
                id('l-ind-2').classList.add('done');
                id('s-ind-3').classList.add('active');
                
                id('step-2').classList.remove('active');
                id('step-3').classList.add('active');
                
                setTimeout(goDashboard, 3000);
            } catch (err) {
                id('api-error-text').textContent = err.message;
                id('api-error').style.display = 'flex';
                btn.disabled = false;
                btn.innerHTML = `Create Account <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>`;
            }
        }

        function goDashboard() {
            if (!successData) return;
            // Build auth-handoff URL
            const u = btoa(JSON.stringify(successData.user || {}));
            window.location.href = API_BASE + '/auth-handoff.html?token=' + encodeURIComponent(successData.token) + '&user=' + encodeURIComponent(u) + '&redirect=' + encodeURIComponent(REACT_APP);
        }
    </script>
HTML);
