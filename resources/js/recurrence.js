/**
 * Herhaling van een vaste taak: één of meer patronen, elk met knoppen of met een eigen code
 * (iCalendar RRULE), en een voorbeeldkalender die laat zien op welke dagen er wordt ingepland.
 *
 * De knop-patronen rekenen hier in JS (zelfde logica als TaskTemplatePattern::occursOn() in PHP).
 * Eigen codes rekent de server uit via het preview-endpoint, zodat voorbeeld en planning altijd gelijk zijn.
 * Gebruikersinvoer komt alleen via x-model/x-text in de pagina, nooit als HTML.
 */

import axios from 'axios';
import { addDays, daysBetween, daysInMonth, firstOfMonth, formatDate, isoDate, isoWeekday, mondayOf, monthCells, parseDate } from './dates';

const WEEKDAYS = { 1: 'maandag', 2: 'dinsdag', 3: 'woensdag', 4: 'donderdag', 5: 'vrijdag', 6: 'zaterdag', 7: 'zondag' };
const NTH = { 1: 'eerste', 2: 'tweede', 3: 'derde', 4: 'vierde', '-1': 'laatste' };
const CODE_DAYS = { 1: 'MO', 2: 'TU', 3: 'WE', 4: 'TH', 5: 'FR', 6: 'SA', 7: 'SU' };
export const MAX_PATTERNS = 10;

/** Kleur per patroon in de kalender en de legenda (Tailwind-klassen staan voluit, voor de build). */
export const COLORS = [
    { cell: 'bg-teal-600 text-white', dot: 'bg-teal-600', border: 'border-l-teal-600', chip: 'ring-teal-300' },
    { cell: 'bg-violet-600 text-white', dot: 'bg-violet-600', border: 'border-l-violet-600', chip: 'ring-violet-300' },
    { cell: 'bg-amber-500 text-white', dot: 'bg-amber-500', border: 'border-l-amber-500', chip: 'ring-amber-300' },
    { cell: 'bg-sky-600 text-white', dot: 'bg-sky-600', border: 'border-l-sky-600', chip: 'ring-sky-300' },
    { cell: 'bg-rose-600 text-white', dot: 'bg-rose-600', border: 'border-l-rose-600', chip: 'ring-rose-300' },
];

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

/**
 * Valt een knop-patroon op deze datum? rule: {recurrence, every, weekdays, nth, weekday, dayOfMonth, anchor}.
 * De geldigheidsperiode van de taak controleert de aanroeper.
 */
