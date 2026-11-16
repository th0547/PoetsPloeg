import axios from 'axios';
import { confirmDialog, confirmWarnings, errorMessage, toast } from './ui/dialog';

export { confirmWarnings, errorMessage };

/** Meldt aan de pagina dat de planning is gewijzigd (het rooster laadt opnieuw). */
export function notifyChanged() {
    window.dispatchEvent(new CustomEvent('shifts-changed'));
}

/** Open het zijpaneel. Met replaceEmployeeId nemen vervangers de plek van die medewerker over. */
export function openShift(id, candidates = false, replaceEmployeeId = null) {
    window.dispatchEvent(new CustomEvent('open-shift', { detail: { id, candidates, replace: replaceEmployeeId } }));
}

const FIT = {
    best: { label: 'Beste keuze', badge: 'badge-teal' },
    good: { label: 'Geschikt', badge: 'badge-green' },
    caution: { label: 'Let op', badge: 'badge-amber' },
};

export default function shiftPanel() {
    return {
        visible: false,
        loading: false,
        shift: null,
        candidates: null,
        candidatesLoading: false,
        showExcluded: false,
        showAll: false,
        query: '',
        editing: false,
        error: null,
        changed: false,
        form: {},

        replace: null,
        notice: null,

        /** Medewerker die vervangen wordt, als die nog op de dienst staat. */
        replacing() {
            return this.replace && this.shift ? this.shift.assignments.find((a) => a.employee_id === this.replace) : null;
        },

        /** Volledig bezet (en niemand te vervangen): wie je nu inplant, komt er extra bij. */
        isFull() {
            return !!this.shift && this.shift.open_slots === 0 && !this.replacing();
        },

        assignLabel() {
            const replacing = this.replacing();
            if (replacing) return 'Vervangt ' + replacing.name.split(' ')[0];
            return this.isFull() ? 'Extra inplannen' : 'Inplannen';
        },

        candidatesTitle() {
            const replacing = this.replacing();
            if (replacing) return 'Vervanger voor ' + replacing.name;
            return this.isFull() ? 'Iemand extra inplannen' : 'Wie kan deze dienst draaien?';
        },

        fit(candidate) {
            return FIT[candidate.fit] ?? FIT.good;
        },

        stateLabel() {
            return { now: 'Nu bezig', done: 'Afgelopen' }[this.shift?.state] ?? '';
        },

        /** Kandidaten na zoeken; standaard de beste vijf, de rest achter "Toon alle". */
        shownCandidates() {
            const all = this.candidates?.candidates ?? [];
            const term = this.query.trim().toLowerCase();
            if (term) return all.filter((c) => c.name.toLowerCase().includes(term));
            return this.showAll ? all : all.slice(0, 5);
        },

        hiddenCount() {
            const all = this.candidates?.candidates ?? [];
            return this.query.trim() || this.showAll ? 0 : Math.max(0, all.length - 5);
        },

        async open(id, withCandidates = false, replace = null) {
            window.dispatchEvent(new CustomEvent('close-employee-panel'));
            this.replace = replace;
            this.notice = null;
            this.visible = true;
            this.loading = true;
            this.shift = null;
            this.candidates = null;
            this.showAll = false;
            this.showExcluded = false;
            this.query = '';
            this.editing = false;
            this.error = null;
            try {
                const { data } = await axios.get(`/api/shifts/${id}`);
                this.setShift(data);
                // Staat er een plek open, dan direct tonen wie kan invallen.
                if (withCandidates || data.open_slots > 0) {
                    await this.loadCandidates();
                }
            } catch (e) {
                this.error = errorMessage(e);
            } finally {
                this.loading = false;
            }
        },

        close() {
            if (!this.visible) return;
            this.visible = false;
            window.dispatchEvent(new CustomEvent('shift-panel-closed', { detail: { changed: this.changed } }));
            this.changed = false;
        },

        setShift(data) {
            this.shift = data;
            this.form = {
                title: data.title,
                date: data.date,
                start_time: data.start_time,
                end_time: data.end_time,
                required_staff: data.required_staff,
                notes: data.notes ?? '',
                skills: data.skills.map((s) => s.id),
            };
        },

        markChanged() {
            this.changed = true;
            notifyChanged();
        },

        levelClass(level) {
            return { danger: 'text-red-600', warning: 'text-amber-700', info: 'text-gray-500' }[level] ?? 'text-gray-500';
        },

        /** Kies wie vervangen moet worden en toon de kandidaten. */
        async startReplace(assignment) {
            this.replace = assignment.employee_id;
            this.notice = null;
            await this.loadCandidates();
            this.$nextTick(() => this.$refs.candidates?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        },

        cancelReplace() {
            this.replace = null;
        },

        async loadCandidates() {
            this.candidatesLoading = true;
            this.candidates ??= { candidates: [], excluded: [] };
            try {
                const { data } = await axios.get(`/api/shifts/${this.shift.id}/candidates`);
                this.candidates = data;
            } catch (e) {
                this.error = errorMessage(e);
            } finally {
                this.candidatesLoading = false;
            }
        },

        async assign(candidate, confirm = false) {
            this.error = null;
            const replacing = this.replacing();
            const source = replacing || !this.isFull() ? 'replacement' : 'manual';
            try {
                const { data } = await axios.post(`/api/shifts/${this.shift.id}/assignments`, {
                    employee_id: candidate.id,
                    source,
                    confirm,
                    replace_employee_id: replacing ? replacing.employee_id : null,
                });
                if (data.needs_confirmation) {
                    if (await confirmWarnings(data.warnings, `${candidate.name} inplannen geeft waarschuwingen.`)) {
                        return this.assign(candidate, true);
                    }
                    return;
                }
                this.setShift(data);
                this.notice = replacing ? `${candidate.name} vervangt ${replacing.name}.` : `${candidate.name} is ingepland.`;
                this.replace = null;
                if (data.approved_requests?.length) {
                    this.notice = `Verlofverzoek van ${data.approved_requests.join(', ')} is goedgekeurd: alle diensten zijn vervangen.`;
                }
                this.markChanged();
                if (data.open_slots > 0) {
                    await this.loadCandidates();
                } else {
                    this.candidates = null;
                }
            } catch (e) {
                this.error = errorMessage(e);
            }
        },

        async unassign(assignment) {
            const ok = await confirmDialog({
                title: `${assignment.name} van deze dienst halen?`,
                body: 'De plek komt bij Open diensten te staan. Gaat het om ziekte of verlof, meld dan liever de afwezigheid: dan worden alle diensten in die periode vrijgemaakt.',
                confirmLabel: 'Haal van dienst',
                danger: true,
            });
            if (!ok) return;
            this.error = null;
            try {
                const { data } = await axios.delete(`/api/shifts/${this.shift.id}/assignments/${assignment.employee_id}`);
                this.setShift(data);
                this.replace = null;
                this.markChanged();
                await this.loadCandidates();
            } catch (e) {
                this.error = errorMessage(e);
            }
        },

        async save() {
            this.error = null;
            try {
                const { data } = await axios.patch(`/api/shifts/${this.shift.id}`, this.form);
                this.setShift(data);
                this.editing = false;
                this.markChanged();
                toast('Dienst opgeslagen.');
            } catch (e) {
                this.error = errorMessage(e);
            }
        },

        async cancelShift() {
            const ok = await confirmDialog({
                title: 'Deze dienst annuleren?',
                body: 'De dienst verdwijnt uit de planning. Ingeplande medewerkers zijn dan vrij.',
                confirmLabel: 'Dienst annuleren',
                cancelLabel: 'Terug',
                danger: true,
            });
            if (!ok) return;
            try {
                await axios.delete(`/api/shifts/${this.shift.id}`);
                this.markChanged();
                this.close();
                toast('Dienst geannuleerd.');
            } catch (e) {
                this.error = errorMessage(e);
            }
        },
    };
}
