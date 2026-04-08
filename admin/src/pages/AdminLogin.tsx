// @ts-nocheck
import React, { useState, useEffect, useCallback } from 'react';
import { FiUsers, FiSend, FiSettings, FiLogOut, FiLock, FiAlertCircle, FiEye, FiEyeOff, FiCopy, FiCheck, FiX, FiRefreshCw, FiKey, FiHome, FiClock, FiActivity, FiMessageSquare, FiCreditCard, FiShield, FiPlus, FiMinus, FiTrash2, FiChevronLeft, FiChevronRight, FiSearch, FiSun, FiMoon, FiMoreVertical, FiToggleLeft } from 'react-icons/fi';
import logoUrl from '../assets/NOLA SMS PRO Logo.png';
import Antigravity from '../components/ui/Antigravity';

const ADMIN_API = '/api/admin_sender_requests.php';
const POLL_INTERVAL = 15000; // 15 seconds real-time sync

// ─── Types ───────────────────────────────────────────────────────────────────

interface SenderRequest {
    id: string;
    location_id: string;
    requested_id: string;
    purpose?: string;
    sample_message?: string;
    status: 'pending' | 'approved' | 'rejected';
    rejection_note?: string;
    created_at?: string;
    location_name?: string;
}

interface Account {
    id: string;
    location_id: string;
    location_name?: string;
    approved_sender_id?: string;
    nola_pro_api_key?: string;
    api_key?: string;
    semaphore_api_key?: string;
    credits?: number;
    credit_balance?: number;
    free_usage_count?: number;
    free_credits_total?: number;
}

interface AdminLayoutProps {
    darkMode: boolean;
    toggleDarkMode: () => void;
}

// ─── Admin Login ─────────────────────────────────────────────────────────────


