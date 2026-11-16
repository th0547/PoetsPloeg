import axios from 'axios';
import { confirmDialog } from './ui/dialog';

const STATUS = {
    ok: { cls: 'text-emerald-700', icon: 'check', dot: 'bg-emerald-500' },
    warning: { cls: 'text-amber-700', icon: 'alert', dot: 'bg-amber-500' },
    blocked: { cls: 'text-red-700', icon: 'alert-circle', dot: 'bg-red-500' },
};

const issueText = (result) => result.issues.map((i) => i.text).join(' · ');

/** Vraag bevestiging als er vaste krachten zijn gekozen die (een deel van) de diensten niet kunnen. */
async function confirmProblems(problems) {
    return confirmDialog({
        title: 'Toch als vaste kracht instellen?',
        body: 'Niet iedereen kan alle diensten draaien. Wie niet kan wordt op die diensten niet ingepland; de plek komt dan bij Open diensten te staan.',
        items: problems.map((text) => ({ level: 'warning', text })),
        confirmLabel: 'Toch opslaan',
    });
}

/**
 * Vaste bezetting in het taakformulier: toont bij elke medewerker of hij of zij de diensten van deze
 * taak kan draaien (beschikbaarheid, afwezigheid, andere diensten, contracturen), ook terwijl de
 * tijden of dagen nog worden aangepast.
 */
export default function staffPicker(config) {
    return {
        results: {},
        total: null,
        loading: false,
        onlyFit: false,
        selected: config.selected ?? [],
        confirmed: false,
        timer: null,
        request: 0,

        init() {
            const form = this.$el.closest('form');
            // Elke wijziging in het formulier (tijden, dagen, vaardigheden) kan de uitkomst veranderen.
            form.addEventListener('input', () => this.schedule());
            form.addEventListener('change', () => this.schedule());
            form.addEventListener('submit', (event) => this.onSubmit(event, form));
            this.check();
        },

        schedule() {
            window.clearTimeout(this.timer);
            this.timer = window.setTimeout(() => this.check(), 450);
        },

        async check() {
            const form = this.$el.closest('form');
            const body = new FormData(form);
            body.delete('_method');
            body.set('building_id', config.buildingId);
            if (config.templateId) body.set('template_id', config.templateId);

            const id = ++this.request;
            this.loading = true;
            try {
                const { data } = await axios.post(config.url, body);
                if (id !== this.request) return;
                this.results = data.employees;
                this.total = data.total;
            } catch (e) {
                // Formulier nog niet compleet (bijv. geen dag gekozen): dan valt er niets te toetsen.
                if (id === this.request) {
                    this.results = {};
                    this.total = null;
                }
            } finally {
                if (id === this.request) this.loading = false;
            }
        },

        result(id) {
            return this.results[id] ?? null;
        },

        visible(id) {
            if (!this.onlyFit || this.selected.includes(id)) return true;
            const r = this.result(id);
            return !r || r.status === 'ok';
        },

        /** Volgorde in de lijst: gekozen eerst, dan wie alles kan, dan deels, dan wie niet kan. */
        rank(id) {
            if (this.selected.includes(id)) return 0;
            return { ok: 1, warning: 2, blocked: 3 }[this.result(id)?.status] ?? 1;
        },

        cls(id) {
            return STATUS[this.result(id)?.status]?.cls ?? 'text-gray-400';
        },

        dot(id) {
            return STATUS[this.result(id)?.status]?.dot ?? 'bg-gray-300';
        },

        detail(id) {
            const r = this.result(id);
            if (!r) return '';
            return [issueText(r), ...r.notes].filter(Boolean).join(' · ');
        },

        fitCount() {
            return Object.values(this.results).filter((r) => r.status === 'ok').length;
        },

        problems() {
            return this.selected
                .map((id) => this.result(id))
                .filter((r) => r && r.status !== 'ok')
                .map((r) => `${r.name}: ${issueText(r)}`);
        },

        async onSubmit(event, form) {
            const problems = this.problems();
            // Na een waarschuwing van de server beslist het vinkje "Toch zo opslaan".
            if (this.confirmed || !problems.length || config.serverWarned) return;
            event.preventDefault();
            if (await confirmProblems(problems)) {
                this.confirmed = true;
                this.$nextTick(() => form.requestSubmit(event.submitter ?? undefined));
            }
        },
    };
}

/**
 * Bezettingstabel op de pandpagina: per vakje (taak × medewerker) een markering of die medewerker
 * de diensten van die taak kan draaien.
 */
export function staffingTable(config) {
    return {
        results: {},
        loaded: false,
        confirmed: false,
        showAll: !!config.showAll,

        async init() {
            this.$el.querySelector('form')?.addEventListener('submit', (event) => this.onSubmit(event));
            try {
                const { data } = await axios.post(config.url, { building_id: config.buildingId });
                this.results = data.templates;
                this.loaded = true;
            } catch (e) {
                this.loaded = false;
            }
        },

        result(templateId, employeeId) {
            return this.results[templateId]?.[employeeId] ?? null;
        },

        dot(templateId, employeeId) {
            return STATUS[this.result(templateId, employeeId)?.status]?.dot ?? 'bg-transparent';
        },

        title(templateId, employeeId) {
            const r = this.result(templateId, employeeId);
            if (!r) return '';
            return [r.summary, issueText(r), ...r.notes].filter(Boolean).join('\n');
        },

        async onSubmit(event) {
            if (this.confirmed) return;
            // Alleen nieuw aangevinkte vakjes: wie er al stond, is eerder beoordeeld.
            const problems = [...event.target.querySelectorAll('input[type=checkbox][data-template]:checked')]
                .filter((box) => !box.defaultChecked)
                .map((box) => ({ box, r: this.result(box.dataset.template, box.value) }))
                .filter(({ r }) => r && r.status !== 'ok')
                .map(({ box, r }) => `${box.dataset.task} – ${r.name}: ${issueText(r)}`);
            if (!problems.length) return;
            event.preventDefault();
            if (await confirmProblems(problems)) {
                this.confirmed = true;
                this.$nextTick(() => event.target.requestSubmit(event.submitter ?? undefined));
            }
        },
    };
}
