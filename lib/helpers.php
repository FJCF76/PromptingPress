<?php
/**
 * lib/helpers.php — PromptingPress Utility Functions
 *
 * Small helpers used across templates and components.
 * All functions prefixed pp_ to avoid collisions.
 */

/**
 * Echoes a value with esc_attr() applied.
 *
 * @param mixed $value  Value to escape and echo.
 */
function pp_esc_attr_e($value): void {
    echo esc_attr($value);
}

/**
 * Joins an array of CSS class names into a single space-separated string.
 * Empty strings, null values, and false values are filtered out.
 *
 * @param array $classes  Array of class name strings.
 * @return string
 */
function pp_classes(array $classes): string {
    return implode(' ', array_filter($classes, function ($class) {
        return !empty($class);
    }));
}

/**
 * Renders a footer contact block as safe HTML: the plain-text pp_footer_contact
 * value with recognizable email and international phone substrings turned into
 * actionable mailto:/tel: links, newlines preserved. Issue 427.
 *
 * Lives in lib/helpers.php (loaded once) rather than in footer.php, which is
 * INCLUDED PER RENDER — a function defined there fatals on the second render
 * (FooterChromeTest renders the footer many times in one process).
 *
 * Contract (matches the #427 addendum — the recorded decision):
 *   - The input is plain text (the free-text pp_footer_contact option, #300). It
 *     is HTML-escaped FIRST; linkification runs on the ESCAPED string, so no raw
 *     markup can ever be reintroduced. Email/phone tokens contain no HTML-special
 *     characters, so an escaped token is byte-identical to the original token —
 *     the anchor text is therefore always the escaped match, never the raw input.
 *   - Email: a conservative address becomes <a href="mailto:addr">addr</a>.
 *   - Phone: ONLY international numbers (an explicit leading "+") are linked, so
 *     order numbers, postcodes, dates, tax IDs, and street numbers pass through
 *     untouched (the conservative-parser requirement). The tel: target is
 *     normalized to "+" followed by digits only.
 *   - Text matching neither passes through byte-identical, then nl2br() — so a
 *     value with no email/phone renders exactly as the pre-427 footer did.
 *
 * A single alternation pass consumes each character once (email branch first),
 * so an address is never re-scanned by the phone branch and matches never overlap.
 *
 * @param string $contact  Raw plain-text contact value.
 * @return string  Safe HTML for echoing inside the contact <address> block.
 */
function pp_footer_linkify_contact(string $contact): string {
    $escaped = esc_html($contact);

    $pattern = '/'
        // Email: local@domain.tld — conservative (no spaces; TLD >= 2 letters). The
        // left boundary keeps it from linking inside a larger token (e.g. a URL path
        // or an identifier glued to an address).
        . '(?<![A-Za-z0-9._%+\-@])(?<email>[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})'
        . '|'
        // Phone: an explicit leading "+", then digits with optional SINGLE-LINE
        // separators (space, dot, hyphen, parentheses — never a newline, so a match
        // can't span two address lines). Left boundary: the "+" must not follow a
        // word char or another "+", so it never links inside "SKU+1234567". Trailing
        // boundary: a number glued to letters (e.g. "+1234567abc") is not a phone.
        . '(?<![\w+])(?<phone>\+\d[\d ().\-]{5,17}\d)(?![\w])'
        . '/';

    // preg_replace_callback returns null on a PCRE failure (e.g. a backtrack-limit
    // hit on a pathological value); fall back to the escaped text so the block still
    // renders safely instead of passing null into nl2br().
    $linked = preg_replace_callback($pattern, function (array $m): string {
        if (isset($m['email']) && $m['email'] !== '') {
            $addr = $m['email'];
            return '<a href="' . esc_url('mailto:' . $addr) . '">' . $addr . '</a>';
        }
        $display = $m['phone'];
        // tel: target = "+" plus digits only (strip spaces/dots/hyphens/parens).
        $tel = '+' . preg_replace('/\D/', '', $display);
        return '<a href="' . esc_url('tel:' . $tel) . '">' . $display . '</a>';
    }, $escaped) ?? $escaped;

    return nl2br($linked);
}
