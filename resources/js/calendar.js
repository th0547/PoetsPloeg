import { Calendar } from '@fullcalendar/core';
import nlLocale from '@fullcalendar/core/locales/nl';
import dayGridPlugin from '@fullcalendar/daygrid';
import { openShift } from './shift-panel';

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('calendar');
    if (!el) return;

    const calendar = new Calendar(el, {
        plugins: [dayGridPlugin],
        locale: nlLocale,
        initialView: 'dayGridMonth',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,dayGridWeek' },
        firstDay: 1,
        weekNumbers: true,
        height: 'auto',
        dayMaxEvents: 6,
        eventDisplay: 'block',
        displayEventTime: false, // de begintijd staat al in de titel
        events: '/api/calendar',
        eventClick: (info) => {
            const id = info.event.extendedProps.shiftId;
            if (id) {
                info.jsEvent.preventDefault();
                openShift(id);
            }
        },
    });
    calendar.render();

    window.addEventListener('shifts-changed', () => calendar.refetchEvents());
});
