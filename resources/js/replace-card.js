import axios from 'axios';
import { openShift } from './shift-panel';
import { confirmWarnings, errorMessage, toast } from './ui/dialog';

const FIT = {
    best: { label: 'Beste keuze', badge: 'badge-teal' },
    good: { label: 'Geschikt', badge: 'badge-green' },
    caution: { label: 'Let op', badge: 'badge-amber' },
};

/**
 * Kaartje bij een dienst die een vervanger nodig heeft: toont direct de beste kandidaten, met één
 * klik in te plannen. Gebruikt op het vervangscherm (Afwezigheid), Open diensten en Vandaag.
 *
 * @param shiftId  de dienst
 * @param replaceId  medewerker die vervangen wordt (verlofverzoek), of null bij een open plek
 */
export default function replaceCard(shiftId, replaceId = null, limit = 3) {
    return {
        loading: false,
        loaded: false,
        candidates: [],
        total: 0,
        error: null,
        done: null,
        busy: null,

        init() {
            // Pas laden als het kaartje in beeld komt: op een lange lijst scheelt dat veel verzoeken.
            const observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    observer.disconnect();
                    this.load();
                }
            }, { rootMargin: '200px' });
            observer.observe(this.$el);
            window.addEventListener('shift-panel-closed', (event) => {
                if (event.detail.changed && !this.done) this.load();
            });
        },

        fit(candidate) {
            return FIT[candidate.fit] ?? FIT.good;
        },

        async load() {
            this.loading = true;
            this.error = null;
            try {
                const { data } = await axios.get(`/api/shifts/${shiftId}/candidates`);
                this.total = data.candidates.length;
                this.candidates = data.candidates.slice(0, limit);
                this.loaded = true;
            } catch (e) {
                this.error = errorMessage(e);
            } finally {
                this.loading = false;
            }
        },

        async assign(candidate, confirm = false) {
            this.busy = candidate.id;
            this.error = null;
            try {
                const { data } = await axios.post(`/api/shifts/${shiftId}/assignments`, {
                    employee_id: candidate.id,
                    source: 'replacement',
                    replace_employee_id: replaceId,
                    confirm,
                });
                if (data.needs_confirmation) {
                    if (await confirmWarnings(data.warnings, `${candidate.name} inplannen geeft waarschuwingen.`)) {
                        return this.assign(candidate, true);
                    }
                    return null;
                }
                const approved = data.approved_requests?.length
                    ? ` Het verlofverzoek van ${data.approved_requests.join(', ')} is goedgekeurd.`
                    : '';
                toast(`${candidate.name} is ingepland.${approved}`);
                if (data.open_slots > 0 && !replaceId) {
                    await this.load();
                } else {
                    this.done = candidate.name;
                }
                window.dispatchEvent(new CustomEvent('replace-card-filled', { detail: { shiftId, approved: !!approved } }));
            } catch (e) {
                this.error = errorMessage(e);
            } finally {
                this.busy = null;
            }
            return null;
        },

        more() {
            openShift(shiftId, true, replaceId);
        },
    };
}
