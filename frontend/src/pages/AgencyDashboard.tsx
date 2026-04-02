import React, { useState } from 'react';
import { Sidebar, ViewTab } from '../components/Sidebar';
import { SubaccountManager } from '../components/agency/SubaccountManager';
import { Settings } from './Settings';
import { useAuth } from '../context/AuthContext';

export const AgencyDashboard: React.FC = () => {
    const [activeTab, setActiveTab] = useState<ViewTab>('compose'); // Using 'compose' as default management view for now
    const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);
    const { logout, user } = useAuth();

    // Map 'compose' to SubaccountManager for Agency view
    const renderContent = () => {
        switch (activeTab) {
            case 'compose':
            case 'contacts':
            case 'templates':
                return <SubaccountManager />;
            case 'settings':
                return <Settings darkMode={false} toggleDarkMode={() => {}} />;
            default:
                return <SubaccountManager />;
        }
    };

    return (
        <div className="flex h-screen bg-[#f8f9fa] dark:bg-[#0b0c0d] overflow-hidden font-inter text-[#1a1c1e] dark:text-[#e2e2e6]">
            <Sidebar
                activeTab={activeTab}
                onTabChange={(tab) => setActiveTab(tab)}
                onSelectContact={() => {}} // No contact selection in agency view
                isCollapsed={isSidebarCollapsed}
                onToggleCollapse={() => setIsSidebarCollapsed(!isSidebarCollapsed)}
            />

            <main className="flex-1 flex flex-col h-full min-w-0 relative">
                {/* Header Profile / Help */}
                <div className="h-16 px-6 flex items-center justify-between border-b border-[#0000000a] dark:border-[#ffffff0a] bg-white/50 dark:bg-[#121415]/50 backdrop-blur-xl shrink-0 z-50">
                    <div className="flex items-center gap-2">
                        <span className="text-[12px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider">Agency Panel</span>
                        <div className="w-1 h-1 rounded-full bg-gray-300 mx-1" />
                        <span className="text-[14px] font-semibold text-[#111111] dark:text-white">{user?.name || 'Agency Owner'}</span>
                    </div>
                    
                    <div className="flex items-center gap-4">
                        <button 
                            onClick={logout}
                            className="text-[13px] font-bold text-red-500 hover:text-red-600 transition-colors"
                        >
                            Logout
                        </button>
                        <div className="w-10 h-10 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 shadow-sm flex items-center justify-center text-white font-bold text-sm">
                            {user?.name?.charAt(0) || 'A'}
                        </div>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto custom-scrollbar p-6">
                    <div className="max-w-[1200px] mx-auto">
                        {renderContent()}
                    </div>
                </div>
            </main>
        </div>
    );
};
