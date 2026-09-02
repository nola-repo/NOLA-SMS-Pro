import { devLog } from '../utils/devLog';
import { useState, useEffect, useMemo, useRef, useCallback } from "react";
import { fetchContactsMeta, getCachedContactsMeta, addContact, updateContact, deleteContact, isGhlReconnectError } from "../api/contacts";
import { deleteContact as deleteContactLocal } from "../utils/storage";
import type { Contact } from "../types/Contact";
import { FiSearch, FiX, FiMail, FiCheck, FiUser, FiPlus, FiTrash2, FiMoreVertical, FiEdit2, FiMessageCircle, FiLoader, FiTag, FiAlertCircle, FiCopy } from "react-icons/fi";
import { useLocationId } from "../context/LocationContext";
import { safeStorage } from "../utils/safeStorage";
import { GHL_RECONNECT_REQUIRED_STORAGE_KEY } from "../config";
import { publishAppEvent } from "../utils/appEvents";

// Normalize any PH phone to 09XXXXXXXXX (aligned with send_sms.php clean_numbers)
const normalizePHPhone = (input: string): string => {
  const digits = input.replace(/\D/g, "");
  if (/^09\d{9}$/.test(digits)) return digits;
  if (/^9\d{9}$/.test(digits)) return '0' + digits;
  if (/^639\d{9}$/.test(digits)) return '0' + digits.slice(2);
  return digits;
};

// Format phone for display: 0917 123 4567
const formatDisplayPhone = (phone: string): string => {
  const d = normalizePHPhone(phone);
  if (d.length === 11 && d.startsWith('0')) {
    return `${d.slice(0, 4)} ${d.slice(4, 7)} ${d.slice(7)}`;
  }
  return phone;
};

// Format input as user types: XXXX XXX XXXX
const formatPhoneInput = (raw: string): string => {
  const normalized = normalizePHPhone(raw);
  const digits = normalized.replace(/\D/g, "").substring(0, 11);
  if (digits.length > 7) {
    return `${digits.slice(0, 4)} ${digits.slice(4, 7)} ${digits.slice(7)}`;
  } else if (digits.length > 4) {
    return `${digits.slice(0, 4)} ${digits.slice(4)}`;
  }
  return digits;
};

const normalizeSearchPhone = (value: string): string => value.replace(/\s+/g, "").toLowerCase();

const formatLastMessaged = (value?: string): string => {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return `Last messaged ${date.toLocaleDateString([], { month: "short", day: "numeric", year: "numeric" })}`;
};

const getContactSourceLabel = (contact: Contact): string => {
  const source = (contact.source || "").toLowerCase();
  if (contact.id.startsWith("manual-") || source.includes("manual")) return "";
  if (source.includes("import") || source.includes("csv")) return "Import";
  if (contact.ghl_contact_id || source.includes("ghl") || source.includes("highlevel")) return "GHL Sync";
  return "";
};

const toProperContactName = (name: string): string =>
  name
    .trim()
    .toLowerCase()
    .replace(/\b([a-z])/g, (char) => char.toUpperCase());

const formatContactNameInput = (name: string): string =>
  name.toLowerCase().replace(/\b([a-z])/g, (char) => char.toUpperCase());

type GhlContactsError = {
  kind: 'reconnect' | 'generic';
  message?: string;
};

interface ContactsTabProps {
  onSendToComposer: (contacts: Contact[]) => void;
  onViewMessages?: (contact: Contact) => void;
  autoOpenAddModal?: boolean;
  onAutoOpenAddModalHandled?: () => void;
}

const ContactSkeleton = () => (
  <div className="flex items-center gap-3 sm:gap-4 p-3 sm:p-4 rounded-xl bg-white/50 dark:bg-[#1a1b1e]/50 border border-gray-100 dark:border-white/5 animate-pulse mb-2">
    <div className="w-6 h-6 rounded-lg bg-gray-200 dark:bg-white/10 flex-shrink-0" />
    <div className="w-9 sm:w-11 h-9 sm:h-11 rounded-xl sm:rounded-2xl bg-gray-200 dark:bg-white/10 flex-shrink-0" />
    <div className="flex-1 space-y-2 min-w-0">
      <div className="h-4 w-1/3 bg-gray-200 dark:bg-white/10 rounded-md" />
      <div className="h-3 w-1/4 bg-gray-100 dark:bg-white/5 rounded-md" />
    </div>
    <div className="w-8 h-8 rounded-lg bg-gray-100 dark:bg-white/5 flex-shrink-0" />
  </div>
);

