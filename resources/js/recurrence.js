/**
 * Herhalingspatroon van een vaste taak: kiezen met knoppen in plaats van "elke N weken", met een
 * voorbeeldkalender die laat zien op welke dagen er wordt ingepland.
 * De herhalingslogica is gelijk aan ShiftGenerator::occursOn() in PHP.
 */

import { addDays, daysBetween, daysInMonth, firstOfMonth, formatDate, isoDate, isoWeekday, mondayOf, monthCells, parseDate } from './dates';

const WEEKDAYS = { 1: 'maandag', 2: 'dinsdag', 3: 'woensdag', 4: 'donderdag', 5: 'vrijdag', 6: 'zaterdag', 7: 'zondag' };
const NTH = { 1: 'eerste', 2: 'tweede', 3: 'derde', 4: 'vierde', '-1': 'laatste' };

export const PRESETS = {
    weekly: [
        { every: 1, label: 'Elke week' },
        { every: 2, label: 'Om de week' },
        { every: 3, label: 'Elke 3 weken' },
        { every: 4, label: 'Elke 4 weken' },
    ],
    monthly: [
        { every: 1, label: 'Elke maand' },
        { every: 2, label: 'Om de maand' },
        { every: 3, label: 'Elk kwartaal' },
        { every: 6, label: 'Elk half jaar' },
        { every: 12, label: 'Elk jaar' },
    ],
};

/** Valt de taak op deze datum? rule: {recurrence, every, weekdays, nth, weekday, dayOfMonth, validFrom, validUntil}. */
export function occursOn(rule, date) {
    if (!rule.validFrom) return false;
    const from = parseDate(rule.validFrom);
    if (date < from) return false;
    if (rule.validUntil && date > parseDate(rule.validUntil)) return false;

    const every = Math.max(1, Number(rule.every) || 1);
    const monthMatches = () => ((date.getFullYear() * 12 + date.getMonth()) - (from.getFullYear() * 12 + from.getMonth())) % every === 0;

    switch (rule.recurrence) {
        case 'weekly':
            if (!rule.weekdays.includes(isoWeekday(date))) return false;
            return Math.floor(daysBetween(mondayOf(from), mondayOf(date)) / 7) % every === 0;
        case 'monthly_nth_weekday':
            if (isoWeekday(date) !== Number(rule.weekday) || !monthMatches()) return false;
            if (Number(rule.nth) === -1) return addDays(date, 7).getMonth() !== date.getMonth();
            return Math.ceil(date.getDate() / 7) === Number(rule.nth);
        case 'monthly_day':
            return monthMatches() && date.getDate() === Math.min(Number(rule.dayOfMonth), daysInMonth(date));
        default:
            return false;
    }
}

/** "maandag, woensdag en vrijdag" */
const sentenceList = (items) => (items.length <= 1 ? items.join('') : items.slice(0, -1).join(', ') + ' en ' + items[items.length - 1]);

