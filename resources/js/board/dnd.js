/**
 * Slepen in het rooster met pointer-events (muis, pen en aanraking).
 *
 * - Week/maand/kalender: een dienst naar een andere medewerker en/of dag slepen.
 * - Dagweergave: horizontaal slepen verschuift de tijd, de randen verslepen wijzigt begin of eind
 *   (per kwartier); naar een andere rij slepen wisselt de medewerker.
 * - Aanraking: even vasthouden start het slepen, zodat vegen gewoon blijft scrollen.
 *
 * Het bord (index.js) bepaalt wat een geldige plek is en voert de wijziging uit.
 */
import { clock } from '../ui/dom';
import { dayDiff, snap15 } from './layout';

const MOVE_THRESHOLD = 5;
const TOUCH_HOLD_MS = 350;

export default function attachDnd(root, board) {
    let drag = null;

    const targetAt = (x, y) => document.elementFromPoint(x, y)?.closest('.bg-cell, .tl-track, .cal-day') ?? null;

    function clearDrop() {
        root.querySelectorAll('.is-drop, .is-nodrop').forEach((el) => el.classList.remove('is-drop', 'is-nodrop'));
    }

    function finish() {
        if (!drag) return;
        window.clearTimeout(drag.timer);
        drag.ghost?.remove();
        drag.el.classList.remove('drag-src');
        if (drag.mode !== 'move' && drag.original) {
            drag.el.style.left = drag.original.left;
            drag.el.style.width = drag.original.width;
        }
        clearDrop();
        root.classList.remove('is-dragging');
        document.removeEventListener('touchmove', blockScroll, { passive: false });
        window.cancelAnimationFrame(drag.scrollFrame);
        if (drag.active) board.dragEnd();
        drag = null;
    }

    const blockScroll = (event) => event.preventDefault();

    function activate() {
        if (!drag || drag.active) return;
        drag.active = true;
        root.classList.add('is-dragging');
        drag.el.classList.add('drag-src');
        document.addEventListener('touchmove', blockScroll, { passive: false });
        board.dragStart(drag.shift, drag.fromEmp);

        if (drag.mode === 'move') {
            const rect = drag.el.getBoundingClientRect();
            const ghost = drag.el.cloneNode(true);
            ghost.classList.add('drag-ghost');
            // Buiten het rooster gelden de lettergrootte en kleuren van het bord niet vanzelf.
            ghost.style.fontSize = getComputedStyle(drag.el).fontSize;
            ghost.style.width = rect.width + 'px';
            ghost.style.height = rect.height + 'px';
            ghost.style.left = '0';
            ghost.style.top = '0';
            drag.offset = { x: drag.startX - rect.left, y: drag.startY - rect.top };
            const label = document.createElement('span');
            label.className = 'drag-label';
            ghost.append(label);
            document.body.append(ghost);
            drag.ghost = ghost;
            drag.label = label;
        } else {
            drag.original = { left: drag.el.style.left, width: drag.el.style.width };
        }
    }

    /** Minuten per pixel in de dagweergave. */
    function minutesPerPixel(track) {
        const [start, end] = board.window();
        return (end - start) / track.getBoundingClientRect().width;
    }

    /** Scrol mee als de aanwijzer bij de rand van het rooster komt. */
    function autoScroll(x, y) {
        const scroller = board.scroller();
        const rect = scroller.getBoundingClientRect();
        const edge = 48;
        const dx = x < rect.left + edge ? -1 : (x > rect.right - edge ? 1 : 0);
        const dy = y < rect.top + edge ? -1 : (y > rect.bottom - edge ? 1 : 0);
        window.cancelAnimationFrame(drag.scrollFrame);
        if (!dx && !dy) return;
        const step = () => {
            if (!drag?.active) return;
            scroller.scrollBy(dx * 12, dy * 12);
            drag.scrollFrame = window.requestAnimationFrame(step);
        };
        drag.scrollFrame = window.requestAnimationFrame(step);
    }

    function update(event) {
        const { clientX: x, clientY: y } = event;
        drag.intent = null;
        clearDrop();
        autoScroll(x, y);

        if (drag.mode !== 'move') {
            // Begin of eind verslepen (dagweergave).
            const track = drag.el.closest('.tl-track');
            const delta = snap15((x - drag.startX) * minutesPerPixel(track));
            const [winStart, winEnd] = board.window();
            let start = drag.shift.start_min;
            let end = drag.shift.end_min;
            if (drag.mode === 'start') start = Math.min(end - 15, Math.max(winStart, start + delta));
            else end = Math.max(start + 15, Math.min(winEnd, end + delta));
            const length = winEnd - winStart;
            drag.el.style.left = ((start - winStart) / length) * 100 + '%';
            drag.el.style.width = ((end - start) / length) * 100 + '%';
            const time = drag.el.querySelector('.sc-time');
            if (time) time.textContent = `${clock(start)}–${clock(end)}`;
            if (start !== drag.shift.start_min || end !== drag.shift.end_min) {
                drag.intent = { toRow: drag.fromRow, dayDelta: 0, start, end };
            }
            return;
        }

        drag.ghost.style.transform = `translate(${x - drag.offset.x}px, ${y - drag.offset.y}px)`;
        const target = targetAt(x, y);
        if (!target || !root.contains(target)) {
            drag.label.textContent = '';
            return;
        }

        const toRow = target.dataset.row;
        const intent = { toRow, dayDelta: dayDiff(drag.shift.day, target.dataset.day) };
        if (target.classList.contains('tl-track')) {
            // Dagweergave: de tijd schuift mee met de horizontale beweging.
            const delta = snap15((x - drag.startX) * minutesPerPixel(target));
            const length = drag.shift.end_min - drag.shift.start_min;
            const [winStart, winEnd] = board.window();
            const start = Math.max(winStart, Math.min(winEnd - length, drag.shift.start_min + delta));
            intent.dayDelta = 0;
            if (start !== drag.shift.start_min) {
                intent.start = start;
                intent.end = start + length;
            }
        }

        const verdict = board.canDrop(drag.shift, drag.fromRow, intent);
        target.classList.add(verdict.ok ? 'is-drop' : 'is-nodrop');
        drag.label.textContent = verdict.label ?? '';
        drag.intent = verdict.ok && verdict.changed ? intent : null;
    }

    root.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || drag) return;
        const el = event.target.closest('[data-shift]');
        if (!el || !root.contains(el)) return;
        const shift = board.shift(Number(el.dataset.shift));
        if (!shift) return;

        const handle = event.target.closest('[data-resize]');
        const container = el.closest('.bg-cell, .tl-track, .cal-day');
        drag = {
            el,
            shift,
            fromEmp: el.dataset.emp ? Number(el.dataset.emp) : null,
            fromRow: container?.dataset.row ?? null,
            mode: handle && event.pointerType !== 'touch' ? handle.dataset.resize : 'move',
            startX: event.clientX,
            startY: event.clientY,
            pointerId: event.pointerId,
            touch: event.pointerType === 'touch',
            active: false,
            intent: null,
        };
        if (drag.touch) {
            drag.timer = window.setTimeout(() => {
                activate();
                navigator.vibrate?.(15);
            }, TOUCH_HOLD_MS);
        }
    });

    window.addEventListener('pointermove', (event) => {
        if (!drag || event.pointerId !== drag.pointerId) return;
        const moved = Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY);
        if (!drag.active) {
            if (drag.touch) {
                // Bewogen voordat het vasthouden klaar was: dat is scrollen, geen slepen.
                if (moved > 8) finish();
                return;
            }
            if (moved < MOVE_THRESHOLD) return;
            activate();
        }
        event.preventDefault();
        update(event);
    });

    window.addEventListener('pointerup', (event) => {
        if (!drag || event.pointerId !== drag.pointerId) return;
        const { active, intent, shift, fromEmp, fromRow, el } = drag;
        if (active) {
            // De klik die op dit loslaten volgt mag het paneel niet openen.
            const swallow = (e) => {
                e.stopPropagation();
                e.preventDefault();
            };
            window.addEventListener('click', swallow, { capture: true, once: true });
            window.setTimeout(() => window.removeEventListener('click', swallow, { capture: true }), 50);
        }
        // Bij het verslepen van een rand blijft de nieuwe maat staan tot het bord opnieuw tekent.
        if (active && intent && drag.mode !== 'move') drag.original = null;
        finish();
        if (active && intent) board.drop(shift, fromEmp, fromRow, intent, el);
    });

    window.addEventListener('pointercancel', (event) => {
        if (drag && event.pointerId === drag.pointerId) finish();
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && drag?.active) {
            event.stopPropagation();
            finish();
        }
    }, true);
}
