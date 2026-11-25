import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';

import { useOpenShifts } from '@/api/hooks';
import { ShiftRow } from '@/components/ShiftRow';
import { ErrorView, Loading, Notice, Section, styles } from '@/components/ui';
import { dayLabel } from '@/lib/dates';
import { groupBy } from '@/lib/planning';
import { spacing, useTheme } from '@/theme';

const PERIODS = [1, 2, 4, 8];

/** Alle diensten met open plekken, per dag. Tik op een dienst om iemand in te plannen. */
export default function OpenScreen() {
  const t = useTheme();
  const [weeks, setWeeks] = useState(2);
  const { data, error, isLoading, refetch, isRefetching } = useOpenShifts(weeks);

  return (
    <ScrollView contentContainerStyle={styles.screen} refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} tintColor={t.primary} />}>
      <View style={{ flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.lg }}>
        {PERIODS.map((w) => (
          <Pressable
            key={w}
            onPress={() => setWeeks(w)}
            style={{ flex: 1, paddingVertical: 8, borderRadius: 8, borderWidth: 0.5, borderColor: t.border, backgroundColor: weeks === w ? t.primary : t.card }}
          >
            <Text style={{ textAlign: 'center', color: weeks === w ? t.primaryText : t.text }}>{w === 1 ? '1 week' : `${w} weken`}</Text>
          </Pressable>
        ))}
      </View>

      {isLoading ? <Loading /> : error || !data ? <ErrorView error={error} onRetry={refetch} /> : (
        <>
          <Notice
            level={data.slot_count ? 'warning' : 'success'}
            text={data.slot_count ? `${data.slot_count} open ${data.slot_count === 1 ? 'plek' : 'plekken'} in ${data.shift_count} ${data.shift_count === 1 ? 'dienst' : 'diensten'}.` : 'Alle diensten zijn bezet.'}
          />
          {groupBy(data.shifts, (s) => s.date).map(([date, shifts]) => (
            <Section key={date} title={dayLabel(date)}>
              {shifts.map((s, i) => (
                <View key={s.id}>
                  <ShiftRow shift={s} last={i === shifts.length - 1 && !s.fixed_missing.length} />
                  {s.fixed_missing.length ? (
                    <Text style={{ color: t.warning, paddingHorizontal: spacing.md, paddingBottom: spacing.md, marginTop: -spacing.sm }}>
                      Vaste kracht: {s.fixed_missing.map((m) => `${m.name} (${m.reason})`).join(', ')}
                    </Text>
                  ) : null}
                </View>
              ))}
            </Section>
          ))}
        </>
      )}
    </ScrollView>
  );
}
