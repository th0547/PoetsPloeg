import axios from 'axios';
import moment from 'moment';
import 'moment/dist/locale/nl';
import { DataSet } from 'vis-data/peer';
import { Timeline } from 'vis-timeline/peer';
import 'vis-timeline/styles/vis-timeline-graph2d.css';
import '../css/planning.css';
import { confirmWarnings, errorMessage, openShift } from './shift-panel';

moment.locale('nl');

const pad = (n) => String(n).padStart(2, '0');
const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseDate = (s) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
};
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const startOfWeek = (d) => addDays(d, -((d.getDay() + 6) % 7));
const isoWeek = (d) => {
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    t.setUTCDate(t.getUTCDate() + 4 - (t.getUTCDay() || 7));
    const yearStart = new Date(Date.UTC(t.getUTCFullYear(), 0, 1));
    return Math.ceil(((t - yearStart) / 86400000 + 1) / 7);
};
const fmt = (d, opts) => new Intl.DateTimeFormat('nl-NL', opts).format(d);

function store(key, value) {
    try {
        if (value === undefined) return localStorage.getItem('poetsploeg.' + key);
        localStorage.setItem('poetsploeg.' + key, value);
    } catch (e) {
        return null;
    }
}

function planningBoard() {
    const params = new URLSearchParams(window.location.search);

    return {
        view: params.get('view') || store('view') || 'employee',
        range: params.get('range') || store('range') || 'week',
        date: params.get('date') || isoDate(new Date()),
        loading: false,
        label: '',
        timeline: null,
        items: new DataSet(),
        groups: new DataSet(),

        init() {
            this.timeline = new Timeline(this.$refs.timeline, this.items, this.groups, {
                locale: 'nl',
                moment: (date) => moment(date).locale('nl'),
                orientation: { axis: 'top', item: 'top' },
                stack: true,
                verticalScroll: true,
                maxHeight: 'calc(100vh - 230px)',
                minHeight: '300px',
                zoomKey: 'ctrlKey',
                horizontalScroll: true,
                groupHeightMode: 'auto',
                margin: { item: { horizontal: 0, vertical: 4 } },
                snap: (date) => {
                    if (this.range !== 'day') {
                        // Week/maand: diensten staan als dagblok; verslepen gaat per hele dag.
                        const d = new Date(date);
                        const midnight = new Date(d.getFullYear(), d.getMonth(), d.getDate());
                        return d - midnight >= 12 * 3600 * 1000 ? addDays(midnight, 1) : midnight;
                    }
                    const step = 15 * 60 * 1000;
                    return Math.round(date / step) * step;
                },
                itemsAlwaysDraggable: { item: true, range: false },
                editable: { add: false, remove: false, updateTime: true, updateGroup: true, overrideItems: false },
                groupOrder: 'order',
                tooltip: { followMouse: true, overflowMethod: 'cap' },
                xss: { disabled: true }, // inhoud wordt server-side ge-escaped
                onMove: (item, callback) => this.onMove(item, callback),
            });

            this.timeline.on('click', (props) => {
                if (props.what !== 'item' || !props.item) return;
                const item = this.items.get(props.item);
                if (item && item.shiftId) openShift(item.shiftId);
            });

            window.addEventListener('shifts-changed', () => this.load());
            this.load();
        },

        period() {
            const d = parseDate(this.date);
            if (this.range === 'day') return [d, d];
            if (this.range === 'week') {
                const s = startOfWeek(d);
                return [s, addDays(s, 6)];
            }
            const s = new Date(d.getFullYear(), d.getMonth(), 1);
            return [s, new Date(d.getFullYear(), d.getMonth() + 1, 0)];
        },

        updateLabel(from, to) {
            if (this.range === 'day') {
                this.label = fmt(from, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
            } else if (this.range === 'week') {
                this.label = `Week ${isoWeek(from)} · ${fmt(from, { day: 'numeric', month: 'short' })} – ${fmt(to, { day: 'numeric', month: 'short', year: 'numeric' })}`;
            } else {
                this.label = fmt(from, { month: 'long', year: 'numeric' });
            }
        },

        async load() {
            const [from, to] = this.period();
            this.updateLabel(from, to);
            const query = new URLSearchParams({ view: this.view, range: this.range, date: this.date });
            window.history.replaceState(null, '', '?' + query.toString());
            store('view', this.view);
            store('range', this.range);

            this.loading = true;
            try {
                const { data } = await axios.get('/api/planning', {
                    params: { from: isoDate(from), to: isoDate(to), view: this.view },
                });
                data.groups.forEach((g, i) => (g.order = i));
                this.groups.clear();
                this.groups.add(data.groups);
                this.items.clear();
                this.items.add(data.items.map((item) => this.present(item)));

                const end = addDays(to, 1);
                this.timeline.setOptions({
                    min: from,
                    max: end,
                    zoomMin: 60 * 60 * 1000,
                    timeAxis: this.range === 'month' ? { scale: 'day', step: 1 } : {},
                });
                this.timeline.setWindow(...this.visibleWindow(from, end), { animation: false });
            } catch (e) {
                window.alert(errorMessage(e));
            } finally {
                this.loading = false;
            }
        },

        /**
         * Dagweergave: dienst op exacte tijd. Week/maand: dienst als dagblok met de tijden in de tekst,
         * zodat ook korte diensten leesbaar blijven.
         */
        present(item) {
            if (item.type === 'background' || !item.shiftId) return item;
            const out = { ...item };
            if (this.range === 'day') return out;

            // Tijden en dag komen van de server (Nederlandse tijd), ongeacht de tijdzone van de browser.
            const day = parseDate(item.day);
            out.start = day;
            out.end = addDays(day, 1);
            out.content = this.range === 'month'
                ? `<b>${item.startLabel}</b>`
                : `<b>${item.startLabel}–${item.endLabel}</b> ${item.content}`;
            return out;
        },

        /**
         * Op een smal scherm tonen we een deel van de periode (dag: 8 uur, week: 3 dagen, maand: 7 dagen).
         * De rest is bereikbaar door horizontaal te vegen; de namenkolom blijft dan in beeld.
         */
        visibleWindow(from, end) {
            if (window.innerWidth >= 1024) return [from, end];

            const hour = 3600 * 1000;
            const span = { day: 8 * hour, week: 3 * 24 * hour, month: 7 * 24 * hour }[this.range];
            let start = this.range === 'day'
                ? new Date(from.getFullYear(), from.getMonth(), from.getDate(), this.firstItemHour())
                : parseDate(this.date);
            if (start < from) start = from;
            if (start.getTime() + span > end.getTime()) start = new Date(end.getTime() - span);
            return [start, new Date(start.getTime() + span)];
        },

        /** Vroegste begin-uur van de diensten in beeld (dagweergave), standaard 06:00. */
        firstItemHour() {
            const hours = this.items.get({ filter: (i) => i.shiftId }).map((i) => new Date(i.start).getHours());
            return hours.length ? Math.max(0, Math.min(...hours) - 1) : 6;
        },

        setView(view) {
            this.view = view;
            this.load();
        },

        setRange(range) {
            this.range = range;
            this.load();
        },

        shift(dir) {
            const d = parseDate(this.date);
            const next = this.range === 'day' ? addDays(d, dir)
                : this.range === 'week' ? addDays(d, 7 * dir)
                : new Date(d.getFullYear(), d.getMonth() + dir, 1);
            this.date = isoDate(next);
            this.load();
        },

        goToday() {
            this.date = isoDate(new Date());
            this.load();
        },

        async onMove(item, callback, confirm = false) {
            const original = this.items.get(item.id);
            const toGroup = item.group;
            const payload = { shift_id: item.shiftId };
            if (this.range === 'day') {
                payload.starts_at = new Date(item.start).toISOString();
                payload.ends_at = new Date(item.end).toISOString();
            } else {
                // Dagblok verplaatst: de server schuift de dienst dit aantal dagen op (zelfde tijden).
                const from = new Date(original.start);
                const to = new Date(item.start);
                payload.shift_days = Math.round((Date.UTC(to.getFullYear(), to.getMonth(), to.getDate()) - Date.UTC(from.getFullYear(), from.getMonth(), from.getDate())) / 86400000);
            }
            Object.assign(payload, {
                from_employee_id: this.view === 'employee' ? original.employeeId : null,
                to_employee_id: this.view === 'employee' ? (toGroup === 'open' ? null : toGroup) : null,
                confirm,
            });

            try {
                const { data } = await axios.post('/api/planning/move', payload);
                if (data.needs_confirmation) {
                    if (confirmWarnings(data.warnings, 'Deze wijziging geeft waarschuwingen:')) {
                        return this.onMove(item, callback, true);
                    }
                    callback(null);
                    return;
                }
                callback(item);
                this.load();
            } catch (e) {
                callback(null);
                window.alert(errorMessage(e));
            }
        },
    };
}

window.Alpine.data('planningBoard', planningBoard);
