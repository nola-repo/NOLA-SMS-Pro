import React, { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import { authService } from '../services/authService';
import { Link } from 'react-router-dom';

interface Branding {
  company_name: string;
  logo_url: string;
  primary_color: string;
  agency_id: string | null;
}

const DEFAULT_BRANDING: Branding = {
  company_name: 'NOLA SMS Pro',
  logo_url: '',
  primary_color: '#2b83fa',
  agency_id: null,
};

const API_BASE = import.meta.env.VITE_API_BASE || '';

export const SharedLogin: React.FC = () => {
  const [branding, setBranding] = useState<Branding>(DEFAULT_BRANDING);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [brandingLoaded, setBrandingLoaded] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const { login } = useAuth();

  // Fetch white-label branding on mount
  useEffect(() => {
    const domain = window.location.hostname;
    fetch(`${API_BASE}/api/public/whitelabel?domain=${encodeURIComponent(domain)}`)
      .then((res) => res.json())
      .then((data) => {
        if (data.status === 'success' && data.branding) {
          setBranding(data.branding);
        }
      })
      .catch(() => {
        // Keep defaults on error
      })
      .finally(() => setBrandingLoaded(true));
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const data = await authService.login(email, password);

      if (data.status !== 'success') {
        setError(data.message || 'Login failed. Please try again.');
        setLoading(false);
        return;
      }

      // Store auth session via context
      login(data);

      // Route based on role
      if (data.role === 'agency') {
        window.location.href = '/agency/';
      } else {
        window.location.href = '/';
      }
    } catch {
      setError('Network error. Please check your connection.');
      setLoading(false);
    }
  };

  const primaryColor = branding.primary_color || '#2b83fa';

  // Derive a darker shade for hover states
  const darkenColor = (hex: string, amount: number): string => {
    const num = parseInt(hex.replace('#', ''), 16);
    const r = Math.max(0, (num >> 16) - amount);
    const g = Math.max(0, ((num >> 8) & 0x00ff) - amount);
    const b = Math.max(0, (num & 0x0000ff) - amount);
    return `#${(r << 16 | g << 8 | b).toString(16).padStart(6, '0')}`;
  };

  return (
    <div style={styles.wrapper}>
      {/* Animated gradient background */}
      <div
        style={{
          ...styles.bgGradient,
          background: `
            radial-gradient(ellipse at 20% 50%, ${primaryColor}15 0%, transparent 50%),
            radial-gradient(ellipse at 80% 20%, ${primaryColor}10 0%, transparent 50%),
            radial-gradient(ellipse at 50% 80%, #6366f108 0%, transparent 50%),
            linear-gradient(135deg, #0f0f12 0%, #1a1a2e 50%, #16162a 100%)
          `,
        }}
      />

      {/* Floating orb decorations */}
      <div style={{ ...styles.orb, ...styles.orb1, backgroundColor: `${primaryColor}20` }} />
      <div style={{ ...styles.orb, ...styles.orb2, backgroundColor: `${primaryColor}12` }} />
      <div style={{ ...styles.orb, ...styles.orb3, backgroundColor: '#6366f115' }} />

      {/* Login Card */}
      <div
        style={{
          ...styles.card,
          opacity: brandingLoaded ? 1 : 0,
          transform: brandingLoaded ? 'translateY(0)' : 'translateY(30px)',
        }}
      >
        {/* Logo / Company Name */}
        <div style={styles.logoSection}>
          {branding.logo_url ? (
            <img
              src={branding.logo_url}
              alt={branding.company_name}
              style={styles.logo}
            />
          ) : (
            <div style={{ ...styles.logoFallback, background: `linear-gradient(135deg, ${primaryColor}, ${darkenColor(primaryColor, 40)})` }}>
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
              </svg>
            </div>
          )}
          <h1 style={styles.companyName}>{branding.company_name}</h1>
          <p style={styles.subtitle}>Sign in to your account</p>
        </div>

        {/* Error Banner */}
        {error && (
          <div style={styles.errorBanner}>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0 }}>
              <circle cx="12" cy="12" r="10" />
              <line x1="15" y1="9" x2="9" y2="15" />
              <line x1="9" y1="9" x2="15" y2="15" />
            </svg>
            <span>{error}</span>
          </div>
        )}

        {/* Login Form */}
        <form onSubmit={handleSubmit} style={styles.form}>
          <div style={styles.inputGroup}>
            <label htmlFor="login-email" style={styles.label}>Email Address</label>
            <div style={styles.inputWrapper}>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" style={styles.inputIcon}>
                <rect x="2" y="4" width="20" height="16" rx="2" />
                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
              </svg>
              <input
                id="login-email"
                type="email"
                placeholder="you@company.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="email"
                style={styles.input}
              />
            </div>
          </div>

          <div style={styles.inputGroup}>
            <label htmlFor="login-password" style={styles.label}>Password</label>
            <div style={styles.inputWrapper}>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" style={styles.inputIcon}>
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
              </svg>
              <input
                id="login-password"
                type={showPassword ? 'text' : 'password'}
                placeholder="••••••••"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoComplete="current-password"
                style={styles.input}
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                style={styles.eyeBtn}
                tabIndex={-1}
                aria-label="Toggle password visibility"
              >
                {showPassword ? (
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
                    <line x1="1" y1="1" x2="23" y2="23" />
                  </svg>
                ) : (
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                )}
              </button>
            </div>
          </div>

          <button
            type="submit"
            disabled={loading}
            style={{
              ...styles.submitBtn,
              background: loading
                ? '#64748b'
                : `linear-gradient(135deg, ${primaryColor}, ${darkenColor(primaryColor, 30)})`,
              boxShadow: loading
                ? 'none'
                : `0 8px 32px ${primaryColor}40, 0 2px 8px ${primaryColor}30`,
              cursor: loading ? 'not-allowed' : 'pointer',
            }}
          >
            {loading ? (
              <div style={styles.spinner} />
            ) : (
              <>
                Sign In
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                  <line x1="5" y1="12" x2="19" y2="12" />
                  <polyline points="12 5 19 12 12 19" />
                </svg>
              </>
            )}
          </button>
        </form>

        {/* Footer */}
        <p style={styles.footer}>
          Don't have an account? <Link to="/register" style={{ color: primaryColor, fontWeight: 700, textDecoration: 'none' }}>Register here &rarr;</Link>
          <br /><br />
          Powered by <span style={{ fontWeight: 700, color: '#e2e8f0' }}>NOLA SMS Pro</span>
        </p>
      </div>

      {/* CSS Keyframe animations injected via style tag */}
      <style>{`
        @keyframes float1 {
          0%, 100% { transform: translate(0, 0) scale(1); }
          50% { transform: translate(30px, -40px) scale(1.1); }
        }
        @keyframes float2 {
          0%, 100% { transform: translate(0, 0) scale(1); }
          50% { transform: translate(-20px, 30px) scale(1.05); }
        }
        @keyframes float3 {
          0%, 100% { transform: translate(0, 0) scale(1); }
          50% { transform: translate(15px, 20px) scale(1.08); }
        }
        @keyframes spin {
          to { transform: rotate(360deg); }
        }
        input::placeholder {
          color: #475569;
        }
        input:focus {
          border-color: ${primaryColor} !important;
          box-shadow: 0 0 0 3px ${primaryColor}25, 0 1px 3px rgba(0,0,0,0.3) !important;
        }
        button[type="submit"]:not(:disabled):hover {
          transform: translateY(-2px);
          filter: brightness(1.1);
        }
        button[type="submit"]:not(:disabled):active {
          transform: translateY(0) scale(0.98);
        }
      `}</style>
    </div>
  );
};

