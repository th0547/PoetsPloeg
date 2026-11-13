/**
 * Beschikbaarheid van een medewerker: een weekoverzicht met balken, daaronder het weekpatroon (kan wel /
 * kan niet, met herhaling en eventueel een periode) en de afwijkende dagen. Een afwijkende dag vervangt
 * die dag het weekpatroon. Afwezigheid in de gekozen week wordt er overheen getoond, die gaat altijd vóór.
 * De logica is gelijk aan AvailabilityPattern::occursOn() en AvailabilitySchedule::windowsOn() in PHP.
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

/** Valt de datum binnen de periode (geldig van / t/m) van het tijdvak? */
export function coversDate(p, date) {
    const iso = isoDate(date);
    return (!p.valid_from || p.valid_from <= iso) && (!p.valid_until || p.valid_until >= iso);
}

export function occursOn(p, date) {
    if (!coversDate(p, date)) return false;
    const { unit, every } = REPEATS[p.repeat] ?? REPEATS.w1;
    const anchor = p.starts_on ? parseDate(p.starts_on) : null;
    if (unit === 'day') {
        return every === 1 || !anchor || floorMod(daysBetween(anchor, date), every) === 0;
    }
    if (isoWeekday(date) !== Number(p.weekday)) return false;
    if (every === 1 || !anchor) return true;
    return floorMod(Math.round(daysBetween(mondayOf(anchor), mondayOf(date)) / 7), every) === 0;
}

/** Tijdvakken van een afwijkende dag, zoals AvailabilityDay::rowsFromForm() in PHP. */
export function dayWindows(rule) {
    if (rule.mode === 'unavailable_all') return [{ type: 'unavailable', start_time: '00:00', end_time: '00:00', note: rule.note }];
    if (rule.mode === 'available_all') return [{ type: 'available', start_time: '00:00', end_time: '00:00', note: rule.note }];
    if (rule.mode === 'slots') return rule.slots.filter((s) => s.start_time && s.end_time);
    return [];
}

const span = (w) => {
    const s = minutes(w.start_time);
    let e = minutes(w.end_time);
    if (e <= s) e = 24 * 60;
    return [s, e];
};

