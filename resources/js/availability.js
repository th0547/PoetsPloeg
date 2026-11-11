/**
 * Vaste (terugkerende) beschikbaarheid van een medewerker: een weekoverzicht met balken en daaronder de
 * tijdvakken (kan wel / kan niet, met herhaling). Afwezigheid in de gekozen week wordt er overheen getoond,
 * omdat die altijd vóór het vaste patroon gaat.
 * De herhalingslogica is gelijk aan AvailabilityPattern::occursOn() in PHP.
 */

import { addDays, daysBetween, floorMod, formatDate, isoDate, isoWeekday, minutes, mondayOf, parseDate } from './dates';

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

export default function availabilityEditor(initial = [], absences = []) {
    return {
        patterns: initial.map((p) => ({ repeat: 'w1', starts_on: null, note: '', ...p })),
        absences,
        weekStart: mondayOf(new Date()),
        highlight: null,

        init() {
            this.sort();
        },

        isDaily(p) {
            return (REPEATS[p.repeat] ?? REPEATS.w1).unit === 'day';
        },

        needsStart(p) {
            return p.repeat !== 'w1' && p.repeat !== 'd1';
        },

        add(type, weekday = 1) {
            this.patterns.push(type === 'unavailable'
                ? { type, repeat: 'w1', weekday, starts_on: null, start_time: '08:00', end_time: '15:00', note: '' }
                : { type, repeat: 'w1', weekday, starts_on: null, start_time: '17:00', end_time: '22:00', note: '' });
            this.focus(this.patterns.length - 1);
        },

        remove(i) {
            this.patterns.splice(i, 1);
            this.highlight = null;
        },

        /** Laat even zien welke regel bij een balk in het weekoverzicht hoort. */
        focus(i) {
            this.highlight = i;
            this.$nextTick(() => this.$root.querySelector(`[data-pattern="${i}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
            clearTimeout(this.highlightTimer);
            this.highlightTimer = setTimeout(() => { this.highlight = null; }, 2500);
        },

        /** Wat geldt er buiten de ingevulde tijdvakken? Gelijk aan AvailabilityCheck::evaluate() in PHP. */
        restIs() {
            if (!this.patterns.length) return 'none';
            return this.patterns.some((p) => p.type === 'available') ? 'unavailable' : 'available';
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

        // --- Weekoverzicht ---

        days() {
            return [0, 1, 2, 3, 4, 5, 6].map((i) => addDays(this.weekStart, i));
        },

        dayLabel(d) {
            return formatDate(d, { weekday: 'short', day: 'numeric', month: 'short' });
        },

        weekLabel() {
            const end = addDays(this.weekStart, 6);
            return `${formatDate(this.weekStart, { day: 'numeric', month: 'short' })} – ${formatDate(end, { day: 'numeric', month: 'short', year: 'numeric' })}`;
        },

        isThisWeek() {
            return isoDate(this.weekStart) === isoDate(mondayOf(new Date()));
        },

        isToday(d) {
            return isoDate(d) === isoDate(new Date());
        },

        shiftWeek(n) {
            this.weekStart = addDays(this.weekStart, 7 * n);
        },

        thisWeek() {
            this.weekStart = mondayOf(new Date());
        },

        /** Uren die het overzicht toont: minimaal 06:00–22:00, uitgebreid tot alle tijdvakken passen. */
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

        /** Uurlabels boven de balken (om de twee uur), met hun positie in procenten. */
        hourTicks() {
            const { from, to } = this.hours();
            const ticks = [];
            for (let h = from; h <= to; h += 2) {
                ticks.push({ label: String(h).padStart(2, '0'), left: ((h - from) / (to - from)) * 100 });
            }
            return ticks;
        },

        /** Plaats van een tijdvak (in minuten vanaf middernacht) op de tijdbalk, of null als het erbuiten valt. */
        place(startMin, endMin) {
            const { from, to } = this.hours();
            const s = Math.max(startMin, from * 60);
            const e = Math.min(endMin, to * 60);
            if (e <= s) return null;
            const span = (to - from) * 60;
            return `left:${((s - from * 60) / span) * 100}%;width:${((e - s) / span) * 100}%`;
        },

        /** De tijdvakken die op deze dag gelden. */
        blocks(day) {
            return this.patterns
                .map((p, index) => ({ p, index }))
                .filter(({ p }) => p.start_time && p.end_time && occursOn(p, day))
                .map(({ p, index }) => {
                    const s = minutes(p.start_time);
                    let e = minutes(p.end_time);
                    if (e <= s) e = 24 * 60;
                    const repeat = (REPEATS[p.repeat] ?? REPEATS.w1).every > 1 ? ' (niet elke week)' : '';
                    return {
                        index,
                        type: p.type,
                        label: `${p.start_time}–${p.end_time}${p.note ? ' · ' + p.note : ''}`,
                        title: `${p.type === 'unavailable' ? 'Kan niet' : 'Kan wel'} ${p.start_time}–${p.end_time}${p.note ? ' · ' + p.note : ''}${repeat}`,
                        style: this.place(s, e),
                    };
                })
                .filter((b) => b.style);
        },

        /** Goedgekeurde afwezigheid op deze dag: die gaat vóór het vaste patroon. */
        absencesOn(day) {
            const dayStart = day.getTime();
            const dayEnd = addDays(day, 1).getTime();
            return this.absences
                .map((a) => ({ a, s: new Date(a.start).getTime(), e: new Date(a.end).getTime() }))
                .filter(({ s, e }) => s < dayEnd && e > dayStart)
                .map(({ a, s, e }) => {
                    const startMin = Math.max(0, (s - dayStart) / 60000);
                    // 23:59 telt als einde van de dag.
                    const endMin = Math.min(1440, Math.ceil((e - dayStart) / 60000 / 5) * 5);
                    return { label: `Afwezig: ${a.label}`, style: this.place(startMin, endMin) };
                })
                .filter((b) => b.style);
        },

        weekHasAbsence() {
            return this.days().some((d) => this.absencesOn(d).length > 0);
        },
    };
}