// ─── Styles ──────────────────────────────────────────────────────────────────

const styles: Record<string, React.CSSProperties> = {
  wrapper: {
    position: 'fixed',
    inset: 0,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    fontFamily: "'Poppins', system-ui, -apple-system, sans-serif",
    overflow: 'hidden',
  },
  bgGradient: {
    position: 'absolute',
    inset: 0,
    zIndex: 0,
  },
  orb: {
    position: 'absolute',
    borderRadius: '50%',
    filter: 'blur(80px)',
    zIndex: 1,
  },
  orb1: {
    width: 400,
    height: 400,
    top: '-10%',
    right: '-5%',
    animation: 'float1 15s ease-in-out infinite',
  },
  orb2: {
    width: 300,
    height: 300,
    bottom: '-5%',
    left: '-5%',
    animation: 'float2 18s ease-in-out infinite',
  },
  orb3: {
    width: 250,
    height: 250,
    top: '40%',
    left: '60%',
    animation: 'float3 12s ease-in-out infinite',
  },
  card: {
    position: 'relative',
    zIndex: 10,
    width: '100%',
    maxWidth: 420,
    margin: '0 20px',
    background: 'rgba(15, 15, 25, 0.75)',
    backdropFilter: 'blur(40px) saturate(180%)',
    WebkitBackdropFilter: 'blur(40px) saturate(180%)',
    borderRadius: 28,
    padding: '44px 36px 32px',
    border: '1px solid rgba(255, 255, 255, 0.08)',
    boxShadow: '0 25px 80px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.05)',
    transition: 'opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1)',
  },
  logoSection: {
    textAlign: 'center' as const,
    marginBottom: 32,
  },
  logo: {
    height: 52,
    maxWidth: 200,
    objectFit: 'contain' as const,
    marginBottom: 16,
  },
  logoFallback: {
    width: 56,
    height: 56,
    borderRadius: 18,
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 16,
    boxShadow: '0 8px 24px rgba(43, 131, 250, 0.3)',
  },
  companyName: {
    fontSize: 26,
    fontWeight: 800,
    letterSpacing: '-0.8px',
    color: '#f1f5f9',
    marginBottom: 4,
    lineHeight: 1.2,
  },
  subtitle: {
    fontSize: 14,
    color: '#64748b',
    fontWeight: 500,
  },
  errorBanner: {
    display: 'flex',
    alignItems: 'center',
    gap: 10,
    padding: '12px 16px',
    borderRadius: 14,
    background: 'rgba(239, 68, 68, 0.1)',
    border: '1px solid rgba(239, 68, 68, 0.2)',
    color: '#fca5a5',
    fontSize: 13,
    fontWeight: 600,
    marginBottom: 20,
    animation: 'fade-in 0.3s ease',
  },
  form: {
    display: 'flex',
    flexDirection: 'column' as const,
    gap: 20,
  },
  inputGroup: {
    display: 'flex',
    flexDirection: 'column' as const,
    gap: 6,
  },
  label: {
    fontSize: 11,
    fontWeight: 700,
    textTransform: 'uppercase' as const,
    color: '#94a3b8',
    letterSpacing: '0.06em',
    paddingLeft: 2,
  },
  inputWrapper: {
    position: 'relative' as const,
    display: 'flex',
    alignItems: 'center',
  },
  inputIcon: {
    position: 'absolute' as const,
    left: 14,
    pointerEvents: 'none' as const,
    zIndex: 2,
  },
  input: {
    width: '100%',
    padding: '14px 48px 14px 44px',
    borderRadius: 14,
    border: '1px solid rgba(255, 255, 255, 0.1)',
    background: 'rgba(255, 255, 255, 0.05)',
    color: '#e2e8f0',
    fontFamily: 'inherit',
    fontSize: 14,
    fontWeight: 500,
    outline: 'none',
    transition: 'all 0.2s ease',
  },
  eyeBtn: {
    position: 'absolute' as const,
    right: 12,
    background: 'none',
    border: 'none',
    padding: 4,
    cursor: 'pointer',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 2,
  },
  submitBtn: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
    padding: '15px 24px',
    borderRadius: 16,
    border: 'none',
    color: '#fff',
    fontSize: 15,
    fontWeight: 700,
    fontFamily: 'inherit',
    letterSpacing: '0.01em',
    transition: 'all 0.25s cubic-bezier(0.4, 0, 0.2, 1)',
    marginTop: 4,
  },
  spinner: {
    width: 20,
    height: 20,
    border: '2.5px solid rgba(255,255,255,0.3)',
    borderTopColor: '#fff',
    borderRadius: '50%',
    animation: 'spin 0.6s linear infinite',
  },
  footer: {
    textAlign: 'center' as const,
    fontSize: 11,
    color: '#475569',
    marginTop: 28,
    fontWeight: 500,
  },
};

export default SharedLogin;
