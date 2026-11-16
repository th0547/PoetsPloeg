import axios from 'axios';
import { openEmployee } from '../employee-panel';

/** Vaste acties in de zoekbalk; `words` zijn extra zoekwoorden. */
const ACTIONS = [
    { label: 'Uitval of afwezigheid melden', icon: 'user-x', url: '/afwezigheid/create', words: 'ziek verlof vervangen uitval afwezig' },
    { label: 'Losse opdracht inplannen', icon: 'plus', url: '/diensten/nieuw', words: 'nieuw dienst opdracht' },
    { label: 'Vandaag', icon: 'sun', url: '/', words: 'dashboard overzicht nu' },
    { label: 'Planning', icon: 'grid', url: '/planning', words: 'rooster week maand dag' },
    { label: 'Open diensten', icon: 'inbox', url: '/open-diensten', words: 'open plekken vervanger' },
    { label: 'Afwezigheid', icon: 'user-x', url: '/afwezigheid', words: 'verlof ziekte' },
    { label: 'Medewerkers', icon: 'users', url: '/medewerkers', words: 'personeel' },
    { label: 'Panden', icon: 'building', url: '/panden', words: 'objecten locaties klanten' },
];

export default function palette() {
    return {
        visible: false,
        query: '',
        loading: false,
        employees: [],
        buildings: [],
        active: 0,
        request: 0,

        init() {
            window.addEventListener('keydown', (event) => {
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                    event.preventDefault();
                    this.visible ? this.close() : this.open();
                }
            });
            this.$watch('query', () => this.search());
        },

        open() {
            this.visible = true;
            this.query = '';
            this.active = 0;
            this.search();
            // Even wachten tot de zoekbalk zichtbaar is; een verborgen veld kan geen focus krijgen.
            window.setTimeout(() => this.$refs.input?.focus(), 40);
        },

        close() {
            this.visible = false;
        },

        actions() {
            const term = this.query.trim().toLowerCase();
            if (!term) return ACTIONS.slice(0, 2);
            return ACTIONS.filter((a) => (a.label + ' ' + a.words).toLowerCase().includes(term));
        },

        /** Alle resultaten onder elkaar, voor de pijltjestoetsen. */
        items() {
            return [
                ...this.employees.map((e) => ({ type: 'employee', key: 'e' + e.id, data: e })),
                ...this.buildings.map((b) => ({ type: 'building', key: 'b' + b.id, data: b })),
                ...this.actions().map((a) => ({ type: 'action', key: 'a' + a.url, data: a })),
            ];
        },

        indexOf(key) {
            return this.items().findIndex((i) => i.key === key);
        },

        async search() {
            const id = ++this.request;
            this.loading = true;
            try {
                const { data } = await axios.get('/api/search', { params: { q: this.query.trim() } });
                if (id !== this.request) return; // er is alweer verder getypt
                this.employees = data.employees;
                this.buildings = data.buildings;
                this.active = 0;
            } catch (e) {
                this.employees = [];
                this.buildings = [];
            } finally {
                if (id === this.request) this.loading = false;
            }
        },

        move(step) {
            const count = this.items().length;
            if (!count) return;
            this.active = (this.active + step + count) % count;
            this.$nextTick(() => this.$refs.list?.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' }));
        },

        choose(item = this.items()[this.active]) {
            if (!item) return;
            this.close();
            if (item.type === 'employee') {
                openEmployee(item.data.id);
            } else {
                window.location.href = item.data.url;
            }
        },

        todayLine(employee) {
            return employee.today.map((s) => `${s.start}–${s.end} ${s.building}`).join(' · ');
        },
    };
}