const ContactEmptyState: React.FC<{
  isFiltered: boolean;
  onCreate: () => void;
  onClearFilters: () => void;
}> = ({ isFiltered, onCreate, onClearFilters }) => (
  <div className="flex flex-col items-center justify-center rounded-3xl border border-dashed border-gray-200 bg-white/70 px-6 py-14 text-center shadow-sm dark:border-white/10 dark:bg-[#1a1b1e]/60">
    <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-[#eef6ff] text-[#2b83fa] dark:bg-white/5">
      {isFiltered ? <FiSearch className="h-8 w-8" /> : <FiUser className="h-8 w-8" />}
    </div>
    <h3 className="text-[16px] font-extrabold text-[#111111] dark:text-[#ececf1]">
      {isFiltered ? "No contacts match your filters" : "No contacts yet"}
    </h3>
    <p className="mt-2 max-w-sm text-[13px] font-medium leading-relaxed text-gray-500 dark:text-gray-400">
      {isFiltered
        ? "Clear the search or tag filters to return to your full contact list."
        : "Add your first recipient or reconnect your CRM so synced contacts appear here."}
    </p>
    <button
      onClick={isFiltered ? onClearFilters : onCreate}
      className="mt-5 inline-flex items-center justify-center gap-2 rounded-xl bg-[#2b83fa] px-4 py-2.5 text-[13px] font-bold text-white shadow-md shadow-blue-500/15 transition-all hover:bg-[#1d6bd4] active:scale-95"
    >
      {isFiltered ? <FiX className="h-4 w-4" /> : <FiPlus className="h-4 w-4" />}
      {isFiltered ? "Clear filters" : "Add Contact"}
    </button>
  </div>
);

export const ContactsTab: React.FC<ContactsTabProps> = ({
  onSendToComposer,
  onViewMessages,
  autoOpenAddModal,
  onAutoOpenAddModalHandled,
}) => {
  const { locationId } = useLocationId();
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [selectedContacts, setSelectedContacts] = useState<Contact[]>([]);
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedTags, setSelectedTags] = useState<string[]>([]);
  const [isTagDropdownOpen, setIsTagDropdownOpen] = useState(false);
  const tagDropdownRef = useRef<HTMLDivElement>(null);
  const [loading, setLoading] = useState(true);
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [newContactName, setNewContactName] = useState("");
  const [newContactPhone, setNewContactPhone] = useState("");
  const [deleteConfirmId, setDeleteConfirmId] = useState<string | null>(null);
  const [openMenuId, setOpenMenuId] = useState<string | null>(null);
  const [editingContact, setEditingContact] = useState<Contact | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isPullRefreshing, setIsPullRefreshing] = useState(false);
  const [ghlContactsError, setGhlContactsError] = useState<GhlContactsError | null>(null);
  const [contactsRefreshing, setContactsRefreshing] = useState(false);
  const [contactsCacheNote, setContactsCacheNote] = useState<string | null>(null);
  const [copyNotice, setCopyNotice] = useState<string | null>(null);

  const touchStartY = useRef<number>(0);
  const listRef = useRef<HTMLDivElement>(null);
  const fetchAbortControllerRef = useRef<AbortController | null>(null);

  const [debouncedSearchQuery, setDebouncedSearchQuery] = useState("");
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearchQuery(searchQuery);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  const applyContactsFetch = useCallback((result: Awaited<ReturnType<typeof fetchContactsMeta>>) => {
    if (result.ok) {
      setContacts(result.contacts);
      setGhlContactsError(null);
      if (result.cacheMeta?.status === 'failed') {
        setContactsCacheNote('Showing last synced contacts. Refresh failed.');
      } else if (result.cacheMeta?.stale) {
        setContactsCacheNote('Showing last synced contacts.');
      } else if (result.cacheMeta?.cached) {
        setContactsCacheNote(null);
      } else {
        setContactsCacheNote(null);
      }
      return;
    }

    setContacts((current) => {
      if (current.length > 0) {
        setContactsCacheNote(result.message || 'Could not refresh contacts. Showing current list.');
        return current;
      }
      setSelectedContacts([]);
      return [];
    });
    setGhlContactsError({
      kind: result.kind === 'reconnect' ? 'reconnect' : 'generic',
      message: result.message,
    });
  }, []);

  const navigateToSettings = () => {
    safeStorage.setItem(GHL_RECONNECT_REQUIRED_STORAGE_KEY, 'true');
    publishAppEvent('navigate-to-settings', { tab: 'account', reconnect: true });
  };

  const setReconnectError = (message?: string) => {
    setGhlContactsError({
      kind: 'reconnect',
      message: message || 'GoHighLevel connection expired',
    });
    setSelectedContacts([]);
  };

  const handleMutationError = (err: unknown, fallback: string): string => {
    if (isGhlReconnectError(err)) {
      setReconnectError(err.message);
      return 'Reconnect your CRM in Settings, then try again.';
    }
    return err instanceof Error ? err.message : fallback;
  };

  useEffect(() => {
    let cancelled = false;
    fetchAbortControllerRef.current?.abort();
    const controller = new AbortController();
    fetchAbortControllerRef.current = controller;

    setLoading(true);
    setGhlContactsError(null);

    const cached = getCachedContactsMeta(locationId || undefined);
    if (cached?.ok) {
      applyContactsFetch(cached);
      setContactsRefreshing(true);
    }

    fetchContactsMeta(locationId || undefined, { forceRefresh: Boolean(cached), signal: controller.signal })
      .then((result) => {
        if (!cancelled && !controller.signal.aborted) applyContactsFetch(result);
      })
      .catch((err) => {
        if (err?.name === 'AbortError' || controller.signal.aborted) return;
        devLog.error(err);
        if (!cancelled) {
          setContacts([]);
          setGhlContactsError({ kind: 'generic', message: 'Contacts could not be loaded. Check your connection and try again.' });
        }
      })
      .finally(() => {
        if (!cancelled && !controller.signal.aborted) {
          setLoading(false);
          setContactsRefreshing(false);
        }
      });

    return () => {
      cancelled = true;
      controller.abort();
    };
  }, [applyContactsFetch, locationId]);

  useEffect(() => {
    if (!autoOpenAddModal) return;

    setError(null);
    setSearchQuery("");
    setIsAddModalOpen(true);
    onAutoOpenAddModalHandled?.();
  }, [autoOpenAddModal, onAutoOpenAddModalHandled]);

  const refreshContacts = async () => {
    fetchAbortControllerRef.current?.abort();
    const controller = new AbortController();
    fetchAbortControllerRef.current = controller;

    setIsPullRefreshing(true);
    setContactsRefreshing(true);
    try {
      const result = await fetchContactsMeta(locationId || undefined, { forceRefresh: true, signal: controller.signal });
      if (!controller.signal.aborted) applyContactsFetch(result);
    } catch (e: any) {
      if (e?.name === 'AbortError' || controller.signal.aborted) return;
      devLog.error(e);
      setGhlContactsError({ kind: 'generic', message: 'Contacts could not be refreshed. Your previous list will remain visible when available.' });
    } finally {
      if (!controller.signal.aborted) {
        setIsPullRefreshing(false);
        setContactsRefreshing(false);
      }
    }
  };

  // Close menu when clicking outside
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      setOpenMenuId(null);
      if (tagDropdownRef.current && !tagDropdownRef.current.contains(e.target as Node)) {
        setIsTagDropdownOpen(false);
      }
    };
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, []);

  // Convert name to proper case (title case)
  const toProperCase = (name: string): string => {
    return name.replace(/\b\w/g, (char) => char.toUpperCase());
  };

  const phoneCounts = useMemo(() => {
    const counts = new Map<string, number>();
    contacts.forEach((contact) => {
      const key = normalizePHPhone(contact.phone);
      if (!/^09\d{9}$/.test(key)) return;
      counts.set(key, (counts.get(key) || 0) + 1);
    });
    return counts;
  }, [contacts]);

  const copyPhoneNumber = async (phone: string) => {
    const normalized = normalizePHPhone(phone);
    try {
      await navigator.clipboard.writeText(normalized || phone);
      setCopyNotice("Phone number copied");
      window.setTimeout(() => setCopyNotice(null), 1800);
    } catch {
      setCopyNotice("Could not copy phone number");
      window.setTimeout(() => setCopyNotice(null), 1800);
    }
  };

  const allTags = useMemo(() => {
    const tagSet = new Set<string>();
    contacts.forEach(c => {
      if (c.tags) {
        c.tags.forEach(t => tagSet.add(t));
      }
    });
    return Array.from(tagSet).sort();
  }, [contacts]);

  const filteredContacts = useMemo(() => {
    let result = contacts;
    if (selectedTags.length > 0) {
      result = result.filter(c => c.tags?.some(t => selectedTags.includes(t)));
    }
    if (debouncedSearchQuery) {
      const lowerQ = debouncedSearchQuery.toLowerCase();
      const phoneQ = normalizeSearchPhone(debouncedSearchQuery);
      result = result.filter(
        (c) =>
          c.name.toLowerCase().includes(lowerQ) ||
          normalizeSearchPhone(c.phone).includes(phoneQ)
      );
    }
    return result;
  }, [debouncedSearchQuery, contacts, selectedTags]);

  // Group contacts by first letter (or by phone number if name is a phone number)
  const groupedContacts = useMemo(() => {
    const groups: Record<string, Contact[]> = {};
    filteredContacts.forEach((contact) => {
      // Guard against null/undefined names from GHL raw API
      const safeName = contact.name || contact.phone || '?';
      // If name looks like a phone number, use the first digit for grouping
      let firstLetter: string;
      if (/^\d/.test(safeName)) {
        firstLetter = safeName.charAt(0) || '#';
      } else {
        firstLetter = (safeName.charAt(0) || '#').toUpperCase();
      }
      if (!groups[firstLetter]) {
        groups[firstLetter] = [];
      }
      groups[firstLetter].push(contact);
    });
    return Object.entries(groups).sort(([a], [b]) => a.localeCompare(b));
  }, [filteredContacts]);

  const handleToggleContact = (contact: Contact) => {
    setSelectedContacts((prev) => {
      const isSelected = prev.some((c) => c.id === contact.id);
      if (isSelected) {
        return prev.filter((c) => c.id !== contact.id);
      }
      return [...prev, contact];
    });
  };

  const handleSelectAll = () => {
    setSelectedContacts([...filteredContacts]);
  };

  const handleClearSelection = () => {
    setSelectedContacts([]);
  };

  const handleSendToComposer = () => {
    if (selectedContacts.length > 0) {
      onSendToComposer(selectedContacts);
    }
  };

  const handleAddContact = async () => {
    const normalized = normalizePHPhone(newContactPhone);
    if (!newContactName.trim() || !/^09\d{9}$/.test(normalized)) {
      setError("Please enter a valid Philippine mobile number (e.g. 0917-123-4567)");
      return;
    }

    // Check for duplicates (normalized comparison)
    const isDuplicate = contacts.some(c => normalizePHPhone(c.phone) === normalized);
    if (isDuplicate) {
      setError("A contact with this phone number already exists.");
      return;
    }

    setIsSubmitting(true);
    setError(null);

    try {
      // Send +63 format to GHL API (strip leading 0, prepend +63)
      const ghlPhone = '+63' + normalized.slice(1);
      const newContact = await addContact({
        name: toProperContactName(newContactName),
        phone: ghlPhone,
      });

      if (newContact) {
        // Normalize newly added contact phone for consistency
        newContact.phone = normalizePHPhone(newContact.phone);
        setContacts((prev) => [...prev, newContact]);
        setSearchQuery("");
        setGhlContactsError(null);
      }
    } catch (err: unknown) {
      devLog.error("Error adding contact:", err);
      setError(handleMutationError(err, "Failed to add contact to GHL"));
    } finally {
      setIsSubmitting(false);
      setNewContactName("");
      setNewContactPhone("");
      setIsAddModalOpen(false);
    }
  };

  const handleEditContact = async () => {
    if (!editingContact) return;
    const normalized = normalizePHPhone(editingContact.phone);
    if (!editingContact.name.trim() || !/^09\d{9}$/.test(normalized)) {
      setError("Please enter a valid Philippine mobile number (e.g. 0917-123-4567)");
      return;
    }

    // Check for duplicates (excluding the current contact being edited)
    const isDuplicate = contacts.some(c => normalizePHPhone(c.phone) === normalized && c.id !== editingContact.id);
    if (isDuplicate) {
      setError("A contact with this phone number already exists.");
      return;
    }

    setIsSubmitting(true);
    setError(null);

    const originalContact = contacts.find((c) => c.id === editingContact.id) || editingContact;
    const displayName = toProperContactName(editingContact.name);
    const displayPhone = normalizePHPhone(editingContact.phone);
    const nextContact = {
      ...editingContact,
      name: displayName,
      phone: displayPhone,
    };

    const applyContact = (contact: Contact, previous: Contact | null) => {
      setContacts((prev) => prev.map((c) =>
        c.id === contact.id ? contact : c
      ));
      setSelectedContacts((prev) => prev.map((c) =>
        c.id === contact.id ? { ...c, ...contact } : c
      ));
      publishAppEvent('nola-contact-updated', { contact, previous, locationId });
    };

    applyContact(nextContact, originalContact);
    setGhlContactsError(null);
    setEditingContact(null);
    setIsSubmitting(false);

    // Only update GHL contacts through the API. Manual contacts are already saved locally.
    if (editingContact.id.startsWith('manual-')) return;

    const ghlPhone = '+63' + normalized.slice(1);
    updateContact({
      id: editingContact.id,
      name: displayName,
      phone: ghlPhone,
      previousPhone: originalContact.phone,
      previousName: originalContact.name,
    })
      .then((updated) => {
        if (!updated) return;
        const confirmedContact = {
          ...nextContact,
          ...updated,
          name: toProperContactName(updated.name || displayName),
          phone: normalizePHPhone(updated.phone || displayPhone),
        };
        applyContact(confirmedContact, originalContact);
      })
      .catch((err: unknown) => {
        devLog.error("Error updating contact:", err);
        applyContact(originalContact, nextContact);
        setError(handleMutationError(err, "Failed to update contact in GHL"));
      });
  };

  const handleDeleteContact = async (contactId: string, e?: React.MouseEvent) => {
    e?.stopPropagation();

    setIsSubmitting(true);
    setError(null);

    try {
      // Only delete from GHL if it's a GHL contact (has numeric ID, not starting with 'manual-')
      if (!contactId.startsWith('manual-')) {
        await deleteContact(contactId);
      }

      // Add to local deleted list so it's hidden from Sidebar too
      deleteContactLocal(contactId);

      // Remove from contacts list
      setContacts((prev) => prev.filter((c) => c.id !== contactId));
      // Remove from selected if selected
      setSelectedContacts((prev) => prev.filter((c) => c.id !== contactId));
      setGhlContactsError(null);
    } catch (err: unknown) {
      devLog.error("Error deleting contact:", err);
      setError(handleMutationError(err, "Failed to delete contact from GHL"));
    } finally {
      setIsSubmitting(false);
    }
  };

  const isAllSelected = filteredContacts.length > 0 && selectedContacts.length === filteredContacts.length;


  return (
    <div className="flex flex-col h-full bg-[#f3f4f6] dark:bg-[#09090b] relative">
      {/* Header */}
      <div className="flex-shrink-0 bg-gradient-to-br from-[#2b83fa] to-[#1d6bd4] rounded-b-[40px] shadow-[0_18px_45px_rgba(29,107,212,0.24)]">
        <div className="max-w-5xl mx-auto px-3 md:px-6 pt-5 pb-7">
          <div className="flex items-center justify-between gap-4 mb-5">
            <div className="flex items-center gap-3 min-w-0">
              <div className="w-10 h-10 rounded-full bg-white/20 border border-white/20 flex items-center justify-center text-white shadow-md shadow-blue-950/10 flex-shrink-0">
                <FiUser className="h-5 w-5" />
              </div>
              <div className="min-w-0">
                <h2 className="text-[20px] font-extrabold text-white tracking-tight">Contacts</h2>
                <p className="text-[12px] text-white/75">
                  {contacts.length} contacts available{contactsRefreshing ? ' / Updating...' : ''}
                </p>
                {contactsCacheNote && (
                  <p className="mt-0.5 text-[11px] font-bold text-white/85">{contactsCacheNote}</p>
                )}
                {copyNotice && (
                  <p className="mt-0.5 text-[11px] font-bold text-white/85">{copyNotice}</p>
                )}
              </div>
            </div>
            <button
              onClick={(e) => {
                e.stopPropagation();
                setSearchQuery("");
                setIsAddModalOpen(true);
              }}
              className="group flex flex-shrink-0 items-center justify-center gap-1 sm:gap-2 bg-white text-[#1d6bd4] px-3 sm:px-4 py-2.5 rounded-2xl text-[13px] font-bold hover:bg-white/90 hover:shadow-[0_8px_25px_rgba(0,0,0,0.16)] active:scale-95 transition-all duration-200"
            >
              <FiPlus className="h-4 w-4" />
              <span className="hidden sm:inline">Add Contact</span>
            </button>
          </div>

          {/* Search Bar and Tag Filter */}
          <div className="flex flex-col sm:flex-row gap-3 relative z-20">
            <div className="relative flex-1">
              <FiSearch className="absolute left-3 sm:left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-white/70" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search by name or phone..."
                disabled={isAddModalOpen}
                className="w-full pl-10 sm:pl-11 pr-10 py-2.5 sm:py-3 bg-white/10 border border-white/20 rounded-xl text-[14px] font-medium text-white placeholder-white/70 focus:outline-none focus:ring-2 focus:ring-white/25 focus:border-white/40 transition-all disabled:opacity-50"
              />
              {searchQuery && !isAddModalOpen && (
                <button
                  onClick={(e) => {
                    e.stopPropagation();
                    setSearchQuery("");
                  }}
                  className="absolute right-3 sm:right-3 top-1/2 -translate-y-1/2 p-1 rounded-full hover:bg-white/20 text-white/70 hover:text-white transition-colors"
                >
                  <FiX className="h-4 w-4" />
                </button>
              )}
            </div>

            {/* Tag Filter Dropdown */}
            <div className="relative w-full sm:w-auto" ref={tagDropdownRef}>
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  setIsTagDropdownOpen(!isTagDropdownOpen);
                }}
                className={`flex items-center justify-center p-2.5 sm:p-3 border rounded-xl transition-all w-[44px] h-[44px] sm:w-[48px] sm:h-[48px] shadow-sm
                  ${selectedTags.length > 0 ? 'text-[#1d6bd4] border-white bg-white' : 'text-white border-white/20 bg-white/10 hover:bg-white/20'}`}
                title="Filter by Tags"
              >
                <div className="relative">
                  <FiTag className="h-[18px] w-[18px] sm:h-5 sm:w-5" />
                  {selectedTags.length > 0 && (
                    <span className="absolute -top-1.5 -right-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-[#1d6bd4] text-[10px] font-bold text-white border-2 border-white">
                      {selectedTags.length}
                    </span>
                  )}
                </div>
              </button>

              {isTagDropdownOpen && (
                <div className="absolute top-full right-0 mt-2 w-full sm:w-64 max-h-60 overflow-y-auto bg-white dark:bg-[#1a1b1e] border border-gray-200/60 dark:border-white/10 rounded-xl shadow-xl z-50 custom-scrollbar p-2">
                  {allTags.length === 0 ? (
                    <div className="px-3 py-4 text-center text-[13px] text-gray-500 font-medium">No tags available</div>
                  ) : (
                    allTags.map(tag => {
                      const isSelected = selectedTags.includes(tag);
                      return (
                        <div
                          key={tag}
                          onClick={(e) => {
                            e.stopPropagation();
                            setSelectedTags(prev => 
                              prev.includes(tag) ? prev.filter(t => t !== tag) : [...prev, tag]
                            );
                          }}
                          className={`flex items-center gap-2.5 px-3 py-2 rounded-lg cursor-pointer transition-colors
                            ${isSelected ? 'bg-[#2b83fa]/10 text-[#2b83fa]' : 'hover:bg-gray-50 dark:hover:bg-white/5 text-[#111111] dark:text-[#ececf1]'}`}
                        >
                          <div className={`w-4 h-4 rounded border flex items-center justify-center flex-shrink-0
                            ${isSelected ? 'border-[#2b83fa] bg-[#2b83fa]' : 'border-gray-300 dark:border-gray-600'}`}
                          >
                            {isSelected && <FiCheck className="h-3 w-3 text-white" />}
                          </div>
                          <span className="text-[13px] font-medium truncate" title={tag}>{tag}</span>
                        </div>
                      );
                    })
                  )}
                </div>
              )}
            </div>
          </div>

          {/* Selection Controls */}
          <div className="flex items-center justify-between gap-3 mt-4 pt-3 border-t border-white/20">
            <div className="flex items-center gap-2 sm:gap-3">
              <button
                onClick={isAllSelected ? handleClearSelection : handleSelectAll}
                className={`flex items-center gap-2 px-3 sm:px-4 py-2 rounded-xl text-[12px] font-bold transition-all duration-200 border shadow-sm ${isAllSelected
                  ? "bg-white text-[#1d6bd4] border-white shadow-blue-950/10 hover:bg-white/90"
                  : "bg-white/10 text-white hover:bg-white/20 border-white/20"
                  }`}
              >
                <FiCheck className="h-3.5 w-3.5" />
                <span className="hidden sm:inline">{isAllSelected ? "Deselect All" : "Select All"}</span>
                <span className="sm:hidden">{isAllSelected ? "Deselect All" : "Select All"}</span>
              </button>
              {selectedContacts.length > 0 && (
                <button
                  onClick={handleClearSelection}
                  className="px-3 sm:px-4 py-2 text-[12px] font-semibold text-white/75 hover:text-white hover:bg-white/10 rounded-xl transition-all duration-200"
                >
                  Clear
                </button>
              )}
            </div>
            <span className="text-[12px] font-bold text-white bg-white/10 border border-white/20 px-3 py-1 rounded-full whitespace-nowrap">
              {selectedContacts.length} selected
            </span>
          </div>
        </div>
      </div>
      {/* Contacts List */}
      <div
        ref={listRef}
        className="flex-1 overflow-y-auto px-3 md:px-6 py-4 custom-scrollbar"
        onTouchStart={(e) => { touchStartY.current = e.touches[0].clientY; }}
        onTouchEnd={(e) => {
          const delta = e.changedTouches[0].clientY - touchStartY.current;
          const atTop = (listRef.current?.scrollTop ?? 0) === 0;
          if (delta > 60 && atTop && !isPullRefreshing) refreshContacts();
        }}
      >
        <div className="max-w-5xl mx-auto">
          {/* Pull-to-refresh spinner */}
          {isPullRefreshing && (
            <div className="flex justify-center items-center py-3">
              <svg className="animate-spin h-5 w-5 text-[#2b83fa]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
              </svg>
            </div>
          )}
          {ghlContactsError && (
            <div className={`mb-4 rounded-xl border p-4 shadow-sm ${
              ghlContactsError.kind === 'reconnect'
                ? 'bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20'
                : 'bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/20'
            }`}>
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div className="flex items-start gap-3 min-w-0">
                  <div className={`mt-0.5 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg ${
                    ghlContactsError.kind === 'reconnect'
                      ? 'bg-amber-100 dark:bg-amber-500/15 text-amber-700 dark:text-amber-300'
                      : 'bg-red-100 dark:bg-red-500/15 text-red-600 dark:text-red-300'
                  }`}>
                    <FiAlertCircle className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <h3 className={`text-[14px] font-bold ${
                      ghlContactsError.kind === 'reconnect'
                        ? 'text-amber-900 dark:text-amber-200'
                        : 'text-red-800 dark:text-red-200'
                    }`}>
                      {ghlContactsError.kind === 'reconnect'
                        ? 'GoHighLevel connection expired'
                        : 'Contacts could not be loaded'}
                    </h3>
                    <p className={`mt-1 text-[13px] leading-snug ${
                      ghlContactsError.kind === 'reconnect'
                        ? 'text-amber-800/90 dark:text-amber-200/80'
                        : 'text-red-700/90 dark:text-red-200/80'
                    }`}>
                      {ghlContactsError.kind === 'reconnect'
                        ? 'Reconnect your CRM to load contacts.'
                        : (ghlContactsError.message || 'Try again in a moment.')}
                    </p>
                  </div>
                </div>
                <button
                  onClick={ghlContactsError.kind === 'reconnect' ? navigateToSettings : refreshContacts}
                  disabled={ghlContactsError.kind === 'generic' && isPullRefreshing}
                  className={`flex-shrink-0 rounded-xl px-4 py-2 text-[13px] font-bold transition-all active:scale-95 disabled:opacity-60 ${
                    ghlContactsError.kind === 'reconnect'
                      ? 'bg-amber-600 text-white hover:bg-amber-700'
                      : 'bg-red-600 text-white hover:bg-red-700'
                  }`}
                >
                  {ghlContactsError.kind === 'reconnect' ? 'Open Settings' : 'Try again'}
                </button>
              </div>
            </div>
          )}
          {loading ? (
            <div className="space-y-1">
              {[1, 2, 3, 4, 5, 6].map((i) => (
                <ContactSkeleton key={i} />
              ))}
            </div>
          ) : ghlContactsError ? null : groupedContacts.length === 0 ? (
            <ContactEmptyState
              isFiltered={Boolean(searchQuery || selectedTags.length > 0)}
              onCreate={() => {
                setSearchQuery("");
                setSelectedTags([]);
                setIsAddModalOpen(true);
              }}
              onClearFilters={() => {
                setSearchQuery("");
                setSelectedTags([]);
              }}
            />
          ) : (
            <div className="space-y-6">
              {groupedContacts.map(([letter, letterContacts]) => (
                <div key={letter}>
                  <h3 className="text-[11px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-2 ml-1">
                    {letter}
                  </h3>
                  <div className="flex flex-col gap-1">
                    {letterContacts.map((contact) => {
                      const isSelected = selectedContacts.some((c) => c.id === contact.id);
                      const normalizedPhone = normalizePHPhone(contact.phone);
                      const isDuplicatePhone = /^09\d{9}$/.test(normalizedPhone) && (phoneCounts.get(normalizedPhone) || 0) > 1;
                      const sourceLabel = getContactSourceLabel(contact);
                      const lastMessagedLabel = formatLastMessaged(contact.lastSentAt);
                      return (
                        <div
                          key={contact.id}
                          onClick={() => handleToggleContact(contact)}
                          className={`
                            group flex items-center gap-2 sm:gap-4 p-3 sm:p-4 rounded-xl sm:rounded-2xl cursor-pointer transition-all duration-200
                            ${isSelected
                              ? "bg-[#2b83fa]/10 dark:bg-[#2b83fa]/15 border border-[#2b83fa]/20"
                              : "bg-white dark:bg-[#1a1b1e] border border-gray-100 dark:border-white/5 hover:border-gray-200 dark:hover:border-white/10 shadow-sm"
                            }
                          `}
                        >
                          {/* Checkbox */}
                          <div
                            className={`
                              w-6 h-6 rounded-lg border-2 flex items-center justify-center transition-all duration-200 flex-shrink-0
                              ${isSelected
                                ? "bg-[#2b83fa] border-[#2b83fa]"
                                : "border-gray-300 dark:border-gray-600 group-hover:border-[#2b83fa]"
                              }
                            `}
                          >
                            {isSelected && <FiCheck className="h-4 w-4 text-white" />}
                          </div>
                          {/* Avatar - show phone icon for phone-number contacts */}
                          <div
                            className={`
                              w-9 sm:w-11 h-9 sm:h-11 rounded-xl sm:rounded-2xl flex items-center justify-center font-bold text-[13px] sm:text-[14px] flex-shrink-0 transition-all duration-200
                              ${isSelected
                                ? "bg-[#2b83fa] text-white shadow-lg shadow-blue-500/20"
                                : "bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-300"
                              }
                            `}
                          >
                            {(() => {
                              // If name looks like a phone number, show a phone icon
                              if (/^\d/.test(contact.name)) {
                                return (
                                  <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                  </svg>
                                );
                              }
                              // Otherwise show initials
                              const parts = contact.name.split(" ").filter((p) => p.length > 0);
                              const first = parts[0]?.charAt(0) || "";
                              const last = parts.length > 1 ? parts[parts.length - 1]?.charAt(0) || "" : "";
                              return (first + last).toUpperCase() || "?";
                            })()}
                          </div>
                          {/* Info */}
                          <div className="flex-1 min-w-0 flex flex-col justify-center">
                            <div className="flex items-center gap-2 w-full overflow-hidden">
                              <p
                                className={`
                                  text-[14px] font-semibold truncate transition-colors flex-shrink min-w-0
                                  ${isSelected ? "text-[#2b83fa]" : "text-[#111111] dark:text-[#ececf1]"}
                                `}
                              >
                                {toProperCase(contact.name)}
                              </p>
                              {sourceLabel && (
                                <span className="text-[10px] font-black uppercase bg-[#eef6ff] dark:bg-[#2b83fa]/15 text-[#1d6bd4] dark:text-[#8bbcff] px-1.5 py-0.5 rounded-md flex-shrink-0">
                                  {sourceLabel}
                                </span>
                              )}
                              {isDuplicatePhone && (
                                <span className="text-[10px] font-black uppercase bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded-md flex-shrink-0" title="Another contact has this phone number">
                                  Duplicate
                                </span>
                              )}
                              {contact.tags && contact.tags.length > 0 && (
                                <div className="flex items-center gap-1.5 overflow-hidden flex-shrink-0">
                                  {contact.tags.map(tag => (
                                    <span key={tag} title={tag} className="text-[10px] font-medium bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-300 px-1.5 py-0.5 rounded-md truncate max-w-[80px] sm:max-w-[120px] flex-shrink-0">
                                      {tag}
                                    </span>
                                  ))}
                                </div>
                              )}
                            </div>
                            <p className="mt-0.5 truncate text-[12px] text-gray-500 dark:text-gray-400" title={formatDisplayPhone(contact.phone)}>
                              {formatDisplayPhone(contact.phone)}
                            </p>
                            {lastMessagedLabel && (
                              <p className="text-[11px] text-gray-400 dark:text-gray-500 truncate mt-0.5">
                                {lastMessagedLabel}
                              </p>
                            )}
                          </div>
                          {/* Last message preview */}
                          {contact.lastMessage && (
                            <div className="hidden md:block flex-1 min-w-0">
                              <p className="text-[12px] text-gray-500 dark:text-gray-400 truncate">
                                {contact.lastMessage}
                              </p>
                            </div>
                          )}
                          {/* More button with dropdown */}
                          <div className="relative">
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                setOpenMenuId(openMenuId === contact.id ? null : contact.id);
                              }}
                              className="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-all duration-200"
                              title="More options"
                            >
                              <FiMoreVertical className="h-4 w-4" />
                            </button>
                            {openMenuId === contact.id && (
                              <div
                                className="absolute right-0 top-full mt-1 bg-white dark:bg-[#2d2d2d] rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 py-1 min-w-[168px] z-50"
                                onClick={(e) => e.stopPropagation()}
                              >
                                <button
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    setEditingContact({
                                      ...contact,
                                      name: formatContactNameInput(contact.name),
                                      phone: normalizePHPhone(contact.phone),
                                    });
                                    setOpenMenuId(null);
                                  }}
                                  className="w-full px-3 py-2 text-left text-[13px] text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 flex items-center gap-2 whitespace-nowrap"
                                >
                                  <FiEdit2 className="w-4 h-4" />
                                  Edit
                                </button>
                                <button
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    copyPhoneNumber(contact.phone);
                                    setOpenMenuId(null);
                                  }}
                                  className="w-full px-3 py-2 text-left text-[13px] text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 flex items-center gap-2 whitespace-nowrap"
                                >
                                  <FiCopy className="w-4 h-4" />
                                  Copy Phone
                                </button>
                                {onViewMessages && (
                                  <button
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      onViewMessages(contact);
                                      setOpenMenuId(null);
                                    }}
                                    className="w-full px-3 py-2 text-left text-[13px] text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 flex items-center gap-2 whitespace-nowrap"
                                  >
                                    <FiMessageCircle className="w-4 h-4" />
                                    View Messages
                                  </button>
                                )}
                                <button
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    setDeleteConfirmId(contact.id);
                                    setOpenMenuId(null);
                                  }}
                                  className="w-full px-3 py-2 text-left text-[13px] text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 flex items-center gap-2 whitespace-nowrap"
                                >
                                  <FiTrash2 className="w-4 h-4" />
                                  Delete
                                </button>
                              </div>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
      {/* Floating Action Bar */}
      {selectedContacts.length > 0 && (
        <div className="absolute bottom-4 sm:bottom-6 left-1/2 -translate-x-1/2 z-50 animate-in slide-in-from-bottom-4 fade-in duration-300 px-3 sm:px-0">
          <div className="flex flex-col sm:flex-row items-center gap-3 sm:gap-4 px-4 sm:px-6 py-3 sm:py-4 bg-white dark:bg-[#1a1b1e] rounded-2xl shadow-2xl shadow-black/20 dark:shadow-black/30 border border-gray-100 dark:border-white/10">
            <span className="text-[13px] sm:text-[14px] font-bold text-gray-700 dark:text-white">
              {selectedContacts.length} contact{selectedContacts.length !== 1 ? "s" : ""} selected
            </span>
            <div className="flex items-center gap-2 w-full sm:w-auto">
              <button
                onClick={handleClearSelection}
                className="flex-1 sm:flex-none px-4 py-2 text-[13px] font-semibold text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-white transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleSendToComposer}
                className="flex-1 sm:flex-none group flex items-center justify-center gap-2 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white px-4 sm:px-5 py-2.5 rounded-2xl font-bold text-[13px] hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] active:scale-95 transition-all duration-200"
              >
                <FiMail className="h-4 w-4" />
                Send
              </button>
            </div>
          </div>
        </div>
      )}
      {/* Delete Confirmation Modal */}
      {deleteConfirmId && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div
            className="absolute inset-0 bg-black/50 backdrop-blur-sm"
            onClick={() => setDeleteConfirmId(null)}
          />
          <div className="relative w-full max-w-sm bg-white dark:bg-[#1a1b1e] rounded-2xl shadow-2xl p-4 sm:p-6 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-center mb-4">
              <div className="w-12 h-12 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
                <FiTrash2 className="h-6 w-6 text-red-500" />
              </div>
            </div>
            <h3 className="text-[16px] sm:text-[18px] font-bold text-[#111111] dark:text-[#ececf1] text-center mb-2">
              Delete Contact?
            </h3>
            <p className="text-[13px] sm:text-[14px] text-gray-500 dark:text-gray-400 text-center mb-6">
              Are you sure you want to delete this contact? This action cannot be undone.
            </p>
            <div className="flex flex-col-reverse sm:flex-row items-center gap-3">
              <button
                onClick={() => setDeleteConfirmId(null)}
                className="w-full sm:flex-1 px-4 py-2.5 text-[14px] font-semibold text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={() => {
                  handleDeleteContact(deleteConfirmId);
                  setDeleteConfirmId(null);
                }}
                disabled={isSubmitting}
                className="w-full sm:flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-red-500 hover:bg-red-600 disabled:bg-red-400 text-white rounded-xl font-semibold text-[14px] transition-all duration-200"
              >
                {isSubmitting ? (
                  <>
                    <FiLoader className="h-4 w-4 animate-spin" />
                    Deleting...
                  </>
                ) : (
                  <>
                    <FiTrash2 className="h-4 w-4" />
                    Delete
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
      {/* Add Contact Modal */}
      {isAddModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div
            className="absolute inset-0 bg-black/50 backdrop-blur-sm"
            onClick={() => setIsAddModalOpen(false)}
          />
          <div className="relative w-full max-w-md bg-white dark:bg-[#1a1b1e] rounded-2xl shadow-2xl p-4 sm:p-6 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between mb-4 sm:mb-6">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-[#2b83fa]/10 dark:bg-[#2b83fa]/20 flex items-center justify-center">
                  <FiUser className="h-4 w-4 text-[#2b83fa]" />
                </div>
                <h3 className="text-[16px] sm:text-[18px] font-bold text-[#111111] dark:text-[#ececf1]">Add New Contact</h3>
              </div>
              <button
                onClick={() => setIsAddModalOpen(false)}
                className="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 text-gray-500 transition-colors"
              >
                <FiX className="h-5 w-5" />
              </button>
            </div>

            <div className="space-y-4">
              {error && (
                <div className="p-3 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 rounded-xl">
                  <p className="text-[13px] text-red-600 dark:text-red-400">{error}</p>
                </div>
              )}
              <div>
                <label className="block text-[11px] sm:text-[12px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                  Name
                </label>
                <input
                  type="text"
                  value={newContactName}
                  onChange={(e) => setNewContactName(e.target.value)}
                  placeholder="Enter contact name"
                  className="w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] font-medium text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all"
                />
              </div>

              <div>
                <label className="block text-[11px] sm:text-[12px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                  Phone Number
                </label>
                <div className="relative">
                  <input
                    type="tel"
                    inputMode="tel"
                    value={newContactPhone}
                    onChange={(e) => setNewContactPhone(formatPhoneInput(e.target.value))}
                    placeholder="09XX XXX XXXX"
                    className="w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] font-medium text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all"
                  />
                </div>
              </div>
            </div>

            <div className="flex flex-col-reverse sm:flex-row items-center gap-3 sm:gap-3 mt-4 sm:mt-6">
              <button
                onClick={() => setIsAddModalOpen(false)}
                className="w-full sm:flex-1 px-4 py-3 text-[14px] font-semibold text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleAddContact}
                disabled={!newContactName.trim() || newContactPhone.replace(/\D/g, "").length < 7 || isSubmitting}
                className="w-full sm:flex-1 flex items-center justify-center gap-2 px-4 py-3 bg-[#2b83fa] hover:bg-[#1d6bd4] disabled:bg-gray-300 dark:disabled:bg-gray-600 disabled:cursor-not-allowed text-white disabled:text-gray-500 dark:disabled:text-gray-400 rounded-xl font-semibold text-[14px] transition-all duration-200"
              >
                {isSubmitting ? (
                  <>
                    <FiLoader className="h-4 w-4 animate-spin" />
                    Adding...
                  </>
                ) : (
                  <>
                    <FiPlus className="h-4 w-4" />
                    Add Contact
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
      {/* Edit Contact Modal */}
      {editingContact && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div
            className="absolute inset-0 bg-black/50 backdrop-blur-sm"
            onClick={() => setEditingContact(null)}
          />
          <div className="relative w-full max-w-md bg-white dark:bg-[#1a1b1e] rounded-2xl shadow-2xl p-4 sm:p-6 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-between mb-4 sm:mb-6">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-[#2b83fa]/10 dark:bg-[#2b83fa]/20 flex items-center justify-center">
                  <FiEdit2 className="h-4 w-4 text-[#2b83fa]" />
                </div>
                <h3 className="text-[16px] sm:text-[18px] font-bold text-[#111111] dark:text-[#ececf1]">Edit Contact</h3>
              </div>
              <button
                onClick={() => setEditingContact(null)}
                className="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 text-gray-500 transition-colors"
              >
                <FiX className="h-5 w-5" />
              </button>
            </div>

            <div className="space-y-4">
              {error && (
                <div className="p-3 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 rounded-xl">
                  <p className="text-[13px] text-red-600 dark:text-red-400">{error}</p>
                </div>
              )}
              <div>
                <label className="block text-[11px] sm:text-[12px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                  Name
                </label>
                <input
                  type="text"
                  value={editingContact.name}
                  onChange={(e) => setEditingContact({ ...editingContact, name: formatContactNameInput(e.target.value) })}
                  onBlur={() => setEditingContact({ ...editingContact, name: toProperContactName(editingContact.name) })}
                  placeholder="Enter contact name"
                  className="w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] font-medium text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all"
                />
              </div>

              <div>
                <label className="block text-[11px] sm:text-[12px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                  Phone Number
                </label>
                <div className="relative">
                  <input
                    type="tel"
                    inputMode="tel"
                    value={formatPhoneInput(editingContact.phone)}
                    onChange={(e) => setEditingContact({ ...editingContact, phone: formatPhoneInput(e.target.value) })}
                    placeholder="0917-123-4567"
                    className="w-full px-3 sm:px-4 py-2.5 sm:py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] font-medium text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all"
                  />
                </div>
              </div>
            </div>

            <div className="flex flex-col-reverse sm:flex-row items-center gap-3 sm:gap-3 mt-4 sm:mt-6">
              <button
                onClick={() => setEditingContact(null)}
                className="w-full sm:flex-1 px-4 py-3 text-[14px] font-semibold text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleEditContact}
                disabled={!editingContact.name.trim() || editingContact.phone.replace(/\D/g, "").length < 7 || isSubmitting}
                className="w-full sm:flex-1 flex items-center justify-center gap-2 px-4 py-3 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] hover:shadow-[0_8px_25px_rgba(43,131,250,0.35)] disabled:from-gray-300 disabled:to-gray-300 dark:disabled:from-gray-600 dark:disabled:to-gray-600 disabled:cursor-not-allowed text-white disabled:text-gray-500 dark:disabled:text-gray-400 rounded-xl font-semibold text-[14px] transition-all duration-200 shadow-md shadow-blue-500/20 disabled:shadow-none"
              >
                {isSubmitting ? (
                  <>
                    <FiLoader className="h-4 w-4 animate-spin" />
                    Saving...
                  </>
                ) : (
                  <>
                    <FiCheck className="h-4 w-4" />
                    Save Changes
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
