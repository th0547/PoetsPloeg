/**
 * Proef "nieuwe invoer" voor beschikbaarheid: een weekrooster waarin je sleept, in twee standen.
 * - Elke week: je bewerkt het weekpatroon. De regels met dagknopjes eronder zijn hetzelfde patroon.
 * - Alleen deze week: wat je sleept wordt een afwijkende dag voor die datum.
 * Het rooster werkt op dezelfde gegevens (patterns en dayRules) als de gewone invoer, dus opslaan en
 * terugschakelen werkt zonder omzetten. Tijdvakken met een herhaling of periode blijven regels.
 */

import { isoDate, minutes, mondayOf, pad, parseDate } from './dates';

const SLOT = 30; // minuten per vakje
const ROWS = 1440 / SLOT;
const STORAGE_KEY = 'poetsploeg.beschikbaarheid.nieuw';

const hhmm = (m) => `${pad(Math.floor(m / 60) % 24)}:${pad(m % 60)}`;
const same = (a, b) => (a?.t ?? null) === (b?.t ?? null) && (a?.n ?? '') === (b?.n ?? '');

/** Tijdvakken naar vakjes: null of { t: type, n: opmerking }. "Kan niet" gaat vóór "kan wel". */
export function rasterize(windows) {
    const cells = Array(ROWS).fill(null);
    ['available', 'unavailable'].forEach((type) => {
        windows.filter((w) => w.type === type && w.start_time && w.end_time).forEach((w) => {
            const s = minutes(w.start_time);
            let e = minutes(w.end_time);
            if (e <= s) e = 1440;
            const from = Math.min(ROWS - 1, Math.round(s / SLOT));
            const to = Math.min(ROWS, Math.max(from + 1, Math.round(e / SLOT)));
            for (let r = from; r < to; r++) cells[r] = { t: type, n: w.note || '' };
        });
    });
    return cells;
}

/** Vakjes naar aaneengesloten blokken: { type, note, s, e } in rijen. */
export function runs(cells) {
    const out = [];
    let r = 0;
    while (r < ROWS) {
        if (!cells[r]) { r++; continue; }
        let e = r;
        while (e < ROWS && same(cells[e], cells[r])) e++;
        out.push({ type: cells[r].t, note: cells[r].n, s: r, e });
        r = e;
    }
    return out;
}

const times = (run) => ({ start_time: hhmm(run.s * SLOT), end_time: hhmm(run.e * SLOT) });

