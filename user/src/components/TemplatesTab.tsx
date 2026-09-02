import { devLog } from '../utils/devLog';
import React, { useState, useEffect, useMemo, useRef, useCallback } from "react";
import { DotLottieReact } from "@lottiefiles/dotlottie-react";
import { fetchTemplates, createTemplate, updateTemplate, deleteTemplate } from "../api/templates";
import { fetchContacts } from "../api/contacts";
import { sendSms } from "../api/sms";
import type { Template } from "../types/Template";
import type { Contact } from "../types/Contact";
import { useLocationId } from "../context/LocationContext";
import { safeStorage } from "../utils/safeStorage";
import { getCachedTemplates } from "../utils/templateCache";
import { formatTemplateValidationMessage, validateTemplateContent } from "../utils/templateValidation";
import {
  FiAlertCircle,
  FiCheck,
  FiCode,
  FiEdit2,
  FiLoader,
  FiMessageSquare,
  FiMoreVertical,
  FiPlus,
  FiSearch,
  FiSend,
  FiSmartphone,
  FiTrash2,
  FiX,
} from "react-icons/fi";

const CATEGORIES = ["All", "Appointments", "Marketing", "Transactional", "General"];
const EDIT_CATEGORIES = CATEGORIES.filter((category) => category !== "All");

const PREBUILT_TEMPLATES: Array<Pick<Template, "name" | "content" | "category">> = [
  {
    name: "Appointment Confirmed",
    category: "Appointments",
    content: "Hi {{contact.first_name}}, your appointment with {{company.name}} is confirmed. Reply YES to confirm or call us if you need to reschedule.",
  },
  {
    name: "Appointment Reminder",
    category: "Appointments",
    content: "Hi {{contact.first_name}}, reminder that your appointment with {{company.name}} is coming up soon. Reply C to confirm or R to reschedule.",
  },
  {
    name: "Reschedule Appointment",
    category: "Appointments",
    content: "Hi {{contact.first_name}}, your appointment with {{company.name}} needs to be rescheduled. Please reply with a preferred day and time.",
  },
  {
    name: "New Customer Welcome",
    category: "General",
    content: "Hi {{contact.first_name}}, welcome to {{company.name}}. We are glad to have you here. Reply to this message if you need help.",
  },
  {
    name: "Business Hours Reply",
    category: "General",
    content: "Hi {{contact.first_name}}, thanks for contacting {{company.name}}. Our team will get back to you during business hours.",
  },
  {
    name: "Customer Review Request",
    category: "General",
    content: "Hi {{contact.first_name}}, thank you for choosing {{company.name}}. We would love your feedback. Please reply with your rating from 1 to 5.",
  },
  {
    name: "Payment Reminder",
    category: "Transactional",
    content: "Hi {{contact.first_name}}, this is a friendly reminder from {{company.name}} that your payment is due soon. Thank you.",
  },
  {
    name: "Order Confirmed",
    category: "Transactional",
    content: "Hi {{contact.first_name}}, your order with {{company.name}} has been confirmed. We will update you once it is ready.",
  },
  {
    name: "Document Request",
    category: "Transactional",
    content: "Hi {{contact.first_name}}, {{company.name}} needs one more document to complete your request. Please reply here when ready.",
  },
  {
    name: "Promotional Offer",
    category: "Marketing",
    content: "Hi {{contact.first_name}}, {{company.name}} has a limited-time offer for you. Reply DEAL and our team will send the details.",
  },
  {
    name: "Flash Sale",
    category: "Marketing",
    content: "Hi {{contact.first_name}}, flash sale at {{company.name}} today only. Reply SAVE to claim the offer before it ends.",
  },
  {
    name: "Event Invite",
    category: "Marketing",
    content: "Hi {{contact.first_name}}, you are invited to an upcoming {{company.name}} event. Reply RSVP and we will reserve your spot.",
  },
];

const MOCK_CONTACT: Contact = {
  id: "mock-contact",
  name: "John Santos",
  phone: "09171234567",
  email: "john@example.com",
};

type TemplateListItem = Template & { isPrebuilt?: boolean };

const createPrebuiltTemplate = (template: Pick<Template, "name" | "content" | "category">, index: number): TemplateListItem => ({
  id: `prebuilt-${template.category}-${index}-${template.name}`.toLowerCase().replace(/[^a-z0-9]+/g, "-"),
  location_id: "",
  name: template.name,
  content: template.content,
  category: template.category || "General",
  created_at: "",
  updated_at: "",
  isPrebuilt: true,
});

