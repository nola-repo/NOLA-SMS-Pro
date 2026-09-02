import React from 'react';
import { FiCheckCircle, FiAlertCircle, FiInfo, FiX } from 'react-icons/fi';

const ICONS = {
  success: <FiCheckCircle className="w-4 h-4 text-[#22c55e] shrink-0" />,
  error:   <FiAlertCircle className="w-4 h-4 text-[#ef4444] shrink-0" />,
  warning: <FiAlertCircle className="w-4 h-4 text-[#f59e0b] shrink-0" />,
  info:    <FiInfo        className="w-4 h-4 text-[#2b83fa] shrink-0" />,
};

const BORDERS = {
  success: 'border-[#22c55e]/20 dark:border-[#22c55e]/20',
  error:   'border-[#ef4444]/20 dark:border-[#ef4444]/20',
  warning: 'border-[#f59e0b]/25 dark:border-[#f59e0b]/25',
  info:    'border-[rgba(0,0,0,0.08)] dark:border-white/10',
};

export const ToastContainer = ({ toasts, onDismiss }) => {
  return (
    <div className="fixed bottom-4 left-1/2 z-[9999] flex w-[calc(100%-2rem)] -translate-x-1/2 flex-col items-center gap-2 pointer-events-none sm:bottom-6 sm:w-auto" role="region" aria-label="Notifications">
      {toasts.map(t => (
        <div 
          key={t.id} 
          className={`flex w-full max-w-[420px] items-center gap-3 rounded-2xl border bg-white/95 px-4 py-3 shadow-[0_12px_34px_rgba(15,23,42,0.16)] backdrop-blur-xl pointer-events-auto toast-animate sm:min-w-[320px] dark:bg-[#1a1c1e]/95 ${BORDERS[t.type] || BORDERS.info}`}
          role="alert"
        >
          {ICONS[t.type] || ICONS.info}
          <span className="min-w-0 flex-1 break-words text-[13.5px] font-semibold leading-snug text-[#111111] dark:text-[#ececf1]">{t.message}</span>
          <button 
            className="p-1 rounded-md text-[#9ca3af] hover:bg-black/5 dark:hover:bg-white/5 hover:text-[#111111] dark:hover:text-white transition-colors ml-2" 
            onClick={() => onDismiss(t.id)} 
            aria-label="Dismiss"
          >
            <FiX className="w-3.5 h-3.5" />
          </button>
        </div>
      ))}
    </div>
  );
};
