/**
 * Travel Booking Engine - Utility functions
 *
 * Date parsing, formatting, locale detection and
 * a helper to read window.TravelTranslations.
 */

import { useEffect } from 'react';
import {
    MONTHS_SHORT_EN,
    MONTHS_SHORT_RO,
    DAYS_SHORT_EN,
    DAYS_SHORT_RO,
} from './translations';

// ---------------------------------------------------------------------------
// Locale detection
// ---------------------------------------------------------------------------

/**
 * Detect the active locale by inspecting DOM attributes and
 * the CS-Cart Tygh global.
 *
 * @returns {'en'|'ro'}
 */
export function getLocale() {
    // 1. Explicit data-lang on a booking widget element
    const els = document.querySelectorAll('[data-lang], [data-travel-booking]');
    for (const el of els) {
        const lang = el.getAttribute('data-lang');
        if (lang && lang.toLowerCase().startsWith('ro')) return 'ro';
    }

    // 2. <html lang="...">
    const html = document.documentElement;
    const htmlLang = (html.getAttribute('lang') || html.getAttribute('xml:lang') || '').toLowerCase();
    if (htmlLang.startsWith('ro')) return 'ro';

    // 3. CS-Cart Tygh.language
    if (typeof window !== 'undefined' && window.Tygh && window.Tygh.language) {
        if (window.Tygh.language.toLowerCase().startsWith('ro')) return 'ro';
    }

    return 'en';
}

// ---------------------------------------------------------------------------
// Translation helper
// ---------------------------------------------------------------------------

/**
 * Read a key from `window.TravelTranslations`, then fall back
 * to the provided default.
 */
export function t(key, fallback) {
    const dict = (typeof window !== 'undefined' && window.TravelTranslations) || {};
    return dict[key] || fallback || key;
}

// ---------------------------------------------------------------------------
// Date utilities
// ---------------------------------------------------------------------------

/**
 * Parse a "YYYY-MM-DD" string into a Date (noon, to avoid TZ issues).
 * Validates month (1-12) and day bounds for the given month.
 */
export function parseDate(str) {
    if (!str) return null;
    const parts = str.split('-');
    if (parts.length !== 3) return null;
    const year = parseInt(parts[0], 10);
    const month = parseInt(parts[1], 10);
    const day = parseInt(parts[2], 10);
    if (isNaN(year) || isNaN(month) || isNaN(day)) return null;
    if (month < 1 || month > 12) return null;
    const maxDay = new Date(year, month, 0).getDate();
    if (day < 1 || day > maxDay) return null;
    return new Date(year, month - 1, day, 12, 0, 0);
}

/**
 * Format a Date as "YYYY-MM-DD".
 */
export function toDateString(date) {
    if (!date) return '';
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/**
 * Human-readable short date – e.g. "Mon. 14 Feb." / "Lun. 14 Febr."
 */
export function formatDateShort(date) {
    if (!date) return '';
    const locale = getLocale();
    const dayNames = locale === 'ro' ? DAYS_SHORT_RO : DAYS_SHORT_EN;
    const monthNames = locale === 'ro' ? MONTHS_SHORT_RO : MONTHS_SHORT_EN;
    return dayNames[date.getDay()] + ' ' + date.getDate() + ' ' + monthNames[date.getMonth()];
}

/**
 * Date with weekday and year, for the Design A bar and calendar —
 * "lun. 26 oct. 2026" / "Mon. 26 Oct. 2026".
 */
export function formatDateField(date) {
    if (!date) return '';
    const text = `${formatDateShort(date)} ${date.getFullYear()}`;
    return getLocale() === 'ro' ? text.toLowerCase() : text;
}

/**
 * Count nights between two dates.
 */
export function nightsBetween(checkIn, checkOut) {
    if (!checkIn || !checkOut) return 0;
    return Math.ceil((checkOut.getTime() - checkIn.getTime()) / 86400000);
}

/**
 * Romanian-aware pluralization.
 *
 * Romanian has 3 plural forms:
 *   one: n == 1                    (1 noapte)
 *   few: n == 0 or n % 100 in 2..19   (2 nopți, 0 nopți, 112 nopți)
 *   other: everything else            (20 de nopți, 100 de nopți)
 */
export function tPlural(n, oneKey, fewKey, otherKey, oneFallback, fewFallback, otherFallback) {
    const locale = getLocale();
    if (locale !== 'ro') {
        return n === 1 ? t(oneKey, oneFallback) : t(fewKey, fewFallback);
    }
    if (n === 1) return t(oneKey, oneFallback);
    const mod100 = n % 100;
    if (n === 0 || (mod100 >= 2 && mod100 <= 19)) return t(fewKey, fewFallback);
    return t(otherKey || fewKey, otherFallback || fewFallback);
}

// ---------------------------------------------------------------------------
// Focus trap hook for WCAG 2.1 dialog compliance
// ---------------------------------------------------------------------------

const FOCUSABLE_SELECTOR = [
    'a[href]', 'button:not([disabled])', 'input:not([disabled])',
    'select:not([disabled])', 'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

/**
 * Trap keyboard focus within a dialog element while it is mounted.
 */
export function useFocusTrap(ref) {
    useEffect(() => {
        const el = ref.current;
        if (!el) return;

        const previouslyFocused = document.activeElement;

        const focusable = el.querySelectorAll(FOCUSABLE_SELECTOR);
        if (focusable.length > 0) {
            focusable[0].focus();
        }

        function handleKeyDown(e) {
            if (e.key !== 'Tab') return;

            const nodes = el.querySelectorAll(FOCUSABLE_SELECTOR);
            if (nodes.length === 0) return;

            const first = nodes[0];
            const last = nodes[nodes.length - 1];

            if (e.shiftKey) {
                if (document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if (document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }

        el.addEventListener('keydown', handleKeyDown);

        return () => {
            el.removeEventListener('keydown', handleKeyDown);
            if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                previouslyFocused.focus();
            }
        };
    }, [ref]);
}