export function occursOn(rule, date) {
    if (!rule.anchor) return false;
    const from = parseDate(rule.anchor);
    if (date < from) return false;

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
const shortDate = (d) => formatDate(d, { weekday: 'short', day: 'numeric', month: 'short' });
const longDate = (d) => formatDate(d, { day: 'numeric', month: 'long' });

let uid = 0;

/** Een patroon zoals de editor het bijhoudt. */
function makePattern(p = {}, defaults = {}) {
    const recurrence = p.recurrence || 'weekly';
    const mode = recurrence === 'rrule' ? 'code' : (recurrence === 'weekly' ? 'weekly' : 'monthly');
    const every = Math.max(1, Number(p.every) || 1);
    const presetMode = mode === 'monthly' ? 'monthly' : 'weekly';

    return {
        uid: ++uid,
        mode,
        lastMode: mode === 'code' ? 'weekly' : mode,
        monthlyKind: recurrence === 'monthly_day' ? 'day' : 'nth',
        every,
        custom: !PRESETS[presetMode].some((x) => x.every === every),
        weekdays: (p.weekdays || []).map(Number).sort(),
        nth: Number(p.nth) || 1,
        weekday: Number(p.weekday) || 1,
        dayOfMonth: Number(p.dayOfMonth) || 1,
        rrule: typeof p.rrule === 'string' ? p.rrule : '',
        startsOn: p.startsOn || defaults.startsOn || isoDate(new Date()),
        ownTimes: !!p.ownTimes,
        startTime: p.startTime || defaults.startTime || '18:00',
        endTime: p.endTime || defaults.endTime || '20:00',
    };
}

export default function recurrenceEditor(initial = {}) {
    const validFrom = initial.validFrom || isoDate(new Date());
    const defaults = { startsOn: validFrom, startTime: initial.startTime, endTime: initial.endTime };
    const patterns = (initial.patterns && initial.patterns.length ? initial.patterns : [{}]).map((p) => makePattern(p, defaults));

    return {
        patterns,
        validFrom,
        validUntil: initial.validUntil || '',
        skipHolidays: !!initial.skipHolidays,
        holidays: initial.holidays || {},
        taskStart: initial.startTime || '18:00',
        taskEnd: initial.endTime || '20:00',
        previewUrl: initial.previewUrl,
        previewOffset: 0,
        maxPatterns: MAX_PATTERNS,
        // Resultaat van het preview-endpoint per patroon (uid): { key, dates: Set, error, label, loading }.
        codes: {},
        timers: {},

        init() {
            this.patterns.filter((p) => p.mode === 'code').forEach((p) => this.refreshCode(p, 0));
            this.$watch('previewOffset', () => this.patterns.filter((p) => p.mode === 'code').forEach((p) => this.refreshCode(p, 0)));
            // Patronen die gewoon vanaf "Vanaf" tellen, schuiven mee als Vanaf verandert.
            this.$watch('validFrom', (value, old) => {
                this.patterns.forEach((p) => {
                    if (!p.startsOn || p.startsOn === old) {
                        p.startsOn = value;
                        if (p.mode === 'code') this.refreshCode(p, 0);
                    }
                });
            });
        },

        /** De tijden in het blok "Taak" staan buiten dit onderdeel; luister mee (op window). */
        syncTaskTimes(event) {
            if (event.target?.id === 'start_time') this.taskStart = event.target.value;
            if (event.target?.id === 'end_time') this.taskEnd = event.target.value;
        },

        // --- Patronen beheren ---

        color(i) {
            return COLORS[i % COLORS.length];
        },

        addPattern() {
            if (this.patterns.length >= MAX_PATTERNS) return;
            const last = this.patterns[this.patterns.length - 1];
            const next = last
                ? {
                    // Kopie van het laatste patroon, een week later: handig voor "de ene week dit, de andere week dat".
                    ...JSON.parse(JSON.stringify(last)),
                    uid: ++uid,
                    startsOn: isoDate(addDays(parseDate(last.startsOn || this.validFrom), 7)),
                    ownTimes: false,
                }
                : makePattern({}, defaults);
            this.patterns.push(next);
            if (next.mode === 'code') this.refreshCode(next, 0);
        },

        removePattern(index) {
            if (this.patterns.length <= 1) return;
            const [removed] = this.patterns.splice(index, 1);
            delete this.codes[removed.uid];
        },

        /** De opgeslagen soort herhaling van een patroon. */
        recurrenceOf(p) {
            if (p.mode === 'code') return 'rrule';
            if (p.mode === 'weekly') return 'weekly';
            return p.monthlyKind === 'day' ? 'monthly_day' : 'monthly_nth_weekday';
        },

        ruleOf(p) {
            return {
                recurrence: this.recurrenceOf(p),
                every: p.every,
                weekdays: p.weekdays,
                nth: p.nth,
                weekday: p.weekday,
                dayOfMonth: p.dayOfMonth,
                anchor: p.startsOn || this.validFrom,
            };
        },

        // --- Kiezen ---

        setMode(p, mode) {
            if (mode === p.mode) return;
            if (mode !== 'code') {
                if (p.mode !== 'code' || mode !== p.lastMode) {
                    p.every = 1;
                    p.custom = false;
                }
                p.lastMode = mode;
            }
            p.mode = mode;
        },

        /** "Aangepast (code)": begin met de code die hoort bij wat er nu is gekozen. */
        useCode(p) {
            if (p.mode === 'code') return;
            if (!p.rrule) p.rrule = this.codeFor(p);
            p.lastMode = p.mode;
            p.mode = 'code';
            this.refreshCode(p, 0);
        },

        codeFor(p) {
            const interval = p.every > 1 ? `;INTERVAL=${p.every}` : '';
            if (p.mode === 'weekly') {
                const days = (p.weekdays.length ? p.weekdays : [1]).map((d) => CODE_DAYS[d]).join(',');
                return `FREQ=WEEKLY${interval};BYDAY=${days}`;
            }
            if (p.monthlyKind === 'day') return `FREQ=MONTHLY${interval};BYMONTHDAY=${p.dayOfMonth}`;
            return `FREQ=MONTHLY${interval};BYDAY=${p.nth}${CODE_DAYS[p.weekday]}`;
        },

        presets(p) {
            return PRESETS[p.mode === 'monthly' ? 'monthly' : 'weekly'];
        },

        setEvery(p, n) {
            if (p.mode === 'code') p.mode = p.lastMode || 'weekly';
            p.every = n;
            p.custom = false;
        },

        setCustomEvery(p) {
            if (p.mode === 'code') p.mode = p.lastMode || 'weekly';
            p.custom = true;
        },

        unit(p) {
            if (p.mode === 'monthly') return p.every === 1 ? 'maand' : 'maanden';
            return p.every === 1 ? 'week' : 'weken';
        },

        toggleWeekday(p, n) {
            p.weekdays = p.weekdays.includes(n) ? p.weekdays.filter((d) => d !== n) : [...p.weekdays, n].sort();
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

        // --- Eigen code: voorbeeld van de server ---

        /** Periode waarvoor de kalender en "eerstvolgende keren" datums nodig hebben (max. 400 dagen). */
        codeRange() {
            const today = new Date();
            const from = parseDate(this.validFrom);
            const base = firstOfMonth(from > today ? from : today, Math.max(0, this.previewOffset - 9));
            return { from: isoDate(base), to: isoDate(addDays(base, 399)) };
        },

        refreshCode(p, delay = 400) {
            clearTimeout(this.timers[p.uid]);
            this.timers[p.uid] = setTimeout(() => this.fetchCode(p), delay);
        },

        async fetchCode(p) {
            if (p.mode !== 'code' || !this.previewUrl) return;
            const { from, to } = this.codeRange();
            const startsOn = p.startsOn || this.validFrom;
            const key = [p.rrule, startsOn, from].join('|');
            if (this.codes[p.uid]?.key === key && !this.codes[p.uid].loading) return;
            if (!p.rrule.trim()) {
                this.codes[p.uid] = { key, dates: new Set(), error: 'Vul een code in, bijvoorbeeld FREQ=WEEKLY;INTERVAL=2;BYDAY=TH.', label: '' };
                return;
            }
            this.codes[p.uid] = { ...(this.codes[p.uid] || { dates: new Set() }), key, loading: true };
            try {
                const { data } = await axios.post(this.previewUrl, { rrule: p.rrule, starts_on: startsOn, from, to });
                if (this.codes[p.uid]?.key !== key) return; // intussen weer gewijzigd
                this.codes[p.uid] = { key, dates: new Set(data.dates), error: '', label: data.label, loading: false };
            } catch (e) {
                if (this.codes[p.uid]?.key !== key) return;
                const message = e.response?.status === 422 && typeof e.response.data?.message === 'string'
                    ? e.response.data.message
                    : 'Het voorbeeld kon niet worden geladen. Probeer het zo opnieuw.';
                this.codes[p.uid] = { key, dates: new Set(), error: message, label: '', loading: false };
            }
        },

        codeState(p) {
            return this.codes[p.uid] || { dates: new Set(), error: '', label: '', loading: true };
        },

        // --- Wanneer valt wat? ---

        inPeriod(date) {
            if (!this.validFrom || date < parseDate(this.validFrom)) return false;
            return !(this.validUntil && date > parseDate(this.validUntil));
        },

        patternOn(p, date) {
            if (!this.inPeriod(date)) return false;
            if (p.mode === 'code') return this.codeState(p).dates.has(isoDate(date));
            return occursOn(this.ruleOf(p), date);
        },

        /** Feestdag waarop de taak wordt overgeslagen, of null. */
        holiday(iso) {
            return this.skipHolidays ? (this.holidays[iso] ?? null) : null;
        },

        timesOf(p) {
            return p.ownTimes ? `${p.startTime}–${p.endTime}` : `${this.taskStart}–${this.taskEnd}`;
        },

        // --- Samenvattingen ---

        frequencyLabel(p) {
            const preset = this.presets(p).find((x) => x.every === p.every);
            return preset ? preset.label : `Elke ${p.every} ${this.unit(p)}`;
        },

        summary(p) {
            if (p.mode === 'code') {
                const state = this.codeState(p);
                return state.error ? 'Eigen patroon (code nog niet geldig)' : (state.label ? `Eigen patroon: ${state.label}` : 'Eigen patroon');
            }
            if (p.mode === 'weekly') {
                if (!p.weekdays.length) return 'Kies minimaal één dag van de week.';
                const all = p.weekdays.join('');
                const days = all === '1234567' ? 'alle dagen' : (all === '12345' ? 'maandag t/m vrijdag' : sentenceList(p.weekdays.map((d) => WEEKDAYS[d])));
                return `${this.frequencyLabel(p)} op ${days}`;
            }
            if (p.monthlyKind === 'day') return `${this.frequencyLabel(p)} op de ${p.dayOfMonth}e`;
            return `${this.frequencyLabel(p)} op de ${NTH[p.nth]} ${WEEKDAYS[p.weekday]}`;
        },

        periodLabel() {
            if (!this.validFrom) return 'Kies een startdatum.';
            const long = (s) => formatDate(parseDate(s), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
            return `Vanaf ${long(this.validFrom)}` + (this.validUntil ? ` t/m ${long(this.validUntil)}` : ', tot je de taak stopt');
        },

        /**
         * Uitleg bij "om de week" en minder vaak: vanaf welke week wordt er geteld, en wat is de eerste keer?
         * Vallen alle gekozen dagen van de eerste week vóór "Telt vanaf", dan zeggen we dat expliciet,
         * met een voorstel om de week erna te gebruiken. Geeft { text, warn, fix: { label, date } | null }.
         */
        anchorHint(p) {
            if (p.mode === 'code' || !p.startsOn) return null;
            const anchor = parseDate(p.startsOn);
            if (p.mode === 'monthly') {
                if (p.every <= 1) return null;
                return { text: `Er wordt geteld vanaf ${formatDate(anchor, { month: 'long', year: 'numeric' })} (de maand van “Telt vanaf”).`, warn: false, fix: null };
            }
            if (!p.weekdays.length) return null;

            const monday = mondayOf(anchor);
            const rest = p.every === 1 ? '' : (p.every === 2 ? ', daarna steeds een week niet' : `, daarna ${p.every - 1} weken niet`);
            const firstWeek = p.weekdays.map((d) => addDays(monday, d - 1));
            const skipped = firstWeek.filter((d) => d < anchor);
            const first = this.firstOccurrence(p);
            const firstText = first ? ` De eerste keer is ${shortDate(first)}.` : '';

            if (p.every > 1 && skipped.length === firstWeek.length) {
                const alt = addDays(monday, 7 + p.weekdays[0] - 1);
                return {
                    text: `Er wordt geteld vanaf de week van ${longDate(monday)}. ${sentenceList(skipped.map(shortDate))} ${skipped.length === 1 ? 'ligt' : 'liggen'} vóór “Telt vanaf”, dus die week valt weg${rest}.${firstText}`,
                    warn: true,
                    fix: { label: `Liever ${shortDate(alt)} als eerste keer? Zet “Telt vanaf” op ${longDate(alt)}`, date: isoDate(alt) },
                };
            }
            if (p.every === 1) {
                return first ? { text: `De eerste keer is ${shortDate(first)}.`, warn: false, fix: null } : null;
            }
            const skippedText = skipped.length
                ? ` ${sentenceList(skipped.map(shortDate))} ${skipped.length === 1 ? 'ligt' : 'liggen'} vóór “Telt vanaf” en ${skipped.length === 1 ? 'wordt' : 'worden'} overgeslagen.`
                : '';
            return { text: `Er wordt geteld vanaf de week van ${longDate(monday)}: die week wel${rest}.${skippedText}${firstText}`, warn: skipped.length > 0, fix: null };
        },

        firstOccurrence(p) {
            let date = parseDate(p.startsOn || this.validFrom);
            const from = parseDate(this.validFrom);
            if (date < from) date = from;
            for (let i = 0; i < 800; i++, date = addDays(date, 1)) {
                if (this.validUntil && date > parseDate(this.validUntil)) return null;
                if (this.patternOn(p, date) && !this.holiday(isoDate(date))) return date;
            }
            return null;
        },

        /** De eerstvolgende diensten (vanaf vandaag), over alle patronen, met tijd en kleur. */
        next(count = 8) {
            if (!this.validFrom) return [];
            const today = parseDate(isoDate(new Date()));
            const from = parseDate(this.validFrom);
            const result = [];
            let date = from > today ? from : today;
            for (let i = 0; i < 800 && result.length < count; i++, date = addDays(date, 1)) {
                if (this.holiday(isoDate(date))) continue;
                const starts = new Set();
                this.patterns.forEach((p, index) => {
                    if (result.length >= count || !this.patternOn(p, date)) return;
                    const start = this.timesOf(p).split('–')[0];
                    if (starts.has(start)) return; // zelfde dag en begintijd: één dienst
                    starts.add(start);
                    const year = date.getFullYear() === today.getFullYear() ? {} : { year: 'numeric' };
                    result.push({
                        key: `${isoDate(date)}-${index}`,
                        label: `${formatDate(date, { weekday: 'short', day: 'numeric', month: 'short', ...year })} ${start}`,
                        ring: this.color(index).chip,
                    });
                });
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
                        const hits = this.patterns.map((p, i) => (this.patternOn(p, cell.date) ? i : -1)).filter((i) => i >= 0);
                        const holiday = hits.length ? this.holiday(cell.iso) : null;
                        let classes = cell.weekend ? 'text-gray-300' : 'text-gray-500';
                        let title = '';
                        if (holiday) {
                            classes = 'bg-amber-100 text-amber-800 line-through ring-1 ring-amber-300';
                            title = `${holiday}: wordt overgeslagen`;
                        } else if (hits.length) {
                            classes = `${this.color(hits[0]).cell} font-semibold` + (hits.length > 1 ? ' ring-2 ring-offset-1 ring-gray-500' : '');
                            title = hits.map((i) => `Patroon ${i + 1} (${this.timesOf(this.patterns[i])})`).join(' en ');
                        }
                        return { ...cell, title, classes: classes + (cell.iso === todayIso ? ' outline outline-1 outline-gray-400' : '') };
                    }),
                };
            });
        },
    };
}

