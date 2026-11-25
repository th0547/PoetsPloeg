import { useMemo, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';

import { usePlanning } from '@/api/hooks';
import { DayNav } from '@/components/DayNav';
import { ShiftRow } from '@/components/ShiftRow';
import { EmptyRow, ErrorView, Loading, Section, styles } from '@/components/ui';
import { dayLabel, shiftDays, shortDayLabel, todayIso, weekStart } from '@/lib/dates';
import { groupBy, planningShifts } from '@/lib/planning';
import { spacing, useTheme } from '@/theme';

type View_ = 'day' | 'building' | 'employee';
const VIEWS: { key: View_; label: string }[] = [
  { key: 'day', label: 'Per dag' },
  { key: 'building', label: 'Per pand' },
  { key: 'employee', label: 'Per medewerker' },
];

/** Het rooster van een week als lijst: per dag, per pand of per medewerker. */
export default function RoosterScreen() {
  const t = useTheme();
  const [from, setFrom] = useState(weekStart(todayIso()));
  const [view, setView] = useState<View_>('day');
  const to = shiftDays(from, 6);
  const { data, error, isLoading, refetch, isRefetching } = usePlanning(from, to);

  const groups = useMemo(() => {
    if (!data) return [];
    const shifts = planningShifts(data);
    if (view === 'day') {
      return data.days.map((d) => ({
        key: d.date,
        title: `${dayLabel(d.date)}${d.holiday ? ` · ${d.holiday}` : ''}`,
        shifts: shifts.filter((s) => s.date === d.date),
        showDate: undefined as string | undefined,
      }));
    }
    if (view === 'building') {
      return groupBy(shifts, (s) => s.building?.name ?? 'Onbekend pand')
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([name, list]) => ({ key: name, title: name, shifts: list, showDate: 'day' }));
    }
    // Per medewerker; diensten met een open plek komen apart bovenaan.
    const open = shifts.filter((s) => s.open_slots > 0);
    const people = data.employees
      .map((e) => ({ key: String(e.id), title: e.name, shifts: shifts.filter((s) => s.assignments?.some((a) => a.employee.id === e.id)), showDate: 'day' }))
      .filter((g) => g.shifts.length);
    return [{ key: 'open', title: 'Open plekken', shifts: open, showDate: 'day' }, ...people];
  }, [data, view]);

  return (
    <ScrollView contentContainerStyle={styles.screen} refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} tintColor={t.primary} />}>
      <DayNav
        label={`${shortDayLabel(from)} – ${shortDayLabel(to)}`}
        onPrev={() => setFrom(shiftDays(from, -7))}
        onNext={() => setFrom(shiftDays(from, 7))}
        onToday={() => setFrom(weekStart(todayIso()))}
      />
      <View style={{ flexDirection: 'row', backgroundColor: t.card, borderRadius: 10, padding: 3, marginBottom: spacing.lg, borderColor: t.border, borderWidth: 0.5 }}>
        {VIEWS.map((v) => (
          <Pressable key={v.key} onPress={() => setView(v.key)} style={{ flex: 1, paddingVertical: 8, borderRadius: 8, backgroundColor: view === v.key ? t.primary : 'transparent' }}>
            <Text style={{ textAlign: 'center', color: view === v.key ? t.primaryText : t.text, fontWeight: '500' }}>{v.label}</Text>
          </Pressable>
        ))}
      </View>

      {isLoading ? <Loading /> : error || !data ? <ErrorView error={error} onRetry={refetch} /> : (
        groups.map((g) => (
          <Section key={g.key} title={`${g.title} (${g.shifts.length})`}>
            {g.shifts.length ? (
              g.shifts.map((s, i) => (
                <ShiftRow key={s.id} shift={s} last={i === g.shifts.length - 1} showDate={g.showDate ? shortDayLabel(s.date) : undefined} />
              ))
            ) : (
              <EmptyRow text={view === 'day' ? 'Geen diensten.' : 'Geen open plekken deze week.'} />
            )}
          </Section>
        ))
      )}
    </ScrollView>
  );
}
