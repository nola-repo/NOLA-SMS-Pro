import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { motion, AnimatePresence } from 'motion/react';
import { FiCheck, FiEye, FiEyeOff, FiMail, FiPhone, FiLock, FiAlertCircle, FiUser, FiArrowRight, FiArrowLeft, FiBriefcase } from 'react-icons/fi';
import defaultLogo from '../assets/NOLA SMS PRO Logo.png';
import { SESSION_KEYS } from '../services/agencyAuthHelper';
import { safeStorage } from '../utils/safeStorage';
import { sessionSafeStorage } from '../utils/sessionSafeStorage';
import { apiFetch } from '../utils/apiFetch';

// Same-origin relative URLs — nginx proxy handles routing (matches agencyAuthHelper pattern)
const API_BASE = '';

// ── Types ────────────────────────────────────────────────────────────────────
interface InstallData {
  type:         string;
  company_id:   string | null;
  company_name: string | null;
  location_id:  string | null;
}

type Step = 1 | 2 | 3;

// ── Password strength ─────────────────────────────────────────────────────────
const getStrength = (p: string) => {
  let score = 0;
  if (p.length >= 8)               score++;
  if (/[A-Z]/.test(p))             score++;
  if (/[0-9]/.test(p))             score++;
  if (/[^A-Za-z0-9]/.test(p))     score++;
  return score; // 0–4
};

const STRENGTH_LABEL = ['', 'Weak', 'Fair', 'Good', 'Strong'];
const STRENGTH_COLOR = ['', 'bg-red-500', 'bg-amber-400', 'bg-blue-400', 'bg-emerald-500'];

