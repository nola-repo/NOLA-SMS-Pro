const VARIABLE_PATTERN = /\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/g;

const CONTACT_PLACEHOLDERS = [
  "contact.name",
  "contact.first_name",
  "contact.last_name",
  "contact.phone",
  "contact.email",
];

// Placeholder groups that are recommended but NOT strictly required
const RECOMMENDED_PLACEHOLDER_GROUPS = [
  {
    label: "contact",
    examples: ["{{contact.first_name}}", "{{contact.name}}"],
    values: CONTACT_PLACEHOLDERS,
  },
];

const ALLOWED_PLACEHOLDERS = new Set([
  ...CONTACT_PLACEHOLDERS,
  "company.name",
]);

export type TemplateValidationResult = {
  /** True only when there are no unknown/unsupported placeholders */
  isValid: boolean;
  /** Recommended placeholder groups that are missing (warning only, not blocking) */
  missingGroups: Array<{ label: string; examples: string[] }>;
  /** Truly unsupported placeholders that will not be replaced at send-time */
  unknownPlaceholders: string[];
};

export const extractTemplatePlaceholders = (content: string): string[] => {
  const matches = new Set<string>();
  for (const match of content.matchAll(VARIABLE_PATTERN)) {
    matches.add(match[1].trim().toLowerCase());
  }
  return Array.from(matches);
};

export const validateTemplateContent = (content: string): TemplateValidationResult => {
  const placeholders = extractTemplatePlaceholders(content);
  const placeholderSet = new Set(placeholders);

  // Recommended groups that are missing (informational/warning only)
  const missingGroups = RECOMMENDED_PLACEHOLDER_GROUPS
    .filter((group) => !group.values.some((value) => placeholderSet.has(value)))
    .map(({ label, examples }) => ({ label, examples }));

  // Only truly unknown placeholders are errors (they will send literally as {{...}})
  const unknownPlaceholders = placeholders.filter((placeholder) => !ALLOWED_PLACEHOLDERS.has(placeholder));

  return {
    // Only block if there are unknown placeholders — missing recommended groups are not blocking
    isValid: unknownPlaceholders.length === 0,
    missingGroups,
    unknownPlaceholders,
  };
};

export const formatTemplateValidationMessage = (result: TemplateValidationResult): string => {
  // Only format error-level issues (unknown placeholders that won't resolve)
  if (result.unknownPlaceholders.length === 0) return "";

  return `Remove or replace unsupported placeholder${result.unknownPlaceholders.length === 1 ? "" : "s"}: ${result.unknownPlaceholders.map((value) => `{{${value}}}`).join(", ")}.`;
};
