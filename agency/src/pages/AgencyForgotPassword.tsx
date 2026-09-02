import React, { useState, useEffect, useRef } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { useNavigate } from 'react-router-dom';
import { FiEye, FiEyeOff, FiAlertTriangle, FiArrowLeft, FiMail, FiLock, FiShield, FiClock, FiRefreshCw, FiCheckCircle, FiArrowRight } from 'react-icons/fi';
import { requestPasswordOtp, resetPasswordWithOtp } from '../services/agencyAuthHelper';
import defaultLogo from '../assets/NOLA SMS PRO Logo.png';
import { useAgency } from '../context/AgencyContext.tsx';
import { useToast } from '../hooks/useToast';
import { ToastContainer } from '../components/ui/ToastContainer';

const formatCountdown = (seconds: number) => {
  const mins = Math.floor(seconds / 60).toString().padStart(2, '0');
  const secs = (seconds % 60).toString().padStart(2, '0');
  return `${mins}:${secs}`;
};

type ForgotPhase = 'forgot_request' | 'forgot_verify' | 'forgot_change_password';



const Card: React.FC<{ children: React.ReactNode; motionKey: string }> = ({ children, motionKey }) => (
  <motion.div
    key={motionKey}
    initial={{ opacity: 0, scale: 0.95, y: 10 }}
    animate={{ opacity: 1, scale: 1, y: 0 }}
    exit={{ opacity: 0, scale: 0.96, y: -6 }}
    transition={{ duration: 0.32, ease: 'easeOut' }}
    className="w-full max-w-md p-8 md:p-10 rounded-3xl bg-white/70 dark:bg-[#1a1b1e]/70 backdrop-blur-2xl border border-white/20 dark:border-white/10 shadow-[0_8px_32px_0_rgba(0,0,0,0.05)] dark:shadow-[0_8px_32px_0_rgba(0,0,0,0.4)] z-10"
  >
    {children}
  </motion.div>
);

