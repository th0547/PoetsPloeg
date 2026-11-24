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
 * Vaste bezetting per patroon in het taakformulier. Wordt in de herhalingseditor (recurrence.js) gemengd:
 * elk patroon heeft `employees` (ids, in volgorde). Bij elke medewerker staat of hij of zij de diensten van
 * dat patroon kan draaien (beschikbaarheid, afwezigheid, andere diensten, contracturen), ook terwijl de
 * tijden of dagen nog worden aangepast.
 */
export function patternStaffing(config = {}) {
    return {
        staffEmployees: config.employees ?? [],
        staffResults: [],
        staffLoading: false,
        staffConfirmed: false,
        staffTimer: null,
        staffRequest: 0,
        // Elke wijziging verhoogt staffVersion; staffCheckedVersion is de versie van de laatste uitkomst.
        staffVersion: 0,
        staffCheckedVersion: -1,
        staffSubmitting: false,

        initStaffing() {
            const form = this.$el.closest('form');
            if (!form || !config.url) return;
            // Elke wijziging (tijden, dagen, vaardigheden, wie er vast op staat) kan de uitkomst veranderen.
            form.addEventListener('input', () => this.scheduleStaff());
            form.addEventListener('change', () => this.scheduleStaff());
            this.$watch('patterns', () => this.scheduleStaff());
            form.addEventListener('submit', (event) => this.onStaffSubmit(event, form));
            this.$nextTick(() => this.checkStaff());
        },

        scheduleStaff() {
            this.staffVersion++;
            window.clearTimeout(this.staffTimer);
            this.staffTimer = window.setTimeout(() => this.checkStaff(), 450);
        },

        async checkStaff() {
            const form = this.$el.closest('form');
            const body = new FormData(form);
            body.delete('_method');
            body.set('building_id', config.buildingId);
            if (config.templateId) body.set('template_id', config.templateId);

            const id = ++this.staffRequest;
            const version = this.staffVersion;
            this.staffLoading = true;
            try {
                const { data } = await axios.post(config.url, body);
                if (id === this.staffRequest) {
                    this.staffResults = data.patterns;
                    this.staffCheckedVersion = version;
                }
            } catch (e) {
                // Formulier nog niet compleet (bijv. geen dag gekozen): dan valt er niets te toetsen.
                if (id === this.staffRequest) this.staffResults = [];
            } finally {
                if (id === this.staffRequest) this.staffLoading = false;
            }
        },

        staffEmployee(id) {
            return this.staffEmployees.find((e) => e.id === id) ?? { id, name: '?', known: false };
        },

        staffResult(index, id) {
            return this.staffResults[index]?.employees?.[id] ?? null;
        },

        staffTotal(index) {
            return this.staffResults[index]?.total ?? null;
        },

        /** Wie er nog bij dit patroon kan: eerst wie alles kan, dan wie het pand kent, dan op naam. */
        staffCandidates(index) {
            const p = this.patterns[index];
            const rank = (e) => ({ ok: 0, warning: 1, blocked: 2 }[this.staffResult(index, e.id)?.status] ?? 1);
            return this.staffEmployees
                .filter((e) => !p.employees.includes(e.id))
                .sort((a, b) => rank(a) - rank(b) || Number(b.known) - Number(a.known) || a.name.localeCompare(b.name, 'nl'));
        },

        staffOption(index, e) {
            const r = this.staffResult(index, e.id);
            const mark = { ok: '✓', warning: '!', blocked: '✗' }[r?.status] ?? '·';
            return `${mark} ${e.name}${e.known ? ' (kent pand)' : ''}${r ? ' – ' + r.summary.toLowerCase() : ''}`;
        },

        addStaff(p, event) {
            const id = Number(event.target.value);
            event.target.value = '';
            if (id && !p.employees.includes(id)) p.employees.push(id);
        },

        removeStaff(p, id) {
            p.employees = p.employees.filter((e) => e !== id);
        },

        staffCls(index, id) {
            return STATUS[this.staffResult(index, id)?.status]?.cls ?? 'text-gray-500';
        },

        staffDot(index, id) {
            return STATUS[this.staffResult(index, id)?.status]?.dot ?? 'bg-gray-300';
        },

        staffDetail(index, id) {
            const r = this.staffResult(index, id);
            if (!r) return '';
            return [issueText(r), ...r.notes].filter(Boolean).join(' · ');
        },

        staffProblems() {
            return this.patterns.flatMap((p, index) => p.employees
                .map((id) => this.staffResult(index, id))
                .filter((r) => r && r.status !== 'ok')
                .map((r) => (this.patterns.length > 1 ? `Patroon ${index + 1} – ` : '') + `${r.name}: ${issueText(r)}`));
        },

        async onStaffSubmit(event, form) {
            // Na een waarschuwing van de server beslist het vinkje "Toch zo opslaan".
            if (this.staffConfirmed || this.staffSubmitting || config.serverWarned) return;
            const stale = this.staffCheckedVersion !== this.staffVersion;
            if (!stale && !this.staffProblems().length) return;
            event.preventDefault();
            if (stale) {
                // Eerst de controle met de laatste invoer afwachten, anders oordelen we op een oude uitkomst.
                window.clearTimeout(this.staffTimer);
                await this.checkStaff();
            }
            const problems = this.staffProblems();
            if (problems.length && !(await confirmProblems(problems))) return;
            if (problems.length) this.staffConfirmed = true;
            this.staffSubmitting = true;
            this.$nextTick(() => form.requestSubmit(event.submitter ?? undefined));
        },
    };
}

/**
 * Bezettingstabel op de pandpagina: per vakje (patroon × medewerker) een markering of die medewerker
 * de diensten van dat patroon kan draaien.
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
                this.results = data.patterns;
                this.loaded = true;
            } catch (e) {
                this.loaded = false;
            }
        },

        result(patternId, employeeId) {
            return this.results[patternId]?.[employeeId] ?? null;
        },

        dot(patternId, employeeId) {
            return STATUS[this.result(patternId, employeeId)?.status]?.dot ?? 'bg-transparent';
        },

        title(patternId, employeeId) {
            const r = this.result(patternId, employeeId);
            if (!r) return '';
            return [r.summary, issueText(r), ...r.notes].filter(Boolean).join('\n');
        },

        async onSubmit(event) {
            if (this.confirmed) return;
            // Alleen nieuw aangevinkte vakjes: wie er al stond, is eerder beoordeeld.
            const problems = [...event.target.querySelectorAll('input[type=checkbox][data-pattern]:checked')]
                .filter((box) => !box.defaultChecked)
                .map((box) => ({ box, r: this.result(box.dataset.pattern, box.value) }))
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
