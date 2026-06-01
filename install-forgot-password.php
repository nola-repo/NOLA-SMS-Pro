<?php
/**
 * install-forgot-password.php
 * Served at: /forgot-password
 *
 * Standalone, beautifully designed page for the 2-step OTP password reset flow.
 * Part of NOLA SMS Pro portal suite.
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
        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.1;
            pointer-events: none;
            z-index: 0;
        }
        .blob-tl {
            top: -10%;
            left: -10%;
            width: 50vw;
            height: 50vw;
            background: #3b82f6;
        }
        .blob-br {
            bottom: -10%;
            right: -10%;
            width: 50vw;
            height: 50vw;
            background: #3b82f6;
        }
        .card {
            max-width: 460px; width: 100%;
            background: rgba(26, 27, 30, 0.7);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border-radius: 28px; padding: 44px 38px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
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
        
        label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin-bottom: 8px; letter-spacing: 0.8px; }
        .field { margin-bottom: 20px; }
        
        .input-wrap { position: relative; }
        .input-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: #64748b; pointer-events: none; transition: color 0.25s ease; z-index: 2; }
        
        input[type=email], input[type=password], input[type=text] {
            width: 100%; padding: 13px 16px 13px 42px; border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.05); background: rgba(0, 0, 0, 0.4);
            font-family: inherit; font-size: 14px; outline: none; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            color: #f4f6fa; position: relative;
        }
        .pw-wrap input[type=password], .pw-wrap input[type=text] {
            padding-right: 48px;
        }
        input:focus { border-color: #3b82f6; background: rgba(0, 0, 0, 0.4); box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.25); }
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
            display: none;
            animation: fadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        .error-box::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #ef4444;
        }
        
        .banner-success {
            background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25);
            border-radius: 16px; padding: 14px 18px; margin-bottom: 24px;
            position: relative; overflow: hidden;
            display: none;
            animation: fadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        .banner-success::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #10b981;
        }
        .banner-success p { font-size: 13px; color: #6ee7b7; line-height: 1.5; font-weight: 500; padding-left: 4px; }
        
        .timer-badge {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 6px 12px;
            font-size: 12px;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: -8px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        .timer-badge strong {
            color: #2b83fa;
            font-family: monospace;
            font-size: 13px;
        }
        .timer-warning strong {
            color: #ef4444 !important;
            animation: pulse 1s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .hidden { display: none !important; }
        .fade-in-up { animation: fadeInUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) both; }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .footer { font-size: 12.5px; color: #94a3b8; text-align: center; margin-top: 24px; font-weight: 500; }
        .footer a { text-decoration: none; transition: color 0.2s; color: #2b83fa; font-weight: 600; }
        .footer a:hover { color: #3b82f6 !important; }
        .footer a.disabled { color: #64748b; cursor: not-allowed; pointer-events: none; }
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
</body>
</html>
HTML;
    exit;
}

$bodyContent = <<<HTML
    <!-- Alerts -->
    <div id="error-alert" class="error-box"></div>
    <div id="success-alert" class="banner-success"><p id="success-alert-text"></p></div>

    <!-- Step 1: Request OTP -->
    <div id="step1-wrapper">
        <h1>Forgot Password</h1>
        <p class="subtitle">Enter your email address to request a 6-digit verification code.</p>
        
        <form id="otp-request-form" novalidate>
            <div class="field">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    <input id="email" name="email" type="email" required placeholder="you@company.com" autocomplete="email">
                </div>
            </div>
            <button id="step1-btn" type="submit" class="btn-submit">
                <span>Send Verification Code</span>
            </button>
        </form>
        <p class="footer">
            <a href="/login">Back to Sign In</a>
        </p>
    </div>

    <!-- Step 2: Verify OTP & Reset Password -->
    <div id="step2-wrapper" class="hidden">
        <h1>Verify Code</h1>
        <p class="subtitle" id="step2-subtitle">Enter the verification code sent to your email.</p>
        
        <div id="expiry-timer-badge" class="timer-badge">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Code expires in: <strong id="expiry-countdown">10:00</strong>
        </div>

        <form id="password-reset-form" novalidate>
            <div class="field">
                <label for="otp">Verification Code</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <input id="otp" name="otp" type="text" required placeholder="6-Digit Code" autocomplete="off" maxlength="6" pattern="\d{6}" style="text-align: center; letter-spacing: 0.35em; font-size: 16px; font-weight: 700; padding-left: 16px; padding-right: 16px;">
                </div>
            </div>

            <div class="field">
                <label for="new_password">New Password</label>
                <div class="input-wrap pw-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input id="new_password" name="new_password" type="password" required placeholder="••••••••" autocomplete="new-password">
                    <button type="button" id="toggle-new-pw" class="pw-toggle" aria-label="Show/hide password">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm New Password</label>
                <div class="input-wrap pw-wrap">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <input id="confirm_password" name="confirm_password" type="password" required placeholder="••••••••" autocomplete="new-password">
                    <button type="button" id="toggle-confirm-pw" class="pw-toggle" aria-label="Show/hide password">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <button id="step2-btn" type="submit" class="btn-submit">Reset Password</button>
        </form>
        <p class="footer" style="margin-top: 24px;">
            <a href="#" id="resend-btn" style="margin-right: 16px;">Resend Code</a>
            <a href="/login" style="color: #94a3b8; font-weight: 500;">Back to Sign In</a>
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

        // Hide/Show password functionality
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

        // OTP text parsing - enforce numbers only and length
        var otpInput = document.getElementById('otp');
        if (otpInput) {
            otpInput.addEventListener('input', function(e) {
                var cleaned = e.target.value.replace(/\D/g, '').substring(0, 6);
                e.target.value = cleaned;
            });
        }

        // State variables
        var registeredEmail = '';
        var expiryInterval = null;
        var cooldownInterval = null;
        var cooldownSeconds = 0;

        var errAlert = document.getElementById('error-alert');
        var succAlert = document.getElementById('success-alert');
        var succText = document.getElementById('success-alert-text');

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
            succAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function clearAlerts() {
            errAlert.style.display = 'none';
            succAlert.style.display = 'none';
        }

        // Expiry Timer countdown
        function startExpiryTimer() {
            if (expiryInterval) clearInterval(expiryInterval);
            var duration = 10 * 60; // 10 minutes
            var timerEl = document.getElementById('expiry-countdown');
            var timerBadge = document.getElementById('expiry-timer-badge');
            
            function updateTimer() {
                var minutes = Math.floor(duration / 60);
                var seconds = duration % 60;
                
                minutes = minutes < 10 ? '0' + minutes : minutes;
                seconds = seconds < 10 ? '0' + seconds : seconds;
                
                timerEl.textContent = minutes + ':' + seconds;

                if (duration <= 60) {
                    timerBadge.classList.add('timer-warning');
                } else {
                    timerBadge.classList.remove('timer-warning');
                }

                if (--duration < 0) {
                    clearInterval(expiryInterval);
                    timerEl.textContent = "00:00";
                    showError("Your verification code has expired. Please resend a new code.");
                }
            }
            updateTimer();
            expiryInterval = setInterval(updateTimer, 1000);
        }

        // Resend Cooldown
        function startResendCooldown() {
            if (cooldownInterval) clearInterval(cooldownInterval);
            cooldownSeconds = 60;
            var resendBtn = document.getElementById('resend-btn');
            resendBtn.classList.add('disabled');
            
            function updateCooldown() {
                if (cooldownSeconds <= 0) {
                    clearInterval(cooldownInterval);
                    resendBtn.classList.remove('disabled');
                    resendBtn.textContent = "Resend Code";
                } else {
                    resendBtn.textContent = "Resend Code (" + cooldownSeconds + "s)";
                    cooldownSeconds--;
                }
            }
            updateCooldown();
            cooldownInterval = setInterval(updateCooldown, 1000);
        }

        // Submit Step 1: Request OTP
        var reqForm = document.getElementById('otp-request-form');
        var step1Btn = document.getElementById('step1-btn');
        if (reqForm) {
            reqForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                clearAlerts();
                
                var emailInput = document.getElementById('email');
                var email = emailInput.value.trim().toLowerCase();
                
                if (!email) {
                    showError("Email address is required.");
                    return;
                }
                
                step1Btn.disabled = true;
                step1Btn.querySelector('span').textContent = 'Sending…';

                try {
                    var res = await fetch('/api/auth/forgot-password-otp', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ email: email })
                    });
                    
                    var data = await res.json().catch(function() { return {}; });

                    // Always transition since backend returns success to prevent user enumeration
                    registeredEmail = email;
                    document.getElementById('step2-subtitle').innerHTML = 'We sent a verification code to:<br><strong>' + email + '</strong>';
                    
                    // Fade out step 1 and fade in step 2
                    var step1Wrap = document.getElementById('step1-wrapper');
                    var step2Wrap = document.getElementById('step2-wrapper');
                    
                    step1Wrap.classList.add('hidden');
                    step2Wrap.classList.remove('hidden');
                    step2Wrap.classList.add('fade-in-up');
                    
                    showSuccess(data.message || "If your email is registered, you will receive an OTP code shortly.");
                    startExpiryTimer();
                    startResendCooldown();
                } catch (err) {
                    showError("Connection failed. Please check your internet connection.");
                } finally {
                    step1Btn.disabled = false;
                    step1Btn.querySelector('span').textContent = 'Send Verification Code';
                }
            });
        }

        // Resend Code Click Handler
        var resendBtn = document.getElementById('resend-btn');
        if (resendBtn) {
            resendBtn.addEventListener('click', async function(e) {
                e.preventDefault();
                if (resendBtn.classList.contains('disabled')) return;
                
                clearAlerts();
                resendBtn.classList.add('disabled');
                resendBtn.textContent = "Sending…";
                
                try {
                    var res = await fetch('/api/auth/forgot-password-otp', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ email: registeredEmail })
                    });
                    
                    var data = await res.json().catch(function() { return {}; });
                    showSuccess("A new verification code has been dispatched. Please check your inbox.");
                    startExpiryTimer();
                    startResendCooldown();
                } catch (err) {
                    showError("Failed to resend code. Please try again.");
                    resendBtn.classList.remove('disabled');
                    resendBtn.textContent = "Resend Code";
                }
            });
        }

        // Submit Step 2: Reset Password
        var resetForm = document.getElementById('password-reset-form');
        var step2Btn = document.getElementById('step2-btn');
        if (resetForm) {
            resetForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                clearAlerts();
                
                var otp = document.getElementById('otp').value.trim();
                var newPassword = document.getElementById('new_password').value;
                var confirmPassword = document.getElementById('confirm_password').value;
                
                if (!otp || otp.length !== 6) {
                    showError("Please enter the 6-digit verification code.");
                    return;
                }
                
                if (!newPassword || newPassword.length < 8) {
                    showError("New password must be at least 8 characters long.");
                    return;
                }
                
                if (newPassword !== confirmPassword) {
                    showError("Passwords do not match.");
                    return;
                }
                
                step2Btn.disabled = true;
                step2Btn.textContent = 'Resetting Password…';
                
                try {
                    var res = await fetch('/api/auth/reset-password-otp', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            email: registeredEmail,
                            otp: otp,
                            new_password: newPassword
                        })
                    });
                    
                    var data = await res.json().catch(function() { return {}; });
                    
                    if (res.ok && data.status === 'success') {
                        // Clear intervals
                        if (expiryInterval) clearInterval(expiryInterval);
                        if (cooldownInterval) clearInterval(cooldownInterval);
                        
                        showSuccess("Password updated successfully! Redirecting to sign in...");
                        setTimeout(function() {
                            window.location.assign('/login?reset_success=1');
                        }, 1500);
                    } else {
                        showError(data.message || "Failed to reset password. Please check your verification code.");
                        step2Btn.disabled = false;
                        step2Btn.textContent = 'Reset Password';
                    }
                } catch (err) {
                    showError("Connection failed. Please check your internet connection.");
                    step2Btn.disabled = false;
                    step2Btn.textContent = 'Reset Password';
                }
            });
        }
      })();
    </script>
HTML;

ifp_page('Reset Password', $bodyContent);
