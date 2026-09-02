import React, { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { FiArrowLeft, FiCheckCircle, FiKey, FiLock, FiMail, FiShield } from "react-icons/fi";
import nolaLogo from "../assets/NOLA SMS PRO Logo.png";
import { apiFetch } from "../utils/apiFetch";

type Step = "email" | "otp" | "password" | "success";
type Status = { type: "success" | "error" | "info"; message: string } | null;

const resetCopy = {
  email: "Enter the email connected to your NOLA SMS PRO account.",
  otp: "Enter the verification code sent to your inbox.",
  password: "Create a new password for your account.",
};

const parseApiMessage = async (res: Response, fallback: string): Promise<string> => {
  const data = await res.json().catch(() => null);
  return data?.message || data?.error || fallback;
};

export const ForgotPassword: React.FC = () => {
  const navigate = useNavigate();
  const [step, setStep] = useState<Step>("email");
  const [email, setEmail] = useState("");
  const [otp, setOtp] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState<Status>(null);
  const [redirectSeconds, setRedirectSeconds] = useState(4);

  const heading = useMemo(() => {
    if (step === "email") return "Reset your password";
    if (step === "otp") return "Verify your code";
    if (step === "password") return "Choose a new password";
    return "Password updated";
  }, [step]);

  useEffect(() => {
    if (step !== "success") return;
    const redirectTimer = window.setTimeout(() => navigate("/login", { replace: true }), 4000);
    const tick = window.setInterval(() => {
      setRedirectSeconds((value) => Math.max(1, value - 1));
    }, 1000);
    return () => {
      window.clearTimeout(redirectTimer);
      window.clearInterval(tick);
    };
  }, [navigate, step]);

  const requestCode = async () => {
    const normalizedEmail = email.trim().toLowerCase();
    if (!normalizedEmail) {
      setStatus({ type: "error", message: "Enter your email address." });
      return;
    }

    setBusy(true);
    setStatus(null);
    try {
      const res = await apiFetch("/api/auth/forgot_password_otp.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: normalizedEmail }),
      });
      if (!res.ok) throw new Error(await parseApiMessage(res, "Could not request a reset code."));
      setEmail(normalizedEmail);
      setStep("otp");
      setStatus({
        type: "success",
        message: "If an account exists for that email, a verification code has been sent.",
      });
    } catch (error) {
      setStatus({ type: "error", message: error instanceof Error ? error.message : "Could not request a reset code." });
    } finally {
      setBusy(false);
    }
  };

  const verifyCode = async () => {
    const code = otp.trim();
    if (!/^\d{6}$/.test(code)) {
      setStatus({ type: "error", message: "Enter the 6-digit verification code." });
      return;
    }

    setBusy(true);
    setStatus(null);
    try {
      const res = await apiFetch("/api/auth/forgot_password_otp.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, otp_check: code }),
      });
      if (!res.ok) throw new Error(await parseApiMessage(res, "Invalid or expired verification code."));
      setStep("password");
      setStatus({ type: "success", message: "Code verified. Create your new password." });
    } catch (error) {
      setStatus({ type: "error", message: error instanceof Error ? error.message : "Invalid or expired verification code." });
    } finally {
      setBusy(false);
    }
  };

  const resetPassword = async () => {
    const code = otp.trim();
    if (newPassword.length < 8) {
      setStatus({ type: "error", message: "Use at least 8 characters for the new password." });
      return;
    }
    if (newPassword !== confirmPassword) {
      setStatus({ type: "error", message: "Passwords do not match." });
      return;
    }

    setBusy(true);
    setStatus(null);
    try {
      const res = await apiFetch("/api/auth/reset_password_otp.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, otp: code, new_password: newPassword }),
      });
      if (!res.ok) throw new Error(await parseApiMessage(res, "Could not update your password."));
      setStep("success");
      setStatus({ type: "success", message: "Your password has been updated. Redirecting to sign in." });
    } catch (error) {
      setStatus({ type: "error", message: error instanceof Error ? error.message : "Could not update your password." });
    } finally {
      setBusy(false);
    }
  };

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    if (step === "email") void requestCode();
    if (step === "otp") void verifyCode();
    if (step === "password") void resetPassword();
  };

  const statusClass = status?.type === "error"
    ? "border-red-200 bg-red-50 text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-200"
    : status?.type === "success"
      ? "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200"
      : "border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-200";

  return (
    <div className="min-h-screen bg-[#f7f8fc] px-4 py-8 text-[#111111] dark:bg-[#09090b] dark:text-white">
      <div className="mx-auto flex min-h-[calc(100vh-4rem)] w-full max-w-md flex-col justify-center">
        <button
          type="button"
          onClick={() => navigate("/login")}
          className="mb-6 inline-flex w-fit items-center gap-2 rounded-xl px-2 py-1 text-[13px] font-bold text-[#5f6368] transition-colors hover:text-[#111111] dark:text-[#9aa0a6] dark:hover:text-white"
        >
          <FiArrowLeft className="h-4 w-4" />
          Back to sign in
        </button>

        <div className="rounded-2xl border border-[#e5e7eb] bg-white p-6 shadow-xl shadow-black/[0.04] dark:border-white/10 dark:bg-[#151618] sm:p-8">
          <div className="mb-7 flex items-center gap-3">
            <div className="flex h-12 w-12 items-center justify-center overflow-hidden rounded-2xl border border-black/[0.06] bg-white shadow-sm dark:border-white/10 dark:bg-[#1a1b1e]">
              <img src={nolaLogo} alt="NOLA SMS PRO" className="h-9 w-9 object-contain" />
            </div>
            <div className="min-w-0">
              <p className="text-[12px] font-black uppercase tracking-[0.14em] text-[#2b83fa]">NOLA SMS PRO</p>
              <h1 className="text-[24px] font-black tracking-tight sm:text-[28px]">{heading}</h1>
            </div>
          </div>

          {step !== "success" && (
            <p className="mb-5 text-[13px] leading-6 text-[#5f6368] dark:text-[#b6bac2]">{resetCopy[step]}</p>
          )}

          <div className="mb-6 grid grid-cols-3 gap-2" aria-hidden="true">
            {["email", "otp", "password"].map((item) => {
              const active = item === step || (step === "success" && item === "password");
              return (
                <div
                  key={item}
                  className={`h-1.5 rounded-full ${active ? "bg-[#2b83fa]" : "bg-[#e8eaed] dark:bg-white/10"}`}
                />
              );
            })}
          </div>

          {status && (
            <div className={`mb-5 rounded-xl border px-4 py-3 text-[13px] font-semibold leading-5 ${statusClass}`}>
              {status.message}
            </div>
          )}

          {step === "success" ? (
            <div className="text-center">
              <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300">
                <FiCheckCircle className="h-7 w-7" />
              </div>
              <p className="mb-6 text-[13px] leading-6 text-[#5f6368] dark:text-[#b6bac2]">
                You can sign in with your new password. Redirecting in {redirectSeconds} seconds.
              </p>
              <button
                type="button"
                onClick={() => navigate("/login", { replace: true })}
                className="w-full rounded-xl bg-[#2b83fa] px-4 py-3 text-[13px] font-black text-white shadow-lg shadow-blue-500/20 transition-all hover:bg-[#1d6bd4] active:scale-[0.99]"
              >
                Return to Sign In
              </button>
            </div>
          ) : (
            <form className="space-y-4" onSubmit={handleSubmit}>
              {step === "email" && (
                <label className="block">
                  <span className="mb-2 block text-[12px] font-bold text-[#37352f] dark:text-[#ececf1]">Email address</span>
                  <span className="flex items-center gap-3 rounded-xl border border-[#e5e7eb] bg-[#fafafa] px-3 py-3 focus-within:border-[#2b83fa] focus-within:bg-white dark:border-white/10 dark:bg-white/[0.04] dark:focus-within:bg-white/[0.06]">
                    <FiMail className="h-4 w-4 flex-shrink-0 text-[#8a8f98]" />
                    <input
                      type="email"
                      value={email}
                      onChange={(event) => setEmail(event.target.value)}
                      placeholder="you@example.com"
                      autoComplete="email"
                      className="min-w-0 flex-1 bg-transparent text-[14px] font-semibold outline-none placeholder:text-[#a1a7b0]"
                    />
                  </span>
                </label>
              )}

              {step === "otp" && (
                <label className="block">
                  <span className="mb-2 block text-[12px] font-bold text-[#37352f] dark:text-[#ececf1]">Verification code</span>
                  <span className="flex items-center gap-3 rounded-xl border border-[#e5e7eb] bg-[#fafafa] px-3 py-3 focus-within:border-[#2b83fa] focus-within:bg-white dark:border-white/10 dark:bg-white/[0.04] dark:focus-within:bg-white/[0.06]">
                    <FiShield className="h-4 w-4 flex-shrink-0 text-[#8a8f98]" />
                    <input
                      value={otp}
                      onChange={(event) => setOtp(event.target.value.replace(/\D/g, "").slice(0, 6))}
                      placeholder="000000"
                      inputMode="numeric"
                      autoComplete="one-time-code"
                      className="min-w-0 flex-1 bg-transparent text-[18px] font-black tracking-[0.2em] outline-none placeholder:text-[#a1a7b0]"
                    />
                  </span>
                </label>
              )}

              {step === "password" && (
                <>
                  <label className="block">
                    <span className="mb-2 block text-[12px] font-bold text-[#37352f] dark:text-[#ececf1]">New password</span>
                    <span className="flex items-center gap-3 rounded-xl border border-[#e5e7eb] bg-[#fafafa] px-3 py-3 focus-within:border-[#2b83fa] focus-within:bg-white dark:border-white/10 dark:bg-white/[0.04] dark:focus-within:bg-white/[0.06]">
                      <FiLock className="h-4 w-4 flex-shrink-0 text-[#8a8f98]" />
                      <input
                        type="password"
                        value={newPassword}
                        onChange={(event) => setNewPassword(event.target.value)}
                        placeholder="At least 8 characters"
                        autoComplete="new-password"
                        className="min-w-0 flex-1 bg-transparent text-[14px] font-semibold outline-none placeholder:text-[#a1a7b0]"
                      />
                    </span>
                  </label>
                  <label className="block">
                    <span className="mb-2 block text-[12px] font-bold text-[#37352f] dark:text-[#ececf1]">Confirm password</span>
                    <span className="flex items-center gap-3 rounded-xl border border-[#e5e7eb] bg-[#fafafa] px-3 py-3 focus-within:border-[#2b83fa] focus-within:bg-white dark:border-white/10 dark:bg-white/[0.04] dark:focus-within:bg-white/[0.06]">
                      <FiKey className="h-4 w-4 flex-shrink-0 text-[#8a8f98]" />
                      <input
                        type="password"
                        value={confirmPassword}
                        onChange={(event) => setConfirmPassword(event.target.value)}
                        placeholder="Repeat new password"
                        autoComplete="new-password"
                        className="min-w-0 flex-1 bg-transparent text-[14px] font-semibold outline-none placeholder:text-[#a1a7b0]"
                      />
                    </span>
                  </label>
                </>
              )}

              <button
                type="submit"
                disabled={busy}
                className="w-full rounded-xl bg-[#2b83fa] px-4 py-3 text-[13px] font-black text-white shadow-lg shadow-blue-500/20 transition-all hover:bg-[#1d6bd4] active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60"
              >
                {busy ? "Please wait..." : step === "email" ? "Send Verification Code" : step === "otp" ? "Verify Code" : "Update Password"}
              </button>

              {step === "otp" && (
                <button
                  type="button"
                  onClick={requestCode}
                  disabled={busy}
                  className="w-full rounded-xl px-4 py-2.5 text-[12px] font-bold text-[#5f6368] transition-colors hover:bg-[#f1f3f4] hover:text-[#111111] disabled:opacity-60 dark:text-[#b6bac2] dark:hover:bg-white/[0.06] dark:hover:text-white"
                >
                  Resend Code
                </button>
              )}
            </form>
          )}
        </div>
      </div>
    </div>
  );
};

export default ForgotPassword;
