import React, { useState, useEffect, useMemo, useRef } from "react";
import { fetchTemplates, createTemplate, updateTemplate, deleteTemplate } from "../api/templates";
import type { Template } from "../types/Template";
import { FiSearch, FiX, FiPlus, FiTrash2, FiMoreVertical, FiEdit2, FiMessageSquare, FiLoader, FiCode } from "react-icons/fi";

const TemplateSkeleton = () => (
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

export const TemplatesTab: React.FC = () => {
  const [templates, setTemplates] = useState<Template[]>([]);
  const [searchQuery, setSearchQuery] = useState("");
  const [loading, setLoading] = useState(true);
  
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<Template | null>(null);
  const [formData, setFormData] = useState({ name: "", content: "" });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  
  const [openMenuId, setOpenMenuId] = useState<string | null>(null);
  const [deleteConfirmId, setDeleteConfirmId] = useState<string | null>(null);
  const [isCustomValuesOpen, setIsCustomValuesOpen] = useState(false);
  
  const customValuesRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  const customValues = [
    { label: "Contact Name", value: "{{contact.name}}" },
    { label: "Contact First Name", value: "{{contact.first_name}}" },
    { label: "Contact Email", value: "{{contact.email}}" },
    { label: "Contact Phone", value: "{{contact.phone}}" },
    { label: "Company Name", value: "{{contact.company_name}}" },
    { label: "Appointment Time", value: "{{appointment.start_time}}" },
  ];

  // Close menus when clicking outside
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      setOpenMenuId(null);
      if (customValuesRef.current && !customValuesRef.current.contains(e.target as Node)) {
        setIsCustomValuesOpen(false);
      }
    };
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, []);

  const loadTemplates = async () => {
    setLoading(true);
    try {
      const data = await fetchTemplates();
      setTemplates(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error(err);
      setTemplates([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadTemplates();
  }, []);

  const filteredTemplates = useMemo(() => {
    const list = Array.isArray(templates) ? templates : [];
    if (!searchQuery) return list;
    const lowerQ = searchQuery.toLowerCase();
    return list.filter(
      (t) =>
        t.name?.toLowerCase().includes(lowerQ) ||
        t.content?.toLowerCase().includes(lowerQ)
    );
  }, [searchQuery, templates]);

  const sortedTemplates = useMemo(() => {
    return [...filteredTemplates].sort((a, b) => a.name.localeCompare(b.name));
  }, [filteredTemplates]);

  const handleOpenModal = (template?: Template) => {
    if (template) {
      setEditingTemplate(template);
      setFormData({ name: template.name, content: template.content });
    } else {
      setEditingTemplate(null);
      setFormData({ name: "", content: "" });
    }
    setError(null);
    setIsModalOpen(true);
  };

  const handleCloseModal = () => {
    setIsModalOpen(false);
    setEditingTemplate(null);
    setFormData({ name: "", content: "" });
    setError(null);
  };

  const handleSave = async () => {
    if (!formData.name.trim() || !formData.content.trim()) {
      setError("Name and content are required.");
      return;
    }
    
    setIsSubmitting(true);
    setError(null);
    
    try {
      if (editingTemplate) {
        const updated = await updateTemplate(editingTemplate.id, formData.name, formData.content);
        setTemplates(prev => prev.map(t => t.id === editingTemplate.id ? updated : t));
      } else {
        const created = await createTemplate(formData.name, formData.content);
        setTemplates(prev => [created, ...prev]);
      }
      handleCloseModal();
    } catch (err: any) {
      setError(err.message || 'Failed to save template');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDelete = async (id: string) => {
    setIsSubmitting(true);
    try {
      await deleteTemplate(id);
      setTemplates(prev => prev.filter(t => t.id !== id));
      if (deleteConfirmId === id) {
          setDeleteConfirmId(null);
      }
    } catch (err: any) {
      console.error(err);
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="flex flex-col h-full bg-[#f7f7f7] dark:bg-[#111111]">
      {/* Header */}
      <div className="flex-shrink-0 bg-white/80 dark:bg-[#1a1b1e]/80 backdrop-blur-xl border-b border-gray-200/60 dark:border-white/5 shadow-sm">
        <div className="max-w-5xl mx-auto px-3 md:px-6 py-4">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-full bg-gradient-to-br from-[#2b83fa] to-[#60a5fa] flex items-center justify-center text-white shadow-md shadow-blue-500/10">
                <FiMessageSquare className="h-5 w-5" />
              </div>
              <div>
                <h2 className="text-[18px] font-bold text-[#111111] dark:text-[#ececf1] tracking-tight">Templates</h2>
                <p className="text-[12px] text-gray-500 dark:text-gray-400">
                  {templates.length} templates available
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
              placeholder="Search templates by name or content..."
              disabled={isModalOpen}
              className="w-full pl-10 sm:pl-11 pr-10 py-2.5 sm:py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] font-medium text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all disabled:opacity-50"
            />
            {searchQuery && !isModalOpen && (
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

          <div className="flex items-center justify-end mt-4 pt-3 border-t border-gray-100 dark:border-white/5">
            <button
              onClick={(e) => {
                e.stopPropagation();
                setSearchQuery("");
                handleOpenModal();
              }}
              className="group flex items-center justify-center gap-1 sm:gap-2 bg-gradient-to-r from-[#2b83fa] to-[#1d6bd4] text-white px-3 sm:px-4 py-2.5 rounded-2xl text-[13px] font-bold hover:shadow-[0_8px_25px_rgba(43,131,250,0.4)] active:scale-95 transition-all duration-200"
            >
              <FiPlus className="h-4 w-4" />
              <span className="hidden sm:inline">Add Template</span>
            </button>
          </div>
        </div>
      </div>

      {/* Templates List */}
      <div
        ref={listRef}
        className="flex-1 overflow-y-auto px-3 md:px-6 py-4 custom-scrollbar"
      >
        <div className="max-w-5xl mx-auto">
          {loading ? (
            <div className="space-y-1">
              {[1, 2, 3, 4, 5, 6].map((i) => (
                <TemplateSkeleton key={i} />
              ))}
            </div>
          ) : sortedTemplates.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16">
              <div className="w-16 h-16 mb-4 rounded-2xl bg-gray-100 dark:bg-white/5 flex items-center justify-center">
                <FiSearch className="h-8 w-8 text-gray-400" />
              </div>
              <p className="text-[14px] text-gray-500 dark:text-gray-400 font-medium">
                {searchQuery ? "No templates match your search" : "No templates available"}
              </p>
            </div>
          ) : (
            <div className="flex flex-col gap-1">
              {sortedTemplates.map((template) => {
                return (
                  <div
                    key={template.id}
                    className="group flex items-center gap-2 sm:gap-4 p-3 sm:p-4 rounded-xl sm:rounded-2xl bg-white dark:bg-[#1a1b1e] border border-gray-100 dark:border-white/5 shadow-sm transition-all duration-200 hover:border-gray-200 dark:hover:border-white/10"
                  >
                    {/* Avatar */}
                    <div className="w-9 sm:w-11 h-9 sm:h-11 rounded-xl sm:rounded-2xl flex items-center justify-center font-bold text-[13px] sm:text-[14px] flex-shrink-0 transition-all duration-200 bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-300">
                      {template.name.charAt(0).toUpperCase()}
                    </div>

                    {/* Info */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <p className="text-[14px] font-semibold truncate transition-colors text-[#111111] dark:text-[#ececf1]">
                          {template.name}
                        </p>
                      </div>
                      <p className="text-[12px] text-gray-500 dark:text-gray-400 truncate mt-0.5">
                        {template.content}
                      </p>
                    </div>

                    {/* More button with dropdown */}
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
                            Edit
                          </button>
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

      {/* Floating Action Bar */}
      {/* Templates action bar removed */}

      {/* Create / Edit Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div
            className="absolute inset-0 bg-black/50 backdrop-blur-sm"
            onClick={handleCloseModal}
          />
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
              <button
                onClick={handleCloseModal}
                className="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-white/5 text-gray-500 transition-colors"
              >
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
                <label className="block text-[12px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-2">
                  Template Name
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g. Welcome Message"
                  className="w-full px-4 py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] font-medium text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all"
                />
              </div>

              <div>
                <div className="flex justify-between items-baseline mb-2">
                  <label className="block text-[12px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-widest">
                    Message Content
                  </label>
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
                        Custom Values
                      </button>
                      {isCustomValuesOpen && (
                        <div className="absolute top-full right-0 sm:left-0 sm:right-auto mt-2 w-48 bg-white dark:bg-[#202123] border border-gray-100 dark:border-white/10 rounded-xl shadow-xl z-50 py-1 max-h-48 overflow-y-auto">
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
                    <span className="text-[11px] font-medium text-gray-400">
                      {formData.content.length} characters
                    </span>
                  </div>
                </div>
                <textarea
                  value={formData.content}
                  onChange={(e) => setFormData({ ...formData, content: e.target.value })}
                  placeholder="Type your template message here..."
                  rows={6}
                  className="w-full px-4 py-3 bg-gray-50 dark:bg-[#111111] border border-gray-200/60 dark:border-white/10 rounded-xl text-[14px] leading-relaxed text-[#111111] dark:text-[#ececf1] placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#2b83fa]/20 focus:border-[#2b83fa] transition-all resize-none custom-scrollbar"
                />
              </div>
            </div>

            <div className="flex items-center gap-3 mt-8 pt-4 border-t border-gray-100 dark:border-white/5">
              <button
                onClick={handleCloseModal}
                className="flex-1 px-4 py-3.5 text-[14px] font-bold text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleSave}
                disabled={!formData.name.trim() || !formData.content.trim() || isSubmitting}
                className="flex-[2] flex items-center justify-center gap-2 px-4 py-3.5 bg-[#2b83fa] hover:bg-[#1d6bd4] disabled:bg-gray-200 dark:disabled:bg-gray-800 disabled:cursor-not-allowed text-white disabled:text-gray-400 dark:disabled:text-gray-600 rounded-xl font-bold text-[14px] transition-all duration-200 shadow-md shadow-blue-500/10"
              >
                {isSubmitting ? (
                  <FiLoader className="h-5 w-5 animate-spin" />
                ) : (
                  "Save Template"
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Delete Confirmation Modal */}
      {deleteConfirmId && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
          <div
            className="absolute inset-0 bg-black/50 backdrop-blur-sm"
            onClick={() => setDeleteConfirmId(null)}
          />
          <div className="relative w-full max-w-sm bg-white dark:bg-[#1a1b1e] rounded-3xl shadow-2xl p-6 md:p-8 animate-in zoom-in-95 duration-200">
            <div className="flex items-center justify-center mb-5">
              <div className="w-14 h-14 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
                <FiTrash2 className="h-7 w-7 text-red-500" />
              </div>
            </div>
            <h3 className="text-[20px] font-extrabold text-[#111111] dark:text-[#ececf1] text-center mb-3 tracking-tight">
              Delete Template?
            </h3>
            <p className="text-[14px] text-gray-500 dark:text-gray-400 text-center mb-8 leading-relaxed">
              Are you sure you want to delete this template? This action cannot be undone.
            </p>
            <div className="flex items-center gap-3">
              <button
                onClick={() => setDeleteConfirmId(null)}
                className="flex-1 px-4 py-3 text-[14px] font-bold text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={() => handleDelete(deleteConfirmId)}
                disabled={isSubmitting}
                className="flex-1 flex items-center justify-center gap-2 px-4 py-3 bg-red-500 hover:bg-red-600 disabled:bg-red-300 dark:disabled:bg-red-900/50 text-white rounded-xl font-bold text-[14px] transition-all duration-200 shadow-md shadow-red-500/10"
              >
                {isSubmitting ? (
                  <FiLoader className="h-5 w-5 animate-spin" />
                ) : (
                  "Delete"
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
