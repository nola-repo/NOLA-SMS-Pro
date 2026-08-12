<?php

/**
 * TextNormalizer — Normalizes non-standard Unicode digits, dashes, quotes, spaces, and symbols
 * into standard GSM-7 / ASCII SMS equivalents.
 *
 * Uses 100% deterministic strtr() character mapping (no PCRE regex byte ranges) to guarantee
 * identical execution across Windows, Linux, Docker, and Apache Cloud Run environments.
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

        // 1. Direct UTF-8 Character Mapping for Unicode Mathematical, Fullwidth, Superscript & Subscript Digits
        $digitMap = [
            // Mathematical Bold Digits (U+1D7CE - U+1D7D7)
            '𝟎'=>'0', '𝟏'=>'1', '𝟐'=>'2', '𝟑'=>'3', '𝟒'=>'4', '𝟓'=>'5', '𝟔'=>'6', '𝟕'=>'7', '𝟖'=>'8', '𝟗'=>'9',
            // Mathematical Double-Struck Digits (U+1D7D8 - U+1D7E1)
            '𝟘'=>'0', '𝟙'=>'1', '𝟚'=>'2', '𝟛'=>'3', '𝟜'=>'4', '𝟝'=>'5', '𝟞'=>'6', '𝟟'=>'7', '𝟠'=>'8', '𝟡'=>'9',
            // Mathematical Sans-Serif Digits (U+1D7E2 - U+1D7EB)
            '𝟢'=>'0', '𝟣'=>'1', '𝟤'=>'2', '𝟥'=>'3', '𝟦'=>'4', '𝟧'=>'5', '𝟨'=>'6', '𝟩'=>'7', '𝟪'=>'8', '𝟫'=>'9',
            // Mathematical Sans-Serif Bold Digits (U+1D7EC - U+1D7F5)
            '𝟬'=>'0', '𝟭'=>'1', '𝟮'=>'2', '𝟯'=>'3', '𝟰'=>'4', '𝟱'=>'5', '𝟲'=>'6', '𝟳'=>'7', '𝟴'=>'8', '𝟵'=>'9',
            // Mathematical Monospace Digits (U+1D7F6 - U+1D7FF)
            '𝟶'=>'0', '𝟷'=>'1', '𝟸'=>'2', '𝟹'=>'3', '𝟺'=>'4', '𝟻'=>'5', '𝟼'=>'6', '𝟽'=>'7', '𝟾'=>'8', '𝟿'=>'9',
            // Fullwidth Digits (U+FF10 - U+FF19)
            '０'=>'0', '１'=>'1', '２'=>'2', '３'=>'3', '４'=>'4', '５'=>'5', '６'=>'6', '７'=>'7', '８'=>'8', '９'=>'9',
            // Superscript / Subscript Digits
            '⁰'=>'0', '¹'=>'1', '²'=>'2', '³'=>'3', '⁴'=>'4', '⁵'=>'5', '⁶'=>'6', '⁷'=>'7', '⁸'=>'8', '⁹'=>'9',
            '₀'=>'0', '₁'=>'1', '₂'=>'2', '₃'=>'3', '₄'=>'4', '₅'=>'5', '₆'=>'6', '₇'=>'7', '₈'=>'8', '₉'=>'9'
        ];
        $text = strtr($text, $digitMap);

        // 2. Direct UTF-8 Mapping for Unicode Dashes & Hyphens
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

        // 3. Direct UTF-8 Mapping for Smart Quotes & Apostrophes
        $quotesMap = [
            '‘' => "'", '’' => "'", '‚' => "'", '‛' => "'", '`' => "'", '´' => "'",
            '“' => '"', '”' => '"', '„' => '"', '‟' => '"'
        ];
        $text = strtr($text, $quotesMap);

        // 4. Direct UTF-8 Mapping for Symbols (Multiplication sign, Ellipsis, bullets)
        $symbolMap = [
            '×' => 'x',
            '…' => '...',
            '•' => '*',
            '·' => '*',
        ];
        $text = strtr($text, $symbolMap);

        // 5. Direct UTF-8 Mapping for Unicode Spaces (Non-breaking space, En/Em space, Zero-width space)
        $spaceMap = [
            "\u{00A0}" => ' ', // Non-breaking space
            "\u{2000}" => ' ', // En quad
            "\u{2001}" => ' ', // Em quad
            "\u{2002}" => ' ', // En space
            "\u{2003}" => ' ', // Em space
            "\u{2004}" => ' ', // Three-per-em space
            "\u{2005}" => ' ', // Four-per-em space
            "\u{2006}" => ' ', // Six-per-em space
            "\u{2007}" => ' ', // Figure space
            "\u{2008}" => ' ', // Punctuation space
            "\u{2009}" => ' ', // Thin space
            "\u{200A}" => ' ', // Hair space
            "\u{200B}" => '',  // Zero-width space
            "\u{202F}" => ' ', // Narrow non-breaking space
            "\u{205F}" => ' ', // Medium mathematical space
            "\u{3000}" => ' ', // Ideographic space
        ];
        $text = strtr($text, $spaceMap);

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
