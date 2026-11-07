/**
 * How the circuit bar and its rooms (board 16.3) write a date, a price and
 * a party, in the shopper's language.
 */

import { parseDate, formatDateShort, getLocale, t } from './utils';

/** "lun. 5 iul." / "Mon. 5 Jul." for YYYY-MM-DD. */
export function chipDate(date) {
    const text = formatDateShort(parseDate(date));
    return getLocale() === 'ro' ? text.toLowerCase() : text;
}

/** "1.092 €" (ro) / "1,092 €" (en): whole units, as the board shows. */
export function formatPrice(price, currency) {
    const amount = new Intl.NumberFormat(getLocale() === 'ro' ? 'ro-RO' : 'en-GB', { maximumFractionDigits: 0 }).format(price);
    return `${amount} ${currency === 'EUR' ? '€' : currency}`;
}

/** "2 adulți · 1 copil · 2 camere": a party, as the guest field shows it. */
export function partySummary(adults, children, rooms) {
    const parts = [`${adults} ${(adults === 1 ? t('adult', 'adult') : t('adults', 'adults')).toLowerCase()}`];
    if (children > 0) {
        parts.push(`${children} ${(children === 1 ? t('child', 'child') : t('children', 'children')).toLowerCase()}`);
    }
    if (rooms > 1) {
        parts.push(`${rooms} ${t('rooms', 'rooms').toLowerCase()}`);
    }
    return parts.join(' · ');
}
