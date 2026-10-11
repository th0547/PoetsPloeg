import axios from 'axios';

/** Meldt aan de pagina dat de planning is gewijzigd (Gantt/kalender laden opnieuw). */
export function notifyChanged() {
    window.dispatchEvent(new CustomEvent('shifts-changed'));
}

export function openShift(id, candidates = false) {
    window.dispatchEvent(new CustomEvent('open-shift', { detail: { id, candidates } }));
}

/** Toon waarschuwingen en vraag bevestiging. */
export function confirmWarnings(warnings, intro = 'Let op:') {
    const lines = warnings.map((w) => '• ' + (w.employee ? w.employee + ': ' : '') + w.message);
    return window.confirm(intro + '\n\n' + lines.join('\n') + '\n\nToch doorgaan?');
}

export function errorMessage(e) {
    const data = e?.response?.data;
    if (data?.errors) {
        return Object.values(data.errors).flat().join(' ');
    }
    return data?.message || 'Er ging iets mis. Probeer het opnieuw.';
}

export default function shiftPanel() {
    return {
        visible: false,
        loading: false,
        shift: null,
        candidates: null,
        candidatesLoading: false,
        showExcluded: false,
        editing: false,
        error: null,
        changed: false,
        form: {},

        async open(id, withCandidates = false) {
            this.visible = true;
            this.loading = true;
            this.shift = null;
            this.candidates = null;
            this.editing = false;
            this.error = null;
            try {
                const { data } = await axios.get(`/api/shifts/${id}`);
                this.setShift(data);
                if (withCandidates) {
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

        async loadCandidates() {
            this.candidatesLoading = true;
            this.candidates = { candidates: [], excluded: [] };
            try {
                const { data } = await axios.get(`/api/shifts/${this.shift.id}/candidates`);
                this.candidates = data;
            } catch (e) {
                this.error = errorMessage(e);
            } finally {
                this.candidatesLoading = false;
            }
        },

        async assign(employeeId, source = 'manual', confirm = false) {
            this.error = null;
            try {
                const { data } = await axios.post(`/api/shifts/${this.shift.id}/assignments`, { employee_id: employeeId, source, confirm });
                if (data.needs_confirmation) {
                    if (confirmWarnings(data.warnings)) {
                        return this.assign(employeeId, source, true);
                    }
                    return;
                }
                this.setShift(data);
                this.markChanged();
                if (this.candidates) await this.loadCandidates();
            } catch (e) {
                this.error = errorMessage(e);
            }
        },

        async unassign(assignment) {
            if (!window.confirm(`${assignment.name} van deze dienst halen?`)) return;
            this.error = null;
            try {
                const { data } = await axios.delete(`/api/shifts/${this.shift.id}/assignments/${assignment.employee_id}`);
                this.setShift(data);
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
            } catch (e) {
                this.error = errorMessage(e);
            }
        },

        async cancelShift() {
            if (!window.confirm('Deze dienst annuleren? Hij verdwijnt uit de planning.')) return;
            try {
                await axios.delete(`/api/shifts/${this.shift.id}`);
                this.markChanged();
                this.close();
            } catch (e) {
                this.error = errorMessage(e);
            }
        },
    };
}
