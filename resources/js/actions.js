/**
 * Kleine acties via data-attributen in plaats van inline onclick/onsubmit. Zo heeft de
 * Content-Security-Policy geen 'unsafe-inline' voor scripts nodig.
 *
 *   <form data-confirm="Verwijderen?">          vraagt bevestiging voor het versturen
 *   <select data-auto-submit>                    verstuurt het formulier bij een wijziging
 *   <a data-submit-form>                         verstuurt het omliggende formulier (uitloggen)
 *   <button data-open-shift="12" data-candidates data-replace="3">
 *                                                opent het dienstpaneel (met vervangers, eventueel voor medewerker 3)
 */
import { openShift } from './shift-panel';

export default function registerActions(root = document) {
    root.addEventListener('submit', (event) => {
        const message = event.target.closest('form[data-confirm]')?.dataset.confirm;
        if (message && !window.confirm(message)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    root.addEventListener('change', (event) => {
        if (event.target.matches('[data-auto-submit]')) {
            event.target.form?.requestSubmit();
        }
    });

    root.addEventListener('click', (event) => {
        const submitLink = event.target.closest('[data-submit-form]');
        if (submitLink) {
            event.preventDefault();
            submitLink.closest('form')?.requestSubmit();
            return;
        }

        const shift = event.target.closest('[data-open-shift]');
        if (shift) {
            const { openShift: id, replace } = shift.dataset;
            openShift(Number(id), 'candidates' in shift.dataset, replace ? Number(replace) : null);
        }
    });
}
