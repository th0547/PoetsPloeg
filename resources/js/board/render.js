/**
 * Tekent het roosterbord als HTML. Eén keer opbouwen en in de pagina zetten is bij 50 medewerkers
 * × 31 dagen veel sneller dan per cel een component; klikken en slepen gaan via event-delegatie
 * (zie index.js en dnd.js). Alle tekst gaat door esc().
 */
import { avatar, clock, esc, hours, icon, safeColor } from '../ui/dom';
import { absenceOn, dowShort, isoWeek, isWeekend, packLanes, parseDate, weekday } from './layout';

const LEVEL_ICON = { danger: 'alert-circle', warning: 'alert' };

/** Voornaam voor krappe plekken; een titel vooraan ("dr.", "ing.") slaan we over. */
const firstName = (name) => {
    const words = String(name ?? '').trim().split(/\s+/);
    return words.find((w) => !w.endsWith('.')) ?? words[0] ?? '';
};

/** Markering rechtsboven op een kaartje: conflict, waarschuwing, extra bezetting of notitie. */
function flags(shift, level) {
    let out = '';
    if (LEVEL_ICON[level]) out += `<span class="sc-flag sc-flag-${level}">${icon(LEVEL_ICON[level], 'icon-sm')}</span>`;
    if (shift.notes) out += `<span class="sc-flag sc-flag-note" title="Heeft een notitie">${icon('file', 'icon-sm')}</span>`;
    return out;
}

function stateClass(ctx, shift) {
    if (shift.day < ctx.today || (shift.day === ctx.today && shift.end_min <= ctx.nowMin)) return ' is-past';
    if (shift.day === ctx.today && shift.start_min <= ctx.nowMin) return ' is-now';
    return '';
}

/** Kaartje in de weekweergave per medewerker: tijd, pand, taak. */
function employeeCard(ctx, shift, employeeId) {
    const building = ctx.idx.buildings.get(shift.building_id);
    const assignment = employeeId ? shift.assignments.find((a) => a.employee_id === employeeId) : null;
    const level = assignment?.level ?? null;
    const open = !employeeId;
    const sub = open
        ? `${shift.open} van ${shift.required} open`
        : esc(shift.title);
    return `<button type="button" class="sc${open ? ' is-open' : ''}${level ? ' lvl-' + level : ''}${stateClass(ctx, shift)}"`
        + ` data-shift="${shift.id}" data-emp="${employeeId ?? ''}" style="--c:${safeColor(building?.color)}">`
        + `<span class="sc-top"><span class="sc-time">${shift.start}–${shift.end}</span>${flags(shift, level)}</span>`
        + `<span class="sc-main">${esc(building?.name)}</span>`
        + `<span class="sc-sub">${open ? sub + ' · ' + esc(shift.title) : sub}</span>`
        + `</button>`;
}

/** Kaartje in de weekweergave per pand: tijd, taak en wie er staan. */
function buildingCard(ctx, shift) {
    const building = ctx.idx.buildings.get(shift.building_id);
    const people = shift.assignments.map((a) => {
        const e = ctx.idx.employees.get(a.employee_id);
        const mark = LEVEL_ICON[a.level] ? `<span class="pp-flag pp-flag-${a.level}">${icon(LEVEL_ICON[a.level], 'icon-sm')}</span>` : '';
        return `<span class="pp">${avatar(e, 'xs')}<span class="pp-name">${esc(firstName(e?.name))}</span>${mark}</span>`;
    }).join('');
    const open = shift.open > 0 ? `<span class="pp pp-open">${shift.open} open</span>` : '';
    const extra = shift.extra > 0 ? `<span class="pp pp-extra">+${shift.extra}</span>` : '';
    return `<button type="button" class="sc sc-b${shift.open > 0 ? ' has-open' : ''}${shift.level ? ' lvl-' + shift.level : ''}${stateClass(ctx, shift)}"`
        + ` data-shift="${shift.id}" data-emp="" style="--c:${safeColor(building?.color)}">`
        + `<span class="sc-top"><span class="sc-time">${shift.start}–${shift.end}</span>${shift.notes ? flags(shift, null) : ''}</span>`
        + `<span class="sc-sub">${esc(shift.title)}</span>`
        + `<span class="sc-people">${people}${open}${extra}</span>`
        + `</button>`;
}

