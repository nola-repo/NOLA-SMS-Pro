import React from "react";
import { FiMessageSquare } from "react-icons/fi";

interface AppLoadingScreenProps {
  message?: string;
  subtext?: string;
  fullScreen?: boolean;
}

export const AppLoadingScreen: React.FC<AppLoadingScreenProps> = ({
  message = "Loading NOLA SMS Pro",
  subtext = "Preparing your workspace...",
  fullScreen = true,
}) => {
  return (
    <div
      className={`
        flex flex-col items-center justify-center bg-[#f7f8fc] dark:bg-[#0a0a0b] transition-colors duration-200
        ${fullScreen ? "h-screen w-full fixed inset-0 z-[9999]" : "h-full w-full py-12"}
      `}
    >
      <div className="relative flex flex-col items-center gap-5 p-8 text-center animate-in fade-in zoom-in-[0.98] duration-300">
        {/* Glow ambient background ring */}
        <div className="absolute h-28 w-28 rounded-full bg-[#2b83fa]/10 dark:bg-[#2b83fa]/20 blur-xl animate-pulse" />

        <div className="relative flex items-center justify-center">
          {/* Outer rotating spinner */}
          <div className="h-16 w-16 rounded-full border-4 border-[#2b83fa]/20 border-t-[#2b83fa] animate-spin" />

          {/* Inner brand emblem */}
          <div className="absolute flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] text-white shadow-md shadow-blue-500/30">
            <FiMessageSquare className="h-4.5 w-4.5" />
          </div>
        </div>

        <div className="space-y-1 z-10">
          <h2 className="text-[15px] font-extrabold text-[#111111] dark:text-white tracking-tight">
            {message}
          </h2>
          {subtext && (
            <p className="text-[12.5px] font-medium text-[#6e6e73] dark:text-[#9aa0a6]">
              {subtext}
            </p>
          )}
        </div>
      </div>
    </div>
  );
};