export default function availabilityEditor(initial = [], absences = [], days = []) {
    return {
        patterns: initial.map((p) => ({ repeat: 'w1', starts_on: null, valid_from: null, valid_until: null, note: '', ...p, showPeriod: !!(p.valid_from || p.valid_until) })),
        dayRules: days.map((d) => ({ note: '', slots: [], past: false, ...d })),
        copyUntil: '',
        absences,
        weekStart: mondayOf(new Date()),
        highlight: null,
        highlightDay: null,

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
            this.$nextTick(() => document.querySelector(`[data-pattern="${i}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
            clearTimeout(this.highlightTimer);
            this.highlightTimer = setTimeout(() => { this.highlight = null; }, 2500);
        },

        /** Wat geldt er in het algemeen buiten de ingevulde tijdvakken van het weekpatroon? */
        restIs() {
            if (!this.patterns.length) return 'none';
            return this.patterns.some((p) => p.type === 'available') ? 'unavailable' : 'available';
        },

        /** Wat geldt er op deze dag buiten de tijdvakken? Gelijk aan AvailabilitySchedule::windowsOn() in PHP. */
        restOn(day) {
            const rule = this.dayRule(day);
            if (rule) return dayWindows(rule).some((w) => w.type === 'available') ? 'unavailable' : 'available';
            if (!this.patterns.length && !this.dayRules.length) return 'none';
            return this.patterns.some((p) => p.type === 'available' && coversDate(p, day)) ? 'unavailable' : 'available';
        },

        periodLabel(p) {
            const f = (s) => formatDate(parseDate(s), { day: 'numeric', month: 'short', year: 'numeric' });
            if (p.valid_from && p.valid_until) return `van ${f(p.valid_from)} t/m ${f(p.valid_until)}`;
            if (p.valid_from) return `vanaf ${f(p.valid_from)}`;
            if (p.valid_until) return `t/m ${f(p.valid_until)}`;
            return '';
        },

        // --- Afwijkende dagen ---

        dayRule(day) {
            const iso = isoDate(day);
            return this.dayRules.find((d) => d.date === iso) ?? null;
        },

        isPast(day) {
            return isoDate(day) < isoDate(new Date());
        },

        /** Bewerkbare afwijkende dagen (vandaag en later), op datum. */
        editableDays() {
            const today = isoDate(new Date());
            return this.dayRules.filter((d) => d.date >= today).sort((a, b) => a.date.localeCompare(b.date));
        },

        dayTitle(rule) {
            return formatDate(parseDate(rule.date), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        },

        /** "Deze dag anders": begin met wat het patroon die dag zegt, zodat je alleen hoeft aan te passen. */
        editDay(day) {
            if (this.isPast(day)) return;
            let rule = this.dayRule(day);
            if (!rule) {
                const slots = this.patterns.filter((p) => p.start_time && p.end_time && occursOn(p, day))
                    .map((p) => ({ type: p.type, start_time: p.start_time, end_time: p.end_time, note: p.note || '' }));
                rule = { date: isoDate(day), mode: 'slots', note: '', past: false, slots: slots.length ? slots : [{ type: 'available', start_time: '17:00', end_time: '22:00', note: '' }] };
                this.dayRules.push(rule);
            }
            this.focusDay(rule.date);
        },

        resetDay(date) {
            this.dayRules = this.dayRules.filter((d) => d.date !== date);
        },

        addSlot(rule, type) {
            rule.slots.push(type === 'unavailable'
                ? { type, start_time: '08:00', end_time: '15:00', note: '' }
                : { type, start_time: '17:00', end_time: '22:00', note: '' });
        },

        modeChanged(rule) {
            if (rule.mode === 'slots' && !rule.slots.length) this.addSlot(rule, 'available');
        },

        focusDay(date) {
            this.highlightDay = date;
            this.$nextTick(() => document.querySelector(`[data-day="${date}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
            clearTimeout(this.highlightDayTimer);
            this.highlightDayTimer = setTimeout(() => { this.highlightDay = null; }, 2500);
        },

        weekHasDayRules() {
            return this.days().some((d) => this.dayRule(d) && !this.isPast(d));
        },

        /** Kopieer de afwijkende dagen van de getoonde week naar de volgende week, of elke week t/m copyUntil. */
        copyWeek() {
            const source = this.days().map((d) => this.dayRule(d)).filter((r) => r && !r.past);
            if (!source.length) return;
            const last = this.copyUntil ? parseDate(this.copyUntil) : addDays(this.weekStart, 13);
            const limit = addDays(new Date(), 366);
            for (let w = 1; w <= 53; w++) {
                const copies = source.map((r) => ({ ...r, slots: r.slots.map((s) => ({ ...s })), date: isoDate(addDays(parseDate(r.date), 7 * w)) }));
                if (parseDate(copies[0].date) > last || parseDate(copies[0].date) > limit) break;
                copies.forEach((c) => {
                    if (parseDate(c.date) > last || parseDate(c.date) > limit) return;
                    this.dayRules = this.dayRules.filter((d) => d.date !== c.date).concat([c]);
                });
            }
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

        weekStartIso(weeks = 0) {
            return isoDate(addDays(this.weekStart, 7 * weeks));
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
            const all = [...this.patterns, ...this.dayRules.flatMap((d) => (d.mode === 'slots' ? d.slots : []))];
            all.filter((p) => p.start_time && p.end_time).forEach((p) => {
                const [s, e] = span(p);
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

        /** De tijdvakken die op deze dag gelden: die van de afwijkende dag, anders die van het weekpatroon. */
        blocks(day) {
            const rule = this.dayRule(day);
            if (rule) {
                return dayWindows(rule).map((w, i) => {
                    const [s, e] = span(w);
                    const whole = s === 0 && e === 24 * 60;
                    const text = whole ? (w.type === 'unavailable' ? 'Hele dag niet' : 'Hele dag wel') : `${w.start_time}–${w.end_time}`;
                    return {
                        index: `d${i}`,
                        specific: true,
                        type: w.type,
                        label: `${text}${w.note ? ' · ' + w.note : ''}`,
                        title: `Alleen deze dag: ${w.type === 'unavailable' ? 'kan niet' : 'kan wel'} ${text}${w.note ? ' · ' + w.note : ''}`,
                        style: this.place(s, e),
                    };
                }).filter((b) => b.style);
            }
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
                        specific: false,
                        type: p.type,
                        label: `${p.start_time}–${p.end_time}${p.note ? ' · ' + p.note : ''}`,
                        title: `${p.type === 'unavailable' ? 'Kan niet' : 'Kan wel'} ${p.start_time}–${p.end_time}${p.note ? ' · ' + p.note : ''}${repeat}${this.periodLabel(p) ? ' (' + this.periodLabel(p) + ')' : ''}`,
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