/** Compacte chip in de maandweergave. */
function monthChip(ctx, shift, employeeId) {
    const building = ctx.idx.buildings.get(shift.building_id);
    if (ctx.view === 'building') {
        const dots = shift.assignments.slice(0, 3).map((a) => avatar(ctx.idx.employees.get(a.employee_id), 'xs')).join('');
        const more = shift.assignments.length > 3 ? `<span class="sm-more">+${shift.assignments.length - 3}</span>` : '';
        const open = shift.open > 0 ? `<span class="sm-open">${shift.open}</span>` : '';
        return `<button type="button" class="sm sm-b${shift.open > 0 ? ' has-open' : ''}${shift.level ? ' lvl-' + shift.level : ''}${stateClass(ctx, shift)}"`
            + ` data-shift="${shift.id}" data-emp="" style="--c:${safeColor(building?.color)}">`
            + `<b>${shift.start}</b><span class="sm-av">${dots}${more}${open}</span></button>`;
    }
    const assignment = employeeId ? shift.assignments.find((a) => a.employee_id === employeeId) : null;
    const level = assignment?.level;
    return `<button type="button" class="sm${employeeId ? '' : ' is-open'}${level ? ' lvl-' + level : ''}${stateClass(ctx, shift)}"`
        + ` data-shift="${shift.id}" data-emp="${employeeId ?? ''}" style="--c:${safeColor(building?.color)}">`
        + `<b>${shift.start}</b><i>${esc(building?.short)}</i></button>`;
}

/** Afwezigheid en "kan niet" in een cel van een medewerker. */
function cellMarks(ctx, employee, day, compact, empty) {
    let cls = '';
    let html = '';
    for (const absence of ctx.idx.absences.get(employee.id) ?? []) {
        const part = absenceOn(absence, day);
        if (!part) continue;
        cls += absence.pending ? ' has-request' : ' has-absence';
        const times = part.whole ? '' : ` ${clock(part.from)}–${clock(part.to)}`;
        const label = absence.pending ? `${absence.label} (aangevraagd)` : absence.label;
        html += `<a class="ab${absence.pending ? ' ab-pending' : ''}${absence.kind === 'sickness' ? ' ab-sick' : ''}" href="${esc(absence.url)}" title="${esc(label + times)}">`
            + `${esc(compact ? absence.label.slice(0, 4) : absence.label)}${compact ? '' : esc(times)}</a>`;
    }
    const info = ctx.showAvailability ? ctx.data.unavailable?.[employee.id]?.[day] : null;
    if (info) {
        if (info.whole) cls += ' is-unavail';
        // De tekst alleen in een lege cel: daar helpt hij bij het zoeken naar wie kan invallen.
        if (!compact && empty && !html) html += `<span class="un" title="Volgens de opgegeven beschikbaarheid">${esc(info.hint)}</span>`;
    }
    return { cls, html };
}

