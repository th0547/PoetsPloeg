import { describe, expect, it } from '@jest/globals';
import { daysForRange, validateRange } from '@/lib/absenceDays';
import { relativeDayLabel, shiftDays, weekStart } from '@/lib/dates';
import { contrast, groupBy, planningShifts } from '@/lib/planning';
import type { Planning } from '@/api/types';

describe('dates', () => {
  it('rekent dagen en weken', () => {
    expect(shiftDays('2026-12-31', 1)).toBe('2027-01-01');
    expect(weekStart('2026-11-29')).toBe('2026-11-23'); // zondag hoort bij de week vanaf maandag
    expect(relativeDayLabel('2026-11-25', '2026-11-25')).toBe('Vandaag');
    expect(relativeDayLabel('2026-11-26', '2026-11-25')).toBe('Morgen');
    expect(relativeDayLabel('2026-11-27', '2026-11-25')).toBe('vrijdag 27 november');
  });
});

describe('absenceDays', () => {
  it('maakt een dag per datum, met of zonder tijden', () => {
    expect(daysForRange('2026-11-30', '2026-12-02')).toEqual([{ date: '2026-11-30' }, { date: '2026-12-01' }, { date: '2026-12-02' }]);
    expect(daysForRange('2026-11-30', '2026-11-30', { start: '08:00', end: '12:00' })).toEqual([
      { date: '2026-11-30', start_time: '08:00', end_time: '12:00' },
    ]);
    expect(daysForRange('2026-12-02', '2026-11-30')).toEqual([]);
  });

  it('controleert de invoer', () => {
    expect(validateRange('2026-11-30', '2026-11-30')).toBeNull();
    expect(validateRange('30-11-2026', '2026-11-30')).toMatch(/begindatum/);
    expect(validateRange('2026-12-01', '2026-11-30')).toMatch(/vóór/);
    expect(validateRange('2026-11-30', '2026-11-30', { start: '12:00', end: '08:00' })).toMatch(/eindtijd/);
    expect(validateRange('2026-11-30', '2026-11-30', { start: '8', end: '12:00' })).toMatch(/UU:MM/);
  });
});

describe('planning', () => {
  it('kiest leesbare tekstkleur', () => {
    expect(contrast('#ffffff')).toBe('#111827');
    expect(contrast('#0f766e')).toBe('#ffffff');
    expect(contrast(null)).toBe('#ffffff');
  });

  it('zet roosterdiensten om naar lijst-diensten', () => {
    const planning = {
      from: '2026-11-23', to: '2026-11-29', today: '2026-11-25', days: [],
      employees: [{ id: 5, name: 'Anna Jansen', color: '#ffffff', initials: 'AJ', active: true }],
      buildings: [{ id: 3, name: 'ReggeIT', short: 'Regge', color: '#0f766e', city: null }],
      absences: [],
      shifts: [{ id: 1, building_id: 3, title: 'Kantoor', day: '2026-11-25', start: '08:00', end: '10:00', required: 2, open: 1, level: null,
        assignments: [{ employee_id: 5, source: 'fixed', level: null, warnings: [] }] }],
    } as Planning;

    const [shift] = planningShifts(planning);
    expect(shift.building?.name).toBe('ReggeIT');
    expect(shift.open_slots).toBe(1);
    expect(shift.assignments?.[0].employee).toEqual({ id: 5, name: 'Anna Jansen', color: '#ffffff', initials: 'AJ', ink: '#111827' });
    expect(groupBy([1, 2, 3, 4], (n) => (n % 2 ? 'oneven' : 'even'))).toEqual([['oneven', [1, 3]], ['even', [2, 4]]]);
  });
});
