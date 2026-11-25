import { useState } from 'react';
import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { router } from 'expo-router';

import { useToday } from '@/api/hooks';
import type { ShiftSummary } from '@/api/types';
import { DayNav, dayNavLabel } from '@/components/DayNav';
import { ShiftRow } from '@/components/ShiftRow';
import { Badge, Chip, EmptyRow, ErrorView, Loading, Row, Section, styles } from '@/components/ui';
import { shiftDays, shortDayLabel, todayIso } from '@/lib/dates';
import { spacing, useTheme } from '@/theme';

function Shifts({ title, shifts, empty }: { title: string; shifts: ShiftSummary[]; empty?: string }) {
  if (!shifts.length && !empty) return null;
  return (
    <Section title={`${title} (${shifts.length})`}>
      {shifts.length ? shifts.map((s, i) => <ShiftRow key={s.id} shift={s} last={i === shifts.length - 1} />) : <EmptyRow text={empty!} />}
    </Section>
  );
}

function Tile({ value, label, tone }: { value: number; label: string; tone?: 'danger' | 'warning' }) {
  const t = useTheme();
  const color = tone === 'danger' && value > 0 ? t.danger : tone === 'warning' && value > 0 ? t.warning : t.text;
  return (
    <View style={{ flexGrow: 1, flexBasis: '45%', backgroundColor: t.card, borderRadius: 12, padding: spacing.md, borderColor: t.border, borderWidth: 0.5 }}>
      <Text style={{ fontSize: 24, fontWeight: '700', color, fontVariant: ['tabular-nums'] }}>{value}</Text>
      <Text style={{ color: t.muted }}>{label}</Text>
    </View>
  );
}

export default function TodayScreen() {
  const t = useTheme();
  const [date, setDate] = useState(todayIso());
  const { data, error, isLoading, refetch, isRefetching } = useToday(date);

  return (
    <ScrollView
      contentContainerStyle={styles.screen}
      refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} tintColor={t.primary} />}
    >
      <DayNav label={dayNavLabel(date)} onPrev={() => setDate(shiftDays(date, -1))} onNext={() => setDate(shiftDays(date, 1))} onToday={() => setDate(todayIso())} />

      {isLoading ? <Loading /> : error || !data ? <ErrorView error={error} onRetry={refetch} /> : (
        <>
          {data.holiday ? <Text style={{ color: t.warning, textAlign: 'center', marginTop: -spacing.sm, marginBottom: spacing.md }}>{data.holiday}</Text> : null}

          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm, marginBottom: spacing.lg }}>
            {data.is_today ? <Tile value={data.counts.working_now} label="nu aan het werk" /> : <Tile value={data.counts.shifts} label="diensten" />}
            <Tile value={data.counts.open_today} label={data.is_today ? 'open plekken vandaag' : 'open plekken'} tone="danger" />
            <Tile value={data.counts.absent} label="afwezig" />
            <Tile value={data.counts.warnings} label="waarschuwingen" tone="warning" />
          </View>

          {data.pending_requests.length ? (
            <Section title={`Verlofverzoeken (${data.pending_requests.length})`}>
              {data.pending_requests.slice(0, 6).map((a, i, list) => (
                <Row key={a.id} last={i === list.length - 1} onPress={() => router.push({ pathname: '/absence/[id]', params: { id: String(a.id) } })}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.sm }}>
                    {a.employee ? <Chip employee={a.employee} small /> : null}
                    <View style={{ flex: 1 }}>
                      <Text style={{ color: t.text, fontWeight: '500' }}>{a.employee?.name} · {a.title}</Text>
                      <Text style={{ color: t.muted }}>{a.period}</Text>
                    </View>
                    {a.shift_count ? <Badge text={`${a.shift_count} dienst${a.shift_count === 1 ? '' : 'en'}`} level="warning" /> : null}
                  </View>
                </Row>
              ))}
            </Section>
          ) : null}

          {data.is_today ? (
            <>
              <Shifts title="Nu bezig" shifts={data.current} empty="Er is nu niemand aan het werk." />
              <Shifts title="Straks" shifts={data.later} />
              <Shifts title="Afgerond" shifts={data.finished} />
            </>
          ) : (
            <Shifts title="Diensten" shifts={data.later} empty="Geen diensten op deze dag." />
          )}

          {data.absent.length ? (
            <Section title={`Afwezig (${data.absent.length})`}>
              {data.absent.map((a, i) => (
                <Row key={a.id} last={i === data.absent.length - 1} onPress={() => router.push({ pathname: '/absence/[id]', params: { id: String(a.id) } })}>
                  <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.sm }}>
                    {a.employee ? <Chip employee={a.employee} small /> : null}
                    <Text style={{ color: t.text, flex: 1 }}>{a.employee?.name}</Text>
                    <Text style={{ color: t.muted }}>{a.type_label}</Text>
                  </View>
                </Row>
              ))}
            </Section>
          ) : null}

          {data.open_shifts.length ? (
            <Section title={`Open diensten, komende 14 dagen (${data.counts.open_slots_14_days} plekken)`}>
              {data.open_shifts.slice(0, 8).map((s, i, list) => (
                <Row key={s.id} last={i === list.length - 1} onPress={() => router.push({ pathname: '/shift/[id]', params: { id: String(s.id) } })}>
                  <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: spacing.sm }}>
                    <Text style={{ color: t.text, flex: 1 }} numberOfLines={1}>{s.building.name}</Text>
                    <Text style={{ color: t.muted }}>{shortDayLabel(s.date)} {s.start_time}</Text>
                    <Badge text={`${s.open_slots} open`} level="danger" />
                  </View>
                </Row>
              ))}
            </Section>
          ) : null}
        </>
      )}
    </ScrollView>
  );
}