function dayHead(ctx, day, compact) {
    const info = ctx.data.days.find((d) => d.date === day);
    const shifts = ctx.shiftsOn(day);
    const open = shifts.reduce((n, s) => n + s.open, 0);
    const date = parseDate(day);
    const cls = (day === ctx.today ? ' is-today' : '') + (isWeekend(day) ? ' is-weekend' : '') + (info?.holiday ? ' is-holiday' : '');
    const week = compact && weekday(day) === 1 ? `<span class="bg-wk" data-goto-week="${day}" title="Open week ${isoWeek(date)}">wk ${isoWeek(date)}</span>` : '';
    const meta = compact
        ? (open ? `<span class="bg-dopen" title="${open} open">${open}</span>` : '')
        : `<span class="bg-dmeta">${shifts.length ? shifts.length + (shifts.length === 1 ? ' dienst' : ' diensten') : 'geen diensten'}`
            + `${open ? ` · <b>${open} open</b>` : ''}</span>`;
    const holiday = info?.holiday ? `<span class="bg-hol" title="${esc(info.holiday)}">${esc(compact ? '★' : info.holiday)}</span>` : '';
    return `<div class="bg-dayhead${cls}" data-goto-day="${day}" title="Open ${esc(dowShort(day))} ${date.getDate()} in de dagweergave">`
        + `${week}<span class="bg-dow">${esc(dowShort(day))}</span><span class="bg-dnum">${date.getDate()}</span>${meta}${holiday}</div>`;
}

/** Linkerkolom: medewerker met uren of status, pand, of de rij met open diensten. */
function rowHead(ctx, row) {
    if (row.type === 'open') {
        return `<div class="bg-rowhead bg-rowhead-open"><span class="rh-icon">${icon('inbox')}</span>`
            + `<span class="rh-text"><span class="rh-name">Open diensten</span>`
            + `<span class="rh-sub">${row.count ? row.count + (row.count === 1 ? ' plek' : ' plekken') + ' in te vullen' : 'alles bezet'}</span></span></div>`;
    }
    if (row.type === 'building') {
        const b = row.building;
        return `<div class="bg-rowhead"><a class="rh" href="/panden/${b.id}" title="Open ${esc(b.name)}">`
            + `<span class="rh-swatch" style="background:${safeColor(b.color)}"></span>`
            + `<span class="rh-text"><span class="rh-name">${esc(b.name)}</span><span class="rh-sub">${esc(row.sub)}</span></span></a></div>`;
    }
    const e = row.employee;
    let sub;
    if (ctx.range === 'day' && row.status) {
        sub = `<span class="rh-sub"><span class="dot dot-${row.status.state}"></span><span class="rh-status">${esc(row.status.label)}</span></span>`;
    } else if (ctx.range === 'week') {
        const max = e.contract_max;
        const over = max !== null && row.hours > max;
        const under = e.contract_min !== null && row.hours < e.contract_min;
        const width = max ? Math.min(100, (row.hours / max) * 100) : 0;
        sub = `<span class="rh-sub${over ? ' is-over' : ''}"><span class="rh-hours">${hours(row.hours)}${max !== null ? ' / ' + hours(max) : ''} u</span>`
            + (max ? `<span class="meter${over ? ' is-over' : (under ? ' is-under' : '')}"><span style="width:${width}%"></span></span>` : '') + `</span>`;
    } else {
        sub = `<span class="rh-sub"><span class="rh-hours">${hours(row.hours)} u</span></span>`;
    }
    return `<div class="bg-rowhead"><button type="button" class="rh" data-open-employee="${e.id}" data-date="${ctx.date}" title="Bekijk ${esc(e.name)}">`
        + `${avatar(e)}<span class="rh-text"><span class="rh-name">${esc(e.name)}${e.active ? '' : ' <span class="rh-tag">inactief</span>'}</span>`
        + `<span class="rh-fit"></span>${sub}</span></button></div>`;
}

const rowKey = (row) => (row.type === 'open' ? 'open' : (row.type === 'building' ? 'b' + row.building.id : 'e' + row.employee.id));

function emptyState(ctx) {
    return `<div class="bg-empty">${icon('search', 'icon-lg')}<p>Geen ${ctx.view === 'building' ? 'panden' : 'medewerkers'} gevonden met deze filters.</p>`
        + `<button type="button" class="link" data-clear-filters>Filters wissen</button></div>`;
}

/**
 * Week- en maandraster (en de dagweergave op een smal scherm): rijen × dagen.
 */
