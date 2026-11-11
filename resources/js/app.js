import './bootstrap';

import Alpine from 'alpinejs';
import absenceForm from './absence-form';
import availabilityEditor from './availability';
import recurrenceEditor from './recurrence';
import shiftPanel, { openShift } from './shift-panel';

window.Alpine = Alpine;
window.openShift = openShift;

Alpine.data('shiftPanel', shiftPanel);
Alpine.data('availabilityEditor', availabilityEditor);
Alpine.data('absenceForm', absenceForm);
Alpine.data('recurrenceEditor', recurrenceEditor);

// Pagina-specifieke modules (planning, kalender) registreren hun componenten eerst.
document.addEventListener('DOMContentLoaded', () => Alpine.start());
