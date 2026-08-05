import React, { useState, useEffect, useRef } from "react";
import { FiBookOpen, FiMessageSquare, FiMoon, FiMoreHorizontal, FiSun } from "react-icons/fi";
import { ThemeSwitch } from "./ThemeSwitch";

export interface TopMoreOptionsProps {
  darkMode: boolean;
  toggleDarkMode: () => void;
  onboardingDone: boolean;
  onOpenGettingStarted: () => void;
  onOpenTickets: () => void;
}

export const TopMoreOptions: React.FC<TopMoreOptionsProps> = ({
  darkMode,
  toggleDarkMode,
  onboardingDone,
  onOpenGettingStarted,
  onOpenTickets,
}) => {
  const [open, setOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const optionIconClass = "flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-[#f1f3f4] text-[#5f6368] dark:bg-white/[0.06] dark:text-[#b6bac2]";

  useEffect(() => {
    if (!open) return;
    const handleOutside = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", handleOutside);
    return () => document.removeEventListener("mousedown", handleOutside);
  }, [open]);

  const handleGettingStarted = () => {
    onOpenGettingStarted();
    setOpen(false);
  };

  const handleTickets = () => {
    onOpenTickets();
    setOpen(false);
  };

  return (
    <div ref={menuRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className={`
          relative inline-flex items-center justify-center rounded-xl border p-2 shadow-sm transition-all active:scale-95
          ${open
            ? "border-white/30 bg-white/25 text-white"
            : "border-white/20 bg-white/10 text-white hover:bg-white/20"
          }
        `}
        aria-label="More options"
        aria-expanded={open}
        title="More options"
      >
        <FiMoreHorizontal className="h-4 w-4" />
        {!onboardingDone && (
          <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-500 dark:border-[#1a1b1e]" />
        )}
      </button>

      {open && (
        <div className="absolute right-0 top-[calc(100%+10px)] z-[80] w-72 overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white/95 p-2 shadow-2xl backdrop-blur-xl animate-in fade-in-0 zoom-in-[0.97] slide-in-from-top-1 duration-150 dark:border-white/10 dark:bg-[#1a1b1e]/95">
          <button
            type="button"
            onClick={handleGettingStarted}
            className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-[#f4f7fb] dark:hover:bg-white/[0.05]"
          >
            <span className={`relative ${optionIconClass}`}>
              <FiBookOpen className="h-[18px] w-[18px]" />
              {!onboardingDone && (
                <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-500 dark:border-[#1a1b1e]" />
              )}
            </span>
            <span className="min-w-0 flex-1">
              <span className="block text-[13px] font-bold text-[#111111] dark:text-white">Get Started</span>
              <span className="block truncate text-[11.5px] font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
                Open onboarding
              </span>
            </span>
            {!onboardingDone && (
              <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-emerald-500">
                New
              </span>
            )}
          </button>

          <button
            type="button"
            onClick={handleTickets}
            className="mt-1 flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-[#f4f7fb] dark:hover:bg-white/[0.05]"
          >
            <span className={optionIconClass}>
              <FiMessageSquare className="h-[18px] w-[18px]" />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block text-[13px] font-bold text-[#111111] dark:text-white">Tickets</span>
              <span className="block truncate text-[11.5px] font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
                View support tickets
              </span>
            </span>
          </button>

          <div className="mt-1 flex items-center justify-between gap-3 rounded-xl px-3 py-2.5">
            <span className={optionIconClass}>
              {darkMode ? <FiMoon className="h-[18px] w-[18px]" /> : <FiSun className="h-[18px] w-[18px]" />}
            </span>
            <span className="min-w-0 flex-1">
              <span className="block text-[13px] font-bold text-[#111111] dark:text-white">Theme</span>
              <span className="block text-[11.5px] font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
                {darkMode ? "Dark mode" : "Light mode"}
              </span>
            </span>
            <ThemeSwitch checked={darkMode} onChange={toggleDarkMode} />
          </div>
        </div>
      )}
    </div>
  );
};
