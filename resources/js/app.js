import './bootstrap';

import Alpine from 'alpinejs';
import absenceForm from './absence-form';
import registerActions from './actions';
import availabilityEditor from './availability';
import policyForm from './policy';
import recurrenceEditor, { aiPrompt, recurrencePlayground } from './recurrence';
import shiftPanel, { openShift } from './shift-panel';

window.Alpine = Alpine;
window.openShift = openShift;
registerActions();

Alpine.data('shiftPanel', shiftPanel);
Alpine.data('availabilityEditor', availabilityEditor);
Alpine.data('absenceForm', absenceForm);
Alpine.data('recurrenceEditor', recurrenceEditor);
Alpine.data('recurrencePlayground', recurrencePlayground);
Alpine.data('aiPrompt', aiPrompt);
Alpine.data('policyForm', policyForm);

// Pagina-specifieke modules (planning, kalender) registreren hun componenten eerst.
document.addEventListener('DOMContentLoaded', () => Alpine.start());
