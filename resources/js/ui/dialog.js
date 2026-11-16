/**
 * Dialogen en meldingen in de stijl van de app, in plaats van window.alert/confirm.
 *
 *   await confirmDialog({ title, body, items: [{ level, text }], confirmLabel, danger })  → true/false
 *   await alertDialog('Er ging iets mis')
 *   toast('Dienst verplaatst', { action: { label: 'Ongedaan maken', run } })
 */
import { icon } from './dom';

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

export function confirmDialog({
    title = 'Weet je het zeker?',
    body = '',
    items = [],
    confirmLabel = 'Doorgaan',
    cancelLabel = 'Annuleren',
    danger = false,
} = {}) {
    return new Promise((resolve) => {
        const previous = document.activeElement;
        const backdrop = el('div', 'dlg-backdrop');
        const dialog = el('div', 'dlg');
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.append(el('h2', 'dlg-title', title));
        if (body) dialog.append(el('p', 'dlg-body', body));

        if (items.length) {
            const list = el('ul', 'dlg-list');
            for (const item of items) {
                const level = ['danger', 'warning', 'info'].includes(item.level) ? item.level : 'info';
                const li = el('li', 'is-' + level);
                const mark = el('span', 'mt-0.5 shrink-0');
                mark.innerHTML = icon(level === 'info' ? 'info' : 'alert');
                li.append(mark, el('span', '', item.text));
                list.append(li);
            }
            dialog.append(list);
        }

        const actions = el('div', 'dlg-actions');
        const cancel = cancelLabel ? el('button', 'btn-secondary', cancelLabel) : null;
        const confirm = el('button', danger ? 'btn !bg-red-600 hover:!bg-red-700' : 'btn', confirmLabel);
        if (cancel) {
            cancel.type = 'button';
            actions.append(cancel);
        }
        confirm.type = 'button';
        actions.append(confirm);
        dialog.append(actions);
        backdrop.append(dialog);

        const close = (result) => {
            document.removeEventListener('keydown', onKey, true);
            backdrop.remove();
            previous?.focus?.();
            resolve(result);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close(false);
            } else if (event.key === 'Tab') {
                // Focus binnen de dialoog houden.
                const focusable = [cancel, confirm].filter(Boolean);
                const index = focusable.indexOf(document.activeElement);
                event.preventDefault();
                focusable[(index + (event.shiftKey ? -1 : 1) + focusable.length) % focusable.length].focus();
            }
        };
        document.addEventListener('keydown', onKey, true);
        backdrop.addEventListener('mousedown', (event) => event.target === backdrop && close(false));
        cancel?.addEventListener('click', () => close(false));
        confirm.addEventListener('click', () => close(true));

        document.body.append(backdrop);
        confirm.focus();
    });
}

export function alertDialog(message, title = 'Dat lukte niet') {
    return confirmDialog({ title, body: message, confirmLabel: 'Oké', cancelLabel: null });
}

/** Waarschuwingen uit de planning tonen en om bevestiging vragen. */
export function confirmWarnings(warnings, intro = 'Deze wijziging geeft waarschuwingen.') {
    return confirmDialog({
        title: 'Toch doorgaan?',
        body: intro,
        items: warnings.map((w) => ({ level: w.level, text: (w.employee ? w.employee + ': ' : '') + w.message })),
        confirmLabel: 'Toch doorgaan',
    });
}

let toastHost = null;

export function toast(message, { action = null, type = 'info', timeout = 6000 } = {}) {
    if (!toastHost) {
        toastHost = el('div', 'toasts');
        toastHost.setAttribute('role', 'status');
        toastHost.setAttribute('aria-live', 'polite');
        document.body.append(toastHost);
    }
    const node = el('div', 'toast' + (type === 'error' ? ' is-error' : ''));
    node.append(el('span', '', message));
    const remove = () => node.remove();
    if (action) {
        const button = el('button', '', action.label);
        button.type = 'button';
        button.addEventListener('click', () => {
            remove();
            action.run();
        });
        node.append(button);
    }
    toastHost.append(node);
    window.setTimeout(remove, timeout);
    return remove;
}

export function errorMessage(e) {
    const data = e?.response?.data;
    if (data?.errors) {
        return Object.values(data.errors).flat().join(' ');
    }
    return data?.message || 'Er ging iets mis. Probeer het opnieuw.';
}
