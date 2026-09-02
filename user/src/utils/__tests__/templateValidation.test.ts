import { describe, it, expect } from 'vitest';
import {
  extractTemplatePlaceholders,
  validateTemplateContent,
  formatTemplateValidationMessage,
} from '../templateValidation';

describe('templateValidation utils', () => {
  describe('extractTemplatePlaceholders', () => {
    it('extracts unique normalized placeholders from content string', () => {
      const content = 'Hello {{contact.first_name}}, welcome to {{company.name}}! Hi {{ contact.first_name }}.';
      const extracted = extractTemplatePlaceholders(content);
      expect(extracted).toEqual(['contact.first_name', 'company.name']);
    });

    it('returns empty array when no placeholders are present', () => {
      const content = 'Hello world, this is a plain message.';
      expect(extractTemplatePlaceholders(content)).toEqual([]);
    });
  });

  describe('validateTemplateContent', () => {
    it('validates clean templates with supported placeholders', () => {
      const content = 'Hi {{contact.name}}, your appointment with {{company.name}} is confirmed.';
      const res = validateTemplateContent(content);
      expect(res.isValid).toBe(true);
      expect(res.unknownPlaceholders).toHaveLength(0);
      expect(res.missingGroups).toHaveLength(0);
    });

    it('flags unsupported placeholders as invalid', () => {
      const content = 'Hello {{contact.first_name}}, your code is {{system.secret_otp}}.';
      const res = validateTemplateContent(content);
      expect(res.isValid).toBe(false);
      expect(res.unknownPlaceholders).toEqual(['system.secret_otp']);
    });

    it('identifies missing recommended placeholder groups without marking template invalid', () => {
      const content = 'Thanks for reaching out to {{company.name}}!';
      const res = validateTemplateContent(content);
      expect(res.isValid).toBe(true);
      expect(res.missingGroups).toHaveLength(1);
      expect(res.missingGroups[0].label).toBe('contact');
    });
  });

  describe('formatTemplateValidationMessage', () => {
    it('returns empty string when there are no unknown placeholders', () => {
      const res = validateTemplateContent('Hello {{contact.first_name}}!');
      expect(formatTemplateValidationMessage(res)).toBe('');
    });

    it('formats single unknown placeholder warning', () => {
      const res = validateTemplateContent('Hello {{invalid_param}}!');
      expect(formatTemplateValidationMessage(res)).toBe('Remove or replace unsupported placeholder: {{invalid_param}}.');
    });

    it('formats multiple unknown placeholders warning', () => {
      const res = validateTemplateContent('Hello {{param_one}} and {{param_two}}!');
      expect(formatTemplateValidationMessage(res)).toBe('Remove or replace unsupported placeholders: {{param_one}}, {{param_two}}.');
    });
  });
});
