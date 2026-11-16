import axios from 'axios';
import '../../css/board.css';
import { openShift } from '../shift-panel';
import { confirmWarnings, errorMessage, toast } from '../ui/dialog';
import { clock } from '../ui/dom';
import attachDnd from './dnd';
import * as L from './layout';
import { renderCalendar, renderGrid, renderHover, renderTimeline } from './render';

const VIEWS = ['employee', 'building', 'calendar'];
const RANGES = ['day', 'week', 'month'];

function store(key, value) {
    try {
        if (value === undefined) return localStorage.getItem('poetsploeg.' + key);
        localStorage.setItem('poetsploeg.' + key, value);
    } catch (e) {
        return null;
    }
    return null;
}

const pick = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback);

function planningBoard() {
    const params = new URLSearchParams(window.location.search);

    // De gegevens van de server blijven buiten Alpine: reactief maken van honderden diensten kost
    // alleen maar tijd. Het rooster wordt getekend door draw().
    let data = null;
    let idx = null;
    let clockOffset = 0; // verschil tussen de klok van de server (Nederlandse tijd) en die van de browser
    let fit = new Map(); // geschiktheid per medewerker tijdens het slepen
    const fitCache = new Map();
    let hoverTimer = null;
    let lastLoad = 0;

    const clientMinutes = () => {
        const now = new Date();
        return now.getHours() * 60 + now.getMinutes();
    };

    return {
        view: pick(params.get('view') || store('view'), VIEWS, 'employee'),
        range: pick(params.get('range') || store('range'), RANGES, 'week'),
        date: /^\d{4}-\d{2}-\d{2}$/.test(params.get('date') ?? '') ? params.get('date') : L.isoDate(new Date()),
        q: params.get('q') || '',
        sort: pick(store('sort'), ['name', 'hours', 'now'], 'name'),
        sortChosen: store('sort') !== null,
        filters: {
            buildings: [],
            skills: [],
            withShifts: store('withShifts') === '1',
            issues: false,
            availability: store('availability') !== '0',
        },
        buildingOptions: [],
        skillOptions: [],
        summary: { shifts: 0, open: 0, conflicts: 0, warnings: 0, absent: 0, requests: 0 },
        label: '',
        loading: false,
        loaded: false,
        narrow: window.innerWidth < 640,
        filterOpen: false,
        moreOpen: false,

        init() {
            const grid = this.$refs.grid;
            attachDnd(grid, this.dnd());

            grid.addEventListener('click', (event) => this.onClick(event));
            grid.addEventListener('mouseover', (event) => this.onHover(event));
            grid.addEventListener('mouseout', (event) => {
                if (!event.relatedTarget?.closest?.('[data-shift]')) this.hideHover();
            });
            this.$refs.scroll.addEventListener('scroll', () => this.hideHover(), { passive: true });

            window.addEventListener('shifts-changed', () => this.load(true));
            window.addEventListener('resize', () => this.resize());
            window.addEventListener('keydown', (event) => this.onKey(event));
            // Elke halve minuut de nu-lijn en de status bijwerken; na lang wegblijven opnieuw laden.
            window.setInterval(() => {
                if (data && !this.$refs.grid.classList.contains('is-dragging') && document.visibilityState === 'visible') this.draw();
            }, 30000);
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && Date.now() - lastLoad > 5 * 60 * 1000) this.load(true);
            });

            this.$watch('q', () => this.draw());
            this.resize();
            this.load();
        },

        // ---------- Laden ----------

        async load(silent = false) {
            const [from, to] = L.period(this.view, this.range, this.date);
            this.label = L.periodLabel(this.view, this.range, this.date);
            this.syncUrl();
            this.loading = !silent;
            try {
                const response = await axios.get('/api/planning', { params: { from, to } });
                // Tussentijds van periode gewisseld? Dan is dit antwoord verouderd.
                const [nowFrom, nowTo] = L.period(this.view, this.range, this.date);
                if (response.data.from !== nowFrom || response.data.to !== nowTo) return;

                data = response.data;
                idx = L.indexData(data);
                clockOffset = data.now_min - clientMinutes();
                fitCache.clear();
                lastLoad = Date.now();
                this.buildingOptions = data.buildings.map(({ id, name, color }) => ({ id, name, color }));
                this.skillOptions = data.skills;
                // Kalender laadt hele weken; de samenvatting telt alleen de maand zelf.
                const month = this.date.slice(0, 7);
                this.summary = L.summarise(this.view === 'calendar' ? data.shifts.filter((s) => s.day.startsWith(month)) : data.shifts, data.absences);
                this.loaded = true;
                this.draw(true);
            } catch (e) {
                toast(errorMessage(e), { type: 'error' });
            } finally {
                this.loading = false;
            }
        },

        syncUrl() {
            const query = new URLSearchParams({ view: this.view, range: this.range, date: this.date });
            if (this.q) query.set('q', this.q);
            window.history.replaceState(null, '', '?' + query.toString());
            store('view', this.view);
            store('range', this.range);
        },

        // ---------- Tekenen ----------

        nowMin() {
            return (((clientMinutes() + clockOffset) % 1440) + 1440) % 1440;
        },

        /** Alles wat de renderer nodig heeft: zichtbare rijen, dagen en hulpfuncties. */
        context() {
            const days = L.daysBetween(data.from, data.to);
            const today = data.today;
            const nowMin = this.nowMin();
            const term = this.q.trim().toLowerCase();
            const { buildings, skills, withShifts, issues, availability } = this.filters;

            const shiftVisible = (s) => (!buildings.length || buildings.includes(s.building_id)) && (!issues || L.needsAttention(s));
            const visible = data.shifts.filter(shiftVisible);
            const perDay = new Map();
            const perEmployee = new Map();
            const perBuilding = new Map();
            const push = (map, key, day, shift) => {
                if (!map.has(key)) map.set(key, new Map());
                const byDay = map.get(key);
                if (!byDay.has(day)) byDay.set(day, []);
                byDay.get(day).push(shift);
            };
            for (const shift of visible) {
                if (!perDay.has(shift.day)) perDay.set(shift.day, []);
                perDay.get(shift.day).push(shift);
                push(perBuilding, shift.building_id, shift.day, shift);
                for (const a of shift.assignments) push(perEmployee, a.employee_id, shift.day, shift);
            }
            const shiftsOn = (day) => perDay.get(day) ?? [];
            const employeeVisible = (e) => (!term || e.name.toLowerCase().includes(term))
                && (!skills.length || skills.every((id) => e.skills.includes(id)));

            const ctx = {
                data, idx, days, today, nowMin, shiftsOn, employeeVisible,
                view: this.view,
                range: this.range,
                date: this.date,
                showAvailability: availability,
                window: this.range === 'day' ? L.timeWindow(idx.byDay.get(this.date) ?? []) : null,
                createUrl: (row, day) => `/diensten/nieuw?datum=${day}&terug=planning`
                    + (row.type === 'employee' ? `&medewerker=${row.employee.id}` : `&pand=${row.building.id}`),
            };

            if (this.view === 'building') {
                ctx.rows = data.buildings
                    .filter((b) => (!term || b.name.toLowerCase().includes(term)) && (!buildings.length || buildings.includes(b.id)))
                    .map((b) => {
                        const mine = perBuilding.get(b.id);
                        const all = mine ? [...mine.values()].flat() : [];
                        const open = all.reduce((n, s) => n + s.open, 0);
                        return {
                            type: 'building',
                            building: b,
                            count: all.length,
                            sub: all.length ? `${all.length} ${all.length === 1 ? 'dienst' : 'diensten'}${open ? ` · ${open} open` : ''}` : (b.city || 'geen diensten'),
                            shifts: (day) => mine?.get(day) ?? [],
                        };
                    })
                    .filter((row) => (!withShifts && !issues) || row.count > 0);
                ctx.cornerLabel = `Panden (${ctx.rows.length})`;
                return ctx;
            }

            const inRange = today >= data.from && today <= data.to;
            const rows = data.employees.filter(employeeVisible).map((e) => {
                const mine = perEmployee.get(e.id);
                const todays = inRange ? (idx.byDay.get(today) ?? []).filter((s) => s.assignments.some((a) => a.employee_id === e.id)) : [];
                return {
                    type: 'employee',
                    employee: e,
                    count: mine ? [...mine.values()].flat().length : 0,
                    hours: L.plannedHours(e.id, data.shifts),
                    status: inRange ? L.statusAt(todays, idx.absences.get(e.id), today, nowMin, idx.buildings) : null,
                    shifts: (day) => mine?.get(day) ?? [],
                };
            }).filter((row) => (!withShifts && !issues && !buildings.length) || row.count > 0);

            // Dagweergave van vandaag: standaard wie nu werkt bovenaan, tenzij er zelf een volgorde is gekozen.
            const sort = this.sortChosen || this.range !== 'day' || this.date !== today ? this.sort : 'now';
            const order = { working: 0, later: 1, done: 2, free: 3, absent: 4 };
            if (sort === 'hours') rows.sort((a, b) => b.hours - a.hours || a.employee.name.localeCompare(b.employee.name));
            if (sort === 'now' && inRange) {
                rows.sort((a, b) => order[a.status.state] - order[b.status.state]
                    || (a.status.shift?.start_min ?? 0) - (b.status.shift?.start_min ?? 0)
                    || a.employee.name.localeCompare(b.employee.name));
            }

            const openCount = visible.reduce((n, s) => n + s.open, 0);
            ctx.rows = [
                { type: 'open', count: openCount, shifts: (day) => shiftsOn(day).filter((s) => s.open > 0) },
                ...rows,
            ];
            ctx.cornerLabel = `Medewerkers (${rows.length})`;
            return ctx;
        },

        draw(resetScroll = false) {
            if (!data) return;
            this.hideHover();
            const ctx = this.context();
            const grid = this.$refs.grid;
            const scroll = this.$refs.scroll;
            const position = [scroll.scrollLeft, scroll.scrollTop];

            if (this.view === 'calendar') grid.innerHTML = renderCalendar(ctx);
            else if (this.range === 'day' && !this.narrow) grid.innerHTML = renderTimeline(ctx);
            else grid.innerHTML = renderGrid(ctx);

            if (resetScroll) {
                // Past vandaag (maand) of het tijdstip van nu (dag) niet in beeld, schuif er dan naartoe.
                scroll.scrollTop = 0;
                scroll.scrollLeft = 0;
                const anchor = grid.querySelector('.bg-dayhead.is-today, .tl-now');
                if (anchor && anchor.offsetLeft + anchor.offsetWidth > scroll.clientWidth - 24) {
                    const head = parseFloat(getComputedStyle(grid.firstElementChild).getPropertyValue('--head-w')) * 16 || 0;
                    scroll.scrollLeft = Math.max(0, anchor.offsetLeft - head - (scroll.clientWidth - head) / 3);
                }
            } else {
                [scroll.scrollLeft, scroll.scrollTop] = position;
            }
        },

        resize() {
            const narrow = window.innerWidth < 640;
            const scroll = this.$refs.scroll;
            scroll.style.height = Math.max(320, window.innerHeight - scroll.getBoundingClientRect().top - 12) + 'px';
            if (narrow !== this.narrow) {
                this.narrow = narrow;
                this.draw();
            }
        },

        // ---------- Navigatie en filters ----------

        setView(view) {
            this.view = view;
            this.load();
        },

        setRange(range) {
            this.range = range;
            if (this.view === 'calendar') this.view = 'employee';
            this.load();
        },

        step(dir) {
            this.date = L.stepDate(this.view, this.range, this.date, dir);
            this.load();
        },

        goToday() {
            this.date = L.isoDate(new Date());
            this.load();
        },

        gotoDay(day, range = 'day') {
            this.date = day;
            this.range = range;
            if (this.view === 'calendar') this.view = 'employee';
            this.load();
        },

        pickDate() {
            const picker = this.$refs.picker;
            if (picker.showPicker) picker.showPicker();
            else picker.focus();
        },

        toggle(list, id) {
            const at = this.filters[list].indexOf(id);
            if (at === -1) this.filters[list].push(id);
            else this.filters[list].splice(at, 1);
            this.draw();
        },

        setFilter(key, value) {
            this.filters[key] = value;
            if (key === 'withShifts') store('withShifts', value ? '1' : '0');
            if (key === 'availability') store('availability', value ? '1' : '0');
            this.draw();
        },

        setSort(sort) {
            this.sort = sort;
            this.sortChosen = true;
            store('sort', sort);
            this.draw();
        },

        filterCount() {
            const f = this.filters;
            return f.buildings.length + f.skills.length + (f.withShifts ? 1 : 0) + (f.issues ? 1 : 0);
        },

        clearFilters() {
            this.q = '';
            this.filters.buildings = [];
            this.filters.skills = [];
            this.setFilter('issues', false);
            this.setFilter('withShifts', false);
        },

        onKey(event) {
            if (event.ctrlKey || event.metaKey || event.altKey) return;
            if (event.target.closest('input, textarea, select, [contenteditable]')) return;
            if (document.querySelector('.dlg-backdrop') || [...document.querySelectorAll('.drawer, .palette-backdrop')].some((el) => el.offsetParent !== null)) return;
            const actions = {
                ArrowLeft: () => this.step(-1),
                ArrowRight: () => this.step(1),
                t: () => this.goToday(),
                d: () => this.setRange('day'),
                w: () => this.setRange('week'),
                m: () => this.setRange('month'),
                '/': () => this.$refs.search?.focus(),
            };
            const action = actions[event.key];
            if (action) {
                event.preventDefault();
                action();
            }
        },

        onClick(event) {
            const week = event.target.closest('[data-goto-week]');
            if (week) return this.gotoDay(week.dataset.gotoWeek, 'week');
            const day = event.target.closest('[data-goto-day]');
            if (day) return this.gotoDay(day.dataset.gotoDay);
            if (event.target.closest('[data-clear-filters]')) return this.clearFilters();

            const card = event.target.closest('[data-shift]');
            if (card) {
                this.hideHover();
                const shift = data.shifts.find((s) => s.id === Number(card.dataset.shift));
                openShift(Number(card.dataset.shift), !card.dataset.emp && shift?.open > 0);
            }
            return null;
        },

        // ---------- Zweefkaart ----------

        onHover(event) {
            const card = event.target.closest('[data-shift]');
            if (!card || this.$refs.grid.classList.contains('is-dragging') || window.matchMedia('(hover: none)').matches) return;
            window.clearTimeout(hoverTimer);
            hoverTimer = window.setTimeout(() => {
                const shift = data?.shifts.find((s) => s.id === Number(card.dataset.shift));
                if (!shift || !document.body.contains(card)) return;
                const hover = this.$refs.hover;
                hover.innerHTML = renderHover({ idx }, shift);
                hover.hidden = false;
                const rect = card.getBoundingClientRect();
                const box = hover.getBoundingClientRect();
                const top = rect.bottom + 6 + box.height > window.innerHeight ? rect.top - box.height - 6 : rect.bottom + 6;
                hover.style.top = Math.max(8, top) + 'px';
                hover.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - box.width - 8)) + 'px';
            }, 380);
        },

        hideHover() {
            window.clearTimeout(hoverTimer);
            if (this.$refs.hover) this.$refs.hover.hidden = true;
        },

        // ---------- Slepen ----------

        dnd() {
            const board = this;
            return {
                shift: (id) => data?.shifts.find((s) => s.id === id) ?? null,
                window: () => L.timeWindow(idx.byDay.get(board.date) ?? []),
                scroller: () => board.$refs.scroll,
                dragStart: (shift, fromEmp) => board.dragStart(shift, fromEmp),
                dragEnd: () => board.markFit(new Map()),
                canDrop: (shift, fromRow, intent) => board.canDrop(shift, fromRow, intent),
                drop: (shift, fromEmp, fromRow, intent) => board.drop(shift, fromEmp, intent),
            };
        },

        /** Bij het oppakken van een dienst: per medewerker tonen hoe geschikt hij of zij is. */
        async dragStart(shift) {
            this.hideHover();
            if (this.view !== 'employee') return;
            if (!fitCache.has(shift.id)) {
                fitCache.set(shift.id, axios.get(`/api/shifts/${shift.id}/candidates`).then(({ data: result }) => {
                    const map = new Map();
                    for (const c of result.candidates) {
                        const warning = c.warnings.find((w) => w.level !== 'info');
                        map.set(c.id, { level: c.fit === 'caution' ? 'caution' : 'good', note: c.fit === 'caution' ? warning?.message : (c.fit === 'best' ? 'beste keuze' : 'geschikt') });
                    }
                    for (const c of result.excluded) {
                        map.set(c.id, { level: 'bad', note: c.warnings.find((w) => w.level === 'danger')?.message ?? 'kan niet' });
                    }
                    return map;
                }).catch(() => new Map()));
            }
            const map = await fitCache.get(shift.id);
            if (this.$refs.grid.classList.contains('is-dragging')) this.markFit(map);
        },

        markFit(map) {
            fit = map;
            const grid = this.$refs.grid;
            grid.querySelectorAll('.fit-good, .fit-caution, .fit-bad').forEach((row) => {
                row.classList.remove('fit-good', 'fit-caution', 'fit-bad');
                const note = row.querySelector('.rh-fit');
                if (note) note.textContent = '';
            });
            for (const [id, info] of map) {
                const row = grid.querySelector(`.bg-row[data-row="e${id}"]`);
                if (!row) continue;
                row.classList.add('fit-' + info.level);
                const note = row.querySelector('.rh-fit');
                if (note) note.textContent = info.note ?? '';
            }
        },

        canDrop(shift, fromRow, intent) {
            const sameRow = intent.toRow === fromRow;
            const timeChanged = intent.start !== undefined;
            const parts = [];
            if (intent.dayDelta) parts.push(L.dayShort(L.addDaysIso(shift.day, intent.dayDelta)));
            if (timeChanged) parts.push(`${clock(intent.start)}–${clock(intent.end)}`);

            if (this.view !== 'employee') {
                // Per pand en in de kalender verplaats je alleen in de tijd.
                if (!sameRow) return { ok: false, label: 'Een dienst kan niet naar een ander pand' };
                return { ok: true, changed: !!intent.dayDelta || timeChanged, label: parts.join(' · ') };
            }

            if (!sameRow) {
                if (intent.toRow === 'open') {
                    parts.unshift('Van dienst halen');
                } else {
                    const id = Number(intent.toRow.slice(1));
                    const employee = idx.employees.get(id);
                    if (shift.assignments.some((a) => a.employee_id === id)) return { ok: false, label: `${employee?.name} staat al op deze dienst` };
                    if (fromRow === 'open' && shift.open === 0) return { ok: false, label: 'Deze dienst is al bezet' };
                    const info = fit.get(id);
                    parts.unshift(employee?.name + (info?.note && info.level !== 'good' ? ` (${info.note})` : ''));
                }
            }
            return { ok: true, changed: !sameRow || !!intent.dayDelta || timeChanged, label: parts.join(' · ') };
        },

        async drop(shift, fromEmp, intent, confirm = false) {
            const toEmp = this.view === 'employee' && intent.toRow !== 'open' ? Number(intent.toRow.slice(1)) : null;
            const employeeChanged = this.view === 'employee' && toEmp !== fromEmp;
            const timeChanged = intent.start !== undefined;
            const payload = {
                shift_id: shift.id,
                from_employee_id: this.view === 'employee' ? fromEmp : null,
                to_employee_id: this.view === 'employee' ? toEmp : null,
                shift_days: intent.dayDelta || 0,
                confirm,
            };
            if (timeChanged) {
                payload.start_time = clock(intent.start);
                payload.end_time = clock(intent.end);
            }

            try {
                const { data: result } = await axios.post('/api/planning/move', payload);
                if (result.needs_confirmation) {
                    if (await confirmWarnings(result.warnings, 'Deze wijziging geeft waarschuwingen.')) {
                        return this.drop(shift, fromEmp, intent, true);
                    }
                    return this.draw();
                }

                const building = idx.buildings.get(shift.building_id)?.name ?? 'Dienst';
                const what = [];
                if (employeeChanged) what.push(toEmp ? `naar ${idx.employees.get(toEmp)?.name}` : 'naar Open diensten');
                if (intent.dayDelta) what.push(`naar ${L.dayShort(L.addDaysIso(shift.day, intent.dayDelta))}`);
                if (timeChanged) what.push(`${clock(intent.start)}–${clock(intent.end)}`);
                toast(`${building} ${shift.start}–${shift.end}: ${what.join(', ')}.`, {
                    action: {
                        label: 'Ongedaan maken',
                        run: () => this.undo({
                            shift_id: shift.id,
                            from_employee_id: this.view === 'employee' ? toEmp : null,
                            to_employee_id: this.view === 'employee' ? fromEmp : null,
                            shift_days: -(intent.dayDelta || 0),
                            ...(timeChanged ? { start_time: shift.start, end_time: shift.end } : {}),
                            confirm: true,
                        }),
                    },
                });
                await this.load(true);
            } catch (e) {
                toast(errorMessage(e), { type: 'error' });
                this.draw();
            }
            return null;
        },

        async undo(payload) {
            try {
                await axios.post('/api/planning/move', payload);
                toast('Wijziging teruggedraaid.');
            } catch (e) {
                toast(errorMessage(e), { type: 'error' });
            }
            await this.load(true);
        },
    };
}

window.Alpine.data('planningBoard', planningBoard);