/**
 * Proberen op de hulppagina: een code invullen en direct de eerstvolgende datums zien.
 * Rekent via hetzelfde preview-endpoint als het takenformulier.
 */
export function recurrencePlayground(initial = {}) {
    return {
        code: initial.code || 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TH',
        startsOn: isoDate(new Date()),
        previewUrl: initial.previewUrl,
        dates: [],
        label: '',
        error: '',
        loading: false,
        timer: null,
        seq: 0,

        init() {
            this.refresh(0);
        },

        use(code) {
            this.code = code;
            this.refresh(0);
            this.$refs.code?.focus();
        },

        refresh(delay = 350) {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.load(), delay);
        },

        async load() {
            const seq = ++this.seq;
            this.loading = true;
            const from = this.startsOn || isoDate(new Date());
            try {
                const { data } = await axios.post(this.previewUrl, { rrule: this.code, starts_on: from, from, to: isoDate(addDays(parseDate(from), 399)) });
                if (seq !== this.seq) return;
                this.error = '';
                this.label = data.label;
                this.dates = data.dates.slice(0, 12).map((d) => formatDate(parseDate(d), { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }));
            } catch (e) {
                if (seq !== this.seq) return;
                this.dates = [];
                this.label = '';
                this.error = e.response?.status === 422 && typeof e.response.data?.message === 'string'
                    ? e.response.data.message
                    : 'Het voorbeeld kon niet worden geladen. Probeer het zo opnieuw.';
            } finally {
                if (seq === this.seq) this.loading = false;
            }
        },
    };
}
