/** Kleine hulpfuncties voor HTML die in JavaScript wordt opgebouwd (rooster, panelen, zoekbalk). */

const ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/** Tekst veilig in HTML zetten. Alles wat van de server of de gebruiker komt gaat hierdoor. */
export const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ENTITIES[c]);

/** Alleen kleuren als "#0d9488" komen in een style-attribuut terecht. */
export const safeColor = (color) => (/^#[0-9a-fA-F]{6}$/.test(color ?? '') ? color : '#64748b');

/** Leesbare tekstkleur op een achtergrondkleur (gelijk aan Planning::contrast in PHP). */
export function contrast(color) {
    const hex = safeColor(color);
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.62 ? '#0f172a' : '#ffffff';
}

/** "Jan de Vries" → "JV", "Bram" → "BR" (gelijk aan Planning::initials in PHP). */
export function initials(name) {
    const all = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    // Titels ("dr.", "ing.") tellen niet mee, tenzij er verder niets staat.
    const named = all.filter((w) => !w.endsWith('.'));
    const words = named.length ? named : all;
    if (!words.length) return '?';
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
    return (words[0][0] + words[words.length - 1][0]).toUpperCase();
}

/** Icoon uit de sprite in de pagina (resources/views/partials/icons.blade.php). */
export const icon = (name, cls = '') =>
    `<svg class="icon ${cls}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#i-${name}"/></svg>`;

/** Avatar met initialen op de kleur van de medewerker. */
export const avatar = (person, size = '') =>
    `<span class="avatar${size ? ' avatar-' + size : ''}" style="--c:${safeColor(person?.color)};--ink:${contrast(person?.color)}" aria-hidden="true">${esc(person?.initials ?? initials(person?.name))}</span>`;

/** Uren als "7,5" (gelijk aan Planning::hours in PHP). */
export const hours = (n) => (Math.round((Number(n) || 0) * 100) / 100).toString().replace('.', ',');

/** Minuten sinds middernacht → "05:30" (ook na middernacht: 1500 → "01:00"). */
export const clock = (minutes) => {
    const m = ((Math.round(minutes) % 1440) + 1440) % 1440;
    return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
};
