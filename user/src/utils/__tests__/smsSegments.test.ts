import { describe, it, expect } from 'vitest';
import { estimateSmsSegments } from '../smsSegments';

describe('estimateSmsSegments', () => {
  it('returns 0 segments for empty text', () => {
    const res = estimateSmsSegments('');
    expect(res.encoding).toBe('gsm7');
    expect(res.segments).toBe(0);
    expect(res.lengthUnits).toBe(0);
  });

  it('calculates GSM-7 basic characters correctly for single segment (<= 160 units)', () => {
    const text = 'A'.repeat(160);
    const res = estimateSmsSegments(text);
    expect(res.encoding).toBe('gsm7');
    expect(res.lengthUnits).toBe(160);
    expect(res.singleSegmentLimit).toBe(160);
    expect(res.multiSegmentLimit).toBe(153);
    expect(res.segments).toBe(1);
  });

  it('calculates GSM-7 basic characters correctly for multi-segment (> 160 units)', () => {
    const text = 'A'.repeat(161);
    const res = estimateSmsSegments(text);
    expect(res.encoding).toBe('gsm7');
    expect(res.lengthUnits).toBe(161);
    expect(res.segments).toBe(2);
  });

  it('counts GSM-7 extended characters as 2 units each', () => {
    // 5 extended chars: ^ { } [ ] -> 10 units
    const text = '^{}[]';
    const res = estimateSmsSegments(text);
    expect(res.encoding).toBe('gsm7');
    expect(res.lengthUnits).toBe(10);
    expect(res.segments).toBe(1);
  });

  it('correctly handles multi-segment split for extended GSM-7 characters', () => {
    // 150 basic chars + 6 extended chars (12 units) = 162 units -> 2 segments (limit 153 per segment)
    const text = 'A'.repeat(150) + '^{}[]~';
    const res = estimateSmsSegments(text);
    expect(res.encoding).toBe('gsm7');
    expect(res.lengthUnits).toBe(162);
    expect(res.segments).toBe(2);
  });

  it('detects Unicode encoding for non-GSM-7 characters (e.g. emoji, accents outside GSM-7)', () => {
    const text = 'Hello 👋 World';
    const res = estimateSmsSegments(text);
    expect(res.encoding).toBe('unicode');
    expect(res.singleSegmentLimit).toBe(70);
    expect(res.multiSegmentLimit).toBe(67);
  });

  it('calculates single segment limit (<= 70) for Unicode text', () => {
    const text = 'Hello world! 🇵🇭';
    const res = estimateSmsSegments(text);
    expect(res.encoding).toBe('unicode');
    expect(res.segments).toBe(1);
  });

  it('calculates multi-segment limit (> 70, steps of 67) for Unicode text', () => {
    // 71 Unicode characters -> 2 segments
    const text = '🚀'.repeat(36); // Each emoji is 2 UTF-16 units = 72 units
    const res = estimateSmsSegments(text);
    expect(res.encoding).toBe('unicode');
    expect(res.lengthUnits).toBe(72);
    expect(res.segments).toBe(2); // 72 > 70 limit, so 2 segments (72 / 67)
  });
});
