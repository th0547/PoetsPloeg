import axios from 'axios';
import { errorMessage } from './ui/dialog';
import { openShift } from './shift-panel';

/** Open het medewerkerpaneel: waar is iemand nu, hoe ziet de dag en de week eruit. */
export function openEmployee(id, date = null) {
    window.dispatchEvent(new CustomEvent('open-employee', { detail: { id, date } }));
}

const STATE_BADGE = {
    working: 'badge-green',
    later: 'badge-sky',
    absent: 'badge-red',
    done: 'badge-gray',
    free: 'badge-gray',
};

export default function employeePanel() {
    return {
        visible: false,
        loading: false,
        employee: null,
        error: null,

        async open(id, date = null) {
            window.dispatchEvent(new CustomEvent('close-shift-panel'));
            this.visible = true;
            this.error = null;
            if (this.employee?.id !== id) this.employee = null;
            await this.load(id, date);
        },

        async load(id, date = null) {
            this.loading = true;
            try {
                const { data } = await axios.get(`/api/employees/${id}`, { params: date ? { datum: date } : {} });
                this.employee = data;
            } catch (e) {
                this.error = errorMessage(e);
            } finally {
                this.loading = false;
            }
        },

        week(date) {
            return this.load(this.employee.id, date);
        },

        close() {
            this.visible = false;
        },

        openShift(id) {
            openShift(id);
        },

        stateBadge() {
            return STATE_BADGE[this.employee?.status.state] ?? 'badge-gray';
        },

        today() {
            return this.employee?.days.find((d) => d.today) ?? null;
        },

        /** Uren deze week t.o.v. het contract: breedte van de balk en of het te veel of te weinig is. */
        meter() {
            const e = this.employee;
            if (!e) return { width: 0, cls: '', label: '' };
            const { min, max } = e.contract;
            const hours = e.week.hours;
            const label = max !== null ? `${e.week.hours_label} / ${String(max).replace('.', ',')} u` : `${e.week.hours_label} u`;
            const cls = max !== null && hours > max ? 'is-over' : (min !== null && hours < min ? 'is-under' : '');
            return { width: max ? Math.min(100, (hours / max) * 100) : (hours > 0 ? 100 : 0), cls, label };
        },

        contractNote() {
            const e = this.employee;
            if (!e) return '';
            const { min, max } = e.contract;
            if (max !== null && e.week.hours > max) return 'Boven contracturen';
            if (min !== null && e.week.hours < min) return `Onder het minimum van ${String(min).replace('.', ',')} u`;
            return '';
        },
    };
}
