import React, { useEffect, useState } from 'react';
import { FiCheckCircle, FiAlertCircle, FiInfo, FiX } from 'react-icons/fi';

const ICONS = {
  success: <FiCheckCircle className="w-4 h-4 text-[#22c55e] shrink-0" />,
  error:   <FiAlertCircle className="w-4 h-4 text-[#ef4444] shrink-0" />,
  info:    <FiInfo        className="w-4 h-4 text-[#2b83fa] shrink-0" />,
};

const BORDERS = {
  success: 'border-[#22c55e]/20 dark:border-[#22c55e]/20',
  error:   'border-[#ef4444]/20 dark:border-[#ef4444]/20',
  info:    'border-[rgba(0,0,0,0.08)] dark:border-white/10',
};

export const ToastContainer = ({ toasts, onDismiss }) => {
  return (
    <div className="fixed bottom-6 right-6 z-[9999] flex flex-col gap-2 pointer-events-none" role="region" aria-label="Notifications">
      {toasts.map(t => (
        <div 
          key={t.id} 
          className={`flex items-center gap-3 bg-white/95 dark:bg-[#1a1c1e]/95 backdrop-blur-xl border shadow-[0_8px_30px_rgb(0,0,0,0.12)] px-4 py-3 rounded-xl pointer-events-auto toast-animate min-w-[280px] max-w-[400px] ${BORDERS[t.type] || BORDERS.info}`}
          role="alert"
        >
          {ICONS[t.type] || ICONS.info}
          <span className="flex-1 text-[13.5px] font-medium text-[#111111] dark:text-[#ececf1]">{t.message}</span>
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
