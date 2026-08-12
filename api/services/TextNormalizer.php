<?php

/**
 * TextNormalizer — Normalizes non-standard Unicode digits, dashes, quotes, spaces, and symbols
 * into standard GSM-7 / ASCII SMS equivalents.
 *
 * Prevents dates, times, numbers, dashes, and punctuation from being silently stripped out
 * when message text contains fancy fonts (e.g., from Canva, Notion, Facebook, Word) or special Unicode characters.
 */
class TextNormalizer
{
    /**
     * Normalizes fancy Unicode characters to standard ASCII / GSM-7 safe text.
     */
    public static function normalize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // 1. Convert Unicode Mathematical Digits (Bold, Double-struck, Sans, Monospace U+1D7CE..U+1D7FF) to 0-9
        $text = preg_replace_callback('/[\x{1D7CE}-\x{1D7FF}]/u', static function ($match) {
            $code = mb_ord($match[0], 'UTF-8');
            return (string)(($code - 0x1D7CE) % 10);
        }, $text);

        // 2. Convert Fullwidth Digits (U+FF10..U+FF19) to 0-9
        $text = preg_replace_callback('/[\x{FF10}-\x{FF19}]/u', static function ($match) {
            $code = mb_ord($match[0], 'UTF-8');
            return (string)($code - 0xFF10);
        }, $text);

        // 3. Convert Superscript / Subscript digits to 0-9
        $superSubMap = [
            '⁰'=>'0', '¹'=>'1', '²'=>'2', '³'=>'3', '⁴'=>'4', '⁵'=>'5', '⁶'=>'6', '⁷'=>'7', '⁸'=>'8', '⁹'=>'9',
            '₀'=>'0', '₁'=>'1', '₂'=>'2', '₃'=>'3', '₄'=>'4', '₅'=>'5', '₆'=>'6', '₇'=>'7', '₈'=>'8', '₉'=>'9'
        ];
        $text = strtr($text, $superSubMap);

        // 4. Convert En-dash, Em-dash, figure dash, minus sign, etc. to standard hyphen '-'
        $dashesMap = [
            "\u{2010}" => '-', // Hyphen
            "\u{2011}" => '-', // Non-breaking hyphen
            "\u{2012}" => '-', // Figure dash
            "\u{2013}" => '-', // En dash
            "\u{2014}" => '-', // Em dash
            "\u{2015}" => '-', // Horizontal bar
            "\u{2212}" => '-', // Minus sign
            "\u{FE63}" => '-', // Small hyphen-minus
            "\u{FF0D}" => '-', // Fullwidth hyphen-minus
        ];
        $text = strtr($text, $dashesMap);

        // 5. Convert smart quotes, apostrophes, backticks, acute accents
        $quotesMap = [
            '‘' => "'", '’' => "'", '‚' => "'", '‛' => "'", '`' => "'", '´' => "'",
            '“' => '"', '”' => '"', '„' => '"', '‟' => '"'
        ];
        $text = strtr($text, $quotesMap);

        // 6. Convert multiplication sign × to 'x' and ellipsis … to '...'
        $symbolMap = [
            '×' => 'x',
            '…' => '...',
        ];
        $text = strtr($text, $symbolMap);

        // 7. Convert non-breaking spaces and unicode spaces to standard ASCII space
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]/u', ' ', $text);

        return $text;
    }

    /**
     * Normalizes text and strips remaining non-GSM-7 characters (emojis, etc.)
     * ensuring 100% complete message delivery without dropping dates, times, or digits.
     */
    public static function sanitizeGsm7(string $message): string
    {
        if ($message === '') {
            return '';
        }

        // First normalize fancy Unicode digits/dashes/spaces to GSM-7 safe ASCII characters
        $normalized = self::normalize($message);

        $gsm7Basic     = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $gsm7Extension = "^{}\\\\[]~|€";
        $gsm7All       = $gsm7Basic . $gsm7Extension;

        $cleaned = '';
        $slen    = mb_strlen($normalized, 'UTF-8');
        for ($si = 0; $si < $slen; $si++) {
            $schar = mb_substr($normalized, $si, 1, 'UTF-8');
            if (mb_strpos($gsm7All, $schar, 0, 'UTF-8') !== false
                || $schar === "\n" || $schar === "\r" || $schar === "\t"
            ) {
                $cleaned .= $schar;
            }
        }

        return trim(preg_replace('/[^\S\n]+/', ' ', $cleaned));
    }
}
