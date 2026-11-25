import { addDays, format, parseISO, startOfWeek } from 'date-fns';
import { nl } from 'date-fns/locale';

/** JJJJ-MM-DD van een datum, in de tijdzone van het toestel. */
export function isoDate(date: Date): string {
  return format(date, 'yyyy-MM-dd');
}

export function todayIso(): string {
  return isoDate(new Date());
}

export function shiftDays(iso: string, days: number): string {
  return isoDate(addDays(parseISO(iso), days));
}

/** Maandag van de week waarin de datum valt. */
export function weekStart(iso: string): string {
  return isoDate(startOfWeek(parseISO(iso), { weekStartsOn: 1 }));
}

/** "woensdag 25 november" (of met jaar als het een ander jaar is). */
export function dayLabel(iso: string, today: string = todayIso()): string {
  const date = parseISO(iso);
  const sameYear = iso.slice(0, 4) === today.slice(0, 4);
  return format(date, sameYear ? 'EEEE d MMMM' : 'EEEE d MMMM yyyy', { locale: nl });
}

/** "wo 25 nov" */
export function shortDayLabel(iso: string): string {
  return format(parseISO(iso), 'EEEEEE d MMM', { locale: nl });
}

/** "Vandaag", "Morgen", "Gisteren" of de datum. */
export function relativeDayLabel(iso: string, today: string = todayIso()): string {
  if (iso === today) return 'Vandaag';
  if (iso === shiftDays(today, 1)) return 'Morgen';
  if (iso === shiftDays(today, -1)) return 'Gisteren';
  return dayLabel(iso, today);
}
