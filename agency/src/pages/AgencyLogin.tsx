import React, { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { useNavigate } from 'react-router-dom';
import { FiEye, FiEyeOff, FiAlertTriangle, FiLink, FiArrowRight, FiCheckCircle } from 'react-icons/fi';
import { login as authLogin, saveCompanyId, linkCompany, MissingCompanyIdError } from '../services/agencyAuthHelper';
import defaultLogo from '../assets/NOLA SMS PRO Logo.png';
import { useAgency } from '../context/AgencyContext.tsx';

interface AgencyLoginProps {
  darkMode?: boolean;
  toggleDarkMode?: () => void;
}

type LoginPhase = 'credentials' | 'connect_ghl';

const AgencyLogin: React.FC = () => {
  const { darkMode, toggleDarkMode } = useAgency();
  const [phase, setPhase]           = useState<LoginPhase>('credentials');
  const [email, setEmail]           = useState('');
  const [password, setPassword]     = useState('');
  const [showPw, setShowPw]         = useState(false);
  const [loading, setLoading]       = useState(false);
  const [error, setError]           = useState<string | null>(null);

  // Phase 2: manual GHL Company ID
  const [companyId, setCompanyId]   = useState('');
  const [connecting, setConnecting] = useState(false);

  const navigate = useNavigate();
  const primaryColor = '#3b82f6';



  // ── Phase 1: login ──────────────────────────────────────────────────────────
  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      await authLogin(email, password);
      // company_id was in the JWT — go straight to dashboard
      window.location.href = '/';
    } catch (err: any) {
      if (err instanceof MissingCompanyIdError) {
        // Token saved, user is authenticated, but company_id not linked yet
        setPhase('connect_ghl');
      } else {
        setError(err.message || 'Invalid email or password.');
      }
    } finally {
      setLoading(false);
    }
  };

  // ── Phase 2: manual company ID ──────────────────────────────────────────────
  const handleConnect = async (e: React.FormEvent) => {
    e.preventDefault();
    const trimmed = companyId.trim();
    if (!trimmed) {
      setError('Please enter your GHL Company ID.');
      return;
    }
    setConnecting(true);
    setError(null);
    try {
      await linkCompany(trimmed);
      // Small delay so user sees the success state
      await new Promise(r => setTimeout(r, 400));
      window.location.href = '/';
    } catch (err: any) {
      setError(err.message || 'Failed to save Company ID. Please try again.');
      setConnecting(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center relative overflow-hidden bg-gray-50 dark:bg-[#0a0a0b] transition-colors duration-300">

      {/* Background blobs */}
      <div className="absolute top-[-10%] left-[-10%] w-[50%] h-[50%] rounded-full blur-[120px] opacity-20 dark:opacity-10 pointer-events-none" style={{ background: primaryColor }} />
      <div className="absolute bottom-[-10%] right-[-10%] w-[50%] h-[50%] rounded-full blur-[120px] opacity-20 dark:opacity-10 pointer-events-none" style={{ background: primaryColor }} />

      {/* Theme toggle */}
      <button
        onClick={toggleDarkMode}
        className="absolute top-6 right-6 p-2.5 rounded-xl bg-white/50 dark:bg-black/50 backdrop-blur-md border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 shadow-sm hover:bg-white dark:hover:bg-white/10 transition-all z-50"
      >
        {darkMode ? (
          <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
          </svg>
        ) : (
          <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
          </svg>
        )}
      </button>

      <AnimatePresence mode="wait">

        {/* ── Phase 1: Credentials ── */}
        {phase === 'credentials' && (
          <motion.div
            key="credentials"
            initial={{ opacity: 0, scale: 0.95, y: 10 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.96, y: -6 }}
            transition={{ duration: 0.35, ease: 'easeOut' }}
            className="w-full max-w-md p-8 md:p-10 rounded-3xl bg-white/70 dark:bg-[#1a1b1e]/70 backdrop-blur-2xl border border-white/20 dark:border-white/10 shadow-[0_8px_32px_0_rgba(0,0,0,0.05)] dark:shadow-[0_8px_32px_0_rgba(0,0,0,0.4)] z-10"
          >
            <div className="flex flex-col items-center mb-8">
              <img src={defaultLogo} alt="NOLA SMS Pro" className="h-[72px] object-contain mb-4" />
              <h1 className="text-2xl font-bold text-gray-900 dark:text-white mb-1 tracking-tight">Welcome back</h1>
              <p className="text-sm text-gray-500 dark:text-gray-400">Sign in to your Agency account</p>
            </div>

            {error && (
              <motion.div
                initial={{ opacity: 0, y: -10 }}
                animate={{ opacity: 1, y: 0 }}
                className="mb-6 p-4 rounded-xl bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400 text-sm border border-red-100 dark:border-red-500/20 flex items-start gap-2"
              >
                <FiAlertTriangle className="w-4 h-4 mt-0.5 shrink-0" />
                {error}
              </motion.div>
            )}

            <form onSubmit={handleLogin} className="space-y-5">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 ml-1">Email Address</label>
                <input
                  type="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full px-4 py-3.5 rounded-xl bg-gray-100 dark:bg-black/40 border border-transparent dark:border-white/5 focus:border-transparent focus:ring-2 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none transition-all"
                  style={{ '--tw-ring-color': primaryColor } as any}
                  placeholder="you@company.com"
                />
              </div>

              <div>
                <div className="flex items-center justify-between mb-1.5 ml-1 pr-1">
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
                  <a href="#" className="text-xs font-medium hover:underline transition-all" style={{ color: primaryColor }}>Forgot password?</a>
                </div>
                <div className="relative">
                  <input
                    type={showPw ? 'text' : 'password'}
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    className="w-full px-4 py-3.5 pr-11 rounded-xl bg-gray-100 dark:bg-black/40 border border-transparent dark:border-white/5 focus:border-transparent focus:ring-2 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none transition-all"
                    style={{ '--tw-ring-color': primaryColor } as any}
                    placeholder="••••••••"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPw(p => !p)}
                    className="absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
                    tabIndex={-1}
                  >
                    {showPw ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

                <button
                  type="submit"
                  disabled={loading}
                  className="w-full py-3.5 px-4 rounded-xl text-white font-bold shadow-md hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-[#1a1b1e] transition-all disabled:opacity-70 disabled:cursor-not-allowed flex items-center justify-center relative overflow-hidden group btn-new-message"
                >
                  <span className="relative z-10 flex items-center">
                    {loading ? (
                      <>
                        <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Signing in...
                      </>
                    ) : 'Sign In'}
                  </span>
                </button>

            </form>

            <div className="mt-8 pt-6 border-t border-gray-100 dark:border-white/5 text-center space-y-3">
              <p className="text-sm text-gray-500 dark:text-gray-400">
                Don't have an account?{' '}
                <a href="https://app.nolasmspro.com/register?from=agency" className="font-semibold hover:underline text-[#2b83fa]">Register now →</a>
              </p>
              <p className="text-xs text-gray-400 dark:text-gray-500">By signing in, you agree to our Terms of Service and Privacy Policy.</p>
            </div>
          </motion.div>
        )}

        {/* ── Phase 2: Connect GHL (OAuth) ── */}
        {phase === 'connect_ghl' && (
          <motion.div
            key="connect_ghl"
            initial={{ opacity: 0, scale: 0.95, y: 10 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.96, y: -6 }}
            transition={{ duration: 0.35, ease: 'easeOut' }}
            className="w-full max-w-md p-8 md:p-10 rounded-3xl bg-white/70 dark:bg-[#1a1b1e]/70 backdrop-blur-2xl border border-white/20 dark:border-white/10 shadow-[0_8px_32px_0_rgba(0,0,0,0.05)] dark:shadow-[0_8px_32px_0_rgba(0,0,0,0.4)] z-10"
          >
            {/* Header */}
            <div className="flex flex-col items-center mb-8">
              <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] flex items-center justify-center mb-4 shadow-lg shadow-blue-500/20">
                <FiLink className="w-6 h-6 text-white" />
              </div>
              <h1 className="text-2xl font-bold text-gray-900 dark:text-white mb-1 tracking-tight text-center">
                Connect Your GHL Account
              </h1>
              <p className="text-sm text-gray-500 dark:text-gray-400 text-center leading-relaxed">
                One last step — authorize NOLA SMS Pro to access your <strong>GoHighLevel agency</strong> to complete setup.
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

              <a
                href={`https://marketplace.leadconnectorhq.com/oauth/chooselocation?response_type=code&redirect_uri=${encodeURIComponent(import.meta.env.VITE_GHL_REDIRECT_URI ?? 'https://agency.nolasmspro.com/oauth/callback')}&client_id=${import.meta.env.VITE_GHL_CLIENT_ID ?? ''}&version_id=${(import.meta.env.VITE_GHL_CLIENT_ID ?? '').split('-')[0]}&scope=companies.readonly`}
                className="w-full flex items-center justify-center gap-3 py-4 px-6 rounded-2xl font-bold text-white text-[15px] shadow-lg hover:shadow-xl transition-all hover:scale-[1.01] active:scale-[0.99] relative overflow-hidden group"
                style={{ background: 'linear-gradient(135deg, #6366f1 0%, #a855f7 50%, #6366f1 100%)', backgroundSize: '200% 200%' }}
              >
                {/* GHL-like icon */}
                <svg className="w-5 h-5 relative z-10" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/>
                </svg>
                <span className="relative z-10">Connect with GoHighLevel</span>
                <FiArrowRight className="w-4 h-4 ml-auto relative z-10" />
              </a>


            <p className="text-center text-xs text-gray-400 dark:text-gray-500 mt-3">
              You'll be redirected to GoHighLevel to authorize. No password required.
            </p>

            {/* Divider */}
            <div className="flex items-center gap-3 my-6">
              <div className="flex-1 h-px bg-gray-200 dark:bg-white/10" />
              <span className="text-xs text-gray-400 dark:text-gray-500 font-medium">or enter manually</span>
              <div className="flex-1 h-px bg-gray-200 dark:bg-white/10" />
            </div>

            {/* Fallback: manual input */}
            <form onSubmit={handleConnect} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 ml-1">GHL Company ID</label>
                <input
                  type="text"
                  value={companyId}
                  onChange={(e) => setCompanyId(e.target.value)}
                  className="w-full px-4 py-3 rounded-xl bg-gray-100 dark:bg-black/40 border border-transparent dark:border-white/5 focus:border-transparent focus:ring-2 font-mono text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none transition-all text-sm"
                  style={{ '--tw-ring-color': primaryColor } as any}
                  placeholder="e.g. ABC123xyzabc"
                />
              </div>
              <button
                type="submit"
                disabled={connecting || !companyId.trim()}
                className="w-full py-3 px-4 rounded-xl text-white font-semibold text-sm shadow-sm hover:shadow-md focus:outline-none transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                style={{ backgroundColor: primaryColor }}
              >
                {connecting ? (
                  <>
                    <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Saving…
                  </>
                ) : 'Save & Enter Dashboard'}
              </button>
            </form>

            {/* Back link */}
            <div className="mt-5 text-center">
              <button
                onClick={() => { setPhase('credentials'); setError(null); setCompanyId(''); }}
                className="text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors underline underline-offset-2"
              >
                ← Back to sign in
              </button>
            </div>
          </motion.div>
        )}

      </AnimatePresence>
    </div>
  );
};

export default AgencyLogin;
