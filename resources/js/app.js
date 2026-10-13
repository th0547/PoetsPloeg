import './bootstrap';

import Alpine from 'alpinejs';
import availabilityEditor from './availability';
import shiftPanel, { openShift } from './shift-panel';

window.Alpine = Alpine;
window.openShift = openShift;

Alpine.data('shiftPanel', shiftPanel);
Alpine.data('availabilityEditor', availabilityEditor);

// Pagina-specifieke modules (planning, kalender) registreren hun componenten eerst.
document.addEventListener('DOMContentLoaded', () => Alpine.start());
