import React, { useCallback } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import {
    FiUser, FiSend, FiBell, FiCreditCard, FiSettings
} from "react-icons/fi";
import type { SettingsProps, SettingsTab } from "./settings/types";
import { AccountTab } from "./settings/AccountTab";
import { SenderTab } from "./settings/SenderTab";
import { NotificationsTab } from "./settings/NotificationsTab";
import { CreditsTab } from "./settings/CreditsTab";

export type { SettingsTab } from "./settings/types";

const TABS: { id: SettingsTab; label: string; icon: React.ReactNode; description: string }[] = [
    { id: "credits", label: "Credits", icon: <FiCreditCard />, description: "Balance & billing" },
    { id: "senderIds", label: "Sender IDs", icon: <FiSend />, description: "Manage approved sender IDs" },
    { id: "notifications", label: "Notifications", icon: <FiBell />, description: "Alert & report preferences" },
    { id: "account", label: "Account", icon: <FiUser />, description: "Profile & organization info" },
];

const SETTINGS_TAB_ROUTES: Record<SettingsTab, string> = {
    account: "/settings/account",
    senderIds: "/settings/sender-id",
    notifications: "/settings/notifications",
    credits: "/settings/credits",
};

export const Settings: React.FC<SettingsProps> = ({ initialTab, autoOpenAddModal }) => {
    const navigate = useNavigate();
    const location = useLocation();
    const activeTab: SettingsTab = initialTab || "credits";

    const handleTabSelect = (tab: SettingsTab) => {
        navigate({ pathname: SETTINGS_TAB_ROUTES[tab], search: location.search }, { replace: false });
    };

    const renderContent = useCallback(() => {
        switch (activeTab) {
            case "account": return <AccountTab />;
            case "senderIds": return <SenderTab autoOpenAddModal={autoOpenAddModal && activeTab === "senderIds"} />;
            case "notifications": return <NotificationsTab />;
            case "credits": return <CreditsTab />;
        }
    }, [activeTab, autoOpenAddModal]);

    const activeTabInfo = TABS.find(t => t.id === activeTab) || TABS[0];

    return (
        <div className="h-full flex flex-col overflow-hidden bg-[#f3f4f6] dark:bg-[#09090b]">
            {/* Page Header */}
            <div className="flex-shrink-0 bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] rounded-b-[40px] shadow-[0_18px_45px_rgba(29,107,212,0.24)]">
                <div className="max-w-5xl mx-auto px-3 md:px-6 pt-5 pb-5">
                    <div className="flex items-center gap-3 mb-5">
                        <div className="w-10 h-10 rounded-full bg-white/20 border border-white/20 flex items-center justify-center text-white shadow-md shadow-blue-950/10">
                            <FiSettings className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-[22px] font-extrabold text-white tracking-tight">Settings</h1>
                            <p className="text-[12px] text-white/75">{activeTabInfo.description}</p>
                        </div>
                    </div>

                    <nav className="overflow-x-auto custom-scrollbar pb-1">
                        <div className="flex gap-2 min-w-max">
                            {TABS.map(tab => {
                                const isActive = activeTab === tab.id;
                                return (
                                    <button
                                        key={tab.id}
                                        onClick={() => handleTabSelect(tab.id)}
                                        className={`flex items-center gap-2 px-3 sm:px-4 py-2.5 rounded-xl border transition-all duration-200 text-left whitespace-nowrap ${isActive
                                            ? "bg-white text-[#1d6bd4] border-white shadow-sm"
                                            : "bg-white/10 text-white border-white/20 hover:bg-white/20"
                                            }`}
                                    >
                                        <span className="text-[15px] flex-shrink-0">{tab.icon}</span>
                                        <span className="text-[13px] font-bold">{tab.label}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </nav>
                </div>
            </div>

            {/* Scrollable Content Body */}
            <div className="flex-1 overflow-y-auto custom-scrollbar p-3 sm:p-6 md:p-8">
                <div className="max-w-5xl mx-auto">
                    {renderContent()}
                </div>
            </div>
        </div>
    );
};