const TemplateSkeleton = () => (
  <div className="flex items-center gap-3 sm:gap-4 p-3 sm:p-4 rounded-xl bg-white/50 dark:bg-[#1a1b1e]/50 border border-gray-100 dark:border-white/5 animate-pulse mb-2">
    <div className="w-9 sm:w-11 h-9 sm:h-11 rounded-xl sm:rounded-2xl bg-gray-200 dark:bg-white/10 flex-shrink-0" />
    <div className="flex-1 space-y-2 min-w-0">
      <div className="h-4 w-1/3 bg-gray-200 dark:bg-white/10 rounded-md" />
      <div className="h-3 w-2/3 bg-gray-100 dark:bg-white/5 rounded-md" />
    </div>
    <div className="w-8 h-8 rounded-lg bg-gray-100 dark:bg-white/5 flex-shrink-0" />
  </div>
);

const TemplateEmptyState: React.FC<{
  hasAnyTemplates: boolean;
  onCreate: () => void;
  onResetFilters: () => void;
}> = ({ hasAnyTemplates, onCreate, onResetFilters }) => (
  <div className="flex flex-col items-center justify-center rounded-3xl border border-dashed border-gray-200 bg-white/70 px-6 py-14 text-center shadow-sm dark:border-white/10 dark:bg-[#1a1b1e]/60">
    <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-[#eef6ff] text-[#2b83fa] dark:bg-white/5">
      {hasAnyTemplates ? <FiSearch className="h-8 w-8" /> : <FiMessageSquare className="h-8 w-8" />}
    </div>
    <h3 className="text-[16px] font-extrabold text-[#111111] dark:text-[#ececf1]">
      {hasAnyTemplates ? "No templates match your filters" : "No templates yet"}
    </h3>
    <p className="mt-2 max-w-sm text-[13px] font-medium leading-relaxed text-gray-500 dark:text-gray-400">
      {hasAnyTemplates
        ? "Try another keyword or category to find the template you need."
        : "Create reusable SMS messages with contact and company placeholders so first sends are faster and more consistent."}
    </p>
    <button
      onClick={hasAnyTemplates ? onResetFilters : onCreate}
      className="mt-5 inline-flex items-center justify-center gap-2 rounded-xl bg-[#2b83fa] px-4 py-2.5 text-[13px] font-bold text-white shadow-md shadow-blue-500/15 transition-all hover:bg-[#1d6bd4] active:scale-95"
    >
      {hasAnyTemplates ? <FiX className="h-4 w-4" /> : <FiPlus className="h-4 w-4" />}
      {hasAnyTemplates ? "Clear filters" : "Create Template"}
    </button>
  </div>
);

const getFirstName = (name = "") => name.trim().split(/\s+/)[0] || "";
const getLastName = (name = "") => name.trim().split(/\s+/).slice(1).join(" ");

const interpolateTemplate = (content: string, contact: Contact | null = MOCK_CONTACT) => {
  const chosen = contact || MOCK_CONTACT;
  const name = chosen.name || MOCK_CONTACT.name;
  return content
    .replace(/\{\{contact\.name\}\}/gi, name)
    .replace(/\{\{contact\.first_name\}\}/gi, getFirstName(name))
    .replace(/\{\{contact\.last_name\}\}/gi, getLastName(name))
    .replace(/\{\{contact\.phone\}\}/gi, chosen.phone || MOCK_CONTACT.phone)
    .replace(/\{\{contact\.email\}\}/gi, chosen.email || MOCK_CONTACT.email || "")
    .replace(/\{\{company\.name\}\}/gi, "NOLA SMS Pro");
};

const PhonePreview: React.FC<{ template?: TemplateListItem | Template | Pick<Template, "name" | "content" | "category">; contact?: Contact | null }> = ({ template, contact }) => (
  <div className="rounded-[28px] border border-gray-200 dark:border-white/10 bg-gray-100 dark:bg-white/5 p-1.5 shadow-sm">
    <div className="rounded-[22px] bg-[#f7f8fb] dark:bg-[#111318] p-4 min-h-[360px] flex flex-col">
      <div className="flex items-center justify-center gap-2 pb-3 border-b border-gray-200 dark:border-white/10">
        <FiSmartphone className="w-4 h-4 text-[#2b83fa]" />
        <span className="text-[11px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">Preview</span>
      </div>
      <div className="flex-1 flex flex-col justify-end pt-5">
        {template ? (
          <>
            <div className="mb-3">
              <p className="text-[12px] font-bold text-[#111111] dark:text-white truncate">{template.name}</p>
              <p className="text-[10px] font-semibold uppercase tracking-wider text-[#9aa0a6]">{template.category || "General"}</p>
            </div>
            <div className="ml-auto max-w-[86%] rounded-2xl rounded-br-md bg-[#2b83fa] px-4 py-3 text-white shadow-lg shadow-blue-500/20">
              <p className="whitespace-pre-wrap break-words text-[13px] leading-relaxed font-medium">
                {interpolateTemplate(template.content, contact || MOCK_CONTACT)}
              </p>
            </div>
          </>
        ) : (
          <div className="text-center text-[12px] font-medium text-gray-400">Select a template to preview it.</div>
        )}
      </div>
    </div>
  </div>
);

export const TemplatesTab: React.FC = () => {
  const { locationId } = useLocationId();
  const [templates, setTemplates] = useState<Template[]>([]);
  const [searchQuery, setSearchQuery] = useState("");
  const [activeCategory, setActiveCategory] = useState("All");
  const [loading, setLoading] = useState(true);

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<Template | null>(null);
  const [formData, setFormData] = useState({ name: "", content: "", category: "General" });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [openMenuId, setOpenMenuId] = useState<string | null>(null);
  const [deleteConfirmId, setDeleteConfirmId] = useState<string | null>(null);
  const [isCustomValuesOpen, setIsCustomValuesOpen] = useState(false);
  const [quickSendTemplate, setQuickSendTemplate] = useState<TemplateListItem | null>(null);
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [contactsLoading, setContactsLoading] = useState(false);
  const [contactQuery, setContactQuery] = useState("");
  const [selectedContact, setSelectedContact] = useState<Contact | null>(null);
  const [quickSending, setQuickSending] = useState(false);
  const [quickError, setQuickError] = useState<string | null>(null);

  const customValuesRef = useRef<HTMLDivElement>(null);

  const customValues = [
    { label: "Contact Name", value: "{{contact.name}}" },
    { label: "Contact First Name", value: "{{contact.first_name}}" },
    { label: "Contact Email", value: "{{contact.email}}" },
    { label: "Contact Phone", value: "{{contact.phone}}" },
    { label: "Company Name", value: "{{company.name}}" },
  ];

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      setOpenMenuId(null);
      if (customValuesRef.current && !customValuesRef.current.contains(e.target as Node)) {
        setIsCustomValuesOpen(false);
      }
    };
    document.addEventListener("click", handleClickOutside);
    return () => document.removeEventListener("click", handleClickOutside);
  }, []);

  const loadTemplates = useCallback(async (forceRefresh = false) => {
    const cached = getCachedTemplates(locationId || undefined);
    if (cached && !forceRefresh) {
      setTemplates(cached);
      setLoading(false);
      // Fetch in the background to update the cache and state
      try {
        const data = await fetchTemplates(locationId || undefined, true);
        setTemplates(Array.isArray(data) ? data : []);
      } catch (err) {
        devLog.error("Background template fetch failed:", err);
      }
      return;
    }

    setLoading(true);
    try {
      const data = await fetchTemplates(locationId || undefined, forceRefresh);
      setTemplates(Array.isArray(data) ? data : []);
    } catch (err) {
      devLog.error(err);
      setTemplates([]);
    } finally {
      setLoading(false);
    }
  }, [locationId]);

  // Load templates on mount/location change
  useEffect(() => {
    loadTemplates();
  }, [loadTemplates]);

  // Load contacts data on mount/location change
  useEffect(() => {
    const loadContactsData = async () => {
      setContactsLoading(true);
      try {
        const data = await fetchContacts(locationId || undefined);
        setContacts(Array.isArray(data) ? data : []);
      } catch (err) {
        devLog.error("Failed to pre-load contacts in TemplatesTab:", err);
      } finally {
        setContactsLoading(false);
      }
    };

    loadContactsData();
  }, [locationId]);

  const visibleTemplates = useMemo<TemplateListItem[]>(() => {
    const savedNames = new Set(templates.map((template) => template.name.trim().toLowerCase()));
    const prebuilt = PREBUILT_TEMPLATES
      .filter((template) => !savedNames.has(template.name.trim().toLowerCase()))
      .map(createPrebuiltTemplate);

    return [...templates, ...prebuilt];
  }, [templates]);

  const filteredTemplates = useMemo(() => {
    const lowerQ = searchQuery.toLowerCase();
    return visibleTemplates.filter((template) => {
      const category = template.category || "General";
      const matchesCategory = activeCategory === "All" || category === activeCategory;
      const matchesSearch = !lowerQ || template.name.toLowerCase().includes(lowerQ) || template.content.toLowerCase().includes(lowerQ);
      return matchesCategory && matchesSearch;
    });
  }, [activeCategory, searchQuery, visibleTemplates]);

  const sortedTemplates = useMemo(() => {
    return [...filteredTemplates].sort((a, b) => a.name.localeCompare(b.name));
  }, [filteredTemplates]);

  const filteredContacts = useMemo(() => {
    const query = contactQuery.trim().toLowerCase();
    if (!query) return contacts.slice(0, 8);
    return contacts
      .filter((contact) =>
        contact.name.toLowerCase().includes(query) ||
        contact.phone.includes(query) ||
        (contact.email || "").toLowerCase().includes(query)
      )
      .slice(0, 8);
  }, [contactQuery, contacts]);

  const handleOpenModal = (template?: TemplateListItem) => {
    if (template) {
      setEditingTemplate(template.isPrebuilt ? null : template);
      setFormData({ name: template.name, content: template.content, category: template.category || "General" });
    } else {
      setEditingTemplate(null);
      setFormData({ name: "", content: "", category: activeCategory === "All" ? "General" : activeCategory });
    }
    setError(null);
    setIsModalOpen(true);
  };

  const handleCloseModal = () => {
    setIsModalOpen(false);
    setEditingTemplate(null);
    setFormData({ name: "", content: "", category: "General" });
    setError(null);
  };

  const handleSave = async () => {
    if (!formData.name.trim() || !formData.content.trim()) {
      setError("Name and content are required.");
      return;
    }

    const validation = validateTemplateContent(formData.content);
    if (!validation.isValid) {
      setError(formatTemplateValidationMessage(validation));
      return;
    }

    setIsSubmitting(true);
    setError(null);

    try {
      if (editingTemplate) {
        const updated = await updateTemplate(editingTemplate.id, formData.name, formData.content, formData.category);
        setTemplates(prev => prev.map(t => t.id === editingTemplate.id ? updated : t));
      } else {
        const created = await createTemplate(formData.name, formData.content, formData.category);
        setTemplates(prev => [created, ...prev]);
      }
      handleCloseModal();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save template");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDelete = async (id: string) => {
    setIsSubmitting(true);
    try {
      await deleteTemplate(id);
      setTemplates(prev => prev.filter(t => t.id !== id));
      if (deleteConfirmId === id) setDeleteConfirmId(null);
    } catch (err) {
      devLog.error(err);
    } finally {
      setIsSubmitting(false);
    }
  };

  const openQuickSend = (template: TemplateListItem) => {
    const validation = validateTemplateContent(template.content);
    if (!validation.isValid) {
      setError(`Template is missing required variables. ${formatTemplateValidationMessage(validation)}`);
      return;
    }

    setQuickSendTemplate(template);
    setSelectedContact(null);
    setContactQuery("");
    setQuickError(null);

    // Retrieve active contact from storage and pre-select it
    const saved = safeStorage.getItem('nola_active_contact');
    if (saved) {
      try {
        const activeContact = JSON.parse(saved) as Contact;
        if (activeContact && (activeContact.id || activeContact.phone)) {
          const match = contacts.find(c => c.id === activeContact.id || c.phone === activeContact.phone);
          setSelectedContact(match || activeContact);
        }
      } catch (e) {
        devLog.error("Failed to parse active contact from storage", e);
      }
    }
  };

  const handleQuickSend = async () => {
    if (!quickSendTemplate || !selectedContact) return;
    setQuickSending(true);
    setQuickError(null);
    try {
      const resolvedMessage = interpolateTemplate(quickSendTemplate.content, selectedContact);
      const result = await sendSms(
        selectedContact.phone,
        resolvedMessage,
        undefined,
        undefined,
        selectedContact.name,
        undefined,
        selectedContact.ghl_contact_id
      );

      if (!result.success) {
        throw new Error(result.message || result.error || "SMS failed.");
      }

      setNotice(`Sent "${quickSendTemplate.name}" to ${selectedContact.name}.`);
      setQuickSendTemplate(null);
      setTimeout(() => setNotice(null), 2800);
    } catch (err) {
      setQuickError(err instanceof Error ? err.message : "Failed to send SMS.");
    } finally {
      setQuickSending(false);
    }
  };

  return (
    <div className="flex flex-col h-full bg-[#f3f4f6] dark:bg-[#09090b]">
      <div className="flex-shrink-0 bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] rounded-b-[40px] shadow-[0_18px_45px_rgba(29,107,212,0.24)]">
        <div className="max-w-5xl mx-auto px-3 md:px-6 pt-5 pb-7">
          <div className="flex items-center justify-between gap-4 mb-5">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-full bg-white/20 border border-white/20 flex items-center justify-center text-white shadow-md shadow-blue-950/10">
                <FiMessageSquare className="h-5 w-5" />
              </div>
              <div>
                <h2 className="text-[20px] font-extrabold text-white tracking-tight">Templates</h2>
                <p className="text-[12px] text-white/75">{visibleTemplates.length} templates available</p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  setSearchQuery("");
                  handleOpenModal();
                }}
                className="group flex items-center justify-center gap-1 sm:gap-2 bg-white text-[#1d6bd4] px-3 sm:px-4 py-2.5 rounded-2xl text-[13px] font-bold hover:bg-white/90 hover:shadow-[0_8px_25px_rgba(0,0,0,0.16)] active:scale-95 transition-all duration-200"
              >
                <FiPlus className="h-4 w-4" />
                <span className="hidden sm:inline">Add Template</span>
              </button>
            </div>
          </div>

          <div className="relative">
            <FiSearch className="absolute left-3 sm:left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-white/70" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Search templates by name or content..."
              disabled={isModalOpen}
              className="w-full pl-10 sm:pl-11 pr-10 py-2.5 sm:py-3 bg-white/10 border border-white/20 rounded-xl text-[14px] font-medium text-white placeholder-white/70 focus:outline-none focus:ring-2 focus:ring-white/25 focus:border-white/40 transition-all disabled:opacity-50"
            />
            {searchQuery && !isModalOpen && (
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  setSearchQuery("");
                }}
                className="absolute right-3 top-1/2 -translate-y-1/2 p-1 rounded-full hover:bg-white/20 text-white/70 hover:text-white transition-colors"
              >
                <FiX className="h-4 w-4" />
              </button>
            )}
          </div>

          <div className="mt-4 flex gap-2 overflow-x-auto pb-1 custom-scrollbar">
            {CATEGORIES.map((category) => (
              <button
                key={category}
                onClick={() => setActiveCategory(category)}
                className={`px-3 py-1.5 rounded-full text-[12px] font-bold whitespace-nowrap transition-all ${activeCategory === category ? "bg-white text-[#1d6bd4]" : "bg-white/10 text-white/80 hover:bg-white/20"}`}
              >
                {category}
              </button>
            ))}
          </div>
        </div>
      </div>

      <div className="flex-1 overflow-y-auto px-3 md:px-6 py-4 custom-scrollbar">
        <div className="max-w-5xl mx-auto">
          {notice && (
            <div className="mb-3 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-[13px] font-semibold text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
              <FiCheck className="h-4 w-4" />
              {notice}
            </div>
          )}
          {error && (
            <div className="mb-3 flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] font-semibold text-red-600 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
              <FiAlertCircle className="h-4 w-4" />
              {error}
            </div>
          )}

          <div>
              {loading ? (
                <div className="space-y-1">
                  {[1, 2, 3, 4, 5, 6].map((i) => <TemplateSkeleton key={i} />)}
                </div>
              ) : sortedTemplates.length === 0 ? (
                <TemplateEmptyState
                  hasAnyTemplates={visibleTemplates.length > 0}
                  onCreate={() => handleOpenModal()}
                  onResetFilters={() => {
                    setSearchQuery("");
                    setActiveCategory("All");
                  }}
                />
              ) : (
                <div className="flex flex-col gap-2">
                  {sortedTemplates.map((template) => {
                    return (
                    <div
                      key={template.id}
                      role="button"
                      tabIndex={0}
                      onClick={() => openQuickSend(template)}
                      onKeyDown={(event) => {
                        if (event.key === "Enter" || event.key === " ") {
                          event.preventDefault();
                          openQuickSend(template);
                        }
                      }}
                      className="group flex cursor-pointer items-center gap-2 sm:gap-4 p-3 sm:p-4 rounded-xl sm:rounded-2xl bg-white dark:bg-[#1a1b1e] border border-gray-100 dark:border-white/5 shadow-sm transition-all duration-200 hover:border-gray-300 dark:hover:border-white/20 hover:shadow-md focus:outline-none focus:ring-1 focus:ring-gray-300 dark:focus:ring-white/20"
                    >
                      <div className="w-9 sm:w-11 h-9 sm:h-11 rounded-xl sm:rounded-2xl flex items-center justify-center font-bold text-[13px] sm:text-[14px] flex-shrink-0 bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-300">
                        {template.name.charAt(0).toUpperCase()}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <p className="min-w-0 truncate text-[14px] font-semibold text-[#111111] dark:text-[#ececf1]" title={template.name}>{template.name}</p>
                          <span className="hidden sm:inline-flex rounded-full bg-[#eef6ff] dark:bg-white/5 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-[#2b83fa]">
                            {template.category || "General"}
                          </span>
                          {template.isPrebuilt && (
                            <span className="hidden sm:inline-flex rounded-full bg-amber-50 dark:bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-300">
                              Pre-built
                            </span>
                          )}
                        </div>
                        <p className="mt-0.5 truncate text-[12px] text-gray-500 dark:text-gray-400" title={template.content}>{template.content}</p>
                      </div>
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          openQuickSend(template);
                        }}
                        className="p-2 rounded-lg hover:bg-[#eef6ff] dark:hover:bg-white/5 text-gray-400 hover:text-[#2b83fa] transition-all duration-200"
                        title="Quick Send"
                      >
                        <FiSend className="h-4 w-4" />
                      </button>
                      <div className="relative">
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            setOpenMenuId(openMenuId === template.id ? null : template.id);
                          }}
                          className="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-all duration-200"
                          title="More options"
                        >
                          <FiMoreVertical className="h-4 w-4" />
                        </button>
                        {openMenuId === template.id && (
                          <div
                            className="absolute right-0 top-full mt-1 bg-white dark:bg-[#2d2d2d] rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 py-1 min-w-[120px] z-50"
                            onClick={(e) => e.stopPropagation()}
                          >
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                handleOpenModal(template);
                                setOpenMenuId(null);
                              }}
                              className="w-full px-3 py-2 text-left text-[13px] text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 flex items-center gap-2"
                            >
                              <FiEdit2 className="w-4 h-4" />
                              {template.isPrebuilt ? "Customize" : "Edit"}
                            </button>
                            {!template.isPrebuilt && (
                              <button
                                onClick={(e) => {
                                  e.stopPropagation();
                                  setDeleteConfirmId(template.id);
                                  setOpenMenuId(null);
                                }}
                                className="w-full px-3 py-2 text-left text-[13px] text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 flex items-center gap-2"
                              >
                                <FiTrash2 className="w-4 h-4" />
                                Delete
                              </button>
                            )}
                          </div>
                        )}
                      </div>
                    </div>
                    );
                  })}
                </div>
              )}
          </div>
        </div>
      </div>

      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={handleCloseModal} />
          <div className="relative w-full max-w-lg bg-white dark:bg-[#1a1b1e] rounded-3xl shadow-2xl p-6 md:p-8 animate-in zoom-in-95 duration-200 flex flex-col max-h-[90vh]">
            <div className="flex items-center justify-between mb-6">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-[#2b83fa]/10 dark:bg-[#2b83fa]/20 flex items-center justify-center">
                  <FiEdit2 className="h-5 w-5 text-[#2b83fa]" />
                </div>
                <h3 className="text-[20px] font-extrabold text-[#111111] dark:text-[#ececf1] tracking-tight">
                  {editingTemplate ? "Edit Template" : "Create New Template"}
                </h3>
              </div>
              <button onClick={handleCloseModal} className="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-white/5 text-gray-500 transition-colors">
                <FiX className="h-5 w-5" />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto custom-scrollbar pr-2 space-y-5">
              {error && (
                <div className="p-3 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 rounded-xl">
                  <p className="text-[13.5px] font-medium text-red-600 dark:text-red-400">{error}</p>
                </div>
              )}
              <div>
                <label className="block text-[12px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-2">Template Name</label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g. Welcome Message"
                  className="w-full px-4 py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] font-medium text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all"
                />
              </div>
              <div>
                <label className="block text-[12px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-2">Category</label>
                <select
                  value={formData.category}
                  onChange={(e) => setFormData({ ...formData, category: e.target.value })}
                  className="w-full px-4 py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] font-medium text-[#111111] dark:text-[#ececf1] focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all"
                >
                  {EDIT_CATEGORIES.map((category) => (
                    <option key={category} value={category}>{category}</option>
                  ))}
                </select>
              </div>
              <div>
                <div className="flex justify-between items-baseline mb-2">
                  <label className="block text-[12px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest">Message Content</label>
                  <div className="flex items-center gap-2">
                    <div className="relative" ref={customValuesRef}>
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          setIsCustomValuesOpen(!isCustomValuesOpen);
                        }}
                        className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-white/5 hover:bg-gray-200 dark:hover:bg-white/10 text-[11px] font-bold text-[#5f6368] dark:text-[#9aa0a6] uppercase tracking-wider transition-colors"
                      >
                        <FiCode className="h-3 w-3" />
                        Variables
                      </button>
                      {isCustomValuesOpen && (
                        <div className="absolute top-full right-0 sm:left-0 sm:right-auto mt-2 w-52 bg-white dark:bg-[#202123] border border-gray-100 dark:border-white/10 rounded-xl shadow-xl z-50 py-1 max-h-56 overflow-y-auto">
                          {customValues.map((cv) => (
                            <button
                              key={cv.value}
                              onClick={(e) => {
                                e.stopPropagation();
                                setFormData(prev => ({ ...prev, content: prev.content + cv.value }));
                                setIsCustomValuesOpen(false);
                              }}
                              className="w-full text-left px-3 py-2 text-[12px] font-semibold text-[#3c4043] dark:text-[#ececf1] hover:bg-gray-50 dark:hover:bg-white/5 transition-colors"
                            >
                              {cv.label}
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                    <span className="text-[11px] font-medium text-gray-400">{formData.content.length} characters</span>
                  </div>
                </div>
                <textarea
                  value={formData.content}
                  onChange={(e) => setFormData({ ...formData, content: e.target.value })}
                  placeholder="Type your template message here..."
                  rows={6}
                  className="w-full px-4 py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] leading-relaxed text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all resize-none custom-scrollbar"
                />
                <p className="mt-2 text-[11.5px] font-medium leading-relaxed text-gray-500 dark:text-gray-400">
                  Required: add a contact variable such as {"{{contact.first_name}}"} and company variable {"{{company.name}}"} before saving.
                </p>
              </div>
              <PhonePreview template={formData} />
            </div>

            <div className="flex items-center gap-3 mt-8 pt-4 border-t border-gray-100 dark:border-white/5">
              <button onClick={handleCloseModal} className="flex-1 px-4 py-3.5 text-[14px] font-bold text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors">Cancel</button>
              <button
                onClick={handleSave}
                disabled={!formData.name.trim() || !formData.content.trim() || isSubmitting}
                className="flex-[2] flex items-center justify-center gap-2 px-4 py-3.5 bg-[#2b83fa] hover:bg-[#1d6bd4] disabled:bg-gray-200 dark:disabled:bg-gray-800 disabled:cursor-not-allowed text-white disabled:text-gray-400 dark:disabled:text-gray-600 rounded-xl font-bold text-[14px] transition-all duration-200 shadow-md shadow-blue-500/10"
              >
                {isSubmitting ? <FiLoader className="h-5 w-5 animate-spin" /> : "Save"}
              </button>
            </div>
          </div>
        </div>
      )}

      {quickSendTemplate && (
        <div className="fixed inset-0 z-[55] flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={() => setQuickSendTemplate(null)} />
          <div className="relative w-full max-w-4xl bg-white dark:bg-[#1a1b1e] rounded-3xl shadow-2xl p-6 md:p-8 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between mb-6">
              <div>
                <p className="text-[11px] font-black uppercase tracking-widest text-[#2b83fa]">Quick Send</p>
                <h3 className="text-[20px] font-extrabold text-[#111111] dark:text-[#ececf1] tracking-tight">{quickSendTemplate.name}</h3>
              </div>
              <button onClick={() => setQuickSendTemplate(null)} className="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-white/5 text-gray-500 transition-colors">
                <FiX className="h-5 w-5" />
              </button>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_300px] gap-5">
              <div className="space-y-4">
                {quickError && (
                  <div className="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-[12px] font-semibold text-red-600 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                    <FiAlertCircle className="h-4 w-4" />
                    {quickError}
                  </div>
                )}
                <div>
                  <label className="block text-[12px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-2">Contact</label>
                  <input
                    autoFocus
                    value={contactQuery}
                    onChange={(e) => setContactQuery(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        if (selectedContact) {
                          handleQuickSend();
                        } else if (filteredContacts.length > 0) {
                          setSelectedContact(filteredContacts[0]);
                        }
                      }
                    }}
                    placeholder="Search by name, phone, or email..."
                    className="w-full px-4 py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] font-medium text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all"
                  />
                </div>
                <div className="max-h-72 overflow-y-auto rounded-2xl border border-gray-100 dark:border-white/10 custom-scrollbar">
                  {contactsLoading ? (
                    <div className="flex items-center justify-center gap-2 px-4 py-8 text-[13px] font-semibold text-gray-500">
                      <FiLoader className="h-4 w-4 animate-spin" />
                      Loading contacts...
                    </div>
                  ) : filteredContacts.length === 0 ? (
                    <div className="px-4 py-8 text-center text-[13px] font-semibold text-gray-500">No contacts found</div>
                  ) : (
                    filteredContacts.map((contact) => (
                      <button
                        key={contact.id}
                        onClick={() => setSelectedContact(contact)}
                        className={`w-full flex items-center justify-between gap-3 px-4 py-3 text-left border-b border-gray-100 last:border-b-0 dark:border-white/5 transition-colors ${selectedContact?.id === contact.id ? "bg-[#eef6ff] dark:bg-white/10" : "hover:bg-gray-50 dark:hover:bg-white/5"}`}
                      >
                        <div className="min-w-0">
                          <p className="truncate text-[13px] font-bold text-[#111111] dark:text-[#ececf1]">{contact.name}</p>
                          <p className="truncate text-[11px] text-gray-500 dark:text-gray-400">{contact.phone}{contact.email ? ` - ${contact.email}` : ""}</p>
                        </div>
                        {selectedContact?.id === contact.id && <FiCheck className="h-4 w-4 text-[#2b83fa] flex-shrink-0" />}
                      </button>
                    ))
                  )}
                </div>
                <button
                  onClick={handleQuickSend}
                  disabled={!selectedContact || quickSending}
                  className="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-[#2b83fa] px-4 py-3 text-[14px] font-bold text-white shadow-md shadow-blue-500/20 transition-all hover:bg-[#1d6bd4] disabled:bg-gray-200 disabled:text-gray-400 disabled:shadow-none dark:disabled:bg-gray-800"
                >
                  {quickSending ? <FiLoader className="h-4 w-4 animate-spin" /> : <FiSend className="h-4 w-4" />}
                  Send SMS
                </button>
              </div>
              <PhonePreview template={quickSendTemplate} contact={selectedContact || MOCK_CONTACT} />
            </div>
          </div>
        </div>
      )}

      {deleteConfirmId && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={() => setDeleteConfirmId(null)} />
          <div className="relative w-full max-w-sm bg-white dark:bg-[#1a1b1e] rounded-3xl shadow-2xl p-6 md:p-8 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-center mb-5">
              <div className="w-14 h-14 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
                <FiTrash2 className="h-7 w-7 text-red-500" />
              </div>
            </div>
            <h3 className="text-[20px] font-extrabold text-[#111111] dark:text-[#ececf1] text-center mb-3 tracking-tight">Delete Template?</h3>
            <p className="text-[14px] text-gray-500 dark:text-gray-400 text-center mb-8 leading-relaxed">Are you sure you want to delete this template? This action cannot be undone.</p>
            <div className="flex items-center gap-3">
              <button onClick={() => setDeleteConfirmId(null)} className="flex-1 px-4 py-3 text-[14px] font-bold text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors">Cancel</button>
              <button
                onClick={() => handleDelete(deleteConfirmId)}
                disabled={isSubmitting}
                className="flex-1 flex items-center justify-center gap-2 px-4 py-3 bg-red-500 hover:bg-red-600 disabled:bg-red-300 dark:disabled:bg-red-900/50 text-white rounded-xl font-bold text-[14px] transition-all duration-200 shadow-md shadow-red-500/10"
              >
                {isSubmitting ? <FiLoader className="h-5 w-5 animate-spin" /> : "Delete"}
              </button>
            </div>
          </div>
        </div>
      )}
      {/* Sending Overlay — dismissible, non-blocking */}
      {quickSending && (
        <div
          className="fixed inset-0 z-[100] flex flex-col items-center justify-center bg-black/50 backdrop-blur-sm animate-in fade-in duration-200"
          onClick={() => setQuickSendTemplate(null)}
        >
          <div
            className="flex flex-col items-center px-8 pt-6 pb-7 bg-white dark:bg-[#1e1f23] rounded-3xl border border-white/10 shadow-2xl max-w-xs w-full mx-4 text-center animate-in zoom-in-95 duration-200 relative"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Dismiss button */}
            <button
              onClick={() => setQuickSendTemplate(null)}
              className="absolute top-3 right-3 p-1.5 rounded-full text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/10 transition-all"
              title="Dismiss — send continues in background"
            >
              <FiX className="w-4 h-4" />
            </button>

            {/* Lottie animation */}
            <DotLottieReact
              src="https://lottie.host/8bff6661-62db-4473-adb8-7eced34f3649/mii3gOOlir.lottie"
              loop
              autoplay
              className="w-28 h-28 -mt-2 -mb-1"
            />

            <h4 className="text-[16px] font-extrabold text-[#111111] dark:text-white mb-1.5">Sending Message…</h4>
            <p className="text-[13px] text-gray-500 dark:text-gray-400 leading-snug">
              Sending <strong className="text-[#2b83fa]">{quickSendTemplate?.name}</strong> to{" "}
              <strong className="text-gray-800 dark:text-[#ececf1]">{selectedContact?.name}</strong>
            </p>
            <button
              onClick={() => setQuickSendTemplate(null)}
              className="mt-5 text-[11px] font-semibold text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors underline decoration-dashed hover:decoration-solid"
            >
              Dismiss — sending continues in background
            </button>
          </div>
        </div>
      )}
    </div>
  );
};
