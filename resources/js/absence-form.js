/**
 * Afwezigheid registreren: een kalender waarin je één of meer dagen aanklikt (slepen of Shift+klik voor
 * een reeks). Elke gekozen dag wordt een regel met begin- en eindtijd, standaard de hele dag (00:00–23:59).
 */

import { addDays, firstOfMonth, formatDate as fmt, isoDate as iso, monthCells, parseDate as parse } from './dates';

export const DAY_START = '00:00';
export const DAY_END = '23:59';

/** Alle datums van from t/m to (volgorde maakt niet uit), als 'YYYY-MM-DD'. */
export function datesBetween(from, to) {
    const [a, b] = from <= to ? [from, to] : [to, from];
    const result = [];
    for (let d = parse(a); iso(d) <= b; d = addDays(d, 1)) {
        result.push(iso(d));
    }
    return result;
}

/**
 * Aaneengesloten periodes, gelijk aan AbsenceController::periods() in PHP: een dag tot 23:59 en de
 * volgende dag vanaf 00:00 horen bij elkaar.
 */
export function mergePeriods(days) {
    const periods = [];
    [...days].sort((a, b) => a.date.localeCompare(b.date)).forEach((day) => {
        const last = periods[periods.length - 1];
        const adjoins = last && last.end_time === DAY_END && day.start_time === DAY_START
            && iso(addDays(parse(last.to), 1)) === day.date;
        if (adjoins) {
            last.to = day.date;
            last.end_time = day.end_time;
        } else {
            periods.push({ from: day.date, to: day.date, start_time: day.start_time, end_time: day.end_time });
        }
    });
    return periods;
}

export default function absenceForm({ type = 'ziek', asRequest = false, days = [], unplanned = [] } = {}) {
    const initial = days
        .filter((d) => d && d.date)
        .map((d) => ({ date: d.date, start_time: d.start_time || DAY_START, end_time: d.end_time || DAY_END }))
        .sort((a, b) => a.date.localeCompare(b.date));
    const start = initial.length ? parse(initial[0].date) : new Date();

    return {
        type,
        asRequest,
        unplanned,
        days: initial,
        month: firstOfMonth(start),
        today: iso(new Date()),
        drag: null,
        last: null,
        mouseHandled: false,

        /** Ziek en calamiteit registreer je altijd direct, nooit als verzoek. */
        get canRequest() {
            return !this.unplanned.includes(this.type);
        },

        get isRequest() {
            return this.asRequest && this.canRequest;
        },

        // --- Kalender ---

        /** Twee maanden naast elkaar (op een smal scherm is alleen de eerste zichtbaar). */
        months() {
            return [0, 1].map((offset) => {
                const first = firstOfMonth(this.month, offset);
                return { key: iso(first), label: fmt(first, { month: 'long', year: 'numeric' }), cells: monthCells(first) };
            });
        },

        moveMonth(n) {
            this.month = firstOfMonth(this.month, n);
        },

        thisMonth() {
            this.month = firstOfMonth(new Date());
        },

        isSelected(date) {
            return this.days.some((d) => d.date === date);
        },

        dayClass(cell) {
            if (this.isSelected(cell.iso)) {
                return 'bg-teal-600 font-semibold text-white hover:bg-teal-700';
            }
            return (cell.weekend ? 'text-gray-400' : 'text-gray-800') + ' hover:bg-teal-50'
                + (cell.iso === this.today ? ' ring-1 ring-inset ring-teal-500' : '');
        },

        // --- Dagen kiezen ---

        setDays(days) {
            this.days = days.sort((a, b) => a.date.localeCompare(b.date));
        },

        add(dates, base = this.days) {
            const known = new Set(base.map((d) => d.date));
            return [...base, ...dates.filter((date) => !known.has(date)).map((date) => ({ date, start_time: DAY_START, end_time: DAY_END }))];
        },

        toggle(date) {
            this.setDays(this.isSelected(date) ? this.days.filter((d) => d.date !== date) : this.add([date]));
            this.last = date;
        },

        remove(date) {
            this.days = this.days.filter((d) => d.date !== date);
        },

        clear() {
            this.days = [];
            this.last = null;
        },

        /** Muis: indrukken begint een selectie; slepen over andere dagen breidt die uit (of haalt dagen weg). */
        dayDown(cell, event) {
            if (event.pointerType !== 'mouse' || event.button !== 0) return;
            this.mouseHandled = true;
            if (event.shiftKey && this.last) {
                this.setDays(this.add(datesBetween(this.last, cell.iso)));
                this.last = cell.iso;
                return;
            }
            this.drag = { from: cell.iso, adding: !this.isSelected(cell.iso), base: this.days.map((d) => ({ ...d })) };
            this.dragTo(cell.iso);
        },

        dayEnter(cell) {
            if (this.drag) this.dragTo(cell.iso);
        },

        dragTo(date) {
            const range = datesBetween(this.drag.from, date);
            this.setDays(this.drag.adding
                ? this.add(range, this.drag.base)
                : this.drag.base.filter((d) => !range.includes(d.date)));
            this.last = date;
        },

        dragEnd() {
            this.drag = null;
            // De klik die op het loslaten volgt is al verwerkt; daarna tellen klikken (toetsenbord, aanraken) weer mee.
            setTimeout(() => { this.mouseHandled = false; }, 0);
        },

        /** Toetsenbord en aanraakscherm: gewoon aan/uit; met Shift een reeks vanaf de vorige dag. */
        dayClick(cell, event) {
            if (this.mouseHandled) return;
            if (event.shiftKey && this.last) {
                this.setDays(this.add(datesBetween(this.last, cell.iso)));
                this.last = cell.iso;
                return;
            }
            this.toggle(cell.iso);
        },

        // --- Regels per dag ---

        dayLabel(date) {
            return fmt(parse(date), { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
        },

        isWholeDay(day) {
            return day.start_time === DAY_START && day.end_time === DAY_END;
        },

        invalid(day) {
            return !!day.start_time && !!day.end_time && day.end_time <= day.start_time;
        },

        wholeDay(day) {
            day.start_time = DAY_START;
            day.end_time = DAY_END;
        },

        /** Neem de tijden van de eerste dag over op alle dagen. */
        copyTimes() {
            const [first] = this.days;
            this.days.forEach((d) => {
                d.start_time = first.start_time;
                d.end_time = first.end_time;
            });
        },

        timesDiffer() {
            const [first] = this.days;
            return this.days.some((d) => d.start_time !== first.start_time || d.end_time !== first.end_time);
        },

        // --- Samenvatting ---

        periods() {
            return mergePeriods(this.days.filter((d) => d.start_time && d.end_time));
        },

        periodLabel(p) {
            const short = (date) => fmt(parse(date), { weekday: 'short', day: 'numeric', month: 'short' });
            const whole = p.start_time === DAY_START && p.end_time === DAY_END;
            if (p.from === p.to) {
                return short(p.from) + (whole ? '' : ` ${p.start_time}–${p.end_time}`);
            }
            return whole ? `${short(p.from)} t/m ${short(p.to)}` : `${short(p.from)} ${p.start_time} t/m ${short(p.to)} ${p.end_time}`;
        },

        submitLabel() {
            if (this.isRequest) return 'Verzoek indienen';
            return 'Registreren en vervangers zoeken';
        },
    };
}
