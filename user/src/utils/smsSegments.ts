export type SmsEncoding = "gsm7" | "unicode";

// GSM 03.38 basic character set (subset used for billing detection).
// Source: common GSM 03.38 tables; kept inline to avoid dependencies.
const GSM7_BASIC =
  "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\u001BÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡" +
  "ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

// GSM 03.38 extension table characters (count as 2 septets each).
const GSM7_EXT = "^{}\\[~]|€";

function isGsm7BasicChar(ch: string): boolean {
  return GSM7_BASIC.includes(ch);
}

function isGsm7ExtendedChar(ch: string): boolean {
  return GSM7_EXT.includes(ch);
}

export function estimateSmsSegments(text: string): {
  encoding: SmsEncoding;
  lengthUnits: number;
  singleSegmentLimit: number;
  multiSegmentLimit: number;
  segments: number;
} {
  if (!text) {
    return {
      encoding: "gsm7",
      lengthUnits: 0,
      singleSegmentLimit: 160,
      multiSegmentLimit: 153,
      segments: 0,
    };
  }

  // Decide encoding and count "billing units":
  // - GSM-7 basic chars: 1 unit
  // - GSM-7 extended chars: 2 units (escape + char)
  // - Anything else: Unicode (UCS-2) with 1 unit per code unit for our estimate
  let encoding: SmsEncoding = "gsm7";
  let units = 0;

  for (const ch of text) {
    if (isGsm7BasicChar(ch)) {
      units += 1;
      continue;
    }
    if (isGsm7ExtendedChar(ch)) {
      units += 2;
      continue;
    }
    encoding = "unicode";
    break;
  }

  if (encoding === "unicode") {
    // JS string iteration by code points, but backend billing typically follows UCS-2 / UTF-16 units.
    // This estimate uses UTF-16 code units to better match common SMS billing.
    units = text.length;
    const single = 70;
    const multi = 67;
    const segments = units <= single ? 1 : Math.ceil(units / multi);
    return {
      encoding,
      lengthUnits: units,
      singleSegmentLimit: single,
      multiSegmentLimit: multi,
      segments,
    };
  }

  const single = 160;
  const multi = 153;
  const segments = units === 0 ? 0 : units <= single ? 1 : Math.ceil(units / multi);
  return {
    encoding,
    lengthUnits: units,
    singleSegmentLimit: single,
    multiSegmentLimit: multi,
    segments,
  };
}

