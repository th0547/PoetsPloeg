import type { Planning, ShiftSummary } from '@/api/types';

/** Zwarte of witte tekst op een achtergrondkleur (zoals App\Support\Planning::contrast). */
export function contrast(hex: string | null | undefined): string {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex ?? '');
  if (!m) return '#ffffff';
  const n = parseInt(m[1], 16);
  const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  return (r * 299 + g * 587 + b * 114) / 1000 > 150 ? '#111827' : '#ffffff';
}

/** De diensten van het roosterbord in de vorm van een lijst-dienst, zodat ShiftRow ze kan tonen. */
export function planningShifts(planning: Planning): ShiftSummary[] {
  const buildings = new Map(planning.buildings.map((b) => [b.id, b]));
  const employees = new Map(planning.employees.map((e) => [e.id, e]));

  return planning.shifts.map((s) => {
    const b = buildings.get(s.building_id);
    return {
      id: s.id,
      title: s.title,
      date: s.day,
      starts_at: `${s.day}T${s.start}`,
      ends_at: `${s.day}T${s.end}`,
      start_time: s.start,
      end_time: s.end,
      required_staff: s.required,
      open_slots: s.open,
      cancelled: false,
      state: 'later',
      building: b ? { id: b.id, name: b.name, short_name: b.short, color: b.color, ink: contrast(b.color) } : undefined,
      assignments: s.assignments.flatMap((a) => {
        const e = employees.get(a.employee_id);
        return e
          ? [{ employee: { id: e.id, name: e.name, color: e.color, initials: e.initials, ink: contrast(e.color) }, source: a.source, warnings: a.warnings }]
          : [];
      }),
    };
  });
}

/** Groepeer op een sleutel, met behoud van volgorde. */
export function groupBy<T>(items: T[], key: (item: T) => string): [string, T[]][] {
  const map = new Map<string, T[]>();
  for (const item of items) {
    const k = key(item);
    map.set(k, [...(map.get(k) ?? []), item]);
  }
  return [...map.entries()];
}