export function renderGrid(ctx) {
    const compact = ctx.range === 'month';
    const days = ctx.days;
    // Lege weekenddagen in de maand smaller: meer ruimte voor de werkdagen.
    const slim = days.map((day) => compact && isWeekend(day) && !ctx.shiftsOn(day).length);
    const cols = slim.map((s) => (s ? 'minmax(1.6rem, 0.5fr)' : 'minmax(var(--col-min), 1fr)')).join(' ');
    const slimCount = slim.filter(Boolean).length;
    // Minimale breedte: daaronder scrolt het raster horizontaal en blijft de namenkolom staan.
    const minWidth = `calc(var(--head-w) + ${days.length - slimCount} * var(--col-min) + ${slimCount} * 1.6rem)`;

    let html = `<div class="bg bg-${ctx.range} p-${ctx.view}" style="grid-template-columns: var(--head-w) ${cols}; min-width: ${minWidth}">`;
    html += `<div class="bg-corner">${esc(ctx.cornerLabel)}</div>`;
    for (const day of days) html += dayHead(ctx, day, compact);

    for (const row of ctx.rows) {
        html += `<div class="bg-row${row.type === 'open' ? ' bg-row-open' : ''}" data-row="${rowKey(row)}">${rowHead(ctx, row)}`;
        for (const day of days) {
            let cls = (day === ctx.today ? ' is-today' : '') + (isWeekend(day) ? ' is-weekend' : '');
            let body = '';
            const shifts = row.shifts(day);
            if (row.type === 'employee') {
                const marks = cellMarks(ctx, row.employee, day, compact, shifts.length === 0);
                cls += marks.cls;
                body += marks.html;
            }
            for (const shift of shifts) {
                body += compact
                    ? monthChip(ctx, shift, row.type === 'employee' ? row.employee.id : null)
                    : (row.type === 'building' ? buildingCard(ctx, shift) : employeeCard(ctx, shift, row.type === 'employee' ? row.employee.id : null));
            }
            const add = row.type !== 'open' && ctx.range !== 'month' && day >= ctx.today
                ? `<a class="bg-add" href="${esc(ctx.createUrl(row, day))}" title="Losse opdracht inplannen" aria-label="Losse opdracht inplannen">${icon('plus', 'icon-sm')}</a>`
                : '';
            html += `<div class="bg-cell${cls}" data-row="${rowKey(row)}" data-day="${day}">${body}${add}</div>`;
        }
        html += `</div>`;
    }
    html += `</div>`;
    if (ctx.rows.filter((r) => r.type !== 'open').length === 0) html += emptyState(ctx);
    return html;
}

/**
 * Dagweergave: tijdlijn met balken op de echte tijden, banen bij overlap en de nu-lijn.
 */
