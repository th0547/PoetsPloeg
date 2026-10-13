/**
 * Beschikbaarheid van een medewerker: lijst (kan wel / kan niet, met herhaling) en een weekkalender.
 * De herhalingslogica is gelijk aan AvailabilityPattern::occursOn() in PHP.
 */

const pad = (n) => String(n).padStart(2, '0');
const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseDate = (s) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
};
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const mondayOf = (d) => addDays(d, -((d.getDay() + 6) % 7));
const isoWeekday = (d) => ((d.getDay() + 6) % 7) + 1;
const daysBetween = (a, b) => Math.round((Date.UTC(b.getFullYear(), b.getMonth(), b.getDate()) - Date.UTC(a.getFullYear(), a.getMonth(), a.getDate())) / 86400000);
const floorMod = (a, n) => ((a % n) + n) % n;
const minutes = (t) => {
    const [h, m] = (t || '00:00').split(':').map(Number);
    return h * 60 + m;
};

export const REPEATS = {
    w1: { unit: 'week', every: 1 },
    w2: { unit: 'week', every: 2 },
    w3: { unit: 'week', every: 3 },
    w4: { unit: 'week', every: 4 },
    d1: { unit: 'day', every: 1 },
    d2: { unit: 'day', every: 2 },
    d3: { unit: 'day', every: 3 },
};

export function occursOn(p, date) {
    const { unit, every } = REPEATS[p.repeat] ?? REPEATS.w1;
    const anchor = p.starts_on ? parseDate(p.starts_on) : null;
    if (unit === 'day') {
        return every === 1 || !anchor || floorMod(daysBetween(anchor, date), every) === 0;
    }
    if (isoWeekday(date) !== Number(p.weekday)) return false;
    if (every === 1 || !anchor) return true;
    return floorMod(Math.round(daysBetween(mondayOf(anchor), mondayOf(date)) / 7), every) === 0;
}

export default function availabilityEditor(initial = []) {
    return {
        patterns: initial.map((p) => ({ repeat: 'w1', starts_on: null, note: '', ...p })),
        view: 'list',
        weekStart: mondayOf(new Date()),

        init() {
            this.sort();
        },

        isDaily(p) {
            return (REPEATS[p.repeat] ?? REPEATS.w1).unit === 'day';
        },

        needsStart(p) {
            return p.repeat !== 'w1' && p.repeat !== 'd1';
        },

        add(type) {
            this.patterns.push(type === 'unavailable'
                ? { type, repeat: 'w1', weekday: 1, starts_on: null, start_time: '08:00', end_time: '15:00', note: '' }
                : { type, repeat: 'w1', weekday: 1, starts_on: null, start_time: '17:00', end_time: '22:00', note: '' });
        },

        remove(i) {
            this.patterns.splice(i, 1);
        },

        /** Herhaling gewijzigd: zorg voor een startdatum als die nodig is. */
        repeatChanged(p) {
            if (this.needsStart(p) && !p.starts_on) {
                p.starts_on = isoDate(this.isDaily(p) ? new Date() : mondayOf(new Date()));
            }
        },

        copyToWeekdays() {
            const last = this.patterns[this.patterns.length - 1];
            if (!last || this.isDaily(last)) return;
            [1, 2, 3, 4, 5].forEach((d) => {
                const exists = this.patterns.some((p) => Number(p.weekday) === d && p.type === last.type && p.repeat === last.repeat
                    && p.start_time === last.start_time && p.end_time === last.end_time);
                if (!exists) this.patterns.push({ ...last, weekday: d });
            });
            this.sort();
        },

        /** Vaste volgorde: dagelijkse herhalingen eerst, dan per weekdag, op begintijd; "kan niet" na "kan wel". */
        sort() {
            const key = (p) => [this.isDaily(p) ? 0 : Number(p.weekday), minutes(p.start_time), p.type === 'unavailable' ? 1 : 0];
            this.patterns.sort((a, b) => {
                const ka = key(a);
                const kb = key(b);
                for (let i = 0; i < ka.length; i++) {
                    if (ka[i] !== kb[i]) return ka[i] - kb[i];
                }
                return 0;
            });
        },

        // --- Weekkalender ---

        days() {
            return [0, 1, 2, 3, 4, 5, 6].map((i) => addDays(this.weekStart, i));
        },

        dayLabel(d) {
            return new Intl.DateTimeFormat('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' }).format(d);
        },

        weekLabel() {
            const end = addDays(this.weekStart, 6);
            const fmt = (d, o) => new Intl.DateTimeFormat('nl-NL', o).format(d);
            return `${fmt(this.weekStart, { day: 'numeric', month: 'short' })} – ${fmt(end, { day: 'numeric', month: 'short', year: 'numeric' })}`;
        },

        shiftWeek(n) {
            this.weekStart = addDays(this.weekStart, 7 * n);
        },

        thisWeek() {
            this.weekStart = mondayOf(new Date());
        },

        /** Uren die de kalender toont: minimaal 06:00–22:00, uitgebreid tot alle tijdvakken passen. */
        hours() {
            let from = 6;
            let to = 22;
            this.patterns.forEach((p) => {
                const s = minutes(p.start_time);
                let e = minutes(p.end_time);
                if (e <= s) e = 24 * 60;
                from = Math.min(from, Math.floor(s / 60));
                to = Math.max(to, Math.ceil(e / 60));
            });
            return { from, to: Math.min(to, 24) };
        },

        hourLabels() {
            const { from, to } = this.hours();
            return Array.from({ length: to - from }, (_, i) => from + i);
        },

        blocks(day) {
            const { from, to } = this.hours();
            const span = (to - from) * 60;
            return this.patterns
                .filter((p) => p.start_time && p.end_time && occursOn(p, day))
                .map((p) => {
                    const s = Math.max(minutes(p.start_time), from * 60);
                    let e = minutes(p.end_time);
                    if (e <= minutes(p.start_time)) e = 24 * 60;
                    e = Math.min(e, to * 60);
                    return {
                        type: p.type,
                        label: `${p.start_time}–${p.end_time}${p.note ? ' · ' + p.note : ''}`,
                        style: `top:${((s - from * 60) / span) * 100}%;height:${Math.max(((e - s) / span) * 100, 2)}%`,
                    };
                });
        },
    };
}
