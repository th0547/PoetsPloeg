import './bootstrap';

import Alpine from 'alpinejs';
import shiftPanel, { openShift } from './shift-panel';

window.Alpine = Alpine;
window.openShift = openShift;

Alpine.data('shiftPanel', shiftPanel);

// Pagina-specifieke modules (planning, kalender) registreren hun componenten eerst.
document.addEventListener('DOMContentLoaded', () => Alpine.start());