export default function recurrenceEditor(initial = {}) {
    const recurrence = initial.recurrence || 'weekly';
    const mode = recurrence === 'weekly' ? 'weekly' : 'monthly';
    const every = Math.max(1, Number(initial.every) || 1);

    return {
        mode,
        monthlyKind: recurrence === 'monthly_day' ? 'day' : 'nth',
        every,
        custom: !PRESETS[mode].some((p) => p.every === every),
        weekdays: (initial.weekdays || []).map(Number).sort(),
        nth: Number(initial.nth) || 1,
        weekday: Number(initial.weekday) || 1,
        dayOfMonth: Number(initial.dayOfMonth) || 1,
        validFrom: initial.validFrom || isoDate(new Date()),
        validUntil: initial.validUntil || '',
        skipHolidays: !!initial.skipHolidays,
        holidays: initial.holidays || {},
        previewOffset: 0,

        /** De waarde die het formulier opslaat. */
        get recurrence() {
            if (this.mode === 'weekly') return 'weekly';
            return this.monthlyKind === 'day' ? 'monthly_day' : 'monthly_nth_weekday';
        },

        get rule() {
            return {
                recurrence: this.recurrence,
                every: this.every,
                weekdays: this.weekdays,
                nth: this.nth,
                weekday: this.weekday,
                dayOfMonth: this.dayOfMonth,
                validFrom: this.validFrom,
                validUntil: this.validUntil,
            };
        },

        // --- Kiezen ---

        setMode(mode) {
            if (mode === this.mode) return;
            this.mode = mode;
            this.every = 1;
            this.custom = false;
        },

        presets() {
            return PRESETS[this.mode];
        },

        setEvery(n) {
            this.every = n;
            this.custom = false;
        },

        unit() {
            if (this.mode === 'weekly') return this.every === 1 ? 'week' : 'weken';
            return this.every === 1 ? 'maand' : 'maanden';
        },

        toggleWeekday(n) {
            this.weekdays = this.weekdays.includes(n) ? this.weekdays.filter((d) => d !== n) : [...this.weekdays, n].sort();
        },

        /** Opmaak van een keuzeknop. */
        pill(active) {
            return active
                ? 'border-teal-600 bg-teal-600 text-white shadow-sm'
                : 'border-gray-300 bg-white text-gray-700 hover:border-teal-400 hover:bg-teal-50';
        },

        /** Opmaak van een grote keuzekaart. */
        choice(active) {
            return active ? 'border-teal-600 bg-teal-50 ring-1 ring-teal-600' : 'border-gray-200 bg-white hover:border-teal-300';
        },

        // --- Samenvatting ---

        frequencyLabel() {
            const preset = this.presets().find((p) => p.every === this.every);
            return preset ? preset.label : `Elke ${this.every} ${this.unit()}`;
        },

        summary() {
            if (this.mode === 'weekly') {
                if (!this.weekdays.length) return 'Kies minimaal één dag van de week.';
                const all = this.weekdays.join('');
                const days = all === '1234567' ? 'alle dagen' : (all === '12345' ? 'maandag t/m vrijdag' : sentenceList(this.weekdays.map((d) => WEEKDAYS[d])));
                return `${this.frequencyLabel()} op ${days}`;
            }
            if (this.monthlyKind === 'day') {
                return `${this.frequencyLabel()} op de ${this.dayOfMonth}e`;
            }
            return `${this.frequencyLabel()} op de ${NTH[this.nth]} ${WEEKDAYS[this.weekday]}`;
        },

        periodLabel() {
            if (!this.validFrom) return 'Kies een startdatum.';
            const long = (s) => formatDate(parseDate(s), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
            return `Vanaf ${long(this.validFrom)}` + (this.validUntil ? ` t/m ${long(this.validUntil)}` : ', tot je de taak stopt');
        },

        /** Uitleg bij "om de week" en minder vaak: vanaf wanneer wordt er geteld? */
        anchorHint() {
            if (this.every <= 1 || !this.validFrom) return '';
            const from = parseDate(this.validFrom);
            if (this.mode === 'weekly') {
                return `Er wordt geteld vanaf de week van ${formatDate(mondayOf(from), { day: 'numeric', month: 'long' })} (de week van “Vanaf”): die week wel, daarna ${this.every === 2 ? 'steeds een week niet' : `${this.every - 1} weken niet`}.`;
            }
            return `Er wordt geteld vanaf ${formatDate(from, { month: 'long', year: 'numeric' })} (de maand van “Vanaf”).`;
        },

        /** Feestdag waarop de taak wordt overgeslagen, of null. */
        holiday(iso) {
            return this.skipHolidays ? (this.holidays[iso] ?? null) : null;
        },

        /** De eerstvolgende keren dat de taak wordt ingepland (vanaf vandaag). */
        next(count = 6) {
            if (!this.validFrom) return [];
            const today = parseDate(isoDate(new Date()));
            const from = parseDate(this.validFrom);
            const result = [];
            let date = from > today ? from : today;
            for (let i = 0; i < 1100 && result.length < count; i++, date = addDays(date, 1)) {
                if (occursOn(this.rule, date) && !this.holiday(isoDate(date))) {
                    // Het jaar erbij zodra het niet meer dit jaar is.
                    const year = date.getFullYear() === today.getFullYear() ? {} : { year: 'numeric' };
                    result.push(formatDate(date, { weekday: 'short', day: 'numeric', month: 'short', ...year }));
                }
            }
            return result;
        },

        // --- Voorbeeldkalender ---

        previewMonths() {
            const today = new Date();
            const from = this.validFrom ? parseDate(this.validFrom) : today;
            const base = firstOfMonth(from > today ? from : today, this.previewOffset);
            const todayIso = isoDate(today);

            return [0, 1, 2].map((offset) => {
                const first = firstOfMonth(base, offset);
                return {
                    key: isoDate(first),
                    label: formatDate(first, { month: 'long', year: 'numeric' }),
                    cells: monthCells(first).map((cell) => {
                        if (!cell) return null;
                        const planned = occursOn(this.rule, cell.date);
                        const holiday = planned ? this.holiday(cell.iso) : null;
                        return {
                            ...cell,
                            title: holiday ? `${holiday}: wordt overgeslagen` : (planned ? 'Wordt ingepland' : ''),
                            classes: holiday
                                ? 'bg-amber-100 text-amber-800 line-through ring-1 ring-amber-300'
                                : (planned ? 'bg-teal-600 font-semibold text-white' : (cell.weekend ? 'text-gray-300' : 'text-gray-500'))
                                    + (cell.iso === todayIso ? ' ring-1 ring-gray-400' : ''),
                        };
                    }),
                };
            });
        },
    };
}