// ── Input component ───────────────────────────────────────────────────────────
const Field: React.FC<{
  label: string;
  icon: React.ReactNode;
  error?: string;
  children: React.ReactNode;
}> = ({ label, icon, error, children }) => (
  <div className="space-y-1.5 w-full">
    <label className="block text-[11.5px] font-bold uppercase tracking-widest text-[#6e6e73] dark:text-[#9aa0a6]">
      {label}
    </label>
    <div className="relative">
      <span className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[#9aa0a6] pointer-events-none z-10">
        {icon}
      </span>
      {children}
    </div>
    {error && (
      <p className="flex items-center gap-1 text-[11.5px] text-red-500 font-medium mt-1">
        <FiAlertCircle className="w-3 h-3 flex-shrink-0" /> {error}
      </p>
    )}
  </div>
);

const inputCls =
  'w-full pl-10 pr-4 py-3 rounded-xl border border-[rgba(0,0,0,0.08)] dark:border-[rgba(255,255,255,0.08)] bg-[#f7f7f7] dark:bg-[#111317] text-[13.5px] text-[#111111] dark:text-white placeholder-[#aaaaaa] dark:placeholder-[#555] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/30 focus:border-[#2b83fa] transition-all relative z-0';

// ── Step indicator ───────────────────────────────────────────────────────────
const StepBar: React.FC<{ step: Step }> = ({ step }) => {
  const steps = ['Details', 'Review', 'Done'];
  const total = steps.length;
  const current = step - 1;

  return (
    <div className="flex items-center justify-center gap-2 mb-8">
      {steps.map((label, i) => {
        const done    = i < current;
        const active  = i === current;
        return (
          <React.Fragment key={label}>
            <div className="flex flex-col items-center gap-1 min-w-[56px]">
              <div className={`w-8 h-8 rounded-full flex items-center justify-center text-[12px] font-bold border-2 transition-all duration-300 ${
                done   ? 'bg-[#2b83fa] border-[#2b83fa] text-white' :
                active ? 'bg-white dark:bg-[#1a1b1e] border-[#2b83fa] text-[#2b83fa]' :
                         'bg-transparent border-[#d1d5db] dark:border-[#3a3b3f] text-[#9aa0a6]'
              }`}>
                {done ? <FiCheck className="w-3.5 h-3.5" /> : <span>{i + 1}</span>}
              </div>
              <span className={`text-[9.5px] font-semibold uppercase tracking-wider leading-tight text-center ${
                active ? 'text-[#2b83fa]' : 'text-[#9aa0a6]'
              }`}>{label}</span>
            </div>
            {i < total - 1 && (
              <div className={`w-12 h-0.5 mt-[-12px] rounded-full transition-all duration-500 ${
                i < current ? 'bg-[#2b83fa]' : 'bg-[#e5e7eb] dark:bg-[#2a2b32]'
              }`} />
            )}
          </React.Fragment>
        );
      })}
    </div>
  );
};

// ── Main Component ────────────────────────────────────────────────────────────
const AgencyRegisterFromInstall: React.FC = () => {
  const navigate       = useNavigate();
  const [params]       = useSearchParams();
  const installToken   = params.get('install_token') ?? '';

  // Install data from backend
  const [installData,    setInstallData]    = useState<InstallData | null>(null);
  const [tokenLoading,   setTokenLoading]   = useState(true);
  const [tokenError,     setTokenError]     = useState<string | null>(null);

  // Step state
  const [step, setStep] = useState<Step>(1);
  const [dir,  setDir]  = useState(1);   // 1 = forward, -1 = backward

  // Form state
  const [form, setForm] = useState({
    firstName: '',
    lastName:  '',
    email:     '',
    phone:     '',
    password:  '',
    confirm:   '',
  });
  const [errors, setErrors]     = useState<Record<string, string>>({});
  const [showPw,  setShowPw]    = useState(false);
  const [showCpw, setShowCpw]   = useState(false);
  const [agreed,  setAgreed]    = useState(false);
  const [loading, setLoading]   = useState(false);
  const [apiError, setApiError] = useState<string | null>(null);

  const strength = getStrength(form.password);

  // ── 1. Verify the install token on mount ──────────────────────────────────
  useEffect(() => {
    if (!installToken) {
      setTokenError('No installation token found. Please reinstall the app from the GHL Marketplace.');
      setTokenLoading(false);
      return;
    }

    const verify = async () => {
      try {
        const res = await apiFetch(`${API_BASE}/api/auth/verify-install-token?token=${encodeURIComponent(installToken)}`);
        
        let data;
        try {
          data = await res.json();
        } catch (e) {
          throw new Error('Invalid API response. The server may be misconfigured.');
        }

        if (!res.ok) throw new Error(data.error || 'Invalid or expired installation link.');
        setInstallData(data as InstallData);
      } catch (err: any) {
        setTokenError(err.message || 'Could not verify installation link.');
      } finally {
        setTokenLoading(false);
      }
    };
    verify();
  }, [installToken]);

  // ── 2. Navigation & Validation ─────────────────────────────────────────────
  const goTo = (next: Step, forward = true) => {
    setDir(forward ? 1 : -1);
    setStep(next);
  };

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => {
    setForm(prev => ({ ...prev, [k]: e.target.value }));
    if (errors[k]) setErrors(prev => { const n = { ...prev }; delete n[k]; return n; });
  };

  const validateStep1 = () => {
    const e: Record<string, string> = {};
    if (!form.firstName.trim())     e.firstName = 'Required';
    if (!form.lastName.trim())      e.lastName  = 'Required';
    if (!form.email.trim())         e.email     = 'Required';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) e.email = 'Invalid email';
    if (!form.phone.trim())         e.phone     = 'Required';
    if (!form.password)             e.password  = 'Required';
    else if (form.password.length < 8) e.password = 'Min 8 chars';
    if (!form.confirm)              e.confirm   = 'Required';
    else if (form.confirm !== form.password) e.confirm = 'Must match';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleNextStep1 = () => {
    if (validateStep1()) goTo(2);
  };

  // ── 3. Submit ─────────────────────────────────────────────────────────────
  const handleSubmit = useCallback(async () => {
    if (!agreed) return;
    setLoading(true);
    setApiError(null);

    try {
      const res = await apiFetch(`${API_BASE}/api/auth/register-from-install`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          full_name:     `${form.firstName.trim()} ${form.lastName.trim()}`,
          email:         form.email.trim().toLowerCase(),
          phone:         form.phone.trim(),
          password:      form.password,
          location_id:   installData?.location_id  ?? null,
          company_id:    installData?.company_id   ?? null,
          install_token: installToken,
        }),
      });

      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Registration failed. Please try again.');

      // Save agency session using the same keys as agencyAuthHelper.login()
      sessionSafeStorage.setItem(SESSION_KEYS.token, data.token);
      safeStorage.setItem(SESSION_KEYS.token, data.token);
      safeStorage.setItem(SESSION_KEYS.role,     'agency');
      if (data.user)       safeStorage.setItem(SESSION_KEYS.user,      JSON.stringify(data.user));
      if (data.company_id) {
        safeStorage.setItem(SESSION_KEYS.companyId, data.company_id);
        safeStorage.setItem('nola_agency_id',        data.company_id);
      }
      localStorage.setItem('nola_agency', JSON.stringify({
        firstName:  data.user?.firstName ?? form.firstName.trim(),
        lastName:   data.user?.lastName  ?? form.lastName.trim(),
        email:      data.user?.email     ?? form.email.trim().toLowerCase(),
        phone:      data.user?.phone     ?? form.phone.trim(),
        company_id: data.company_id      ?? installData?.company_id ?? null,
      }));

      goTo(3); // Success
    } catch (err: any) {
      setApiError(err.message);
    } finally {
      setLoading(false);
    }
  }, [agreed, form, installData, installToken]);

  // ── 4. Auto-redirect from success ────────────────────────────────────────
  useEffect(() => {
    if (step !== 3) return;
    const t = setTimeout(() => { window.location.href = '/'; }, 3500);
    return () => clearTimeout(t);
  }, [step]);

  // ── Render helpers ────────────────────────────────────────────────────────
  const companyDisplay = installData?.company_name ?? 'Your Agency';
  const companyId      = installData?.company_id   ?? null;

  const variants = {
    enter:   (d: number) => ({ x: d > 0 ? 40 : -40, opacity: 0 }),
    center:  { x: 0,  opacity: 1 },
    exit:    (d: number) => ({ x: d > 0 ? -40 : 40, opacity: 0 }),
  };

  if (tokenLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[#f7f8fc] dark:bg-[#0a0a0b]">
        <div className="w-10 h-10 rounded-full border-4 border-[#2b83fa]/20 border-t-[#2b83fa] animate-spin" />
      </div>
    );
  }

  if (tokenError) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-[#f7f8fc] dark:bg-[#0a0a0b] p-4">
        <div className="w-full max-w-md bg-white/80 dark:bg-[#141618]/80 backdrop-blur-2xl border border-white/30 dark:border-white/[0.07] rounded-3xl shadow-2xl p-8 text-center">
          <FiAlertCircle className="w-12 h-12 text-red-500 mx-auto mb-4" />
          <h1 className="text-xl font-bold text-gray-900 dark:text-white mb-2">Invalid Link</h1>
          <p className="text-[13px] text-gray-500 dark:text-[#9aa0a6] mb-6">{tokenError}</p>
          <a href="https://marketplace.leadconnectorhq.com" className="inline-flex items-center px-6 py-3 bg-[#2b83fa] text-white rounded-xl font-bold text-[14px]">
            Go to GHL Marketplace
          </a>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center relative overflow-hidden bg-[#f7f8fc] dark:bg-[#0a0a0b] px-4 py-10">
      <div className="absolute top-[-15%] left-[-10%] w-[55%] h-[55%] rounded-full blur-[120px] opacity-[0.12] pointer-events-none bg-[#2b83fa]" />
      <div className="absolute bottom-[-15%] right-[-10%] w-[50%] h-[50%] rounded-full blur-[120px] opacity-[0.10] pointer-events-none bg-[#7c3aed]" />

      <div className="relative z-10 w-full max-w-lg">
        <div className="bg-white/80 dark:bg-[#141618]/80 backdrop-blur-2xl border border-white/30 dark:border-white/[0.07] rounded-3xl shadow-2xl shadow-black/10 dark:shadow-black/40 p-8 md:p-10 overflow-hidden">
          
          <div className="flex flex-col items-center mb-8 text-center pt-2">
            <img src={defaultLogo} alt="NOLA SMS Pro" className="h-[60px] object-contain mb-3" />
            <div className="text-[11px] font-bold text-violet-600 dark:text-violet-400 uppercase tracking-wider bg-violet-500/10 px-3 py-1 rounded-full flex items-center gap-1.5">
              <FiBriefcase size={12} /> Setup Agency Account
            </div>
          </div>

          <StepBar step={step} />

          <AnimatePresence mode="wait" custom={dir}>
            <motion.div
              key={step}
              custom={dir}
              variants={variants}
              initial="enter"
              animate="center"
              exit="exit"
              transition={{ duration: 0.22, ease: 'easeInOut' }}
            >
              {/* ── Step 1: Details ── */}
              {step === 1 && (
                <div className="space-y-5">
                  <div>
                    <h1 className="text-[22px] font-extrabold text-[#111111] dark:text-white tracking-tight">Your Information</h1>
                    <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">
                      Setting up for <strong className="text-violet-600 dark:text-violet-400">{companyDisplay}</strong>
                    </p>
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <Field label="First Name" icon={<FiUser className="w-4 h-4" />} error={errors.firstName}>
                      <input type="text" value={form.firstName} onChange={set('firstName')} placeholder="John" className={inputCls} />
                    </Field>
                    <Field label="Last Name" icon={<FiUser className="w-4 h-4" />} error={errors.lastName}>
                      <input type="text" value={form.lastName} onChange={set('lastName')} placeholder="Doe" className={inputCls} />
                    </Field>
                  </div>

                  <Field label="Email Address" icon={<FiMail className="w-4 h-4" />} error={errors.email}>
                    <input type="email" value={form.email} onChange={set('email')} placeholder="you@agency.com" className={inputCls} />
                  </Field>

                  <Field label="Phone Number" icon={<FiPhone className="w-4 h-4" />} error={errors.phone}>
                    <input type="tel" value={form.phone} onChange={set('phone')} placeholder="09171234567" className={inputCls} />
                  </Field>

                  <Field label="Password" icon={<FiLock className="w-4 h-4" />} error={errors.password}>
                    <input type={showPw ? 'text' : 'password'} value={form.password} onChange={set('password')} placeholder="At least 8 characters" className={`${inputCls} pr-10`} />
                    <button type="button" onClick={() => setShowPw(p => !p)} className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#6e6e73] z-20 transition-colors">
                      {showPw ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                    </button>
                  </Field>

                  {form.password.length > 0 && (
                    <div className="space-y-1.5 -mt-2">
                      <div className="flex gap-1.5">
                        {[1,2,3,4].map(i => <div key={i} className={`h-1 flex-1 rounded-full transition-all duration-300 ${i <= strength ? STRENGTH_COLOR[strength] : 'bg-[#e5e7eb] dark:bg-[#2a2b32]'}`} />)}
                      </div>
                      <p className="text-[11px] text-[#9aa0a6] font-medium">
                        Password strength: <span className={`font-bold ${strength >= 3 ? 'text-emerald-500' : strength >= 2 ? 'text-blue-400' : 'text-amber-500'}`}>{STRENGTH_LABEL[strength]}</span>
                      </p>
                    </div>
                  )}

                  <Field label="Confirm Password" icon={<FiLock className="w-4 h-4" />} error={errors.confirm}>
                    <input type={showCpw ? 'text' : 'password'} value={form.confirm} onChange={set('confirm')} placeholder="Re-enter password" className={`${inputCls} pr-10`} />
                    <button type="button" onClick={() => setShowCpw(p => !p)} className="absolute right-3 top-1/2 -translate-y-1/2 text-[#9aa0a6] hover:text-[#6e6e73] z-20 transition-colors">
                      {showCpw ? <FiEyeOff className="w-4 h-4" /> : <FiEye className="w-4 h-4" />}
                    </button>
                  </Field>

                  <div className="pt-2">
                    <button onClick={handleNextStep1} className="w-full flex items-center justify-center gap-2 py-3 rounded-xl text-white font-bold text-[14px] shadow-md shadow-[#2b83fa]/30 hover:shadow-lg hover:shadow-[#2b83fa]/40 transition-all btn-new-message">
                      Review <FiArrowRight />
                    </button>
                  </div>
                </div>
              )}

              {/* ── Step 2: Review ── */}
              {step === 2 && (
                <div className="space-y-6">
                  <div>
                    <h1 className="text-[22px] font-extrabold text-[#111111] dark:text-white tracking-tight">Review Details</h1>
                    <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] mt-1">Please confirm your information.</p>
                  </div>

                  <div className="bg-[#f7f7f7] dark:bg-[#111317] border border-[rgba(0,0,0,0.08)] dark:border-[rgba(255,255,255,0.08)] rounded-2xl p-5 space-y-4">
                    {[
                      { label: 'Name',       value: `${form.firstName} ${form.lastName}` },
                      { label: 'Email',      value: form.email },
                      { label: 'Phone',      value: form.phone },
                      { label: 'Company',    value: companyDisplay, highlight: true },
                    ].map(({ label, value, highlight }) => (
                      <div key={label} className="flex justify-between items-center text-[13px]">
                        <span className="text-[#6e6e73] dark:text-[#9aa0a6] font-semibold">{label}</span>
                        <span className={`font-bold truncate max-w-[65%] text-right ${highlight ? 'text-violet-600 dark:text-violet-400' : 'text-[#111111] dark:text-white'}`}>{value}</span>
                      </div>
                    ))}
                  </div>

                  <label className="flex items-start gap-3 cursor-pointer group">
                    <div className={`w-5 h-5 rounded-md border-2 flex items-center justify-center shrink-0 mt-0.5 transition-all ${agreed ? 'bg-[#2b83fa] border-[#2b83fa]' : 'border-[#d1d5db] dark:border-[#3a3b3f] group-hover:border-[#2b83fa]/50'}`} onClick={() => setAgreed(a => !a)}>
                      {agreed && <FiCheck className="w-3 h-3 text-white" />}
                    </div>
                    <span className="text-[12.5px] text-[#6e6e73] dark:text-[#9aa0a6] leading-relaxed">
                      I agree to the <a href="#" className="text-[#2b83fa] font-bold hover:underline">Terms of Service</a> and <a href="#" className="text-[#2b83fa] font-bold hover:underline">Privacy Policy</a>.
                    </span>
                  </label>

                  {apiError && (
                    <motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} className="flex items-start gap-2.5 p-3.5 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 text-red-600 dark:text-red-400 text-[12.5px] font-medium">
                      <FiAlertCircle className="w-4 h-4 flex-shrink-0 mt-0.5" /> {apiError}
                    </motion.div>
                  )}

                  <div className="flex gap-3 pt-2">
                    <button onClick={() => goTo(1, false)} className="flex items-center gap-1.5 px-5 py-3 rounded-xl border border-[#e5e7eb] dark:border-[#2a2b32] text-[13px] font-semibold text-[#6e6e73] dark:text-[#9aa0a6] hover:bg-[#f7f7f7] dark:hover:bg-[#1a1b1e] transition-colors">
                      <FiArrowLeft className="w-4 h-4" /> Edit
                    </button>
                    <button onClick={handleSubmit} disabled={!agreed || loading} className="flex-1 flex items-center justify-center gap-2 py-3 rounded-xl text-white font-bold text-[14px] shadow-md shadow-[#2b83fa]/30 hover:shadow-lg hover:shadow-[#2b83fa]/40 transition-all disabled:opacity-50 disabled:cursor-not-allowed btn-new-message">
                      {loading ? (<><svg className="animate-spin w-4 h-4 text-white" viewBox="0 0 24 24" fill="none"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>Creating…</>) : (<>Create Account <FiArrowRight /></>)}
                    </button>
                  </div>
                </div>
              )}

              {/* ── Step 3: Success ── */}
              {step === 3 && (
                <div className="flex flex-col items-center text-center space-y-6 py-4">
                  <motion.div initial={{ scale: 0 }} animate={{ scale: 1 }} transition={{ type: 'spring', stiffness: 300, damping: 20 }} className="w-20 h-20 rounded-full bg-gradient-to-br from-emerald-400 to-emerald-600 flex items-center justify-center shadow-lg shadow-emerald-500/30">
                    <FiCheck className="w-9 h-9 text-white stroke-[2.5]" />
                  </motion.div>
                  <div>
                    <h1 className="text-[24px] font-extrabold text-[#111111] dark:text-white tracking-tight">Account Created!</h1>
                    <p className="text-[13.5px] text-[#6e6e73] dark:text-[#9aa0a6] mt-2 leading-relaxed max-w-sm mx-auto">
                      Welcome to NOLA SMS Pro. Your agency account is ready for <strong className="text-[#111111] dark:text-white">{companyDisplay}</strong>.
                    </p>
                  </div>
                  <div className="flex flex-col items-center gap-3 w-full pt-2">
                    <button onClick={() => { window.location.href = '/'; }} className="w-full flex items-center justify-center gap-2 py-3.5 rounded-xl text-white font-bold text-[14px] shadow-md shadow-[#2b83fa]/30 hover:shadow-lg hover:shadow-[#2b83fa]/40 transition-all btn-new-message">
                      Go to Agency Dashboard <FiArrowRight />
                    </button>
                  </div>
                </div>
              )}

            </motion.div>
          </AnimatePresence>
        </div>
      </div>
    </div>
  );
};

export default AgencyRegisterFromInstall;
