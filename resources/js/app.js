import './bootstrap';

import Alpine from 'alpinejs';
import absenceForm from './absence-form';
import registerActions from './actions';
import availabilityEditor from './availability';
import employeePanel, { openEmployee } from './employee-panel';
import policyForm from './policy';
import recurrenceEditor, { aiPrompt, recurrencePlayground } from './recurrence';
import replaceCard from './replace-card';
import shiftPanel, { openShift } from './shift-panel';
import { staffingTable } from './staffing';
import palette from './ui/palette';

window.Alpine = Alpine;
window.openShift = openShift;
window.openEmployee = openEmployee;
registerActions();

/** Schil: zijbalk in- en uitklappen (voorkeur in een cookie, zodat de server hem direct goed tekent). */
Alpine.data('appShell', (collapsed = false) => ({
    collapsed,
    open: false,
    toggle() {
        this.collapsed = !this.collapsed;
        document.cookie = `pp_nav=${this.collapsed ? 1 : 0}; path=/; max-age=31536000; samesite=lax`;
        // Het rooster meet zijn beschikbare ruimte opnieuw.
        window.setTimeout(() => window.dispatchEvent(new Event('resize')), 220);
    },
}));

Alpine.data('shiftPanel', shiftPanel);
Alpine.data('employeePanel', employeePanel);
Alpine.data('palette', palette);
Alpine.data('replaceCard', replaceCard);
Alpine.data('staffingTable', staffingTable);
Alpine.data('availabilityEditor', availabilityEditor);
Alpine.data('absenceForm', absenceForm);
Alpine.data('recurrenceEditor', recurrenceEditor);
Alpine.data('recurrencePlayground', recurrencePlayground);
Alpine.data('aiPrompt', aiPrompt);
Alpine.data('policyForm', policyForm);

// Pagina-specifieke modules (planning) registreren hun componenten eerst.
document.addEventListener('DOMContentLoaded', () => Alpine.start());
