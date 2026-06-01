<?php
/**
 * install-forgot-password.php
 * Served at: /forgot-password
 *
 * 3-step premium OTP password reset flow for NOLA SMS Pro.
 * Step 1 → Email Entry
 * Step 2 → 6-Box OTP Verification (with brand logo)
 * Step 3 → New Password + Confirm (with brand logo)
 */

function ifp_page(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — NOLA SMS Pro</title>
    <link rel="icon" type="image/png" href="https://assets.cdn.filesafe.space/ugBqfQsPtGijLjrmLdmA/media/6a1a6106045e32379f0aa915.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-family: 'Poppins', system-ui, sans-serif; background: #0a0a0b; color: #f4f6fa; -webkit-font-smoothing: antialiased; }
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 20px;
            background: #0a0a0b;
            position: relative;
            overflow-x: hidden;
        }

        /* ── Ambient blobs ── */
        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(130px);
            opacity: 0.12;
            pointer-events: none;
            z-index: 0;
        }
        .blob-tl { top: -10%; left: -10%; width: 55vw; height: 55vw; background: radial-gradient(circle, #3b82f6, #1d4ed8); }
        .blob-br { bottom: -10%; right: -10%; width: 55vw; height: 55vw; background: radial-gradient(circle, #6366f1, #3b82f6); }
        .blob-mid { top: 40%; left: 35%; width: 30vw; height: 30vw; background: #1d6bd4; opacity: 0.06; }

        /* ── Card ── */
        .card {
            max-width: 480px; width: 100%;
            background: rgba(18, 19, 24, 0.78);
            backdrop-filter: blur(28px) saturate(200%);
            -webkit-backdrop-filter: blur(28px) saturate(200%);
            border-radius: 32px;
            padding: 48px 42px;
            box-shadow: 0 8px 40px 0 rgba(0, 0, 0, 0.55), 0 1px 0 rgba(255,255,255,0.06) inset;
            border: 1px solid rgba(255, 255, 255, 0.08);
            animation: card-in 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
            z-index: 10;
            text-align: center;
        }
        @keyframes card-in { from { opacity: 0; transform: translateY(28px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }

        /* ── Logo ── */
        .logo-wrap { text-align: center; margin-bottom: 28px; }
        .logo-img {
            max-height: 54px; width: auto; object-fit: contain;
            display: block; margin: 0 auto;
            transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            filter: drop-shadow(0 4px 14px rgba(43,131,250,0.22));
        }
        .logo-img:hover { transform: scale(1.05); }

        /* ── Step progress dots ── */
        .step-progress {
            display: flex; align-items: center; justify-content: center;
            gap: 0; margin-bottom: 32px;
        }
        .step-dot {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative; z-index: 1;
        }
        .step-dot.done {
            background: #2b83fa; color: #fff;
            box-shadow: 0 0 0 4px rgba(43,131,250,0.2);
        }
        .step-dot.active {
            background: linear-gradient(135deg, #2b83fa, #6366f1);
            color: #fff;
            box-shadow: 0 0 0 4px rgba(43,131,250,0.25), 0 4px 14px rgba(43,131,250,0.35);
        }
        .step-dot.pending {
            background: rgba(255,255,255,0.05);
            color: #64748b;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .step-line {
            width: 40px; height: 2px;
            background: rgba(255,255,255,0.07);
            transition: background 0.4s ease;
            border-radius: 2px;
        }
        .step-line.done { background: #2b83fa; }

        /* ── Headings ── */
        h1 {
            font-size: 24px; font-weight: 800; letter-spacing: -0.7px;
            margin-bottom: 6px;
            background: linear-gradient(135deg, #ffffff 0%, #93c5fd 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .subtitle {
            font-size: 13.5px; color: #94a3b8; margin-bottom: 28px;
            font-weight: 500; line-height: 1.5;
        }
        .subtitle strong { color: #e2e8f0; }

        /* ── Fields ── */
        label {
            display: block; font-size: 11px; font-weight: 700;
            text-transform: uppercase; color: #94a3b8;
            margin-bottom: 8px; letter-spacing: 0.8px; text-align: left;
        }
        .field { margin-bottom: 20px; }
        .input-wrap { position: relative; }
        .input-icon {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            width: 16px; height: 16px; color: #64748b;
            pointer-events: none; transition: color 0.25s ease; z-index: 2;
        }
        input[type=email], input[type=password], input[type=text] {
            width: 100%; padding: 13px 16px 13px 42px; border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.07);
            background: rgba(0,0,0,0.38);
            font-family: inherit; font-size: 14px; outline: none;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            color: #f4f6fa;
        }
        .pw-wrap input[type=password], .pw-wrap input[type=text] { padding-right: 48px; }
        input:focus {
            border-color: #3b82f6;
            background: rgba(0,0,0,0.45);
            box-shadow: 0 0 0 4px rgba(59,130,246,0.2);
        }
        .input-wrap-focus .input-icon { color: #2b83fa; }
        .pw-wrap { position: relative; }
        .pw-toggle {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: #64748b;
            padding: 4px; display: flex; align-items: center; justify-content: center;
            transition: color 0.2s; z-index: 3;
        }
        .pw-toggle:hover { color: #2b83fa; }

        /* ── OTP 6-box grid ── */
        .otp-grid {
            display: flex; gap: 10px; justify-content: center;
            margin-bottom: 28px;
        }
        .otp-cell {
            width: 52px; height: 60px;
            border-radius: 14px;
            border: 1.5px solid rgba(255,255,255,0.09);
            background: rgba(0,0,0,0.38);
            color: #f4f6fa;
            font-family: 'Poppins', monospace;
            font-size: 22px; font-weight: 700;
            text-align: center;
            outline: none;
            transition: border-color 0.22s, box-shadow 0.22s, background 0.22s, transform 0.18s;
            caret-color: #2b83fa;
            appearance: none; -webkit-appearance: none;
            /* Prevent arrows on number inputs */
            -moz-appearance: textfield;
        }
        .otp-cell::-webkit-outer-spin-button,
        .otp-cell::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .otp-cell:focus {
            border-color: #3b82f6;
            background: rgba(10, 20, 50, 0.55);
            box-shadow: 0 0 0 4px rgba(59,130,246,0.22);
            transform: scale(1.06);
        }
        .otp-cell.filled {
            border-color: rgba(43,131,250,0.55);
            background: rgba(10,20,50,0.42);
        }
        .otp-cell.otp-shake {
            animation: otp-shake 0.4s cubic-bezier(0.36, 0.07, 0.19, 0.97);
        }
        @keyframes otp-shake {
            10%, 90% { transform: translateX(-3px); }
            20%, 80% { transform: translateX(3px); }
            30%, 50%, 70% { transform: translateX(-4px); }
            40%, 60% { transform: translateX(4px); }
        }

        /* ── Password strength bar ── */
        .pw-strength-wrap { margin-top: 6px; }
        .pw-strength-track {
            height: 4px; border-radius: 999px;
            background: rgba(255,255,255,0.06);
            overflow: hidden; margin-bottom: 4px;
        }
        .pw-strength-bar {
            height: 100%; border-radius: 999px; width: 0%;
            transition: width 0.35s ease, background 0.35s ease;
        }
        .pw-strength-label { font-size: 11px; color: #64748b; text-align: left; font-weight: 600; }

        /* ── Buttons ── */
        .btn-submit {
            width: 100%; padding: 14px; border-radius: 14px;
            background: linear-gradient(90deg, #2b83fa 0%, #1d6bd4 100%);
            color: #fff; font-size: 14.5px; font-weight: 700;
            border: none; cursor: pointer; margin-top: 4px;
            box-shadow: 0 8px 24px rgba(43,131,250,0.38);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(43,131,250,0.5); }
        .btn-submit:active { transform: scale(0.985) translateY(0); }
        .btn-submit:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }

        /* ── Alerts ── */
        .error-box {
            background: rgba(239,68,68,0.07); border: 1px solid rgba(239,68,68,0.22);
            border-radius: 14px; padding: 12px 16px; margin-bottom: 20px;
            font-size: 13px; color: #fca5a5; font-weight: 600; line-height: 1.45;
            position: relative; overflow: hidden; display: none;
            animation: fadeInUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        .error-box::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #ef4444; }
        .banner-success {
            background: rgba(16,185,129,0.07); border: 1px solid rgba(16,185,129,0.22);
            border-radius: 16px; padding: 12px 18px; margin-bottom: 20px;
            position: relative; overflow: hidden; display: none;
            animation: fadeInUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        .banner-success::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #10b981; }
        .banner-success p { font-size: 13px; color: #6ee7b7; line-height: 1.5; font-weight: 500; padding-left: 4px; }

        /* ── Timer ── */
        .timer-badge {
            background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 999px; padding: 5px 14px;
            font-size: 12px; color: #94a3b8;
            display: inline-flex; align-items: center; gap: 6px;
            margin-bottom: 22px; font-weight: 600;
        }
        .timer-badge strong { color: #2b83fa; font-family: monospace; font-size: 13px; }
        .timer-warning strong { color: #ef4444 !important; animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

        /* ── Footer / helpers ── */
        .hidden { display: none !important; }
        .fade-in-up { animation: fadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) both; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
        .footer { font-size: 12.5px; color: #94a3b8; text-align: center; margin-top: 22px; font-weight: 500; }
        .footer a { text-decoration: none; transition: color 0.2s; color: #2b83fa; font-weight: 600; }
        .footer a:hover { color: #60a5fa !important; }
        .footer a.disabled { color: #64748b; cursor: not-allowed; pointer-events: none; }

        /* ── Match indicator ── */
        .match-indicator {
            font-size: 11.5px; font-weight: 600;
            margin-top: 6px; text-align: left;
            min-height: 16px; transition: color 0.2s;
        }
        .match-indicator.ok { color: #34d399; }
        .match-indicator.fail { color: #f87171; }
    </style>
</head>
<body>
    <div class="blob blob-tl"></div>
    <div class="blob blob-br"></div>
    <div class="blob blob-mid"></div>
    <div class="card">
        {$body}
    </div>
</body>
</html>
HTML;
    exit;
}

$bodyContent = <<<HTML
    <!-- Alerts (shared across all steps) -->
    <div id="error-alert" class="error-box"></div>
    <div id="success-alert" class="banner-success"><p id="success-alert-text"></p></div>

    <!-- ════════════════════════════════════════
         STEP 1 — Email Entry
    ════════════════════════════════════════ -->
    <div id="step1-wrapper">
        <!-- Progress -->
        <div class="step-progress">
            <div class="step-dot active" id="dot1">1</div>
            <div class="step-line" id="line1"></div>
            <div class="step-dot pending" id="dot2">2</div>
            <div class="step-line" id="line2"></div>
            <div class="step-dot pending" id="dot3">3</div>
        </div>

        <h1>Forgot Password</h1>
        <p class="subtitle">Enter your email address and we'll send<br>you a 6-digit verification code.</p>

        <form id="otp-request-form" novalidate>
            <div class="field">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    <input id="email" name="email" type="email" required placeholder="you@company.com" autocomplete="email">
                </div>
            </div>
            <button id="step1-btn" type="submit" class="btn-submit">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                <span>Send Verification Code</span>
            </button>
        </form>
        <p class="footer"><a href="/login">← Back to Sign In</a></p>
    </div>

    <!-- ════════════════════════════════════════
         STEP 2 — OTP Verification (6 boxes)
    ════════════════════════════════════════ -->
    <div id="step2-wrapper" class="hidden">
        <!-- Brand Logo -->
        <div class="logo-wrap">
            <img src="https://smspro-api.nolacrm.io/PNG%20-%20NOLA%20SMS%20PRO%20Standard.png" alt="NOLA SMS Pro" class="logo-img">
        </div>

        <!-- Progress -->
        <div class="step-progress">
            <div class="step-dot done" id="dot1b">✓</div>
            <div class="step-line done" id="line1b"></div>
            <div class="step-dot active" id="dot2b">2</div>
            <div class="step-line" id="line2b"></div>
            <div class="step-dot pending" id="dot3b">3</div>
        </div>

        <h1>Enter Your Code</h1>
        <p class="subtitle" id="step2-subtitle">We sent a 6-digit code to your email.<br>It expires in 10 minutes.</p>

        <div id="expiry-timer-badge" class="timer-badge">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Expires in <strong id="expiry-countdown">10:00</strong>
        </div>

        <!-- 6 Individual OTP Boxes -->
        <div class="otp-grid" id="otp-grid">
            <input class="otp-cell" id="otp-0" type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code" aria-label="Digit 1">
            <input class="otp-cell" id="otp-1" type="text" inputmode="numeric" maxlength="1" autocomplete="off" aria-label="Digit 2">
            <input class="otp-cell" id="otp-2" type="text" inputmode="numeric" maxlength="1" autocomplete="off" aria-label="Digit 3">
            <input class="otp-cell" id="otp-3" type="text" inputmode="numeric" maxlength="1" autocomplete="off" aria-label="Digit 4">
            <input class="otp-cell" id="otp-4" type="text" inputmode="numeric" maxlength="1" autocomplete="off" aria-label="Digit 5">
            <input class="otp-cell" id="otp-5" type="text" inputmode="numeric" maxlength="1" autocomplete="off" aria-label="Digit 6">
        </div>

        <button id="step2-btn" type="button" class="btn-submit">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span>Verify Code</span>
        </button>

        <p class="footer" style="margin-top: 20px;">
            <a href="#" id="resend-btn" style="margin-right: 16px;">Resend Code</a>
            <a href="/login" style="color: #94a3b8; font-weight: 500;">Back to Sign In</a>
        </p>
    </div>

    <!-- ════════════════════════════════════════
         STEP 3 — Set New Password
    ════════════════════════════════════════ -->
    <div id="step3-wrapper" class="hidden">
        <!-- Brand Logo -->
        <div class="logo-wrap">
            <img src="https://smspro-api.nolacrm.io/PNG%20-%20NOLA%20SMS%20PRO%20Standard.png" alt="NOLA SMS Pro" class="logo-img">
        </div>

        <!-- Progress -->
        <div class="step-progress">
            <div class="step-dot done">✓</div>
            <div class="step-line done"></div>
            <div class="step-dot done">✓</div>
            <div class="step-line done"></div>
            <div class="step-dot active">3</div>
        </div>

        <h1>Set New Password</h1>
        <p class="subtitle">Create a strong password to secure<br>your NOLA SMS Pro account.</p>

        <form id="password-reset-form" novalidate>
            <div class="field">
                <label for="new_password">New Password</label>
                <div class="input-wrap pw-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input id="new_password" name="new_password" type="password" required placeholder="Min. 8 characters" autocomplete="new-password">
                    <button type="button" id="toggle-new-pw" class="pw-toggle" aria-label="Toggle password visibility">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div class="pw-strength-wrap">
                    <div class="pw-strength-track"><div class="pw-strength-bar" id="pw-strength-bar"></div></div>
                    <div class="pw-strength-label" id="pw-strength-label"></div>
                </div>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <div class="input-wrap pw-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input id="confirm_password" name="confirm_password" type="password" required placeholder="Re-enter your password" autocomplete="new-password">
                    <button type="button" id="toggle-confirm-pw" class="pw-toggle" aria-label="Toggle password visibility">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div class="match-indicator" id="pw-match-indicator"></div>
            </div>

            <button id="step3-btn" type="submit" class="btn-submit">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <span>Reset Password</span>
            </button>
        </form>
        <p class="footer" style="margin-top: 20px;">
            <a href="/login" style="color: #94a3b8; font-weight: 500;">← Back to Sign In</a>
        </p>
    </div>

    <script>
      (function() {
        /* ─────────────────────────────────────────
           Helpers
        ───────────────────────────────────────── */
        var errAlert  = document.getElementById('error-alert');
        var succAlert = document.getElementById('success-alert');
        var succText  = document.getElementById('success-alert-text');

        function showError(msg) {
            succAlert.style.display = 'none';
            errAlert.textContent = msg;
            errAlert.style.display = 'block';
            errAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        function showSuccess(msg) {
            errAlert.style.display = 'none';
            succText.innerHTML = msg;
            succAlert.style.display = 'block';
        }
        function clearAlerts() {
            errAlert.style.display = 'none';
            succAlert.style.display = 'none';
        }

        /* ─────────────────────────────────────────
           Input focus classes
        ───────────────────────────────────────── */
        document.querySelectorAll('.input-wrap input').forEach(function(inp) {
            inp.addEventListener('focus', function() {
                var w = inp.closest('.input-wrap');
                if (w) w.classList.add('input-wrap-focus');
            });
            inp.addEventListener('blur', function() {
                var w = inp.closest('.input-wrap');
                if (w) w.classList.remove('input-wrap-focus');
            });
        });

        /* ─────────────────────────────────────────
           Step switching
        ───────────────────────────────────────── */
        function goToStep(n) {
            ['step1-wrapper','step2-wrapper','step3-wrapper'].forEach(function(id, i) {
                var el = document.getElementById(id);
                if (i + 1 === n) {
                    el.classList.remove('hidden');
                    el.classList.add('fade-in-up');
                } else {
                    el.classList.add('hidden');
                    el.classList.remove('fade-in-up');
                }
            });
            clearAlerts();
        }

        /* ─────────────────────────────────────────
           State
        ───────────────────────────────────────── */
        var registeredEmail = '';
        var verifiedOtp     = '';
        var expiryInterval  = null;
        var cooldownInterval = null;
        var cooldownSeconds  = 0;

        /* ─────────────────────────────────────────
           Expiry timer
        ───────────────────────────────────────── */
        function startExpiryTimer() {
            if (expiryInterval) clearInterval(expiryInterval);
            var duration  = 10 * 60;
            var timerEl   = document.getElementById('expiry-countdown');
            var timerBadge = document.getElementById('expiry-timer-badge');
            function tick() {
                var m = Math.floor(duration / 60);
                var s = duration % 60;
                timerEl.textContent = (m < 10 ? '0'+m : m) + ':' + (s < 10 ? '0'+s : s);
                if (duration <= 60) timerBadge.classList.add('timer-warning');
                else timerBadge.classList.remove('timer-warning');
                if (--duration < 0) {
                    clearInterval(expiryInterval);
                    timerEl.textContent = '00:00';
                    showError('Your verification code has expired. Please request a new code.');
                }
            }
            tick();
            expiryInterval = setInterval(tick, 1000);
        }

        /* ─────────────────────────────────────────
           Resend cooldown
        ───────────────────────────────────────── */
        function startResendCooldown() {
            if (cooldownInterval) clearInterval(cooldownInterval);
            cooldownSeconds = 60;
            var btn = document.getElementById('resend-btn');
            btn.classList.add('disabled');
            function tick() {
                if (cooldownSeconds <= 0) {
                    clearInterval(cooldownInterval);
                    btn.classList.remove('disabled');
                    btn.textContent = 'Resend Code';
                } else {
                    btn.textContent = 'Resend (' + cooldownSeconds + 's)';
                    cooldownSeconds--;
                }
            }
            tick();
            cooldownInterval = setInterval(tick, 1000);
        }

        /* ─────────────────────────────────────────
           OTP Boxes — keyboard navigation
        ───────────────────────────────────────── */
        var otpCells = Array.from(document.querySelectorAll('.otp-cell'));

        function getOtpValue() {
            return otpCells.map(function(c){ return c.value; }).join('');
        }

        otpCells.forEach(function(cell, idx) {
            /* Only allow single digit */
            cell.addEventListener('input', function(e) {
                var val = e.target.value.replace(/\D/g, '');
                e.target.value = val ? val[val.length - 1] : '';
                if (e.target.value) {
                    cell.classList.add('filled');
                    if (idx < 5) otpCells[idx + 1].focus();
                } else {
                    cell.classList.remove('filled');
                }
            });

            /* Backspace → move left */
            cell.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && !cell.value && idx > 0) {
                    otpCells[idx - 1].focus();
                    otpCells[idx - 1].value = '';
                    otpCells[idx - 1].classList.remove('filled');
                }
                if (e.key === 'ArrowLeft' && idx > 0) otpCells[idx - 1].focus();
                if (e.key === 'ArrowRight' && idx < 5) otpCells[idx + 1].focus();
            });

            /* Handle paste — spread digits across boxes */
            cell.addEventListener('paste', function(e) {
                e.preventDefault();
                var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').substring(0,6);
                pasted.split('').forEach(function(ch, i) {
                    if (otpCells[idx + i]) {
                        otpCells[idx + i].value = ch;
                        otpCells[idx + i].classList.add('filled');
                    }
                });
                var next = idx + pasted.length;
                if (next < 6) otpCells[next].focus();
                else otpCells[5].focus();
            });
        });

        function shakeOtp() {
            otpCells.forEach(function(c) {
                c.classList.remove('otp-shake');
                void c.offsetWidth; // reflow
                c.classList.add('otp-shake');
            });
        }

        /* ─────────────────────────────────────────
           Password strength meter
        ───────────────────────────────────────── */
        var strengthBar   = document.getElementById('pw-strength-bar');
        var strengthLabel = document.getElementById('pw-strength-label');
        var matchIndicator = document.getElementById('pw-match-indicator');

        function calcStrength(pw) {
            var score = 0;
            if (pw.length >= 8)  score++;
            if (pw.length >= 12) score++;
            if (/[A-Z]/.test(pw)) score++;
            if (/[0-9]/.test(pw)) score++;
            if (/[^A-Za-z0-9]/.test(pw)) score++;
            return score;
        }
        var strengthColors = ['#ef4444','#f97316','#eab308','#22c55e','#10b981'];
        var strengthLabels = ['Too weak','Weak','Fair','Strong','Very strong'];

        document.getElementById('new_password').addEventListener('input', function() {
            var pw = this.value;
            if (!pw) { strengthBar.style.width = '0'; strengthLabel.textContent = ''; return; }
            var s = Math.min(calcStrength(pw), 4);
            strengthBar.style.width = ((s+1)/5*100) + '%';
            strengthBar.style.background = strengthColors[s];
            strengthLabel.textContent = strengthLabels[s];
            strengthLabel.style.color = strengthColors[s];
            checkMatch();
        });

        document.getElementById('confirm_password').addEventListener('input', checkMatch);

        function checkMatch() {
            var pw  = document.getElementById('new_password').value;
            var cpw = document.getElementById('confirm_password').value;
            if (!cpw) { matchIndicator.textContent = ''; matchIndicator.className = 'match-indicator'; return; }
            if (pw === cpw) {
                matchIndicator.textContent = '✓ Passwords match';
                matchIndicator.className = 'match-indicator ok';
            } else {
                matchIndicator.textContent = '✗ Passwords do not match';
                matchIndicator.className = 'match-indicator fail';
            }
        }

        /* ─────────────────────────────────────────
           Toggle password visibility
        ───────────────────────────────────────── */
        function bindPwToggle(btnId, inputId) {
            var btn = document.getElementById(btnId);
            if (!btn) return;
            btn.addEventListener('click', function() {
                var inp = document.getElementById(inputId);
                inp.type = inp.type === 'password' ? 'text' : 'password';
            });
        }
        bindPwToggle('toggle-new-pw', 'new_password');
        bindPwToggle('toggle-confirm-pw', 'confirm_password');

        /* ─────────────────────────────────────────
           STEP 1 — Request OTP
        ───────────────────────────────────────── */
        var reqForm  = document.getElementById('otp-request-form');
        var step1Btn = document.getElementById('step1-btn');
        if (reqForm) {
            reqForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                clearAlerts();
                var email = document.getElementById('email').value.trim().toLowerCase();
                if (!email) { showError('Email address is required.'); return; }

                step1Btn.disabled = true;
                step1Btn.querySelector('span').textContent = 'Sending…';

                try {
                    var res  = await fetch('/api/auth/forgot-password-otp', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ email: email })
                    });
                    var data = await res.json().catch(function(){ return {}; });
                    registeredEmail = email;
                    goToStep(2);
                    document.getElementById('step2-subtitle').innerHTML =
                        'We sent a 6-digit code to:<br><strong>' + email + '</strong>';
                    showSuccess(data.message || 'If your email is registered, you will receive an OTP code shortly.');
                    startExpiryTimer();
                    startResendCooldown();
                    otpCells[0].focus();
                } catch(err) {
                    showError('Connection failed. Please check your internet connection.');
                } finally {
                    step1Btn.disabled = false;
                    step1Btn.querySelector('span').textContent = 'Send Verification Code';
                }
            });
        }

        /* ─────────────────────────────────────────
           STEP 2 — Verify OTP
        ───────────────────────────────────────── */
        var step2Btn = document.getElementById('step2-btn');
        if (step2Btn) {
            step2Btn.addEventListener('click', async function() {
                clearAlerts();
                var otp = getOtpValue();
                if (otp.length !== 6) {
                    shakeOtp();
                    showError('Please enter all 6 digits of your verification code.');
                    return;
                }

                step2Btn.disabled = true;
                step2Btn.querySelector('span').textContent = 'Verifying…';

                try {
                    /* Verify OTP against backend before advancing */
                    var res  = await fetch('/api/auth/forgot-password-otp', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ email: registeredEmail, otp_check: otp })
                    });
                    var data = await res.json().catch(function(){ return {}; });

                    if (res.ok && data.status === 'success') {
                        /* OTP confirmed — proceed to password step */
                        verifiedOtp = otp;
                        if (expiryInterval) clearInterval(expiryInterval);
                        goToStep(3);
                    } else if (data.status === 'otp_not_supported' || res.status === 404) {
                        /* Backend doesn't have a separate verify endpoint yet —
                           store the OTP and let the final step verify it */
                        verifiedOtp = otp;
                        if (expiryInterval) clearInterval(expiryInterval);
                        goToStep(3);
                    } else {
                        shakeOtp();
                        showError(data.message || 'Invalid or expired code. Please try again.');
                    }
                } catch(err) {
                    /* Fallback: proceed optimistically, final step will catch bad OTP */
                    verifiedOtp = otp;
                    if (expiryInterval) clearInterval(expiryInterval);
                    goToStep(3);
                } finally {
                    step2Btn.disabled = false;
                    step2Btn.querySelector('span').textContent = 'Verify Code';
                }
            });
        }

        /* ─────────────────────────────────────────
           Resend code
        ───────────────────────────────────────── */
        var resendBtn = document.getElementById('resend-btn');
        if (resendBtn) {
            resendBtn.addEventListener('click', async function(e) {
                e.preventDefault();
                if (resendBtn.classList.contains('disabled')) return;
                clearAlerts();
                resendBtn.classList.add('disabled');
                resendBtn.textContent = 'Sending…';
                try {
                    var res = await fetch('/api/auth/forgot-password-otp', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ email: registeredEmail })
                    });
                    var data = await res.json().catch(function(){ return {}; });
                    otpCells.forEach(function(c){ c.value = ''; c.classList.remove('filled'); });
                    otpCells[0].focus();
                    showSuccess('A new code has been sent. Please check your inbox.');
                    startExpiryTimer();
                    startResendCooldown();
                } catch(err) {
                    showError('Failed to resend. Please try again.');
                    resendBtn.classList.remove('disabled');
                    resendBtn.textContent = 'Resend Code';
                }
            });
        }

        /* ─────────────────────────────────────────
           STEP 3 — Reset Password
        ───────────────────────────────────────── */
        var resetForm = document.getElementById('password-reset-form');
        var step3Btn  = document.getElementById('step3-btn');
        if (resetForm) {
            resetForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                clearAlerts();
                var newPassword     = document.getElementById('new_password').value;
                var confirmPassword = document.getElementById('confirm_password').value;

                if (!newPassword || newPassword.length < 8) {
                    showError('New password must be at least 8 characters long.');
                    return;
                }
                if (newPassword !== confirmPassword) {
                    showError('Passwords do not match.');
                    return;
                }

                step3Btn.disabled = true;
                step3Btn.querySelector('span').textContent = 'Resetting…';

                try {
                    var res = await fetch('/api/auth/reset-password-otp', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            email: registeredEmail,
                            otp: verifiedOtp,
                            new_password: newPassword
                        })
                    });
                    var data = await res.json().catch(function(){ return {}; });

                    if (res.ok && data.status === 'success') {
                        if (cooldownInterval) clearInterval(cooldownInterval);
                        showSuccess('🎉 Password updated! Redirecting to sign in…');
                        setTimeout(function() {
                            window.location.assign('/login?reset_success=1');
                        }, 1800);
                    } else {
                        showError(data.message || 'Failed to reset password. Please go back and re-enter your verification code.');
                        step3Btn.disabled = false;
                        step3Btn.querySelector('span').textContent = 'Reset Password';
                    }
                } catch(err) {
                    showError('Connection failed. Please check your internet connection.');
                    step3Btn.disabled = false;
                    step3Btn.querySelector('span').textContent = 'Reset Password';
                }
            });
        }

      })();
    </script>
HTML;

ifp_page('Reset Password', $bodyContent);