const AgencyForgotPassword: React.FC = () => {
  const { darkMode, toggleDarkMode } = useAgency();
  const [phase, setPhase] = useState<ForgotPhase>('forgot_request');
  const [error, setError] = useState<string | null>(null);

  // Step 1
  const [resetEmail, setResetEmail] = useState('');

  // Step 2
  const [otpDigits, setOtpDigits]       = useState<string[]>(Array(6).fill(''));
  const [expiresIn, setExpiresIn]       = useState(600);
  const [resendCooldown, setResendCooldown] = useState(0);
  const [verifiedOtp, setVerifiedOtp]   = useState('');
  const otpRefs = useRef<Array<HTMLInputElement | null>>([]);

  // Step 3
  const [newPassword, setNewPassword]         = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showNewPw, setShowNewPw]             = useState(false);
  const [showConfirmPw, setShowConfirmPw]     = useState(false);

  const [loading, setLoading] = useState(false);
  const { toasts, showToast, dismissToast } = useToast();
  const navigate    = useNavigate();
  const primaryColor = '#3b82f6';

  // Countdown timer (only active in step 2)
  useEffect(() => {
    if (phase !== 'forgot_verify') return;
    const timer = window.setInterval(() => {
      setExpiresIn(prev => Math.max(0, prev - 1));
      setResendCooldown(prev => Math.max(0, prev - 1));
    }, 1000);
    return () => window.clearInterval(timer);
  }, [phase]);

  // ── Step 1: Request OTP ────────────────────────────────────────────────────
  const handleRequestOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    const trimmedEmail = resetEmail.trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedEmail)) {
      setError('Please enter a valid email address.');
      return;
    }
    setLoading(true);
    setError(null);
    try {
      await requestPasswordOtp(trimmedEmail);
      setResetEmail(trimmedEmail);
      setOtpDigits(Array(6).fill(''));
      setVerifiedOtp('');
      setExpiresIn(600);
      setResendCooldown(60);
      setPhase('forgot_verify');
      showToast('Verification code sent.', 'success');
      window.setTimeout(() => otpRefs.current[0]?.focus(), 120);
    } catch (err: any) {
      setError(err.message || 'Could not send verification code.');
    } finally {
      setLoading(false);
    }
  };

  const handleResendOtp = async () => {
    if (resendCooldown > 0 || loading) return;
    setLoading(true);
    setError(null);
    try {
      await requestPasswordOtp(resetEmail);
      setOtpDigits(Array(6).fill(''));
      setVerifiedOtp('');
      setExpiresIn(600);
      setResendCooldown(60);
      showToast('New verification code sent.', 'success');
      window.setTimeout(() => otpRefs.current[0]?.focus(), 120);
    } catch (err: any) {
      setError(err.message || 'Could not resend verification code.');
    } finally {
      setLoading(false);
    }
  };

  // ── Step 2: OTP input helpers ──────────────────────────────────────────────
  const handleOtpChange = (index: number, value: string) => {
    const digits = value.replace(/\D/g, '');
    const next = [...otpDigits];
    if (!digits) { next[index] = ''; setOtpDigits(next); return; }
    digits.slice(0, 6 - index).split('').forEach((d, off) => { next[index + off] = d; });
    setOtpDigits(next);
    otpRefs.current[Math.min(index + digits.length, 5)]?.focus();
  };

  const handleOtpKeyDown = (index: number, e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Backspace' && !otpDigits[index] && index > 0) otpRefs.current[index - 1]?.focus();
    if (e.key === 'ArrowLeft'  && index > 0) otpRefs.current[index - 1]?.focus();
    if (e.key === 'ArrowRight' && index < 5) otpRefs.current[index + 1]?.focus();
  };

  const handleOtpPaste = (index: number, e: React.ClipboardEvent<HTMLInputElement>) => {
    const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6 - index);
    if (!pasted) return;
    e.preventDefault();
    handleOtpChange(index, pasted);
  };

  // Step 2 → Step 3: Validate OTP, store it, advance
  const handleOtpContinue = (e: React.FormEvent) => {
    e.preventDefault();
    const otp = otpDigits.join('');
    if (otp.length !== 6) { setError('Enter the 6-digit verification code.'); return; }
    if (expiresIn <= 0)   { setError('OTP code has expired. Please resend a new code.'); return; }
    setVerifiedOtp(otp);
    setError(null);
    setNewPassword('');
    setConfirmPassword('');
    setShowNewPw(false);
    setShowConfirmPw(false);
    setPhase('forgot_change_password');
  };

  // ── Step 3: Reset password ─────────────────────────────────────────────────
  const handleResetPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (newPassword.length < 8)       { setError('Password must be at least 8 characters long.'); return; }
    if (newPassword !== confirmPassword) { setError('Passwords do not match.'); return; }

    setLoading(true);
    setError(null);
    try {
      await resetPasswordWithOtp(resetEmail, verifiedOtp, newPassword);
      navigate('/login', { state: { resetSuccess: 'Password updated. Sign in with your new password.' } });
    } catch (err: any) {
      // OTP may have been rejected by server; send back to OTP step
      setError(err.message || 'Could not reset password.');
      if ((err.message || '').toLowerCase().includes('otp') || (err.message || '').toLowerCase().includes('expired')) {
        setVerifiedOtp('');
        setOtpDigits(Array(6).fill(''));
        setPhase('forgot_verify');
      }
    } finally {
      setLoading(false);
    }
  };





  return (
    <div className="min-h-screen flex items-center justify-center relative overflow-x-hidden bg-gray-50 dark:bg-[#0a0a0b] px-4 py-8 transition-colors duration-300">
      <ToastContainer toasts={toasts} onDismiss={dismissToast} />

      <div className="absolute top-[-10%] left-[-10%] w-[50%] h-[50%] rounded-full blur-[120px] opacity-20 dark:opacity-10 pointer-events-none" style={{ background: primaryColor }} />
      <div className="absolute bottom-[-10%] right-[-10%] w-[50%] h-[50%] rounded-full blur-[120px] opacity-20 dark:opacity-10 pointer-events-none" style={{ background: primaryColor }} />

      {/* Theme toggle */}
      <button
        onClick={toggleDarkMode}
        className="absolute top-6 right-6 p-2.5 rounded-xl bg-white/50 dark:bg-black/50 backdrop-blur-md border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 shadow-sm hover:bg-white dark:hover:bg-white/10 transition-all z-50"
      >
        {darkMode ? (
          <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
        ) : (
          <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
        )}
      </button>



      <AnimatePresence mode="wait">

        {/* ── Step 1: Enter email ── */}
        {phase === 'forgot_request' && (
          <Card motionKey="forgot_request">

            <div className="flex flex-col items-center mb-8">
              <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] flex items-center justify-center mb-4 shadow-lg shadow-blue-500/20">
                <FiMail className="w-6 h-6 text-white" />
              </div>
              <h1 className="text-2xl font-bold text-gray-900 dark:text-white mb-1 tracking-tight text-center">Forgot password?</h1>
              <p className="text-sm text-gray-500 dark:text-gray-400 text-center leading-relaxed">
                Enter your Agency email and we'll send a verification code.
              </p>
            </div>

            {error && (
              <motion.div
                initial={{ opacity: 0, y: -10 }}
                animate={{ opacity: 1, y: 0 }}
                className="mb-5 p-4 rounded-xl bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400 text-sm border border-red-100 dark:border-red-500/20 flex items-start gap-2"
              >
                <FiAlertTriangle className="w-4 h-4 mt-0.5 shrink-0" />
                {error}
              </motion.div>
            )}

            <form onSubmit={handleRequestOtp} className="space-y-5">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 ml-1">Email Address</label>
                <input
                  type="email" required autoFocus value={resetEmail}
                  onChange={(e) => { setResetEmail(e.target.value); if (error) setError(null); }}
                  className="w-full px-4 py-3.5 rounded-xl bg-gray-100 dark:bg-black/40 border border-transparent dark:border-white/5 focus:border-transparent focus:ring-2 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none transition-all"
                  style={{ '--tw-ring-color': primaryColor } as any}
                  placeholder="you@company.com" autoComplete="email"
                />
              </div>

              <button
                type="submit" disabled={loading || !resetEmail.trim()}
                className="w-full py-3.5 px-4 rounded-xl text-white font-bold shadow-md hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-[#1a1b1e] transition-all disabled:opacity-70 disabled:cursor-not-allowed flex items-center justify-center gap-2 btn-new-message"
              >
                {loading ? (
                  <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                ) : <><FiMail className="w-4 h-4" /> Send Verification Code</>}
              </button>
            </form>

            <div className="mt-5 text-center">
              <button type="button" onClick={() => navigate('/login')}
                className="inline-flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors underline underline-offset-2"
              >
                <FiArrowLeft className="w-3.5 h-3.5" /> Back to sign in
              </button>
            </div>
          </Card>
        )}

        {/* ── Step 2: Enter OTP ── */}
        {phase === 'forgot_verify' && (
          <Card motionKey="forgot_verify">

            <div className="flex flex-col items-center mb-8">
              <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] flex items-center justify-center mb-4 shadow-lg shadow-blue-500/20">
                <FiShield className="w-6 h-6 text-white" />
              </div>
              <h1 className="text-2xl font-bold text-gray-900 dark:text-white mb-1 tracking-tight text-center">Enter verification code</h1>
              <p className="text-sm text-gray-500 dark:text-gray-400 text-center leading-relaxed">
                We sent a 6-digit code to <span className="font-semibold text-gray-700 dark:text-gray-200">{resetEmail}</span>
              </p>
            </div>

            {error && (
              <motion.div
                initial={{ opacity: 0, y: -10 }}
                animate={{ opacity: 1, y: 0 }}
                className="mb-5 p-4 rounded-xl bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400 text-sm border border-red-100 dark:border-red-500/20 flex items-start gap-2"
              >
                <FiAlertTriangle className="w-4 h-4 mt-0.5 shrink-0" />
                {error}
              </motion.div>
            )}

            <form onSubmit={handleOtpContinue} className="space-y-6">
              <div>
                <div className="flex items-center justify-between mb-3 ml-1 pr-1">
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Verification Code</label>
                  <span className={`inline-flex items-center gap-1 text-[11px] font-semibold ${expiresIn <= 0 ? 'text-red-500 dark:text-red-400' : 'text-gray-400 dark:text-gray-500'}`}>
                    <FiClock className="w-3.5 h-3.5" />
                    {formatCountdown(expiresIn)}
                  </span>
                </div>
                <div className="grid grid-cols-6 gap-2.5">
                  {otpDigits.map((digit, index) => (
                    <input
                      key={index}
                      ref={(el) => { otpRefs.current[index] = el; }}
                      type="text" inputMode="numeric" pattern="[0-9]*" maxLength={1}
                      value={digit}
                      onChange={(e) => handleOtpChange(index, e.target.value)}
                      onKeyDown={(e) => handleOtpKeyDown(index, e)}
                      onPaste={(e) => handleOtpPaste(index, e)}
                      autoComplete={index === 0 ? 'one-time-code' : 'off'}
                      aria-label={`Verification digit ${index + 1}`}
                      className="aspect-square w-full rounded-xl bg-gray-100 dark:bg-black/40 border-2 border-transparent dark:border-white/5 focus:border-[#2b83fa] dark:focus:border-[#2b83fa] text-center text-xl font-bold text-gray-900 dark:text-white focus:outline-none transition-all"
                    />
                  ))}
                </div>
                <div className="mt-3 flex items-center justify-between gap-3 text-xs">
                  <span className="text-gray-400 dark:text-gray-500">
                    {resendCooldown > 0 ? `Resend in ${formatCountdown(resendCooldown)}` : 'Didn\'t receive a code?'}
                  </span>
                  <button type="button" onClick={handleResendOtp} disabled={resendCooldown > 0 || loading}
                    className="inline-flex items-center gap-1.5 font-semibold text-[#2b83fa] hover:text-[#1d6bd4] disabled:text-gray-300 dark:disabled:text-gray-600 disabled:cursor-not-allowed transition-colors"
                  >
                    <FiRefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
                    Resend Code
                  </button>
                </div>
              </div>

              <button
                type="submit" disabled={otpDigits.join('').length !== 6 || expiresIn <= 0}
                className="w-full py-3.5 px-4 rounded-xl text-white font-bold shadow-md hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-[#1a1b1e] transition-all disabled:opacity-70 disabled:cursor-not-allowed flex items-center justify-center gap-2 btn-new-message"
              >
                <FiArrowRight className="w-4 h-4" />
                Continue
              </button>
            </form>

            <div className="mt-5 text-center">
              <button type="button" onClick={() => { setPhase('forgot_request'); setError(null); }}
                className="inline-flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors underline underline-offset-2"
              >
                <FiArrowLeft className="w-3.5 h-3.5" /> Change email
              </button>
            </div>
          </Card>
        )}

        {/* ── Step 3: New password ── */}
        {phase === 'forgot_change_password' && (
          <Card motionKey="forgot_change_password">

            <div className="flex flex-col items-center mb-8">
              <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] flex items-center justify-center mb-4 shadow-lg shadow-blue-500/20">
                <FiLock className="w-6 h-6 text-white" />
              </div>
              <h1 className="text-2xl font-bold text-gray-900 dark:text-white mb-1 tracking-tight text-center">Set new password</h1>
              <p className="text-sm text-gray-500 dark:text-gray-400 text-center leading-relaxed">
                Choose a strong password for your account.
              </p>
            </div>

            {error && (
              <motion.div
                initial={{ opacity: 0, y: -10 }}
                animate={{ opacity: 1, y: 0 }}
                className="mb-5 p-4 rounded-xl bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400 text-sm border border-red-100 dark:border-red-500/20 flex items-start gap-2"
              >
                <FiAlertTriangle className="w-4 h-4 mt-0.5 shrink-0" />
                {error}
              </motion.div>
            )}

            <form onSubmit={handleResetPassword} className="space-y-5">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 ml-1">New Password</label>
                <div className="relative">
                  <input
                    type={showNewPw ? 'text' : 'password'} required minLength={8} autoFocus
                    value={newPassword}
                    onChange={(e) => { setNewPassword(e.target.value); if (error) setError(null); }}
                    className="w-full px-4 py-3.5 pr-11 rounded-xl bg-gray-100 dark:bg-black/40 border border-transparent dark:border-white/5 focus:border-transparent focus:ring-2 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none transition-all"
                    style={{ '--tw-ring-color': primaryColor } as any}
                    placeholder="At least 8 characters" autoComplete="new-password"
                  />
                  <button type="button" onClick={() => setShowNewPw(p => !p)}
                    className="absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors" tabIndex={-1}
                    aria-label="Toggle password visibility"
                  >
                    {showNewPw ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 ml-1">Confirm New Password</label>
                <div className="relative">
                  <input
                    type={showConfirmPw ? 'text' : 'password'} required minLength={8}
                    value={confirmPassword}
                    onChange={(e) => { setConfirmPassword(e.target.value); if (error) setError(null); }}
                    className="w-full px-4 py-3.5 pr-11 rounded-xl bg-gray-100 dark:bg-black/40 border border-transparent dark:border-white/5 focus:border-transparent focus:ring-2 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none transition-all"
                    style={{ '--tw-ring-color': primaryColor } as any}
                    placeholder="Re-enter password" autoComplete="new-password"
                  />
                  <button type="button" onClick={() => setShowConfirmPw(p => !p)}
                    className="absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors" tabIndex={-1}
                    aria-label="Toggle confirm password visibility"
                  >
                    {showConfirmPw ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <button
                type="submit" disabled={loading || !newPassword || !confirmPassword}
                className="w-full py-3.5 px-4 rounded-xl text-white font-bold shadow-md hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-[#1a1b1e] transition-all disabled:opacity-70 disabled:cursor-not-allowed flex items-center justify-center gap-2 btn-new-message"
              >
                {loading ? (
                  <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                ) : <><FiCheckCircle className="w-4 h-4" /> Reset Password</>}
              </button>
            </form>

            <div className="mt-5 text-center">
              <button type="button" onClick={() => { setPhase('forgot_verify'); setError(null); }}
                className="inline-flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors underline underline-offset-2"
              >
                <FiArrowLeft className="w-3.5 h-3.5" /> Back to code entry
              </button>
            </div>
          </Card>
        )}

      </AnimatePresence>
    </div>
  );
};

export default AgencyForgotPassword;