export function renderTimeline(ctx) {
    const day = ctx.days[0];
    const [start, end] = ctx.window;
    const length = end - start;
    const pct = (min) => ((min - start) / length) * 100;
    const hoursCount = length / 60;

    let html = `<div class="tl p-${ctx.view}" style="--hours:${hoursCount}">`;
    html += `<div class="bg-corner">${esc(ctx.cornerLabel)}</div>`;
    html += `<div class="tl-axis">`;
    for (let m = start; m < end; m += 60) {
        html += `<span class="tl-hour" style="left:${pct(m)}%">${clock(m)}</span>`;
    }
    html += `</div>`;

    for (const row of ctx.rows) {
        const items = row.shifts(day).map((shift) => ({ shift, start_min: shift.start_min, end_min: shift.end_min }));
        const lanes = packLanes(items);
        let body = '';

        if (row.type === 'employee') {
            if (ctx.showAvailability) {
                for (const block of ctx.data.unavailable?.[row.employee.id]?.[day]?.blocks ?? []) {
                    const from = Math.max(block.from, start);
                    const to = Math.min(block.to, end);
                    if (to > from) {
                        body += `<span class="tl-unavail" style="left:${pct(from)}%;width:${pct(to) - pct(from)}%" title="Kan niet ${clock(block.from)}–${clock(block.to)}${block.note ? ' · ' + esc(block.note) : ''}">`
                            + `${block.note ? `<i>${esc(block.note)}</i>` : ''}</span>`;
                    }
                }
            }
            for (const absence of ctx.idx.absences.get(row.employee.id) ?? []) {
                const part = absenceOn(absence, day);
                if (!part) continue;
                const from = Math.max(part.from, start);
                const to = Math.min(part.to, end);
                if (to > from) {
                    body += `<a class="tl-absence${absence.pending ? ' is-pending' : ''}${absence.kind === 'sickness' ? ' is-sick' : ''}" href="${esc(absence.url)}"`
                        + ` style="left:${pct(from)}%;width:${pct(to) - pct(from)}%"><span>${esc(absence.label)}${absence.pending ? ' (aangevraagd)' : ''}</span></a>`;
                }
            }
        }

        for (const item of items) {
            const { shift } = item;
            const building = ctx.idx.buildings.get(shift.building_id);
            const employeeId = row.type === 'employee' ? row.employee.id : null;
            const assignment = employeeId ? shift.assignments.find((a) => a.employee_id === employeeId) : null;
            const level = row.type === 'building' ? shift.level : assignment?.level;
            const open = row.type === 'open' || (row.type === 'building' && shift.open > 0);
            const left = pct(Math.max(shift.start_min, start));
            const width = pct(Math.min(shift.end_min, end)) - left;
            const label = row.type === 'building'
                ? (shift.assignments.map((a) => firstName(ctx.idx.employees.get(a.employee_id)?.name)).join(', ') || 'niemand')
                    + (shift.open > 0 ? ` · ${shift.open} open` : '')
                : building?.name;
            body += `<button type="button" class="sc tl-bar${row.type === 'open' ? ' is-open' : ''}${row.type === 'building' && shift.open > 0 ? ' has-open' : ''}${level ? ' lvl-' + level : ''}${stateClass(ctx, shift)}"`
                + ` data-shift="${shift.id}" data-emp="${employeeId ?? ''}"`
                + ` style="--c:${safeColor(building?.color)};left:${left}%;width:${width}%;top:calc(${item.lane} * var(--lane-h) + 4px)">`
                + `<span class="rz rz-l" data-resize="start"></span>`
                + `<span class="tl-bar-body"><span class="sc-time">${shift.start}–${shift.end}</span>`
                + `<span class="sc-main">${esc(label)}</span>${open && row.type === 'open' ? `<span class="sc-sub">${shift.open} open</span>` : ''}</span>`
                + `${LEVEL_ICON[level] ? `<span class="sc-flag sc-flag-${level}">${icon(LEVEL_ICON[level], 'icon-sm')}</span>` : ''}`
                + `<span class="rz rz-r" data-resize="end"></span></button>`;
        }

        html += `<div class="bg-row${row.type === 'open' ? ' bg-row-open' : ''}" data-row="${rowKey(row)}">${rowHead(ctx, row)}`
            + `<div class="tl-track" data-row="${rowKey(row)}" data-day="${day}" style="--lanes:${lanes}">${body}</div></div>`;
    }

    if (day === ctx.today && ctx.nowMin >= start && ctx.nowMin <= end) {
        html += `<div class="tl-now" style="--now:${pct(ctx.nowMin) / 100}"><span>${clock(ctx.nowMin)}</span></div>`;
    }
    html += `</div>`;
    if (ctx.rows.filter((r) => r.type !== 'open').length === 0) html += emptyState(ctx);
    return html;
}

/**
 * Maandkalender: per dag de diensten onder elkaar, met wie er staat.
 */
