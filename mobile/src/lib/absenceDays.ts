import { shiftDays } from './dates';

export interface DayInput {
  date: string;
  start_time?: string;
  end_time?: string;
}

const DATE = /^\d{4}-\d{2}-\d{2}$/;
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;

/**
 * Van een periode (van t/m) de lijst dagen voor POST absences. Zonder tijden: hele dagen.
 * Met tijden gelden die voor elke dag (bijv. elke ochtend 08:00–12:00).
 */
export function daysForRange(from: string, to: string, times?: { start: string; end: string }): DayInput[] {
  if (!DATE.test(from) || !DATE.test(to) || to < from) return [];
  const days: DayInput[] = [];
  for (let d = from; d <= to && days.length < 366; d = shiftDays(d, 1)) {
    days.push(times ? { date: d, start_time: times.start, end_time: times.end } : { date: d });
  }
  return days;
}

/** Een foutmelding voor het formulier, of null als het klopt. */
export function validateRange(from: string, to: string, times?: { start: string; end: string }): string | null {
  if (!DATE.test(from)) return 'Vul de begindatum in als JJJJ-MM-DD.';
  if (!DATE.test(to)) return 'Vul de einddatum in als JJJJ-MM-DD.';
  if (to < from) return 'De einddatum ligt vóór de begindatum.';
  if (times) {
    if (!TIME.test(times.start) || !TIME.test(times.end)) return 'Vul de tijden in als UU:MM.';
    if (times.end <= times.start) return 'De eindtijd moet na de begintijd liggen.';
  }
  return null;
}
