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
        .install-progress { height: 8px; border-radius: 999px; background: rgba(146,64,14,0.16); overflow: hidden; margin: 12px 0 10px; }
        .install-progress-fill { display: block; height: 100%; width: 0%; border-radius: inherit; background: #2b83fa; transition: width 0.35s ease; }
        .install-status-detail { font-size: 12px !important; color: #92400e !important; margin-top: 8px; }
        .install-next {
            display: none; margin-top: 12px; width: 100%; text-align: center;
            padding: 11px 12px; border-radius: 12px; background: #2b83fa;
            color: #fff; text-decoration: none; font-size: 13px; font-weight: 800;
        }
        .install-next:hover { background: #1d6bd4; }
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
        .banner-success {
            background: #ecfdf5; border: 1px solid #a7f3d0;
            border-radius: 14px; padding: 14px 16px; margin-bottom: 22px;
        }
        .banner-success p { font-size: 13px; color: #065f46; line-height: 1.5; }
        .banner-success strong { font-weight: 700; }
        .hidden { display: none !important; }
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
                <div class="pw-wrap">
                    <input id="new_password" name="new_password" type="password" required
                        placeholder="••••••••" autocomplete="new-password">
                    <button type="button" id="toggle-new-pw" class="pw-toggle" aria-label="Show/hide password">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            
            <div class="field">
                <label for="confirm_password">Confirm New Password</label>
                <div class="pw-wrap">
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
            CURLOPT_POSTFIELDS     => json_encode(['email' => $email, 'password' => $password]),
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
{$emailFieldHtml}
            </div>
            <div class="field">
                <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 7px;">
                    <label for="password" style="margin-bottom: 0;">Password</label>
                    <a href="#" id="forgot-pw-link" style="font-size: 11px; font-weight: 700; color: #2b83fa; text-decoration: none; text-transform: uppercase; letter-spacing: 0.05em;">Forgot Password?</a>
                </div>
                <div class="pw-wrap">
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
                <input id="forgot-email" name="email" type="email" required
                    placeholder="you@company.com" autocomplete="email">
            </div>
            <button id="forgot-submit-btn" type="submit" class="btn-submit">Send Reset Link</button>
        </form>
        <p class="footer" style="margin-top: 24px;">
            <a href="#" id="back-to-login-link" style="color: #2b83fa; font-weight: 600; text-decoration: none;">Back to Sign In</a>
        </p>
    </div>

    <script>
      (function() {
        var forgotLink = document.getElementById('forgot-pw-link');
        var backToLoginLink = document.getElementById('back-to-login-link');
        var loginWrapper = document.getElementById('login-form-wrapper');
        var forgotWrapper = document.getElementById('forgot-form-wrapper');
        
        if (forgotLink && backToLoginLink && loginWrapper && forgotWrapper) {
          forgotLink.addEventListener('click', function(e) {
            e.preventDefault();
            loginWrapper.classList.add('hidden');
            forgotWrapper.classList.remove('hidden');
          });
          backToLoginLink.addEventListener('click', function(e) {
            e.preventDefault();
            forgotWrapper.classList.add('hidden');
            loginWrapper.classList.remove('hidden');
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
