/**
 * Rekenwerk voor het roosterbord, los van de pagina (en dus los te testen met `node --test`).
 * Datums gaan als "2026-11-16", tijden als minuten sinds middernacht in Nederlandse tijd: de server
 * rekent ze uit, zodat de tijdzone van de browser er niet toe doet.
 */

export const pad = (n) => String(n).padStart(2, '0');
export const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const parseDate = (s) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
};
export const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
export const addDaysIso = (s, n) => isoDate(addDays(parseDate(s), n));
export const startOfWeek = (d) => addDays(d, -((d.getDay() + 6) % 7));

export const isoWeek = (d) => {
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    t.setUTCDate(t.getUTCDate() + 4 - (t.getUTCDay() || 7));
    const yearStart = new Date(Date.UTC(t.getUTCFullYear(), 0, 1));
    return Math.ceil(((t - yearStart) / 86400000 + 1) / 7);
};

/** Aantal kalenderdagen van a naar b ("2026-11-16" → "2026-11-18" = 2), ongevoelig voor zomertijd. */
export const dayDiff = (a, b) => {
    const utc = (s) => {
        const [y, m, d] = s.split('-').map(Number);
        return Date.UTC(y, m - 1, d);
    };
    return Math.round((utc(b) - utc(a)) / 86400000);
};

/** Weekdag 1 (ma) t/m 7 (zo). */
export const weekday = (s) => ((parseDate(s).getDay() + 6) % 7) + 1;
export const isWeekend = (s) => weekday(s) >= 6;

const fmt = (d, opts) => new Intl.DateTimeFormat('nl-NL', opts).format(d);
export const dowShort = (s) => fmt(parseDate(s), { weekday: 'short' }).replace('.', '');
export const dayLong = (s) => fmt(parseDate(s), { weekday: 'long', day: 'numeric', month: 'long' });
export const dayShort = (s) => fmt(parseDate(s), { weekday: 'short', day: 'numeric', month: 'short' }).replace(/\./g, '');

/**
 * De periode die geladen wordt: [van, tot] (inclusief).
 * De kalender toont hele weken: van de maandag vóór de 1e tot de zondag na de laatste.
 */
export function period(view, range, dateStr) {
    const d = parseDate(dateStr);
    if (view === 'calendar') {
        const first = startOfWeek(new Date(d.getFullYear(), d.getMonth(), 1));
        const last = addDays(startOfWeek(new Date(d.getFullYear(), d.getMonth() + 1, 0)), 6);
        return [isoDate(first), isoDate(last)];
    }
    if (range === 'day') return [dateStr, dateStr];
    if (range === 'week') {
        const s = startOfWeek(d);
        return [isoDate(s), isoDate(addDays(s, 6))];
    }
    return [isoDate(new Date(d.getFullYear(), d.getMonth(), 1)), isoDate(new Date(d.getFullYear(), d.getMonth() + 1, 0))];
}