export const AdminLogin: React.FC<{ onLogin: (username: string) => void }> = ({ onLogin }) => {
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [error, setError] = useState(false);
    const [loading, setLoading] = useState(false);
    const passwordRef = React.useRef<HTMLInputElement>(null);
    
    // Forgot Password Flow States
    const [view, setView] = useState<'login' | 'forgot'>('login');
    const [forgotUsername, setForgotUsername] = useState('');
    const [forgotSuccess, setForgotSuccess] = useState(false);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError(false);

        try {
            const res = await fetch('/api/admin_auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });

            // If the endpoint isn't built yet, it might return a 404 HTML page or fail parsing
            if (!res.ok) {
                throw new Error('API not available, fallback to hardcoded');
            }

            const json = await res.json();
            if (json.status === 'success') {
                onLogin(username);
            } else {
                setError(true);
            }
        } catch (err) {
            // Fallback for seamless dev transition before backend is deployed
            if (username === 'admin' && password === 'admin123') {
                onLogin(username);
            } else {
                setError(true);
            }
        } finally {
            setLoading(false);
        }
    };

    const handleForgotSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError(false);
        try {
            await fetch('/api/admin_forgot_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: forgotUsername })
            });
            // We gently fall back on success even if it errors to allow seamless frontend dev flow
            setForgotSuccess(true);
        } catch (err) {
            // Still show success to prevent user enumeration
            setForgotSuccess(true);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="relative min-h-screen grid place-items-center p-4 overflow-hidden bg-[#f7f7f7] dark:bg-[#111111]">
            <div className="absolute inset-0 z-0">
                <Antigravity
                    count={300}
                    magnetRadius={6}
                    ringRadius={7}
                    waveSpeed={0.4}
                    waveAmplitude={1}
                    particleSize={1}
                    lerpSpeed={0.05}
                    color="#1e57a1"
                    autoAnimate
                    particleVariance={1}
                    rotationSpeed={0}
                    depthFactor={1}
                    pulseSpeed={3}
                    particleShape="capsule"
                    fieldStrength={10}
                />
            </div>
            <div className="relative z-10 w-full max-w-md bg-white/80 dark:bg-[#1a1b1e]/80 backdrop-blur-2xl rounded-2xl shadow-2xl border border-white/40 dark:border-white/10 p-8 animate-in zoom-in-95 duration-200">
                <div className="flex flex-col items-center mb-8">
                    <img src={logoUrl} alt="NOLA SMS Pro Logo" className="w-32 h-32 mb-2 object-contain" />
                    {view === 'login' ? (
                        <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] text-center">
                            Enter your credentials to access NOLA SMS Pro admin dashboard.
                        </p>
                    ) : (
                        <p className="text-[16px] font-bold text-[#111111] dark:text-white text-center mt-2">
                            Reset Password
                        </p>
                    )}
                </div>

                {view === 'forgot' ? (
                    <form onSubmit={handleForgotSubmit} className="admin-login-fields space-y-6 w-full">
                        {forgotSuccess ? (
                            <div className="flex flex-col items-center gap-4 text-center animate-in zoom-in-95 duration-200">
                                <div className="w-14 h-14 rounded-full bg-emerald-500/10 flex items-center justify-center">
                                    <FiCheck className="w-6 h-6 text-emerald-400" />
                                </div>
                                <p className="text-[14px] text-[#6e6e73] dark:text-[#9aa0a6]">
                                    If an admin with that identifier exists, a secure password reset link has been dispatched to the registered email address.
                                </p>
                                <button
                                    type="button"
                                    onClick={() => { setView('login'); setForgotSuccess(false); setForgotUsername(''); }}
                                    className="mt-4 text-[14px] font-bold text-[#2b83fa] hover:text-[#1d6bd4] transition-colors"
                                >
                                    Back to Log In
                                </button>
                            </div>
                        ) : (
                            <div className="animate-in fade-in duration-200">
                                <p className="text-[13px] text-[#6e6e73] dark:text-[#9aa0a6] text-center mb-6">
                                    Enter your admin username or email and we'll send you a link to reset your password.
                                </p>
                                <div className="space-y-2.5 w-full">
                                    <label className="block text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest ml-1">USERNAME OR EMAIL</label>
                                    <input
                                        type="text"
                                        autoFocus
                                        value={forgotUsername}
                                        onChange={(e) => { setForgotUsername(e.target.value); if (error) setError(false); }}
                                        placeholder="e.g. admin"
                                        className="w-full px-5 py-4 rounded-[18px] border border-gray-200 dark:border-white/5 bg-[#f7f7f7] dark:bg-[#0a0b0d] text-[15px] font-sans font-medium text-[#111111] dark:text-white placeholder-gray-400 dark:placeholder-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500/40 transition-all shadow-inner"
                                    />
                                </div>
                                <div className="flex flex-col gap-3 pt-6">
                                    <button
                                        type="submit"
                                        disabled={!forgotUsername.trim() || loading}
                                        className="flex items-center justify-center gap-2 w-full py-4 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-[16px] font-bold text-[16px] transition-all shadow-md shadow-blue-500/20 active:scale-[0.98] disabled:opacity-30 disabled:pointer-events-none disabled:shadow-none"
                                    >
                                        {loading ? <FiRefreshCw className="w-5 h-5 animate-spin" /> : 'Send Reset Link'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => { setView('login'); setError(false); }}
                                        className="w-full py-3 text-[14px] font-bold text-[#6e6e73] hover:text-[#111111] dark:text-[#9aa0a6] dark:hover:text-white transition-colors"
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        )}
                    </form>
                ) : (
                    <form onSubmit={handleSubmit} className="admin-login-fields space-y-8 animate-in fade-in duration-200">
                        {error && (
                            <div className="flex items-center gap-3 px-4 py-3 rounded-2xl bg-red-500/10 border border-red-500/20">
                                <FiAlertCircle className="w-4 h-4 text-red-500 dark:text-red-400 flex-shrink-0" />
                                <p className="text-[12px] font-medium text-red-600 dark:text-red-300">Incorrect username or password.</p>
                            </div>
                        )}
                        
                        <div className="space-y-2.5">
                            <label className="block text-[11px] font-bold text-gray-500 uppercase tracking-widest ml-1">USERNAME</label>
                            <input
                                type="text"
                                autoFocus
                                value={username}
                                onChange={(e) => { setUsername(e.target.value); if (error) setError(false); }}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        passwordRef.current?.focus();
                                    }
                                }}
                                placeholder="e.g. admin"
                                className="w-full px-5 py-4 rounded-[18px] border border-gray-200 dark:border-white/5 bg-[#f7f7f7] dark:bg-[#0a0b0d] text-[15px] font-sans font-medium text-[#111111] dark:text-white placeholder-gray-400 dark:placeholder-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500/40 transition-all shadow-inner"
                            />
                        </div>

                        <div className="space-y-2.5">
                            <div className="flex items-center justify-between ml-1">
                                <label className="block text-[11px] font-bold text-gray-500 uppercase tracking-widest">PASSWORD</label>
                            </div>
                            <div className="relative">
                                <input
                                    ref={passwordRef}
                                    type={showPassword ? "text" : "password"}
                                    value={password}
                                    onChange={(e) => { setPassword(e.target.value); if (error) setError(false); }}
                                    placeholder="••••••••"
                                    className="w-full px-5 py-4 rounded-[18px] border border-gray-200 dark:border-white/5 bg-[#f7f7f7] dark:bg-[#0a0b0d] text-[15px] font-sans font-medium text-[#111111] dark:text-white placeholder-gray-400 dark:placeholder-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500/40 transition-all shadow-inner pr-12"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(!showPassword)}
                                    className="absolute right-4 top-1/2 -translate-y-1/2 p-1.5 text-gray-400 dark:text-white/20 hover:text-gray-600 dark:hover:text-white transition-colors"
                                >
                                    {showPassword ? <FiEyeOff className="w-[18px] h-[18px]" /> : <FiEye className="w-[18px] h-[18px]" />}
                                </button>
                            </div>
                            <div className="flex justify-end pt-1">
                                <button
                                    type="button"
                                    onClick={() => setView('forgot')}
                                    className="text-[13px] font-bold text-[#2b83fa] hover:text-blue-500 dark:hover:text-blue-400 transition-colors"
                                >
                                    Forgot Password?
                                </button>
                            </div>
                        </div>

                        <button
                            type="submit"
                            disabled={!username.trim() || !password.trim() || loading}
                            className="flex items-center justify-center gap-2 w-full py-4 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] text-white rounded-[16px] font-bold text-[16px] transition-all shadow-md shadow-blue-500/20 active:scale-[0.98] disabled:opacity-30 disabled:pointer-events-none disabled:shadow-none mt-2"
                        >
                            {loading ? <FiRefreshCw className="w-5 h-5 animate-spin" /> : 'Log In'}
                        </button>
                    </form>
                )}
            </div>
        </div>
    );
};

// ─── Admin Layout Shell ───────────────────────────────────────────────────────

export default AdminLogin;