export default function gridEditor({ occursOn, dayWindows, contract }) {
    let stored = false;
    try { stored = localStorage.getItem(STORAGE_KEY) === '1'; } catch { /* geen opslag: gewone invoer */ }

    return {
        newMode: stored,
        scope: 'p', // p = elke week (patroon), w = alleen deze week
        brush: 'available',
        showNight: false,
        drag: null,
        touch: null,
        contract: contract === null || contract === '' ? null : Number(contract),

        toggleNew() {
            this.newMode = !this.newMode;
            try { localStorage.setItem(STORAGE_KEY, this.newMode ? '1' : '0'); } catch { /* niet erg */ }
        },

        /** Tijdvak dat niet in het rooster past: herhaling, periode of over middernacht. Blijft een regel. */
        isComplex(p) {
            if (p.repeat !== 'w1' || p.valid_from || p.valid_until || p.showPeriod) return true;
            return !!p.start_time && !!p.end_time && p.end_time !== '00:00' && minutes(p.end_time) <= minutes(p.start_time);
        },

        addComplex() {
            this.patterns.push({ type: 'unavailable', repeat: 'w1', weekday: 1, starts_on: null, valid_from: null, valid_until: null, start_time: '08:00', end_time: '15:00', note: '', showPeriod: true });
        },

        hasComplex() {
            return this.patterns.some((p) => this.isComplex(p));
        },

        // --- Vakjes lezen en schrijven ---

        patternCells(weekday) {
            return rasterize(this.patterns.filter((p) => !this.isComplex(p) && Number(p.weekday) === weekday));
        },

        writePatternCells(weekday, cells) {
            this.patterns = this.patterns
                .filter((p) => this.isComplex(p) || Number(p.weekday) !== weekday)
                .concat(runs(cells).map((run) => ({ type: run.type, repeat: 'w1', weekday, starts_on: null, valid_from: null, valid_until: null, ...times(run), note: run.note, showPeriod: false })));
        },

        dateCells(day) {
            const rule = this.dayRule(day);
            return rasterize(rule ? dayWindows(rule) : this.patterns.filter((p) => occursOn(p, day)));
        },

        writeDateCells(day, cells) {
            const date = isoDate(day);
            const list = runs(cells);
            const rest = this.dayRules.filter((d) => d.date !== date);
            let rule;
            if (!list.length) {
                // Alles gewist: geen voorkeur, dus de hele dag beschikbaar.
                rule = { date, mode: 'available_all', note: '', past: false, slots: [] };
            } else if (list.length === 1 && list[0].s === 0 && list[0].e === ROWS) {
                rule = { date, mode: list[0].type === 'unavailable' ? 'unavailable_all' : 'available_all', note: list[0].note, past: false, slots: [] };
            } else {
                rule = { date, mode: 'slots', note: '', past: false, slots: list.map((run) => ({ type: run.type, ...times(run), note: run.note })) };
            }
            this.dayRules = rest.concat([rule]);
        },

        /** Het rooster: per rij (half uur) zeven vakjes. */
        grid() {
            const days = this.days();
            const cols = days.map((day, d) => (this.scope === 'p' ? this.patternCells(d + 1) : this.dateCells(day)));
            return Array.from({ length: ROWS }, (_, r) => cols.map((c) => c[r]));
        },

        /** Wat geldt er in deze kolom buiten de gekleurde vakjes? */
        restCol(d) {
            if (this.scope === 'w') return this.restOn(this.days()[d]);
            const simple = this.patterns.filter((p) => !this.isComplex(p));
            if (!simple.length) return 'none';
            return simple.some((p) => p.type === 'available') ? 'unavailable' : 'available';
        },

        cellClass(c, d) {
            const past = this.scope === 'w' && this.isPast(this.days()[d]) ? ' opacity-50' : '';
            if (c?.t === 'available') return 'bg-teal-400' + past;
            if (c?.t === 'unavailable') return 'avail-blocked' + past;
            const rest = this.restCol(d);
            return (rest === 'unavailable' ? 'bg-gray-200' : rest === 'available' ? 'bg-teal-50' : 'bg-gray-100') + past;
        },

        firstRow() {
            if (this.showNight) return 0;
            const night = 6 * 60 / SLOT;
            const all = [...this.patterns, ...this.dayRules.flatMap((d) => (d.mode === 'slots' ? d.slots : []))];
            return all.some((w) => w.start_time && w.end_time && w.start_time !== w.end_time && minutes(w.start_time) < 6 * 60) ? 0 : night;
        },

        rowLabel(r) {
            return r % 2 === 0 ? hhmm(r * SLOT) : '';
        },

        gridDayLabel(day, d) {
            const name = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'][d];
            return this.scope === 'w' ? `${name} ${day.getDate()}` : name;
        },

        gridTotal() {
            let cells = 0;
            const grid = this.grid();
            for (let d = 0; d < 7; d++) {
                const rest = this.restCol(d);
                for (let r = 0; r < ROWS; r++) {
                    const t = grid[r][d]?.t;
                    if (t === 'available' || (!t && rest !== 'unavailable')) cells++;
                }
            }
            return cells * SLOT / 60;
        },

        gridTotalText() {
            const n = (v) => String(Math.round(v * 10) / 10).replace('.', ',');
            const hours = this.gridTotal();
            return `${this.scope === 'w' ? 'Deze week' : 'Normale week'}: ${n(hours)} uur beschikbaar`
                + (this.contract !== null ? ` · contract ${n(this.contract)} uur` : '');
        },

        belowContract() {
            return this.contract !== null && this.gridTotal() < this.contract;
        },

        // --- Slepen: een rechthoek van het beginvakje tot het vakje onder de aanwijzer ---

        cellAt(x, y) {
            const el = document.elementFromPoint(x, y);
            if (!el || el.dataset.r === undefined || !el.closest('[data-avgrid]')) return null;
            return [Number(el.dataset.r), Number(el.dataset.d)];
        },

        startPaint(r, d) {
            this.drag = { r, d, last: '', snapshot: JSON.stringify({ patterns: this.patterns, dayRules: this.dayRules }) };
            this.movePaint(r, d);
        },

        movePaint(r, d) {
            if (!this.drag || this.drag.last === `${r}-${d}`) return;
            this.drag.last = `${r}-${d}`;
            const snapshot = JSON.parse(this.drag.snapshot);
            this.patterns = snapshot.patterns;
            this.dayRules = snapshot.dayRules;
            const days = this.days();
            const [r0, r1] = [Math.min(r, this.drag.r), Math.max(r, this.drag.r)];
            for (let col = Math.min(d, this.drag.d); col <= Math.max(d, this.drag.d); col++) {
                if (this.scope === 'w' && this.isPast(days[col])) continue;
                const cells = this.scope === 'p' ? this.patternCells(col + 1) : this.dateCells(days[col]);
                for (let row = r0; row <= r1; row++) {
                    if (this.brush === 'erase') cells[row] = null;
                    else if (cells[row]?.t !== this.brush) cells[row] = { t: this.brush, n: '' };
                }
                if (this.scope === 'p') this.writePatternCells(col + 1, cells);
                else this.writeDateCells(days[col], cells);
            }
        },

        endPaint() {
            this.drag = null;
        },

        gridDown(event) {
            if (event.pointerType === 'touch') return;
            const cell = this.cellAt(event.clientX, event.clientY);
            if (!cell) return;
            event.preventDefault();
            this.startPaint(...cell);
        },

        gridMove(event) {
            if (!this.drag || event.pointerType === 'touch') return;
            const cell = this.cellAt(event.clientX, event.clientY);
            if (cell) this.movePaint(...cell);
        },

        // Aanraken: tik = één vakje; even vasthouden en dan slepen = meerdere. Zo blijft scrollen werken.
        touchStart(event) {
            const t = event.touches[0];
            const cell = this.cellAt(t.clientX, t.clientY);
            if (!cell) return;
            const touch = { x: t.clientX, y: t.clientY, cell, active: false, timer: null };
            touch.timer = setTimeout(() => {
                touch.active = true;
                navigator.vibrate?.(10);
                this.startPaint(...cell);
            }, 300);
            this.touch = touch;
        },

        touchMove(event) {
            const touch = this.touch;
            if (!touch) return;
            const t = event.touches[0];
            if (touch.active) {
                event.preventDefault();
                const cell = this.cellAt(t.clientX, t.clientY);
                if (cell) this.movePaint(...cell);
            } else if (Math.abs(t.clientX - touch.x) > 8 || Math.abs(t.clientY - touch.y) > 8) {
                clearTimeout(touch.timer); // de gebruiker scrolt
                this.touch = null;
            }
        },

        touchEnd(cancelled = false) {
            const touch = this.touch;
            if (!touch) return;
            clearTimeout(touch.timer);
            if (!touch.active && !cancelled) this.startPaint(...touch.cell);
            this.endPaint();
            this.touch = null;
        },

        /** Tik op een dag (stand "alleen deze week"): hele dag niet, of terug naar het patroon. */
        toggleDayOff(day) {
            if (this.scope !== 'w' || this.isPast(day)) return;
            const date = isoDate(day);
            if (this.dayRule(day)) this.resetDay(date);
            else this.dayRules.push({ date, mode: 'unavailable_all', note: '', past: false, slots: [] });
        },

        goToDay(date) {
            this.weekStart = mondayOf(parseDate(date));
            this.scope = 'w';
        },

        daySummary(rule) {
            const note = rule.note ? ` · ${rule.note}` : '';
            if (rule.mode === 'unavailable_all') return `hele dag niet${note}`;
            if (rule.mode === 'available_all') return `hele dag wel${note}`;
            return rule.slots.map((s) => `${s.type === 'unavailable' ? 'niet' : 'wel'} ${s.start_time}–${s.end_time === '00:00' ? '24:00' : s.end_time}${s.note ? ' (' + s.note + ')' : ''}`).join(', ');
        },

        // --- Het weekpatroon als regels: gelijke blokken op meerdere dagen zijn één regel ---

        patternRules() {
            const map = new Map();
            for (let weekday = 1; weekday <= 7; weekday++) {
                runs(this.patternCells(weekday)).forEach((run) => {
                    const key = `${run.type}|${run.s}|${run.e}|${run.note}`;
                    if (!map.has(key)) map.set(key, { key, ...run, days: [] });
                    map.get(key).days.push(weekday);
                });
            }
            return [...map.values()].sort((a, b) => a.s - b.s || a.e - b.e || a.type.localeCompare(b.type));
        },

        timeOptions() {
            return Array.from({ length: ROWS + 1 }, (_, r) => ({ value: r, label: r === ROWS ? '24:00' : hhmm(r * SLOT) }));
        },

        setRun(weekday, s, e, type, note) {
            const cells = this.patternCells(weekday);
            for (let r = s; r < e; r++) cells[r] = { t: type, n: note };
            this.writePatternCells(weekday, cells);
        },

        clearRun(weekday, rule) {
            const cells = this.patternCells(weekday);
            for (let r = rule.s; r < rule.e; r++) if (cells[r]?.t === rule.type) cells[r] = null;
            this.writePatternCells(weekday, cells);
        },

        toggleRuleDay(rule, weekday) {
            if (rule.days.includes(weekday)) this.clearRun(weekday, rule);
            else this.setRun(weekday, rule.s, rule.e, rule.type, rule.note);
        },

        setRuleTimes(rule, s, e) {
            if (e <= s) return;
            rule.days.forEach((weekday) => this.clearRun(weekday, rule));
            rule.days.forEach((weekday) => this.setRun(weekday, s, e, rule.type, rule.note));
        },

        setRuleNote(rule, note) {
            rule.days.forEach((weekday) => this.setRun(weekday, rule.s, rule.e, rule.type, note.trim()));
        },

        removeRule(rule) {
            rule.days.forEach((weekday) => this.clearRun(weekday, rule));
        },

        addRule(type) {
            const [s, e] = type === 'unavailable' ? [16, 30] : [34, 44]; // 08:00–15:00 of 17:00–22:00
            let weekday = 1;
            for (let wd = 1; wd <= 7; wd++) {
                if (this.patternCells(wd).slice(s, e).every((c) => !c)) { weekday = wd; break; }
            }
            this.setRun(weekday, s, e, type, '');
        },
    };
}
