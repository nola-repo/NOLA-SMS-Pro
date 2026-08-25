import React, { useState, useRef, useEffect } from 'react';
import { FiFileText, FiLoader, FiCheck } from 'react-icons/fi';
import type { Template } from '../../types/Template';

export interface CustomValueItem {
  label: string;
  value: string;
}

export interface ComposerDropdownsProps {
  templateOptions: Template[];
  templatesLoading: boolean;
  onSelectTemplate: (template: Template) => void;
  onFetchTemplatesIfNeeded?: () => void;
  customValuesList: CustomValueItem[];
  onSelectCustomValue: (value: string) => void;
  allTags: string[];
  selectedTagsToApply: string[];
  onToggleTag: (tag: string) => void;
}

export const ComposerDropdowns: React.FC<ComposerDropdownsProps> = ({
  templateOptions,
  templatesLoading,
  onSelectTemplate,
  onFetchTemplatesIfNeeded,
  customValuesList,
  onSelectCustomValue,
  allTags,
  selectedTagsToApply,
  onToggleTag,
}) => {
  const [isTemplatesOpen, setIsTemplatesOpen] = useState(false);
  const [isCustomValuesOpen, setIsCustomValuesOpen] = useState(false);
  const [isTagsOpen, setIsTagsOpen] = useState(false);

  const templatePickerRef = useRef<HTMLDivElement>(null);
  const customValuesRef = useRef<HTMLDivElement>(null);
  const tagsRef = useRef<HTMLDivElement>(null);

  // Close dropdowns on outside click
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (templatePickerRef.current && !templatePickerRef.current.contains(event.target as Node)) {
        setIsTemplatesOpen(false);
      }
      if (customValuesRef.current && !customValuesRef.current.contains(event.target as Node)) {
        setIsCustomValuesOpen(false);
      }
      if (tagsRef.current && !tagsRef.current.contains(event.target as Node)) {
        setIsTagsOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleTemplateToggle = () => {
    const nextState = !isTemplatesOpen;
    setIsTemplatesOpen(nextState);
    if (nextState && onFetchTemplatesIfNeeded) {
      onFetchTemplatesIfNeeded();
    }
  };

  return (
    <div className="flex min-w-0 flex-wrap items-center gap-1.5">
      {/* Templates Button */}
      <div className="relative" ref={templatePickerRef}>
        <button
          type="button"
          onClick={handleTemplateToggle}
          title="Use Template"
          className={`px-3 py-1.5 rounded-full transition-all flex items-center gap-1.5 text-[12px] font-bold ${
            isTemplatesOpen
              ? 'bg-[#eaf3ff] text-[#1d6bd4] dark:bg-white/10 dark:text-[#8bbcff]'
              : 'text-[#7a8492] hover:text-[#1d6bd4] hover:bg-[#eef6ff] dark:text-[#8f96a3] dark:hover:text-[#8bbcff] dark:hover:bg-white/[0.06]'
          }`}
        >
          <FiFileText className="h-4 w-4" />
          <span className="hidden sm:inline">Use Template</span>
        </button>

        {isTemplatesOpen && (
          <div className="absolute bottom-full left-0 z-50 mb-2 flex max-h-[44vh] w-[min(18rem,calc(100vw-2rem))] flex-col gap-1 overflow-y-auto rounded-2xl border border-gray-200 bg-white p-2 shadow-xl animate-scale-up custom-scrollbar dark:border-white/10 dark:bg-[#1a1b1e] sm:max-h-72 sm:w-72">
            {templatesLoading ? (
              <div className="flex items-center justify-center gap-2 px-3 py-5 text-[12px] font-semibold text-gray-500">
                <FiLoader className="h-4 w-4 animate-spin" />
                Loading templates...
              </div>
            ) : templateOptions.length === 0 ? (
              <div className="px-3 py-5 text-center">
                <p className="text-[12px] font-bold text-gray-600 dark:text-gray-300">No templates yet</p>
                <p className="mt-1 text-[11px] font-medium leading-relaxed text-gray-500 dark:text-gray-400">
                  Create templates from the Templates page, then insert them here.
                </p>
              </div>
            ) : (
              templateOptions.map(template => (
                <button
                  type="button"
                  key={template.id}
                  onClick={() => {
                    onSelectTemplate(template);
                    setIsTemplatesOpen(false);
                  }}
                  className="w-full px-3 py-2.5 text-left rounded-xl transition-colors hover:bg-gray-100 dark:hover:bg-white/5"
                >
                  <span className="block truncate text-[13px] font-bold text-[#111111] dark:text-[#ececf1]">
                    {template.name}
                  </span>
                  <span className="block truncate text-[11px] text-[#7a8492] dark:text-[#8f96a3]">
                    {template.content}
                  </span>
                </button>
              ))
            )}
          </div>
        )}
      </div>

      {/* Custom Values Button */}
      <div className="relative" ref={customValuesRef}>
        <button
          type="button"
          onClick={() => setIsCustomValuesOpen(!isCustomValuesOpen)}
          title="Custom Values"
          className={`px-3 py-1.5 rounded-full transition-all flex items-center gap-1.5 text-[12px] font-bold ${
            isCustomValuesOpen
              ? 'bg-[#eaf3ff] text-[#1d6bd4] dark:bg-white/10 dark:text-[#8bbcff]'
              : 'text-[#7a8492] hover:text-[#1d6bd4] hover:bg-[#eef6ff] dark:text-[#8f96a3] dark:hover:text-[#8bbcff] dark:hover:bg-white/[0.06]'
          }`}
        >
          <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
          </svg>
          <span className="hidden sm:inline">Custom Values</span>
        </button>

        {isCustomValuesOpen && (
          <div className="absolute bottom-full left-0 mb-2 p-2 bg-white dark:bg-[#1a1b1e] rounded-2xl border border-gray-200 dark:border-white/10 shadow-xl flex flex-col gap-1 z-50 animate-scale-up w-48">
            {customValuesList.map(item => (
              <button
                type="button"
                key={item.value}
                onClick={() => {
                  onSelectCustomValue(item.value);
                  setIsCustomValuesOpen(false);
                }}
                className="w-full px-3 py-2 text-left text-[13px] font-medium text-[#111111] dark:text-[#ececf1] hover:bg-gray-100 dark:hover:bg-white/5 rounded-xl transition-colors"
              >
                {item.label}
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Tags Button */}
      <div className="relative" ref={tagsRef}>
        <button
          type="button"
          onClick={() => setIsTagsOpen(!isTagsOpen)}
          title="Apply Tags to Recipients"
          className={`px-3 py-1.5 rounded-full transition-all flex items-center gap-1.5 text-[12px] font-bold ${
            selectedTagsToApply.length > 0 || isTagsOpen
              ? 'bg-[#eaf3ff] text-[#1d6bd4] dark:bg-white/10 dark:text-[#8bbcff]'
              : 'text-[#7a8492] hover:text-[#1d6bd4] hover:bg-[#eef6ff] dark:text-[#8f96a3] dark:hover:text-[#8bbcff] dark:hover:bg-white/[0.06]'
          }`}
        >
          <div className="relative">
            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
            </svg>
            {selectedTagsToApply.length > 0 && (
              <span className="absolute -top-1.5 -right-1.5 flex h-3 w-3 items-center justify-center rounded-full bg-[#2b83fa] text-[8px] font-bold text-white border border-white dark:border-[#1a1b1e]">
                {selectedTagsToApply.length}
              </span>
            )}
          </div>
          <span className="hidden sm:inline">Apply Tags</span>
        </button>

        {isTagsOpen && (
          <div className="absolute bottom-full left-0 mb-2 p-2 bg-white dark:bg-[#1a1b1e] rounded-2xl border border-gray-200 dark:border-white/10 shadow-xl flex flex-col gap-1 z-50 animate-scale-up w-56 max-h-60 overflow-y-auto custom-scrollbar">
            {allTags.length === 0 ? (
              <div className="px-3 py-4 text-center text-[12px] text-gray-500 font-medium">
                No tags available in your contacts
              </div>
            ) : (
              allTags.map(tag => {
                const isSelected = selectedTagsToApply.includes(tag);
                return (
                  <button
                    type="button"
                    key={tag}
                    onClick={(e) => {
                      e.preventDefault();
                      onToggleTag(tag);
                    }}
                    className={`w-full px-3 py-2 text-left text-[13px] font-medium rounded-xl transition-colors flex items-center justify-between ${
                      isSelected
                        ? 'bg-[#2b83fa]/10 text-[#2b83fa]'
                        : 'text-[#111111] dark:text-[#ececf1] hover:bg-gray-100 dark:hover:bg-white/5'
                    }`}
                  >
                    <span className="truncate mr-2">{tag}</span>
                    <div
                      className={`w-4 h-4 rounded flex items-center justify-center flex-shrink-0 border ${
                        isSelected
                          ? 'border-[#2b83fa] bg-[#2b83fa]'
                          : 'border-gray-300 dark:border-gray-600'
                      }`}
                    >
                      {isSelected && <FiCheck className="h-3 w-3 text-white" />}
                    </div>
                  </button>
                );
              })
            )}
          </div>
        )}
      </div>
    </div>
  );
};
