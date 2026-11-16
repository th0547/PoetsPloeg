/**
 * Kleine acties via data-attributen in plaats van inline onclick/onsubmit. Zo heeft de
 * Content-Security-Policy geen 'unsafe-inline' voor scripts nodig.
 *
 *   <form data-confirm="Verwijderen?">          vraagt bevestiging voor het versturen
 *   <select data-auto-submit>                    verstuurt het formulier bij een wijziging
 *   <a data-submit-form>                         verstuurt het omliggende formulier (uitloggen)
 *   <button data-open-shift="12" data-candidates data-replace="3">
 *                                                opent het dienstpaneel (met vervangers, eventueel voor medewerker 3)
 *   <a data-open-employee="5">                   opent het medewerkerpaneel (waar is iemand nu, dag en week)
 *   <button data-open-palette>                   opent de zoekbalk (Ctrl+K)
 *   <button data-print>                          drukt de pagina af
 */
import { confirmDialog } from './ui/dialog';
import { openEmployee } from './employee-panel';
import { openShift } from './shift-panel';

export default function registerActions(root = document) {
    root.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm]');
        if (!form || form.dataset.confirmed === '1') return;

        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter;
        confirmDialog({
            title: form.dataset.confirmTitle || 'Weet je het zeker?',
            body: form.dataset.confirm,
            confirmLabel: form.dataset.confirmLabel || 'Ja, doorgaan',
            danger: 'confirmDanger' in form.dataset || /verwijder|intrek|afwijz/i.test(form.dataset.confirm),
        }).then((ok) => {
            if (!ok) return;
            form.dataset.confirmed = '1';
            form.requestSubmit(submitter?.form === form ? submitter : undefined);
        });
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

        // Het dichtstbijzijnde element telt: een naam binnen een dienstregel opent de medewerker, niet de dienst.
        const target = event.target.closest('[data-open-employee], [data-open-shift]');
        if (target?.dataset.openEmployee !== undefined) {
            if (event.metaKey || event.ctrlKey) return;
            event.preventDefault();
            openEmployee(Number(target.dataset.openEmployee), target.dataset.date || null);
            return;
        }
        if (target) {
            // Links en knoppen binnen een dienstregel (bijv. naar het pand) doen gewoon hun eigen werk.
            const inner = event.target.closest('a, button');
            if (inner && inner !== target && target.contains(inner)) return;
            const { openShift: id, replace } = target.dataset;
            openShift(Number(id), 'candidates' in target.dataset, replace ? Number(replace) : null);
            return;
        }

        if (event.target.closest('[data-open-palette]')) {
            window.dispatchEvent(new CustomEvent('open-palette'));
            return;
        }

        if (event.target.closest('[data-print]')) {
            window.print();
        }
    });
}
