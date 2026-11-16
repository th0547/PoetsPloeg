// Tests voor het rekenwerk van het roosterbord. Draaien met: npm test
import assert from 'node:assert/strict';
import { test } from 'node:test';
import * as L from '../../resources/js/board/layout.js';

const shift = (start_min, end_min, extra = {}) => ({ start_min, end_min, open: 0, level: null, assignments: [], hours: (end_min - start_min) / 60, ...extra });

test('periode: dag, week (ma t/m zo), maand en kalender (hele weken)', () => {
    assert.deepEqual(L.period('employee', 'day', '2026-11-18'), ['2026-11-18', '2026-11-18']);
    assert.deepEqual(L.period('employee', 'week', '2026-11-18'), ['2026-11-16', '2026-11-22']);
    assert.deepEqual(L.period('building', 'month', '2026-11-18'), ['2026-11-01', '2026-11-30']);
    // November 2026 begint op zondag: de kalender start op maandag 26 oktober en eindigt op zondag 6 december.
    assert.deepEqual(L.period('calendar', 'week', '2026-11-18'), ['2026-10-26', '2026-12-06']);
});

test('bladeren: een dag, een week of een maand verder', () => {
    assert.equal(L.stepDate('employee', 'day', '2026-11-30', 1), '2026-12-01');
    assert.equal(L.stepDate('employee', 'week', '2026-11-18', -1), '2026-11-11');
    assert.equal(L.stepDate('employee', 'month', '2026-01-31', 1), '2026-02-01');
    assert.equal(L.stepDate('calendar', 'week', '2026-12-15', 1), '2027-01-01');
});

test('dagen tellen over de wisseling naar wintertijd heen', () => {
    assert.equal(L.dayDiff('2026-10-24', '2026-10-26'), 2);
    assert.equal(L.dayDiff('2026-03-30', '2026-03-28'), -2);
    assert.equal(L.addDaysIso('2026-10-24', 2), '2026-10-26');
    assert.deepEqual(L.daysBetween('2026-10-24', '2026-10-26'), ['2026-10-24', '2026-10-25', '2026-10-26']);
});

test('weeknummer en weekdag', () => {
    assert.equal(L.isoWeek(L.parseDate('2026-11-16')), 47);
    assert.equal(L.isoWeek(L.parseDate('2027-01-01')), 53);
    assert.equal(L.weekday('2026-11-16'), 1);
    assert.equal(L.isWeekend('2026-11-21'), true);
    assert.equal(L.isWeekend('2026-11-20'), false);
});

test('tijdvenster van de dag: rond de diensten, op hele uren, minstens 8 uur', () => {
    assert.deepEqual(L.timeWindow([]), [360, 1200]);
    // 05:30–08:00 en 18:00–21:00 → 05:00 tot 22:00
    assert.deepEqual(L.timeWindow([shift(330, 480), shift(1080, 1260)]), [300, 1320]);
    // Eén korte dienst: het venster wordt opgerekt tot 8 uur.
    const [start, end] = L.timeWindow([shift(600, 660)]);
    assert.equal(end - start, 480);
    assert.ok(start <= 570 && end >= 690);
    // Nachtdienst die na middernacht eindigt.
    assert.deepEqual(L.timeWindow([shift(1320, 1500)]), [1080, 1560]);
});

test('overlappende balken krijgen elk een eigen baan', () => {
    const items = [shift(540, 660), shift(600, 720), shift(660, 780), shift(900, 960)];
    assert.equal(L.packLanes(items), 2);
    assert.deepEqual(items.map((i) => i.lane), [0, 1, 0, 0]);
    assert.equal(L.packLanes([]), 1);
});

test('afwezigheid: welk deel van de dag', () => {
    const absence = { from_day: '2026-11-16', to_day: '2026-11-18', from_min: 720, to_min: 1440 };
    assert.equal(L.absenceOn(absence, '2026-11-15'), null);
    assert.deepEqual(L.absenceOn(absence, '2026-11-16'), { from: 720, to: 1440, whole: false });
    assert.deepEqual(L.absenceOn(absence, '2026-11-17'), { from: 0, to: 1440, whole: true });
    assert.equal(L.absenceOn(absence, '2026-11-19'), null);
});

test('status nu: afwezig, bezig, straks, klaar of vrij', () => {
    const buildings = new Map([[1, { name: 'Kantoor' }]]);
    const morning = shift(540, 660, { building_id: 1, start: '09:00', end: '11:00' });
    const evening = shift(1080, 1200, { building_id: 1, start: '18:00', end: '20:00' });
    const at = (min, absences = []) => L.statusAt([evening, morning], absences, '2026-11-16', min, buildings);

    assert.deepEqual([at(600).state, at(600).label], ['working', 'Nu bij Kantoor tot 11:00']);
    assert.deepEqual([at(700).state, at(700).label], ['later', 'Straks 18:00 Kantoor']);
    assert.equal(at(1300).state, 'done');
    assert.equal(L.statusAt([], [], '2026-11-16', 600, buildings).state, 'free');

    const sick = { from_day: '2026-11-16', to_day: '2026-11-16', from_min: 0, to_min: 1440, label: 'Ziek', pending: false };
    assert.deepEqual([at(600, [sick]).state, at(600, [sick]).label], ['absent', 'Ziek']);
    // Een verlofverzoek dat nog wacht telt niet als afwezig.
    assert.equal(at(600, [{ ...sick, pending: true }]).state, 'working');
});

test('samenvatting en aandachtspunten', () => {
    const shifts = [
        shift(0, 60, { open: 2 }),
        shift(0, 60, { level: 'danger', assignments: [{ employee_id: 1, level: 'danger' }, { employee_id: 2, level: 'warning' }] }),
        shift(0, 60, { level: 'info', assignments: [{ employee_id: 3, level: 'info' }] }),
    ];
    const absences = [{ employee_id: 1, pending: false }, { employee_id: 1, pending: false }, { employee_id: 2, pending: true }];
    assert.deepEqual(L.summarise(shifts, absences), { shifts: 3, open: 2, conflicts: 1, warnings: 1, absent: 1, requests: 1 });
    assert.deepEqual(shifts.map(L.needsAttention), [true, true, false]);
    assert.equal(L.plannedHours(1, shifts), 1);
});

test('tijden afronden op een kwartier', () => {
    assert.equal(L.snap15(607), 600);
    assert.equal(L.snap15(608), 615);
});
