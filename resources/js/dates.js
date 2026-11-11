/** Kleine datumhulpjes voor de formulieren (lokale datums, week begint op maandag). */

export const pad = (n) => String(n).padStart(2, '0');

export const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

export const parseDate = (s) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
};

export const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);

export const firstOfMonth = (d, offset = 0) => new Date(d.getFullYear(), d.getMonth() + offset, 1);

export const daysInMonth = (d) => new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();

export const mondayOf = (d) => addDays(d, -((d.getDay() + 6) % 7));

/** ISO-weekdag: 1 = maandag ... 7 = zondag. */
export const isoWeekday = (d) => ((d.getDay() + 6) % 7) + 1;

/** Aantal kalenderdagen, ongevoelig voor zomer-/wintertijd. */
export const daysBetween = (a, b) => Math.round((Date.UTC(b.getFullYear(), b.getMonth(), b.getDate()) - Date.UTC(a.getFullYear(), a.getMonth(), a.getDate())) / 86400000);

export const floorMod = (a, n) => ((a % n) + n) % n;

export const minutes = (t) => {
    const [h, m] = (t || '00:00').split(':').map(Number);
    return h * 60 + m;
};

export const formatDate = (d, options) => new Intl.DateTimeFormat('nl-NL', options).format(d);

/** Vakjes van een maandkalender: null voor de lege vakjes vóór de 1e, daarna één vakje per dag. */
export function monthCells(first) {
    const cells = Array.from({ length: (first.getDay() + 6) % 7 }, () => null);
    for (let n = 1; n <= daysInMonth(first); n++) {
        const date = new Date(first.getFullYear(), first.getMonth(), n);
        cells.push({ iso: isoDate(date), day: n, date, weekend: isoWeekday(date) > 5 });
    }
    return cells;
}
