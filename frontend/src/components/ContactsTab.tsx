import { useState, useEffect, useMemo, useRef } from "react";
import { fetchContacts, addContact, updateContact, deleteContact } from "../api/contacts";
import type { Contact } from "../types/Contact";
import { FiSearch, FiX, FiMail, FiCheck, FiUser, FiPlus, FiTrash2, FiMoreVertical, FiEdit2, FiMessageCircle, FiLoader, FiChevronDown, FiChevronUp } from "react-icons/fi";
import { useConversationMessages } from "../hooks/useConversationMessages";

const ContactHistory: React.FC<{ phone: string }> = ({ phone }) => {
  const conversationId = `conv_${phone.replace(/\D/g, "").startsWith("639") ? "0" + phone.replace(/\D/g, "").substring(2) : phone.replace(/\D/g, "")}`;
  const { messages, loading } = useConversationMessages(conversationId);

  if (loading) return <div className="text-[12px] text-gray-500 py-2">Loading history...</div>;
  if (!messages.length) return <div className="text-[12px] text-gray-500 py-2">No message history</div>;

  return (
    <div className="mt-3 bg-gray-50 dark:bg-white/5 rounded-xl p-3 max-h-48 overflow-y-auto custom-scrollbar">
      <div className="flex flex-col gap-2">
        {messages.map((msg) => (
          <div key={msg.id} className={`flex flex-col ${msg.senderName === 'NOLACRM' ? 'items-end' : 'items-start'}`}>
            <div className={`px-3 py-2 rounded-xl max-w-[85%] ${msg.senderName === 'NOLACRM' ? 'bg-[#2b83fa] text-white' : 'bg-gray-200 dark:bg-[#2d2d2d] text-[#111111] dark:text-[#ececf1]'} text-[13px]`}>
              {msg.text || (msg as any).message}
            </div>
            <span className="text-[10px] text-gray-500 mt-0.5">
              {msg.timestamp instanceof Date ? msg.timestamp.toLocaleDateString() : new Date(msg.timestamp).toLocaleDateString()}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
};

interface ContactsTabProps {
  onSendToComposer: (contacts: Contact[]) => void;
  onViewMessages?: (contact: Contact) => void;
}

export const ContactsTab: React.FC<ContactsTabProps> = ({ onSendToComposer, onViewMessages }) => {
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [selectedContacts, setSelectedContacts] = useState<Contact[]>([]);
  const [searchQuery, setSearchQuery] = useState("");
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
  const [expandedHistoryId, setExpandedHistoryId] = useState<string | null>(null);
  const touchStartY = useRef<number>(0);
  const listRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    fetchContacts()
      .then(setContacts)
      .catch(console.error)
      .finally(() => setLoading(false));
  }, []);

  const refreshContacts = async () => {
    setIsPullRefreshing(true);
    try {
      const data = await fetchContacts();
      setContacts(data);
    } catch (e) {
      console.error(e);
    } finally {
      setIsPullRefreshing(false);
    }
  };

  // Close menu when clicking outside
  useEffect(() => {
    const handleClickOutside = () => setOpenMenuId(null);
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, []);

  // Convert name to proper case (title case)
  const toProperCase = (name: string): string => {
    return name.replace(/\b\w/g, (char) => char.toUpperCase());
  };

  const filteredContacts = useMemo(() => {
    if (!searchQuery) return contacts;
    const lowerQ = searchQuery.toLowerCase();
    return contacts.filter(
      (c) =>
        c.name.toLowerCase().includes(lowerQ) ||
        c.phone.includes(lowerQ)
    );
  }, [searchQuery, contacts]);

  // Group contacts by first letter (or by phone number if name is a phone number)
  const groupedContacts = useMemo(() => {
    const groups: Record<string, Contact[]> = {};
    filteredContacts.forEach((contact) => {
      // If name looks like a phone number, use the first digit for grouping
      let firstLetter: string;
      if (/^\d/.test(contact.name)) {
        firstLetter = contact.name.charAt(0);
      } else {
        firstLetter = contact.name.charAt(0).toUpperCase();
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
    const digits = newContactPhone.replace(/\D/g, "");
    if (!newContactName.trim() || digits.length < 7) return;

    setIsSubmitting(true);
    setError(null);

    try {
      // Add to GHL
      const newContact = await addContact({
        name: newContactName.trim(),
        phone: "+63" + digits,
      });

      if (newContact) {
        // Add to local contacts list (don't select automatically)
        setContacts((prev) => [...prev, newContact]);
        // Clear search to show the new contact
        setSearchQuery("");
      }
    } catch (err: any) {
      console.error("Error adding contact:", err);
      setError(err.message || "Failed to add contact to GHL");
    } finally {
      setIsSubmitting(false);
      // Reset and close modal
      setNewContactName("");
      setNewContactPhone("");
      setIsAddModalOpen(false);
    }
  };

  const handleEditContact = async () => {
    if (!editingContact) return;
    let digits = editingContact.phone.replace(/\D/g, "");
    // Normalize: strip leading country code so we always send 10-digit local number
    if (digits.startsWith("639")) digits = digits.slice(2);      // 639XXXXXXXXX → 9XXXXXXXXX
    else if (digits.startsWith("63")) digits = digits.slice(2);  // 63XXXXXXXXX  → XXXXXXXXX
    else if (digits.startsWith("0")) digits = digits.slice(1);   // 09XXXXXXXXX  → 9XXXXXXXXX
    if (!editingContact.name.trim() || digits.length < 9) return;

    setIsSubmitting(true);
    setError(null);

    try {
      // Only update if it's a GHL contact (has numeric ID, not starting with 'manual-')
      if (!editingContact.id.startsWith('manual-')) {
        const updated = await updateContact({
          id: editingContact.id,
          name: editingContact.name.trim(),
          phone: "+63" + digits,
        });

        if (updated) {
          // Update contact in list
          setContacts((prev) => prev.map((c) =>
            c.id === editingContact.id ? { ...editingContact, name: updated.name, phone: updated.phone } : c
          ));

          // Update selected contacts if this one was selected
          setSelectedContacts((prev) => prev.map((c) =>
            c.id === editingContact.id ? { ...c, name: updated.name, phone: updated.phone } : c
          ));
        }
      } else {
        // For manual contacts, just update locally
        setContacts((prev) => prev.map((c) =>
          c.id === editingContact.id ? editingContact : c
        ));

        setSelectedContacts((prev) => prev.map((c) =>
          c.id === editingContact.id ? editingContact : c
        ));
      }
    } catch (err: any) {
      console.error("Error updating contact:", err);
      setError(err.message || "Failed to update contact in GHL");
    } finally {
      setIsSubmitting(false);
      // Close modal
      setEditingContact(null);
    }
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

      // Remove from contacts list
      setContacts((prev) => prev.filter((c) => c.id !== contactId));
      // Remove from selected if selected
      setSelectedContacts((prev) => prev.filter((c) => c.id !== contactId));
    } catch (err: any) {
      console.error("Error deleting contact:", err);
      setError(err.message || "Failed to delete contact from GHL");
    } finally {
      setIsSubmitting(false);
    }
  };

  const isAllSelected = filteredContacts.length > 0 && selectedContacts.length === filteredContacts.length;

  if (loading) {
    return (
      <div className="flex-1 flex items-center justify-center bg-[#f7f7f7] dark:bg-[#18191d]">
        <div className="flex flex-col items-center gap-3">
          <div className="w-10 h-10 border-4 border-[#2b83fa]/30 border-t-[#2b83fa] rounded-full animate-spin"></div>
          <span className="text-[14px] text-gray-500 dark:text-gray-400 font-medium">Loading contacts...</span>
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col h-full bg-[#f7f7f7] dark:bg-[#111111]">
      {/* Header */}
      <div className="flex-shrink-0 bg-white/80 dark:bg-[#1a1b1e]/80 backdrop-blur-xl border-b border-gray-200/60 dark:border-white/5 shadow-sm">
        <div className="max-w-5xl mx-auto px-3 md:px-6 py-4">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-full bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] flex items-center justify-center text-white shadow-md shadow-blue-500/10">
                <FiUser className="h-5 w-5" />
              </div>
              <div>
                <h2 className="text-[18px] font-bold text-[#111111] dark:text-[#ececf1] tracking-tight">Contacts</h2>
                <p className="text-[12px] text-gray-500 dark:text-gray-400">
                  {contacts.length} contacts available
                </p>
              </div>
            </div>
          </div>

          {/* Search Bar */}
          <div className="relative">
            <FiSearch className="absolute left-3 sm:left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Search by name or phone..."
              disabled={isAddModalOpen}
              className="w-full pl-10 sm:pl-11 pr-10 py-2.5 sm:py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] font-medium text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all disabled:opacity-50"
            />
            {searchQuery && !isAddModalOpen && (
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  setSearchQuery("");
                }}
                className="absolute right-3 sm:right-3 top-1/2 -translate-y-1/2 p-1 rounded-full hover:bg-gray-200 dark:hover:bg-white/10 text-gray-400 transition-colors"
              >
                <FiX className="h-4 w-4" />
              </button>
            )}
          </div>

          {/* Selection Controls */}
          <div className="flex items-center justify-between mt-4 pt-3 border-t border-gray-100 dark:border-white/5">
            <div className="flex items-center gap-2 sm:gap-3">
              <button
                onClick={isAllSelected ? handleClearSelection : handleSelectAll}
                className={`flex items-center gap-2 px-3 sm:px-4 py-2 rounded-xl text-[12px] font-semibold transition-all duration-200 ${isAllSelected
                  ? "bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-500/20"
                  : "bg-[#2b83fa]/10 dark:bg-[#2b83fa]/20 text-[#2b83fa] hover:bg-[#2b83fa]/20 dark:hover:bg-[#2b83fa]/30"
                  }`}
              >
                <FiCheck className="h-3.5 w-3.5" />
                <span className="hidden sm:inline">{isAllSelected ? "Deselect All" : "Select All"}</span>
                <span className="sm:hidden">{isAllSelected ? "Deselect All" : "Select All"}</span>
              </button>
              {selectedContacts.length > 0 && (
                <button
                  onClick={handleClearSelection}
                  className="px-3 sm:px-4 py-2 text-[12px] font-semibold text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-all duration-200"
                >
                  Clear
                </button>
              )}
            </div>
            <div className="flex items-center gap-2 sm:gap-3">
              <span className="text-[12px] font-bold text-[#2b83fa] bg-[#2b83fa]/10 px-3 py-1 rounded-full whitespace-nowrap">
                {selectedContacts.length} selected
              </span>
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  setSearchQuery("");
                  setIsAddModalOpen(true);
                }}
                className="group flex items-center justify-center gap-1 sm:gap-2 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white px-3 sm:px-4 py-2.5 rounded-2xl text-[13px] font-bold hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] active:scale-95 transition-all duration-200"
              >
                <FiPlus className="h-4 w-4" />
                <span className="hidden sm:inline">Add Contact</span>
              </button>
            </div>
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
          {groupedContacts.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16">
              <div className="w-16 h-16 mb-4 rounded-2xl bg-gray-100 dark:bg-white/5 flex items-center justify-center">
                <FiSearch className="h-8 w-8 text-gray-400" />
              </div>
              <p className="text-[14px] text-gray-500 dark:text-gray-400 font-medium">
                {searchQuery ? "No contacts match your search" : "No contacts available"}
              </p>
            </div>
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
                          <div className="flex-1 min-w-0" onClick={(e) => {
                            e.stopPropagation();
                            setExpandedHistoryId(expandedHistoryId === contact.id ? null : contact.id);
                          }}>
                            <div className="flex items-center gap-2">
                              <p
                                className={`
                                  text-[14px] font-semibold truncate transition-colors
                                  ${isSelected ? "text-[#2b83fa]" : "text-[#111111] dark:text-[#ececf1]"}
                                `}
                              >
                                {toProperCase(contact.name)}
                              </p>
                              {expandedHistoryId === contact.id ? (
                                <FiChevronUp className="h-4 w-4 text-gray-400" />
                              ) : (
                                <FiChevronDown className="h-4 w-4 text-gray-400" />
                              )}
                            </div>
                            <p className="text-[12px] text-gray-500 dark:text-gray-400 truncate">
                              {contact.phone}
                            </p>
                            
                            {/* Contact History (Accordion) */}
                            {expandedHistoryId === contact.id && (
                              <div onClick={(e) => e.stopPropagation()}>
                                <ContactHistory phone={contact.phone} />
                              </div>
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
                                className="absolute right-0 top-full mt-1 bg-white dark:bg-[#2d2d2d] rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 py-1 min-w-[120px] z-50"
                                onClick={(e) => e.stopPropagation()}
                              >
                                <button
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    setEditingContact(contact);
                                    setOpenMenuId(null);
                                  }}
                                  className="w-full px-3 py-2 text-left text-[13px] text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 flex items-center gap-2"
                                >
                                  <FiEdit2 className="w-4 h-4" />
                                  Edit
                                </button>
                                {onViewMessages && (
                                  <button
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      onViewMessages(contact);
                                      setOpenMenuId(null);
                                    }}
                                    className="w-full px-3 py-2 text-left text-[13px] text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 flex items-center gap-2"
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
                                  className="w-full px-3 py-2 text-left text-[13px] text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 flex items-center gap-2"
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
        <div className="fixed bottom-4 sm:bottom-6 left-1/2 -translate-x-1/2 z-50 animate-in slide-in-from-bottom-4 fade-in duration-300 px-3 sm:px-0">
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
                    onChange={(e) => {
                      const digits = e.target.value.replace(/\D/g, "");
                      let formatted = "";
                      if (digits.length > 0) {
                        formatted = digits.substring(0, 11);
                        if (formatted.length > 7) {
                          formatted = `${formatted.slice(0, 4)} ${formatted.slice(4, 7)} ${formatted.slice(7)}`;
                        } else if (formatted.length > 3) {
                          formatted = `${formatted.slice(0, 3)} ${formatted.slice(3)}`;
                        }
                      }
                      setNewContactPhone(formatted);
                    }}
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
                  onChange={(e) => setEditingContact({ ...editingContact, name: e.target.value })}
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
                    value={editingContact.phone}
                    onChange={(e) => {
                      const digits = e.target.value.replace(/\D/g, "");
                      let formatted = "";
                      if (digits.length > 0) {
                        formatted = digits.substring(0, 11);
                        if (formatted.length > 7) {
                          formatted = `${formatted.slice(0, 4)} ${formatted.slice(4, 7)} ${formatted.slice(7)}`;
                        } else if (formatted.length > 3) {
                          formatted = `${formatted.slice(0, 3)} ${formatted.slice(3)}`;
                        }
                      }
                      setEditingContact({ ...editingContact, phone: formatted });
                    }}
                    placeholder="09XX XXX XXXX"
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
                className="w-full sm:flex-1 flex items-center justify-center gap-2 px-4 py-3 bg-[#2b83fa] hover:bg-[#1d6bd4] disabled:bg-gray-300 dark:disabled:bg-gray-600 disabled:cursor-not-allowed text-white disabled:text-gray-500 dark:disabled:text-gray-400 rounded-xl font-semibold text-[14px] transition-all duration-200"
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