export function periodLabel(view, range, dateStr) {
    const d = parseDate(dateStr);
    if (view === 'calendar' || range === 'month') {
        return fmt(d, { month: 'long', year: 'numeric' });
    }
    if (range === 'day') {
        return fmt(d, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
    const s = startOfWeek(d);
    const e = addDays(s, 6);
    const sameMonth = s.getMonth() === e.getMonth();
    const from = fmt(s, sameMonth ? { day: 'numeric' } : { day: 'numeric', month: 'short' }).replace('.', '');
    return `Week ${isoWeek(s)} · ${from} – ${fmt(e, { day: 'numeric', month: 'short', year: 'numeric' }).replace('.', '')}`;
}

/** De datum een periode vooruit of terug. */
export function stepDate(view, range, dateStr, dir) {
    const d = parseDate(dateStr);
    if (view === 'calendar' || range === 'month') return isoDate(new Date(d.getFullYear(), d.getMonth() + dir, 1));
    return isoDate(addDays(d, (range === 'day' ? 1 : 7) * dir));
}

/** Alle dagen van a t/m b. */
export function daysBetween(a, b) {
    const out = [];
    for (let d = a; d <= b; d = addDaysIso(d, 1)) out.push(d);
    return out;
}

/** Opzoektabellen bij de gegevens van de server. */
export function indexData(data) {
    const employees = new Map(data.employees.map((e) => [e.id, e]));
    const buildings = new Map(data.buildings.map((b) => [b.id, b]));
    const byDay = new Map();
    for (const shift of data.shifts) {
        if (!byDay.has(shift.day)) byDay.set(shift.day, []);
        byDay.get(shift.day).push(shift);
    }
    const absences = new Map();
    for (const absence of data.absences) {
        if (!absences.has(absence.employee_id)) absences.set(absence.employee_id, []);
        absences.get(absence.employee_id).push(absence);
    }
    return { employees, buildings, byDay, absences };
}

/** Welk deel van de dag (in minuten) dekt deze afwezigheid? null = niet op deze dag. */
export function absenceOn(absence, day) {
    if (day < absence.from_day || day > absence.to_day) return null;
    const from = day === absence.from_day ? absence.from_min : 0;
    const to = day === absence.to_day ? absence.to_min : 1440;
    return to > from ? { from, to, whole: from <= 0 && to >= 1439 } : null;
}

/** Geplande uren van een medewerker in de geladen periode. */
export function plannedHours(employeeId, shifts) {
    let total = 0;
    for (const shift of shifts) {
        if (shift.assignments.some((a) => a.employee_id === employeeId)) total += shift.hours;
    }
    return Math.round(total * 100) / 100;
}

/**
 * Tijdvenster van de dagweergave: van een uur voor de eerste tot een uur na de laatste dienst,
 * op hele uren, minimaal 8 uur breed. Zonder diensten: 06:00–20:00.
 */
export function timeWindow(shifts) {
    if (!shifts.length) return [360, 1200];
    let start = Math.min(...shifts.map((s) => s.start_min));
    let end = Math.max(...shifts.map((s) => s.end_min));
    start = Math.max(0, Math.floor((start - 30) / 60) * 60);
    end = Math.min(1440 + 360, Math.ceil((end + 30) / 60) * 60);
    while (end - start < 480) {
        if (start > 0) start -= 60;
        if (end - start < 480 && end < 1440) end += 60;
        if (start === 0 && end >= 1440) break;
    }
    return [start, end];
}

/**
 * Balken die elkaar overlappen krijgen elk een eigen baan. Zet `lane` op elk item en geeft het
 * aantal banen terug.
 */
export function packLanes(items) {
    const lanes = [];
    for (const item of [...items].sort((a, b) => a.start_min - b.start_min || a.end_min - b.end_min)) {
        let lane = lanes.findIndex((end) => end <= item.start_min);
        if (lane === -1) {
            lane = lanes.length;
            lanes.push(0);
        }
        lanes[lane] = item.end_min;
        item.lane = lane;
    }
    return Math.max(1, lanes.length);
}

/** Dichtstbijzijnde kwartier. */
export const snap15 = (minutes) => Math.round(minutes / 15) * 15;

/**
 * Waar is een medewerker nu? Zelfde indeling als App\Support\NowStatus.
 *
 * @param todays  diensten van vandaag waarop de medewerker staat
 * @param absences  afwezigheid van de medewerker
 */
export function statusAt(todays, absences, today, nowMin, buildings) {
    for (const absence of absences ?? []) {
        if (absence.pending) continue;
        const part = absenceOn(absence, today);
        if (part && part.from <= nowMin && part.to > nowMin) {
            return { state: 'absent', label: absence.label };
        }
    }
    const sorted = [...todays].sort((a, b) => a.start_min - b.start_min);
    const name = (shift) => buildings.get(shift.building_id)?.name ?? '';
    const current = sorted.find((s) => s.start_min <= nowMin && s.end_min > nowMin);
    if (current) return { state: 'working', label: `Nu bij ${name(current)} tot ${current.end}`, shift: current };
    const next = sorted.find((s) => s.start_min > nowMin);
    if (next) return { state: 'later', label: `Straks ${next.start} ${name(next)}`, shift: next };
    if (sorted.length) return { state: 'done', label: 'Klaar voor vandaag' };
    return { state: 'free', label: 'Vandaag vrij' };
}

/** Samenvatting van de periode voor de balk boven het rooster. */
export function summarise(shifts, absences) {
    let open = 0;
    let conflicts = 0;
    let warnings = 0;
    for (const shift of shifts) {
        open += shift.open;
        for (const a of shift.assignments) {
            if (a.level === 'danger') conflicts++;
            else if (a.level === 'warning') warnings++;
        }
    }
    const absent = new Set(absences.filter((a) => !a.pending).map((a) => a.employee_id)).size;
    const requests = absences.filter((a) => a.pending).length;
    return { shifts: shifts.length, open, conflicts, warnings, absent, requests };
}

/** Heeft deze dienst iets dat aandacht vraagt (open plek, waarschuwing of conflict)? */
export const needsAttention = (shift) => shift.open > 0 || shift.level === 'danger' || shift.level === 'warning';
