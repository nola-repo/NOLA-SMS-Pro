<?php
/**
 * install-forgot-password.php
 * Served at: /forgot-password
 *
 * 3-step premium OTP password reset flow for NOLA SMS Pro.
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

        :root, [data-theme="dark"] {
            --bg-main: #0a0a0b;
            --bg-overlay: rgba(10, 10, 11, 0.35);
            --text-primary: #f4f6fa;
            --text-secondary: #94a3b8;
            --text-heading: linear-gradient(135deg, #ffffff 0%, #93c5fd 100%);
            --input-bg: rgba(18, 20, 26, 0.68);
            --input-border: rgba(255, 255, 255, 0.12);
            --input-text: #f4f6fa;
            --toggle-bg: rgba(255, 255, 255, 0.12);
            --toggle-border: rgba(255, 255, 255, 0.18);
            --toggle-icon: #f4f6fa;
            --blob-opacity: 0.1;
        }

        [data-theme="light"] {
            --bg-main: #f1f5f9;
            --bg-overlay: rgba(241, 245, 249, 0.05);
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-heading: linear-gradient(135deg, #0f172a 0%, #1d6bd4 100%);
            --input-bg: #ffffff;
            --input-border: #cbd5e1;
            --input-text: #0f172a;
            --toggle-bg: rgba(15, 23, 42, 0.08);
            --toggle-border: rgba(15, 23, 42, 0.12);
            --toggle-icon: #0f172a;
            --blob-opacity: 0.18;
        }

        html { font-family: 'Poppins', system-ui, sans-serif; background: var(--bg-main); color: var(--text-primary); -webkit-font-smoothing: antialiased; }
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 24px 16px;
            background-color: var(--bg-main);
            position: relative;
            overflow-x: hidden;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .bg-image-layer {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 0;
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            pointer-events: none;
            transition: opacity 0.5s ease-in-out;
        }
        .bg-image-layer.dark-layer {
            background-image: url('/marketplace_install_bg.png');
        }
        .bg-image-layer.light-layer {
            background-image: url('/marketplace_install_lightmode.png');
        }
        [data-theme="dark"] .dark-layer { opacity: 1; }
        [data-theme="dark"] .light-layer { opacity: 0; }
        [data-theme="light"] .dark-layer { opacity: 0; }
        [data-theme="light"] .light-layer { opacity: 1; }

        .theme-toggle-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 100;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--toggle-bg);
            border: 1px solid var(--toggle-border);
            color: var(--toggle-icon);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            transition: all 0.25s ease;
        }
        .theme-toggle-btn:hover {
            transform: scale(1.08);
            box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        }
        .sun-icon { display: block; }
        .moon-icon { display: none; }
        [data-theme="dark"] .sun-icon { display: block; }
        [data-theme="dark"] .moon-icon { display: none; }
        [data-theme="light"] .sun-icon { display: none; }
        [data-theme="light"] .moon-icon { display: block; }
        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(120px);
            opacity: var(--blob-opacity);
            pointer-events: none;
            z-index: 0;
            transition: opacity 0.3s ease;
        }
        .blob-tl { top: -10%; left: -10%; width: 50vw; height: 50vw; background: #3b82f6; }
        .blob-br { bottom: -10%; right: -10%; width: 50vw; height: 50vw; background: #3b82f6; }
        .blob-mid { top: 40%; left: 35%; width: 30vw; height: 30vw; background: #1d6bd4; opacity: 0.06; }

        .ifp-canvas-container {
            max-width: 480px;
            width: 100%;
            padding: 20px 0;
            margin: auto;
            position: relative;
            z-index: 10;
            text-align: center;
            animation: canvas-in 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes canvas-in {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-wrap { text-align: center; margin-bottom: 24px; }
        .logo-img { max-height: 56px; width: auto; object-fit: contain; display: block; margin: 0 auto; transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); filter: drop-shadow(0 6px 14px rgba(0,0,0,0.35)); }
        .logo-img:hover { transform: scale(1.05); }

        h1 {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.6px;
            margin-bottom: 6px;
            text-align: center;
            background: var(--text-heading);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .subtitle { font-size: 14px; color: var(--text-secondary); margin-bottom: 24px; text-align: center; line-height: 1.45; }

        label { display: block; font-size: 11.5px; font-weight: 800; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.9px; margin-bottom: 8px; text-align: left; }
        [data-theme="light"] label { color: #1e293b !important; }
        .field { margin-bottom: 20px; text-align: left; }

        .input-wrap { position: relative; }
        .input-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: #64748b; pointer-events: none; transition: color 0.25s ease; z-index: 2; }
        
        input[type=email], input[type=password], input[type=text] {
            width: 100%; min-height: 52px; padding: 13px 16px 13px 44px; border-radius: 16px;
            border: 1.5px solid var(--input-border); background: var(--input-bg);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            font-family: inherit; font-size: 14px; font-weight: 500; outline: none; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            color: var(--input-text); position: relative;
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        [data-theme="light"] input[type=email],
        [data-theme="light"] input[type=password],
        [data-theme="light"] input[type=text] {
            background: #ffffff !important;
            border-color: #cbd5e1 !important;
            color: #0f172a !important;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.07), 0 1px 3px rgba(0, 0, 0, 0.04) !important;
        }
        [data-theme="light"] input:focus {
            border-color: #2b83fa !important;
            box-shadow: 0 0 0 4px rgba(43, 131, 250, 0.25), 0 8px 24px rgba(43, 131, 250, 0.12) !important;
        }
        .pw-wrap input[type=password], .pw-wrap input[type=text] { padding-right: 48px; }
        input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.3), 0 6px 24px rgba(0,0,0,0.18); }
        
        .pw-wrap { position: relative; }
        .pw-toggle { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748b; padding: 4px; display: flex; align-items: center; justify-content: center; transition: color 0.2s; z-index: 3; }
        .pw-toggle:hover { color: #2b83fa; }

        /* OTP 6-box grid */
        .otp-grid { display: flex; gap: 10px; justify-content: center; margin-bottom: 24px; }
        .otp-cell {
            width: 52px !important; height: 60px; padding: 0 !important;
            border-radius: 16px; border: 1.5px solid var(--input-border);
            background: var(--input-bg); backdrop-filter: blur(16px);
            color: var(--input-text); font-family: 'Poppins', monospace;
            font-size: 22px; font-weight: 700; text-align: center; outline: none;
            transition: all 0.2s ease; caret-color: #2b83fa;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        .otp-cell:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59,130,246,0.3); transform: scale(1.05); }

        .btn-submit {
            width: 100%; padding: 14px; border-radius: 16px;
            background: linear-gradient(90deg, #2b83fa 0%, #1d6bd4 100%); color: #fff; font-size: 15px; font-weight: 700;
            border: none; cursor: pointer; margin-top: 10px;
            box-shadow: 0 8px 28px rgba(43, 131, 250, 0.45);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 52px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(43, 131, 250, 0.55); }
        .btn-submit:active { transform: scale(0.985) translateY(0); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-spinner { width: 16px; height: 16px; border-radius: 999px; border: 2px solid rgba(255,255,255,0.42); border-top-color: #ffffff; flex: 0 0 auto; animation: spin 0.75s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .timer-badge { background: var(--input-bg); border: 1px solid var(--input-border); backdrop-filter: blur(12px); border-radius: 999px; padding: 6px 16px; font-size: 12.5px; color: var(--text-secondary); display: inline-flex; align-items: center; gap: 6px; margin-bottom: 22px; font-weight: 600; }
        .timer-badge strong { color: #2b83fa; font-family: monospace; font-size: 13.5px; }

        .error-box { background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 16px; padding: 14px 18px; margin-bottom: 24px; backdrop-filter: blur(12px); font-size: 13px; color: #fca5a5; font-weight: 600; line-height: 1.45; position: relative; overflow: hidden; display: none; }
        .error-box::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #ef4444; }

        .banner-success { background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 16px; padding: 14px 18px; margin-bottom: 24px; backdrop-filter: blur(12px); position: relative; overflow: hidden; display: none; }
        .banner-success::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #10b981; }
        .banner-success p { font-size: 13px; color: #6ee7b7; line-height: 1.5; font-weight: 500; padding-left: 4px; }

        .pw-strength-wrap { margin-top: 8px; }
        .pw-strength-track { height: 4px; border-radius: 999px; background: var(--input-border); overflow: hidden; margin-bottom: 4px; }
        .pw-strength-bar { height: 100%; border-radius: 999px; width: 0%; transition: width 0.35s ease, background 0.35s ease; }
        .pw-strength-label { font-size: 11px; color: var(--text-secondary); text-align: left; font-weight: 600; }
        .match-indicator { font-size: 11.5px; font-weight: 600; margin-top: 6px; text-align: left; min-height: 16px; transition: color 0.2s; }
        .match-indicator.ok { color: #34d399; }
        .match-indicator.fail { color: #f87171; }

        .hidden { display: none !important; }
        .footer { font-size: 13px; color: var(--text-secondary); text-align: center; margin-top: 28px; font-weight: 500; }
        .footer a { text-decoration: none; transition: color 0.2s; font-weight: 600; color: #2b83fa; }
        .footer a:hover { color: #3b82f6 !important; }

        /* Placeholder contrast */
        ::placeholder { color: #64748b; opacity: 0.8; }
        [data-theme="light"] ::placeholder { color: #64748b !important; opacity: 0.85; }

        /* Mobile Viewport Responsiveness */
        @media (max-width: 600px) {
            body { padding: 16px 12px; }
            h1 { font-size: 24px !important; }
            .subtitle { font-size: 13px !important; margin-bottom: 20px !important; }
            .bg-image-layer { background-position: center top; }
            .theme-toggle-btn { top: 14px; right: 14px; width: 38px; height: 38px; }
            .ifp-canvas-container { padding: 12px 0; }
            .otp-cell { width: 44px; height: 48px; font-size: 20px; }
        }
    </style>
</head>
<body>
    <div class="bg-image-layer dark-layer" aria-hidden="true"></div>
    <div class="bg-image-layer light-layer" aria-hidden="true"></div>
    <button type="button" id="theme-toggle" class="theme-toggle-btn" title="Toggle Light/Dark Mode" aria-label="Toggle Light/Dark Mode">
        <svg class="sun-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
        <svg class="moon-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
    </button>
    <div class="blob blob-tl"></div>
    <div class="blob blob-br"></div>
    <div class="blob blob-mid"></div>
    <main class="ifp-canvas-container">
        {$body}
    </main>
    <script>
      var themeBtn = document.getElementById('theme-toggle');
      if (themeBtn) {
        themeBtn.addEventListener('click', function() {
          var cur = document.documentElement.getAttribute('data-theme');
          var next = cur === 'light' ? 'dark' : 'light';
          document.documentElement.setAttribute('data-theme', next);
          localStorage.setItem('nola_theme', next);
        });
      }
      
    </script>
</body>
</html>
HTML;
    exit;
}

$bodyContent = <<<HTML
    <!-- Alerts (shared across all steps) -->
    <div id="error-alert" class="error-box"></div>
    <div id="success-alert" class="banner-success"><p id="success-alert-text"></p></div>

    <!-- STEP 1 — Email Entry -->
    <div id="step1-wrapper">
        <div class="logo-wrap">
            <img src="https://smspro-api.nolacrm.io/PNG%20-%20NOLA%20SMS%20PRO%20Standard.png" alt="NOLA SMS Pro" class="logo-img">
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
        <p class="footer"><a href="/login">Back to Sign In</a></p>
    </div>

    <!-- STEP 2 — OTP Verification (6 boxes) -->
    <div id="step2-wrapper" class="hidden">
        <div class="logo-wrap">
            <img src="https://smspro-api.nolacrm.io/PNG%20-%20NOLA%20SMS%20PRO%20Standard.png" alt="NOLA SMS Pro" class="logo-img">
        </div>
        <h1>Enter Your Code</h1>
        <p class="subtitle" id="step2-subtitle">We sent a 6-digit code to your email.<br>It expires in 10 minutes.</p>

        <div id="expiry-timer-badge" class="timer-badge">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Expires in <strong id="expiry-countdown">10:00</strong>
        </div>

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

    <!-- STEP 3 — Set New Password -->
    <div id="step3-wrapper" class="hidden">
        <div class="logo-wrap">
            <img src="https://smspro-api.nolacrm.io/PNG%20-%20NOLA%20SMS%20PRO%20Standard.png" alt="NOLA SMS Pro" class="logo-img">
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
            <a href="/login" style="color: #94a3b8; font-weight: 500;">Back to Sign In</a>
        </p>
    </div>
    </main>
    <script>
      var themeBtn = document.getElementById('theme-toggle');
      if (themeBtn) {
        themeBtn.addEventListener('click', function() {
          var cur = document.documentElement.getAttribute('data-theme');
          var next = cur === 'light' ? 'dark' : 'light';
          document.documentElement.setAttribute('data-theme', next);
          localStorage.setItem('nola_theme', next);
        });
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
        function setButtonLoading(btn, isLoading, label) {
            if (!btn) return;
            if (!btn.dataset.defaultHtml) {
                btn.dataset.defaultHtml = btn.innerHTML;
            }
            btn.disabled = isLoading;
            btn.classList.toggle('is-loading', isLoading);
            if (isLoading) {
                btn.setAttribute('aria-busy', 'true');
                btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span>' + label + '</span>';
            } else {
                btn.removeAttribute('aria-busy');
                btn.innerHTML = btn.dataset.defaultHtml;
            }
        }
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

                setButtonLoading(step1Btn, true, 'Sending...');

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
                    setButtonLoading(step1Btn, false);
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

                setButtonLoading(step2Btn, true, 'Verifying...');

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
                    setButtonLoading(step2Btn, false);
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

                setButtonLoading(step3Btn, true, 'Resetting...');

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
                        setButtonLoading(step3Btn, true, 'Redirecting...');
                        setTimeout(function() {
                            window.location.assign('/login?reset_success=1');
                        }, 1800);
                    } else {
                        showError(data.message || 'Failed to reset password. Please go back and re-enter your verification code.');
                        setButtonLoading(step3Btn, false);
                    }
                } catch(err) {
                    showError('Connection failed. Please check your internet connection.');
                    setButtonLoading(step3Btn, false);
                }
            });
        }

      })();
    </script>
HTML;

ifp_page('Reset Password', $bodyContent);
