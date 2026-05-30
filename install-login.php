<?php
/**
 * install-login.php
 * Served at: https://smspro-api.nolacrm.io/install-login.php
 *
 * GET  ?welcome_back=1&name=<loc>  → shows login form with welcome-back banner
 * GET  ?bulk_install=1&count=N     → shows login form with bulk banner
 * GET  ?bulk_install=1&provisioning=1&session_id=<id>
 *                                    → shows login form with provisioning banner
 * POST (form submit)               → calls /api/auth/login, redirects to auth-handoff.html
 *
 * Drop this file in the repo root alongside ghl_callback.php.
 */

require_once __DIR__ . '/api/jwt_helper.php';
require_once __DIR__ . '/api/webhook/firestore_client.php';
require_once __DIR__ . '/api/install_helpers.php';

$apiBase  = 'https://smspro-api.nolacrm.io';
$reactApp = 'https://app.nolasmspro.com';
$marketplace = 'https://marketplace.leadconnectorhq.com/apps/overview/68118e8f9f1bac2ffc84ed23';

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
        html { font-family: 'Poppins', system-ui, sans-serif; background: #080c14; color: #f4f6fa; -webkit-font-smoothing: antialiased; }
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 20px;
            background: #080c14;
            position: relative;
            overflow-x: hidden;
        }
        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(140px);
            opacity: 0.15;
            pointer-events: none;
            z-index: 0;
            transition: all 1s ease-in-out;
        }
        .blob-tl {
            top: -12%;
            left: -12%;
            width: 55vw;
            height: 55vw;
            background: radial-gradient(circle, #2b83fa 0%, #1e40af 70%);
            animation: drift-tl 20s ease-in-out infinite alternate;
        }
        .blob-br {
            bottom: -12%;
            right: -12%;
            width: 55vw;
            height: 55vw;
            background: radial-gradient(circle, #1d6bd4 0%, #2b83fa 70%);
            animation: drift-br 25s ease-in-out infinite alternate;
        }
        @keyframes drift-tl {
            0% { transform: translate(0, 0) scale(1) rotate(0deg); }
            50% { transform: translate(5%, 4%) scale(1.08) rotate(90deg); }
            100% { transform: translate(0, 0) scale(1) rotate(0deg); }
        }
        @keyframes drift-br {
            0% { transform: translate(0, 0) scale(1.05) rotate(0deg); }
            50% { transform: translate(-4%, -5%) scale(0.95) rotate(-90deg); }
            100% { transform: translate(0, 0) scale(1.05) rotate(0deg); }
        }
        .card {
            max-width: 460px; width: 100%;
            background: rgba(13, 18, 30, 0.45);
            backdrop-filter: blur(30px) saturate(200%);
            -webkit-backdrop-filter: blur(30px) saturate(200%);
            border-radius: 28px; padding: 44px 38px;
            box-shadow: 0 30px 70px -10px rgba(0, 0, 0, 0.5), inset 0 0 0 1px rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.04);
            animation: card-in 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
            z-index: 10; text-align: left;
        }
        @keyframes card-in { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
        .logo-wrap { text-align: center; margin-bottom: 32px; }
        .logo-img { max-height: 52px; width: auto; object-fit: contain; display: block; margin: 0 auto; transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); filter: drop-shadow(0 4px 8px rgba(0,0,0,0.3)); }
        .logo-img:hover { transform: scale(1.04); }
        h1 {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.8px;
            margin-bottom: 6px;
            text-align: center;
            background: linear-gradient(135deg, #ffffff 0%, #93c5fd 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .subtitle { font-size: 13.5px; color: #94a3b8; margin-bottom: 28px; font-weight: 500; text-align: center; line-height: 1.45; }
        
        /* Banners */
        .banner-blue {
            background: rgba(43, 131, 250, 0.08); border: 1px solid rgba(43, 131, 250, 0.25);
            border-radius: 16px; padding: 14px 18px; margin-bottom: 24px;
            position: relative; overflow: hidden;
        }
        .banner-blue::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #2b83fa;
        }
        .banner-blue p { font-size: 13px; color: #93c5fd; line-height: 1.5; font-weight: 500; padding-left: 4px; }
        .banner-blue strong { font-weight: 700; color: #bfdbfe; }
        
        .banner-amber {
            background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25);
            border-radius: 16px; padding: 14px 18px; margin-bottom: 24px;
            position: relative; overflow: hidden;
        }
        .banner-amber::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #f59e0b;
        }
        .banner-amber p { font-size: 13px; color: #fcd34d; line-height: 1.5; font-weight: 500; padding-left: 4px; }
        .banner-amber strong { font-weight: 700; color: #fef08a; }
        
        .install-progress { height: 6px; border-radius: 999px; background: rgba(255, 255, 255, 0.08); overflow: hidden; margin: 12px 0 10px; }
        .install-progress-fill { display: block; height: 100%; width: 0%; border-radius: inherit; background: linear-gradient(90deg, #2b83fa, #00f2fe); transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        .install-status-detail { font-size: 12px !important; color: #fcd34d !important; margin-top: 8px; font-weight: 600; }
        .install-next {
            display: none; margin-top: 14px; width: 100%; text-align: center;
            padding: 12px; border-radius: 14px; background: linear-gradient(135deg, #2b83fa 0%, #1a70e7 100%);
            color: #fff; text-decoration: none; font-size: 13px; font-weight: 700;
            box-shadow: 0 4px 12px rgba(43, 131, 250, 0.2); transition: all 0.2s;
        }
        .install-next:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(43, 131, 250, 0.3); }
        
        /* Form */
        label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin-bottom: 8px; letter-spacing: 0.8px; }
        .field { margin-bottom: 20px; }
        
        .input-wrap { position: relative; }
        .input-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: #64748b; pointer-events: none; transition: color 0.25s ease; z-index: 2; }
        
        input[type=email], input[type=password], input[type=text] {
            width: 100%; padding: 13px 16px 13px 42px; border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.08); background: rgba(13, 18, 30, 0.4);
            font-family: inherit; font-size: 14px; outline: none; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            color: #f4f6fa; position: relative;
        }
        .pw-wrap input[type=password], .pw-wrap input[type=text] {
            padding-right: 48px;
        }
        input:focus { border-color: #2b83fa; background: rgba(13, 18, 30, 0.6); box-shadow: 0 0 0 4px rgba(43, 131, 250, 0.25); }
        .input-wrap-focus .input-icon { color: #2b83fa; }
        
        .pw-wrap { position: relative; }
        .pw-toggle { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748b; padding: 4px; display: flex; align-items: center; justify-content: center; transition: color 0.2s; z-index: 3; }
        .pw-toggle:hover { color: #2b83fa; }
        
        .btn-submit {
            width: 100%; padding: 14px; border-radius: 14px;
            background: linear-gradient(90deg, #2b83fa 0%, #1d6bd4 100%); color: #fff; font-size: 14.5px; font-weight: 700;
            border: none; cursor: pointer; margin-top: 8px;
            box-shadow: 0 8px 25px rgba(43, 131, 250, 0.4);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(43, 131, 250, 0.5); }
        .btn-submit:active { transform: scale(0.985) translateY(0); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }
        
        .error-box {
            background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 14px;
            padding: 12px 16px; margin-bottom: 24px;
            font-size: 13px; color: #fca5a5; font-weight: 600; line-height: 1.45;
            position: relative; overflow: hidden;
        }
        .error-box::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #ef4444;
        }
        
        .banner-success {
            background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25);
            border-radius: 16px; padding: 14px 18px; margin-bottom: 24px;
            position: relative; overflow: hidden;
        }
        .banner-success::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #10b981;
        }
        .banner-success p { font-size: 13px; color: #6ee7b7; line-height: 1.5; font-weight: 500; padding-left: 4px; }
        .banner-success strong { font-weight: 700; color: #a7f3d0; }
        
        .hidden { display: none !important; }
        .fade-in-up { animation: fadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) both; }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .footer { font-size: 12.5px; color: #94a3b8; text-align: center; margin-top: 24px; font-weight: 500; }
        .footer a { text-decoration: none; transition: color 0.2s; }
        .footer a:hover { color: #3b82f6 !important; }
    </style>
</head>
<body>
    <div class="blob blob-tl"></div>
    <div class="blob blob-br"></div>
    <div class="card">
        <div class="logo-wrap">
            <img src="https://smspro-api.nolacrm.io/PNG%20-%20NOLA%20SMS%20PRO%20Standard.png" alt="NOLA SMS Pro" class="logo-img">
        </div>
        {$body}
    </div>
    <script>
      var pwToggle = document.getElementById('toggle-pw');
      if (pwToggle) pwToggle.addEventListener('click', function() {
        var inp = document.getElementById('password');
        inp.type = inp.type === 'password' ? 'text' : 'password';
      });
      var lockedEmailInput = document.getElementById('email-display');
      if (lockedEmailInput && lockedEmailInput.dataset.lockedEmail) {
        var keepLockedEmail = function() {
          lockedEmailInput.value = lockedEmailInput.dataset.lockedEmail;
        };
        keepLockedEmail();
        lockedEmailInput.addEventListener('input', keepLockedEmail);
        setTimeout(keepLockedEmail, 100);
        setTimeout(keepLockedEmail, 600);
      }
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
$companyName   = htmlspecialchars(trim($_GET['company'] ?? ''), ENT_QUOTES, 'UTF-8');
$locationIdRaw = trim((string)($_GET['location_id'] ?? $_POST['location_id'] ?? ''));
$locationIdSafe = htmlspecialchars($locationIdRaw, ENT_QUOTES, 'UTF-8');
$isBulkInstall = isset($_GET['bulk_install']) && $_GET['bulk_install'] === '1';
$isBulkProvisioning = isset($_GET['provisioning']) && $_GET['provisioning'] === '1';
$bulkCount     = (int)($_GET['count'] ?? 0);
$sessionIdRaw  = trim((string)($_GET['session_id'] ?? ''));
$installTokenRaw = trim((string)($_GET['install_token'] ?? ''));

// ── Handle POST & Password Reset Actions ─────────────────────────────────────
$formError = null;
$infoMessage = null;
$emailVal  = '';
$linkedAccount = null;
$loginInstallClass = null;

$action = $_GET['action'] ?? '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$queryStringForAction = (string)($_SERVER['QUERY_STRING'] ?? '');

$isResetSuccess = isset($_GET['reset_success']) && $_GET['reset_success'] === '1';
if ($isResetSuccess) {
    $infoMessage = "Your password has been successfully reset. Please sign in below.";
}

// 1. Handle Reset Password Form Render or Post Submit
if ($action === 'reset_password' || (isset($_POST['form_action']) && $_POST['form_action'] === 'reset_password')) {
    $db = get_firestore();
    $userDoc = null;
    $userCollection = null;

    if ($token !== '') {
        try {
            $results = $db->collection('agency_users')
                ->where('reset_token', '=', $token)
                ->limit(1)
                ->documents();
            foreach ($results as $doc) {
                if ($doc->exists()) {
                    $userDoc = $doc;
                    $userCollection = 'agency_users';
                    break;
                }
            }

            if (!$userDoc) {
                $results = $db->collection('users')
                    ->where('reset_token', '=', $token)
                    ->limit(1)
                    ->documents();
                foreach ($results as $doc) {
                    if ($doc->exists()) {
                        $userDoc = $doc;
                        $userCollection = 'users';
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[install-login] Token lookup error: " . $e->getMessage());
        }
    }

    if (!$userDoc) {
        $formError = "Invalid or expired password reset link.";
        goto render_login_form;
    }

    // Check token expiration
    $data = $userDoc->data();
    $expires = $data['reset_expires'] ?? null;
    $isExpired = true;
    if ($expires instanceof \Google\Cloud\Core\Timestamp) {
        $isExpired = (time() > $expires->get()->getTimestamp());
    }

    if ($isExpired) {
        $formError = "The password reset link has expired. Please request a new one.";
        goto render_login_form;
    }

    // If it's a POST submit to reset the password
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'reset_password') {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($newPassword) < 8) {
            $resetError = "Password must be at least 8 characters long.";
            goto render_reset_form;
        }

        if ($newPassword !== $confirmPassword) {
            $resetError = "Passwords do not match.";
            goto render_reset_form;
        }

        try {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $userRef = $db->collection($userCollection)->document($userDoc->id());
            $userRef->set([
                'password_hash' => $hash,
                'reset_token' => null,
                'reset_expires' => null,
                'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            ], ['merge' => true]);

            header("Location: " . $apiBase . "/login?reset_success=1", true, 302);
            exit;
        } catch (Exception $e) {
            error_log("[install-login] Password update error: " . $e->getMessage());
            $resetError = "Failed to update password. Please try again.";
            goto render_reset_form;
        }
    }

    render_reset_form:
    $resetErrorHtml = isset($resetError) ? "<div class=\"error-box\">{$resetError}</div>" : '';
    $resetFormAction = '/login?action=reset_password';
    if ($queryStringForAction !== '') {
        $resetFormAction = '/login?' . htmlspecialchars($queryStringForAction, ENT_QUOTES, 'UTF-8');
    }

    il_page('Reset Password', <<<HTML
        <h1>Reset your password</h1>
        <p class="subtitle">Enter your new password below.</p>
        {$resetErrorHtml}
        <form id="reset-password-form" method="POST" action="{$resetFormAction}">
            <input type="hidden" name="form_action" value="reset_password">
            <input type="hidden" name="token" value="{$token}">
            
            <div class="field">
                <label for="new_password">New Password</label>
                <div class="input-wrap pw-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input id="new_password" name="new_password" type="password" required
                        placeholder="••••••••" autocomplete="new-password">
                    <button type="button" id="toggle-new-pw" class="pw-toggle" aria-label="Show/hide password">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            
            <div class="field">
                <label for="confirm_password">Confirm New Password</label>
                <div class="input-wrap pw-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input id="confirm_password" name="confirm_password" type="password" required
                        placeholder="••••••••" autocomplete="new-password">
                    <button type="button" id="toggle-confirm-pw" class="pw-toggle" aria-label="Show/hide password">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            
            <button id="reset-submit-btn" type="submit" class="btn-submit">Reset Password</button>
        </form>
        <p class="footer" style="margin-top:24px;">
            <a href="/login" style="color:#2b83fa;font-weight:600;">Back to Sign In</a>
        </p>
        <script>
            // Toggle input focus active classes
            document.querySelectorAll('.input-wrap input').forEach(function(input) {
              input.addEventListener('focus', function() {
                var wrap = input.closest('.input-wrap');
                if (wrap) wrap.classList.add('input-wrap-focus');
              });
              input.addEventListener('blur', function() {
                var wrap = input.closest('.input-wrap');
                if (wrap) wrap.classList.remove('input-wrap-focus');
              });
            });

            var newPwToggle = document.getElementById('toggle-new-pw');
            if (newPwToggle) newPwToggle.addEventListener('click', function() {
                var inp = document.getElementById('new_password');
                inp.type = inp.type === 'password' ? 'text' : 'password';
            });
            var confirmPwToggle = document.getElementById('toggle-confirm-pw');
            if (confirmPwToggle) confirmPwToggle.addEventListener('click', function() {
                var inp = document.getElementById('confirm_password');
                inp.type = inp.type === 'password' ? 'text' : 'password';
            });
            var form = document.getElementById('reset-password-form');
            if (form) form.addEventListener('submit', function() {
                var btn = document.getElementById('reset-submit-btn');
                btn.disabled = true;
                btn.textContent = 'Resetting…';
            });
        </script>
HTML);
}

// 2. Preload linked account for location if location_id is provided
if ($locationIdRaw !== '') {
    try {
        $dbForInstall = get_firestore();
        $linkedAccount = install_linked_account_for_location($dbForInstall, $locationIdRaw);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($linkedAccount['email'])) {
            $emailVal = htmlspecialchars((string)$linkedAccount['email'], ENT_QUOTES, 'UTF-8');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($linkedAccount['email']) && !$isBulkInstall) {
            $loginInstallClass = install_classify_location($dbForInstall, $locationIdRaw);
            if (($loginInstallClass['status'] ?? '') === INSTALL_STATE_COMPANY_MISMATCH) {
                il_page('Sub-account Mismatch', '<div class="error-box">This login link does not match the selected GoHighLevel sub-account. Please reinstall from the correct sub-account.</div>');
            }
            if (($loginInstallClass['status'] ?? '') === INSTALL_STATE_INSTALL_PENDING) {
                il_page('Install Pending', '<div class="error-box">OAuth is still being resolved for this sub-account. Please restart the Marketplace install if this page does not continue.</div>');
            }
            if (!empty($loginInstallClass['token_exists']) && empty($loginInstallClass['linked'])) {
                $locSnap = $dbForInstall->collection('ghl_tokens')->document($locationIdRaw)->snapshot();
                $locData = $locSnap->exists() ? $locSnap->data() : [];
                $locName = (string)($locData['location_name'] ?? $locationName ?? '');
                $coId = (string)($locData['companyId'] ?? $locData['company_id'] ?? '');
                $coName = (string)($locData['company_name'] ?? $locData['agency_name'] ?? $companyName ?? '');
                $jwtSecretLogin = getenv('JWT_SECRET');
                if ($jwtSecretLogin === false || trim((string)$jwtSecretLogin) === '') {
                    error_log('[install-login] JWT_SECRET missing; cannot build registration URL.');
                    il_page('Configuration Error', '<div class="error-box">Server configuration error: JWT secret missing.</div>');
                }
                header('Location: ' . install_build_registration_url($jwtSecretLogin, $locationIdRaw, $locName, $coId ?: null, $coName, 'login_unregistered_reinstall', (string)($loginInstallClass['status'] ?? INSTALL_STATE_TOKEN_ONLY)), true, 302);
                exit;
            }
        }
    } catch (Exception $e) {
        error_log('[install-login] linked account preload failed: ' . $e->getMessage());
    }
}

// 3. Handle POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formActionType = $_POST['form_action'] ?? 'login';

    if ($formActionType === 'forgot') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if ($email !== '') {
            try {
                $db = get_firestore();
                $userDoc = null;
                $userCollection = null;

                // Query agency_users
                $results = $db->collection('agency_users')
                    ->where('email', '=', $email)
                    ->limit(1)
                    ->documents();
                foreach ($results as $doc) {
                    if ($doc->exists()) {
                        $userDoc = $doc;
                        $userCollection = 'agency_users';
                        break;
                    }
                }

                // Query users
                if (!$userDoc) {
                    $results = $db->collection('users')
                        ->where('email', '=', $email)
                        ->limit(1)
                        ->documents();
                    foreach ($results as $doc) {
                        if ($doc->exists()) {
                            $userDoc = $doc;
                            $userCollection = 'users';
                            break;
                        }
                    }
                }

                if ($userDoc) {
                    $token = bin2hex(random_bytes(32));
                    $expires = new \Google\Cloud\Core\Timestamp(new \DateTime('+1 hour'));

                    $userRef = $db->collection($userCollection)->document($userDoc->id());
                    $userRef->set([
                        'reset_token' => $token,
                        'reset_expires' => $expires,
                        'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
                    ], ['merge' => true]);

                    // Send email
                    $subject = "Password Reset Request - NOLA SMS Pro";
                    $resetLink = $apiBase . '/login?action=reset_password&token=' . $token;
                    $message = "Hello,\n\nWe received a request to reset the password for your NOLA SMS Pro account. To set a new password, please click the link below:\n\n" . $resetLink . "\n\nThis link will expire in 1 hour.\n\nIf you did not request a password reset, please ignore this email.";
                    $headers = "From: noreply@nolacrm.io\r\n" .
                               "Reply-To: support@nolacrm.io\r\n" .
                               "X-Mailer: PHP/" . phpversion();

                    @mail($email, $subject, $message, $headers);
                    error_log("[install-login] Sent password reset email to: {$email}. Reset link: {$resetLink}");
                } else {
                    error_log("[install-login] Forgot password request for non-existent email: {$email}");
                }
            } catch (Exception $e) {
                error_log("[install-login] Forgot password error: " . $e->getMessage());
            }
        }
        $infoMessage = "If an account exists for that email address, we have sent a password reset link. Please check your inbox.";
        goto render_login_form;
    } else {
        // Default login handling
        $email    = strtolower(trim($_POST['email']    ?? ''));
        $password = $_POST['password'] ?? '';
        $emailVal = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

        $ch = curl_init($apiBase . '/api/auth/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'email' => $email,
                'password' => $password,
                'location_id' => $locationIdRaw
            ]),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($resp, true);

        if ($code === 200 && !empty($result['token'])) {
            if ($locationIdRaw !== '') {
                $db = get_firestore();
                $jwtSecret = getenv('JWT_SECRET');
                if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
                    $formError = 'Server configuration error: JWT secret missing.';
                    goto render_login_form;
                }
                $payload = jwt_verify((string)$result['token'], $jwtSecret);
                $authUid = (string)($payload['sub'] ?? '');
                $authEmail = (string)($payload['email'] ?? $email);
                if (!install_user_linked_to_location($db, $authUid, $locationIdRaw, $authEmail)) {
                    $formError = 'This account is not linked to the selected subaccount. Please sign in with the correct linked account.';
                    goto render_login_form;
                }
            }

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
}
render_login_form:

// ── Build banner HTML ─────────────────────────────────────────────────────────
$bannerHtml = '';
if ($isWelcomeBack) {
    // Dynamic reinstall label: prefer selected subaccount/location, then fallback to company.
    // This ensures reinstalling a different linked subaccount shows the correct target.
    $targetLabel = '';
    if ($locationName) {
        $targetLabel = "<strong>{$locationName}</strong>";
    } elseif ($companyName) {
        $targetLabel = "<strong>{$companyName}</strong>";
    }
    $loc = $targetLabel !== '' ? " {$targetLabel} has been" : 'Your app has been';
    $bannerHtml = <<<HTML
    <div class="banner-blue">
        <p>👋 Welcome back! {$loc} reinstalled.<br>Sign in to continue to your dashboard.</p>
    </div>
HTML;
} elseif ($isBulkInstall && $isBulkProvisioning) {
    if ($sessionIdRaw !== '' && $installTokenRaw !== '') {
        $bannerHtml = <<<HTML
    <div class="banner-amber">
        <p><strong id="install-status-title">Installation is being prepared.</strong><br>
        <span id="install-status-copy">Provisioning is running in the background. This page will update automatically.</span></p>
        <div class="install-progress" aria-hidden="true"><span id="install-progress-fill" class="install-progress-fill"></span></div>
        <p id="install-status-detail" class="install-status-detail">Checking provisioning status...</p>
        <a id="install-next-link" class="install-next" href="#">Continue setup</a>
    </div>
HTML;
    } else {
        $bannerHtml = <<<HTML
    <div class="banner-amber">
        <p><strong>Installation is being prepared.</strong><br>
        This install link is missing the signed status token, so this page cannot verify progress. Open NOLA SMS Pro again from the target GoHighLevel sub-account after a moment to continue setup.</p>
    </div>
HTML;
    }
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

if ($infoMessage) {
    $bannerHtml .= <<<HTML
    <div class="banner-success">
        <p>{$infoMessage}</p>
    </div>
HTML;
}

$errorHtml = $formError ? "<div class=\"error-box\">{$formError}</div>" : '';
$linkedEmailSafe = !empty($linkedAccount['email'])
    ? htmlspecialchars((string)$linkedAccount['email'], ENT_QUOTES, 'UTF-8')
    : '';
$linkedNameSafe = !empty($linkedAccount['name'])
    ? htmlspecialchars((string)$linkedAccount['name'], ENT_QUOTES, 'UTF-8')
    : '';
$linkedAccountHtml = '';
if ($linkedEmailSafe !== '') {
    $linkedLabel = $linkedNameSafe !== '' ? "{$linkedNameSafe} &lt;{$linkedEmailSafe}&gt;" : $linkedEmailSafe;
    $linkedAccountHtml = <<<HTML
    <div class="banner-blue">
        <p>Primary NOLA SMS Pro owner for this sub-account: <strong>{$linkedLabel}</strong>.<br>
        Sign in with that email if it is yours. Other team members can enter their own NOLA SMS Pro email and password below.</p>
    </div>
HTML;
}

$emailFieldHtml = '';
$emailLabelFor = 'email';
if ($linkedEmailSafe !== '') {
    $emailFieldHtml = <<<HTML
            <input id="email" name="email" type="email" required
                placeholder="{$linkedEmailSafe}" value="{$emailVal}" autocomplete="username">
HTML;
} else {
    $emailFieldHtml = <<<HTML
            <input id="email" name="email" type="email" required
                placeholder="you@company.com" value="{$emailVal}" autocomplete="email">
HTML;
}
$passwordAutocompleteAttrs = 'autocomplete="current-password"';

$queryStringForAction = (string)($_SERVER['QUERY_STRING'] ?? '');
$formAction = '/login';
if ($queryStringForAction !== '') {
    $formAction .= '?' . htmlspecialchars($queryStringForAction, ENT_QUOTES, 'UTF-8');
}

$footerHtml = '<p class="footer" style="margin-top:16px;">New installation? <a href="' . htmlspecialchars($marketplace, ENT_QUOTES, 'UTF-8') . '" style="color:#2b83fa;font-weight:600;">Open Marketplace</a></p>';
if ($isBulkInstall) {
    $footerHtml = '<p class="footer" style="margin-top:16px;">After provisioning, open NOLA SMS Pro from the target GoHighLevel sub-account to finish setup.</p>';
} elseif ($linkedEmailSafe !== '' && $locationIdRaw !== '') {
    $footerHtml = '<p class="footer" style="margin-top:16px;">New installation? <a href="' . htmlspecialchars($marketplace, ENT_QUOTES, 'UTF-8') . '" style="color:#2b83fa;font-weight:600;">Open Marketplace</a></p>';
    try {
        $dbForFooter = isset($dbForInstall) ? $dbForInstall : get_firestore();
        $locSnap = $dbForFooter->collection('ghl_tokens')->document($locationIdRaw)->snapshot();
        $locData = $locSnap->exists() ? $locSnap->data() : [];
        $jwtSecretFooter = getenv('JWT_SECRET');
        if ($jwtSecretFooter !== false && trim((string)$jwtSecretFooter) !== '') {
            $registerUrl = install_build_registration_url(
                (string)$jwtSecretFooter,
                $locationIdRaw,
                (string)($locData['location_name'] ?? $locationName ?? ''),
                (string)($locData['companyId'] ?? $locData['company_id'] ?? '') ?: null,
                (string)($locData['company_name'] ?? $locData['agency_name'] ?? $companyName ?? ''),
                'login_create_additional_user',
                INSTALL_STATE_LINKED_ACCOUNT,
                ['allow_additional_member' => true]
            );
            $footerHtml .= '<p class="footer" style="margin-top:12px;">Need a separate NOLA SMS Pro login for this same GoHighLevel sub-account? <a href="' . htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#2b83fa;font-weight:600;">Create account</a></p>';
        }
    } catch (Exception $e) {
        error_log('[install-login] footer additional registration link failed: ' . $e->getMessage());
    }
} elseif ($locationIdRaw !== '') {
    try {
        $dbForFooter = isset($dbForInstall) ? $dbForInstall : get_firestore();
        $footerClass = $loginInstallClass ?: install_classify_location($dbForFooter, $locationIdRaw);
        if (($footerClass['status'] ?? '') !== INSTALL_STATE_INSTALL_PENDING && !empty($footerClass['token_exists']) && empty($footerClass['linked'])) {
            $locSnap = $dbForFooter->collection('ghl_tokens')->document($locationIdRaw)->snapshot();
            $locData = $locSnap->exists() ? $locSnap->data() : [];
            $jwtSecretFooter = getenv('JWT_SECRET');
            if ($jwtSecretFooter !== false && trim((string)$jwtSecretFooter) !== '') {
                $registerUrl = install_build_registration_url(
                    (string)$jwtSecretFooter,
                    $locationIdRaw,
                    (string)($locData['location_name'] ?? $locationName ?? ''),
                    (string)($locData['companyId'] ?? $locData['company_id'] ?? '') ?: null,
                    (string)($locData['company_name'] ?? $locData['agency_name'] ?? $companyName ?? ''),
                    'login_create_account_link',
                    (string)($footerClass['status'] ?? INSTALL_STATE_TOKEN_ONLY)
                );
                $footerHtml = '<p class="footer" style="margin-top:16px;">Need to finish setup? <a href="' . htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#2b83fa;font-weight:600;">Create account</a></p>';
            }
        }
    } catch (Exception $e) {
        error_log('[install-login] footer registration link failed: ' . $e->getMessage());
    }
}

$bulkStatusScript = '';
if ($isBulkInstall && $isBulkProvisioning && $sessionIdRaw !== '' && $installTokenRaw !== '') {
    $sessionIdJson = json_encode($sessionIdRaw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $installTokenJson = json_encode($installTokenRaw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $bulkStatusScript = <<<HTML
    <script>
      (function() {
        var sessionId = {$sessionIdJson};
        var installToken = {$installTokenJson};
        var title = document.getElementById('install-status-title');
        var copy = document.getElementById('install-status-copy');
        var detail = document.getElementById('install-status-detail');
        var fill = document.getElementById('install-progress-fill');
        var link = document.getElementById('install-next-link');
        if (!sessionId || !installToken || !detail) return;

        var attempts = 0;
        var stopped = false;
        function setText(el, value) { if (el) el.textContent = value; }
        function setProgress(progress) {
          var total = Number(progress && progress.total_locations ? progress.total_locations : 0);
          var provisioned = Number(progress && progress.provisioned ? progress.provisioned : 0);
          var failed = Number(progress && progress.failed ? progress.failed : 0);
          var percent = total > 0 ? Math.min(100, Math.round(((provisioned + failed) / total) * 100)) : Math.min(92, 8 + attempts * 3);
          if (fill) fill.style.width = percent + '%';
          return total > 0
            ? provisioned + ' of ' + total + ' sub-account' + (total === 1 ? '' : 's') + ' provisioned' + (failed > 0 ? ', ' + failed + ' failed' : '')
            : 'Finding selected sub-accounts...';
        }
        function showNext(action) {
          if (!action || !action.url || !link) return false;
          link.href = action.url;
          link.textContent = action.label || 'Continue setup';
          link.style.display = 'block';
          if (fill) fill.style.width = '100%';
          window.setTimeout(function() { window.location.assign(action.url); }, 1200);
          return true;
        }
        async function poll() {
          if (stopped) return;
          attempts += 1;
          try {
            var url = '/api/agency/install/status?session_id=' + encodeURIComponent(sessionId) + '&install_token=' + encodeURIComponent(installToken);
            var res = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            var data = await res.json().catch(function() { return {}; });
            if (!res.ok) throw new Error(data.error || 'Status check failed');

            var summary = setProgress(data.progress || {});
            var action = data.next_action || {};
            setText(detail, summary);

            if (action.kind === 'register' || action.kind === 'login') {
              stopped = true;
              setText(title, action.kind === 'login' ? 'Installation ready.' : 'Ready to finish setup.');
              setText(copy, action.message || 'Continue to finish setup.');
              showNext(action);
              return;
            }

            if (action.kind === 'open_subaccount' || action.kind === 'complete') {
              stopped = true;
              setText(title, 'Installation complete.');
              setText(copy, action.message || 'Open NOLA SMS Pro from the target GoHighLevel sub-account to finish setup.');
              if (fill) fill.style.width = '100%';
              return;
            }

            if (action.kind === 'failed' || action.kind === 'error') {
              stopped = true;
              setText(title, action.label || 'Installation needs attention.');
              setText(copy, action.message || 'Provisioning could not finish.');
              setText(detail, 'Please reinstall from the selected GoHighLevel sub-account or check backend logs.');
              return;
            }
          } catch (err) {
            setText(detail, 'Could not verify provisioning yet. Retrying...');
          }

          if (attempts < 120) {
            window.setTimeout(poll, 2500);
          } else {
            setText(detail, 'Still provisioning. Open NOLA SMS Pro again from the target GoHighLevel sub-account in a moment.');
          }
        }
        window.setTimeout(poll, 300);
      })();
    </script>
HTML;
}

il_page('Sign In', <<<HTML
    <div id="login-form-wrapper">
        <h1>Welcome back</h1>
        <p class="subtitle">Sign in to your NOLA SMS Pro account.</p>
        {$bannerHtml}
        {$linkedAccountHtml}
        {$errorHtml}
        <form id="login-form" method="POST" action="{$formAction}">
            <input type="hidden" name="location_id" value="{$locationIdSafe}">
            <div class="field">
                <label for="{$emailLabelFor}">Email Address</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    {$emailFieldHtml}
                </div>
            </div>
            <div class="field">
                <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 7px;">
                    <label for="password" style="margin-bottom: 0;">Password</label>
                    <a href="#" id="forgot-pw-link" style="font-size: 11px; font-weight: 700; color: #2b83fa; text-decoration: none; text-transform: uppercase; letter-spacing: 0.05em;">Forgot Password?</a>
                </div>
                <div class="input-wrap pw-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input id="password" name="password" type="password" required
                        placeholder="••••••••" {$passwordAutocompleteAttrs}>
                    <button type="button" id="toggle-pw" class="pw-toggle" aria-label="Show/hide password">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <button id="submit-btn" type="submit" class="btn-submit">Sign In</button>
        </form>
        {$footerHtml}
    </div>

    <div id="forgot-form-wrapper" class="hidden">
        <h1>Forgot Password</h1>
        <p class="subtitle">Enter your email address to request a password reset.</p>
        <form id="forgot-form" method="POST" action="{$formAction}">
            <input type="hidden" name="form_action" value="forgot">
            <div class="field">
                <label for="forgot-email">Email Address</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    <input id="forgot-email" name="email" type="email" required
                        placeholder="you@company.com" autocomplete="email">
                </div>
            </div>
            <button id="forgot-submit-btn" type="submit" class="btn-submit">Send Reset Link</button>
        </form>
        <p class="footer" style="margin-top: 24px;">
            <a href="#" id="back-to-login-link" style="color: #2b83fa; font-weight: 600; text-decoration: none;">Back to Sign In</a>
        </p>
    </div>

    <script>
      (function() {
        // Toggle input focus active classes
        document.querySelectorAll('.input-wrap input').forEach(function(input) {
          input.addEventListener('focus', function() {
            var wrap = input.closest('.input-wrap');
            if (wrap) wrap.classList.add('input-wrap-focus');
          });
          input.addEventListener('blur', function() {
            var wrap = input.closest('.input-wrap');
            if (wrap) wrap.classList.remove('input-wrap-focus');
          });
        });

        var forgotLink = document.getElementById('forgot-pw-link');
        var backToLoginLink = document.getElementById('back-to-login-link');
        var loginWrapper = document.getElementById('login-form-wrapper');
        var forgotWrapper = document.getElementById('forgot-form-wrapper');
        
        if (forgotLink && backToLoginLink && loginWrapper && forgotWrapper) {
          forgotLink.addEventListener('click', function(e) {
            e.preventDefault();
            loginWrapper.classList.add('hidden');
            forgotWrapper.classList.remove('hidden');
            forgotWrapper.classList.add('fade-in-up');
          });
          backToLoginLink.addEventListener('click', function(e) {
            e.preventDefault();
            forgotWrapper.classList.add('hidden');
            loginWrapper.classList.remove('hidden');
            loginWrapper.classList.add('fade-in-up');
          });
        }
        
        var forgotForm = document.getElementById('forgot-form');
        if (forgotForm) {
          forgotForm.addEventListener('submit', function() {
            var btn = document.getElementById('forgot-submit-btn');
            if (btn) {
              btn.disabled = true;
              btn.textContent = 'Sending…';
            }
          });
        }
      })();
    </script>
    {$bulkStatusScript}
HTML);
