<?php
/**
 * install-login.php
 * Served at: https://smspro-api.nolacrm.io/install-login.php
 *
 * GET  ?welcome_back=1&name=<loc>  → shows login form with welcome-back banner
 * GET  ?bulk_install=1&count=N     → shows login form with bulk banner
 * POST (form submit)               → calls /api/auth/login, redirects to auth-handoff.html
 *
 * Drop this file in the repo root alongside ghl_callback.php.
 */

require_once __DIR__ . '/api/jwt_helper.php';

$apiBase  = 'https://smspro-api.nolacrm.io';
$reactApp = 'https://app.nolacrm.io';

// ── Shared page renderer (matches install-register.php / ghl_callback.php) ───
function il_page(string $title, string $body): void {
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
        /* Banners */
        .banner-blue {
            background: #eff6ff; border: 1px solid #bfdbfe;
            border-radius: 14px; padding: 14px 16px; margin-bottom: 22px;
        }
        .banner-blue p { font-size: 13px; color: #1e40af; line-height: 1.5; }
        .banner-blue strong { font-weight: 700; }
        .banner-amber {
            background: #fffbeb; border: 1px solid #fde68a;
            border-radius: 14px; padding: 14px 16px; margin-bottom: 22px;
        }
        .banner-amber p { font-size: 13px; color: #92400e; line-height: 1.5; }
        .banner-amber strong { font-weight: 700; }
        /* Form */
        label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #9aa0a6; margin-bottom: 7px; letter-spacing: 0.05em; }
        .field { margin-bottom: 18px; }
        input[type=email], input[type=password], input[type=text] {
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
        .footer { font-size: 11px; color: #b0b0b0; text-align: center; margin-top: 20px; }
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
      var pwToggle = document.getElementById('toggle-pw');
      if (pwToggle) pwToggle.addEventListener('click', function() {
        var inp = document.getElementById('password');
        inp.type = inp.type === 'password' ? 'text' : 'password';
      });
      var form = document.getElementById('login-form');
      if (form) form.addEventListener('submit', function() {
        var btn = document.getElementById('submit-btn');
        btn.disabled = true;
        btn.textContent = 'Signing in…';
      });
    </script>
</body>
</html>
HTML;
    exit;
}

// ── Read query params ─────────────────────────────────────────────────────────
$isWelcomeBack = isset($_GET['welcome_back']) && $_GET['welcome_back'] === '1';
$locationName  = htmlspecialchars(trim($_GET['name'] ?? ''), ENT_QUOTES, 'UTF-8');
$isBulkInstall = isset($_GET['bulk_install']) && $_GET['bulk_install'] === '1';
$bulkCount     = (int)($_GET['count'] ?? 0);

// ── Handle POST ───────────────────────────────────────────────────────────────
$formError = null;
$emailVal  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email']    ?? ''));
    $password = $_POST['password'] ?? '';
    $emailVal = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    $ch = curl_init($apiBase . '/api/auth/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['email' => $email, 'password' => $password]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($resp, true);

    if ($code === 200 && !empty($result['token'])) {
        // Agency account — redirect to agency portal
        if (($result['role'] ?? '') === 'agency') {
            header('Location: https://agency.nolasmspro.com', true, 302);
            exit;
        }

        $token    = $result['token'];
        $userJson = base64_encode(json_encode($result['user'] ?? []));
        $dest     = $apiBase . '/auth-handoff.html'
            . '?token='    . urlencode($token)
            . '&user='     . urlencode($userJson)
            . '&redirect=' . urlencode($reactApp);
        header('Location: ' . $dest, true, 302);
        exit;
    } else {
        $formError = htmlspecialchars($result['error'] ?? 'Invalid email or password.', ENT_QUOTES, 'UTF-8');
    }
}

// ── Build banner HTML ─────────────────────────────────────────────────────────
$bannerHtml = '';
if ($isWelcomeBack) {
    $loc = $locationName ? " <strong>{$locationName}</strong> has been" : 'Your app has been';
    $bannerHtml = <<<HTML
    <div class="banner-blue">
        <p>👋 Welcome back! {$loc} reinstalled.<br>Sign in to continue to your dashboard.</p>
    </div>
HTML;
} elseif ($isBulkInstall) {
    $countLabel = $bulkCount > 0
        ? "{$bulkCount} sub-account" . ($bulkCount !== 1 ? 's' : '') . ' provisioned'
        : 'Agency installation complete';
    $bannerHtml = <<<HTML
    <div class="banner-amber">
        <p><strong>⚡ {$countLabel}.</strong><br>
        Each sub-account admin must open NOLA SMS Pro from within their own GHL sub-account sidebar to complete individual registration.<br><br>
        If you are a sub-account admin, sign in below or ask your agency owner to resend access.</p>
    </div>
HTML;
}

$errorHtml = $formError ? "<div class=\"error-box\">{$formError}</div>" : '';

il_page('Sign In', <<<HTML
    <h1>Welcome back</h1>
    <p class="subtitle">Sign in to your NOLA SMS Pro account.</p>
    {$bannerHtml}
    {$errorHtml}
    <form id="login-form" method="POST" action="/install-login.php">
        <div class="field">
            <label for="email">Email Address</label>
            <input id="email" name="email" type="email" required
                placeholder="you@company.com" value="{$emailVal}" autocomplete="email">
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="pw-wrap">
                <input id="password" name="password" type="password" required
                    placeholder="••••••••" autocomplete="current-password">
                <button type="button" id="toggle-pw" class="pw-toggle" aria-label="Show/hide password">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
        </div>
        <button id="submit-btn" type="submit" class="btn-submit">Sign In</button>
    </form>
    <p class="footer" style="margin-top:16px;">New installation? <a href="{$reactApp}/register-from-install" style="color:#2b83fa;font-weight:600;">Create account</a></p>
HTML);