export function renderCalendar(ctx) {
    const month = parseDate(ctx.date).getMonth();
    const limit = 6;
    let html = `<div class="cal">`;
    for (const day of ctx.days.slice(0, 7)) html += `<div class="cal-dow">${esc(dowShort(day))}</div>`;

    for (const day of ctx.days) {
        const date = parseDate(day);
        const info = ctx.data.days.find((d) => d.date === day);
        const shifts = ctx.shiftsOn(day);
        const cls = (date.getMonth() !== month ? ' is-out' : '') + (day === ctx.today ? ' is-today' : '') + (isWeekend(day) ? ' is-weekend' : '');
        let body = '';

        for (const absence of ctx.data.absences) {
            if (!absenceOn(absence, day)) continue;
            const e = ctx.idx.employees.get(absence.employee_id);
            if (!e || !ctx.employeeVisible(e)) continue;
            body += `<a class="cal-ab${absence.pending ? ' is-pending' : ''}${absence.kind === 'sickness' ? ' is-sick' : ''}" href="${esc(absence.url)}"`
                + ` title="${esc(e.name + ': ' + absence.label + (absence.pending ? ' (aangevraagd)' : ''))}">${esc(firstName(e.name))} · ${esc(absence.label.toLowerCase())}</a>`;
        }

        for (const shift of shifts.slice(0, limit)) {
            const building = ctx.idx.buildings.get(shift.building_id);
            const who = shift.assignments.map((a) => avatar(ctx.idx.employees.get(a.employee_id), 'xs')).join('');
            body += `<button type="button" class="cal-ev${shift.open > 0 ? ' has-open' : ''}${shift.level ? ' lvl-' + shift.level : ''}${stateClass(ctx, shift)}"`
                + ` data-shift="${shift.id}" data-emp="" style="--c:${safeColor(building?.color)}">`
                + `<b>${shift.start}</b><span class="cal-name">${esc(building?.name)}</span>`
                + `<span class="cal-who">${who}${shift.open > 0 ? `<span class="sm-open">${shift.open}</span>` : ''}</span></button>`;
        }
        if (shifts.length > limit) {
            body += `<button type="button" class="cal-more" data-goto-day="${day}">+ ${shifts.length - limit} meer</button>`;
        }

        html += `<div class="cal-day${cls}" data-day="${day}" data-row="cal">`
            + `<div class="cal-num" data-goto-day="${day}" title="Open deze dag">`
            + `${weekday(day) === 1 ? `<span class="bg-wk" data-goto-week="${day}">wk ${isoWeek(date)}</span>` : ''}`
            + `${info?.holiday ? `<span class="bg-hol">${esc(info.holiday)}</span>` : ''}<span class="cal-n">${date.getDate()}</span></div>`
            + `${body}</div>`;
    }
    return html + `</div>`;
}

/** Inhoud van de zweefkaart bij een dienst. */
export function renderHover(ctx, shift) {
    const building = ctx.idx.buildings.get(shift.building_id);
    const people = shift.assignments.map((a) => {
        const e = ctx.idx.employees.get(a.employee_id);
        const warnings = a.warnings.map((w) => `<li class="hc-w hc-w-${esc(w.level)}">${icon(w.level === 'info' ? 'info' : 'alert', 'icon-sm')}<span>${esc(w.message)}</span></li>`).join('');
        return `<li class="hc-person">${avatar(e, 'sm')}<span class="hc-name">${esc(e?.name)}</span></li>${warnings}`;
    }).join('');
    const open = shift.open > 0 ? `<li class="hc-open">${icon('plus', 'icon-sm')}${shift.open} ${shift.open === 1 ? 'plek' : 'plekken'} open</li>` : '';
    const skills = shift.skills.length ? `<p class="hc-meta">Vereist: ${esc(shift.skills.join(', '))}</p>` : '';
    return `<div class="hc-head" style="--c:${safeColor(building?.color)}"><p class="hc-building">${esc(building?.name)}</p>`
        + `<p class="hc-title">${esc(shift.title)}</p><p class="hc-time">${icon('clock', 'icon-sm')}${shift.start}–${shift.end} · ${hours(shift.hours)} u`
        + `${shift.one_off ? ' · losse opdracht' : ''}</p></div>`
        + `<ul class="hc-people">${people}${open}</ul>${skills}`
        + `<p class="hc-hint">Klik voor details · sleep om te verplaatsen</p>`;
}
