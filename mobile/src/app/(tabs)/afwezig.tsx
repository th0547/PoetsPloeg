import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { router } from 'expo-router';

import { useAbsences } from '@/api/hooks';
import type { Absence } from '@/api/types';
import { Badge, Button, Chip, EmptyRow, ErrorView, Loading, Row, Section, styles } from '@/components/ui';
import { spacing, useTheme } from '@/theme';

function AbsenceRows({ items, empty }: { items: Absence[]; empty: string }) {
  const t = useTheme();
  if (!items.length) return <EmptyRow text={empty} />;
  return (
    <>
      {items.map((a, i) => (
        <Row key={a.id} last={i === items.length - 1} onPress={() => router.push({ pathname: '/absence/[id]', params: { id: String(a.id) } })}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.sm }}>
            {a.employee ? <Chip employee={a.employee} small /> : null}
            <View style={{ flex: 1 }}>
              <Text style={{ color: t.text, fontWeight: '500' }}>{a.employee?.name} · {a.title}</Text>
              <Text style={{ color: t.muted }}>{a.period}</Text>
            </View>
            {a.status === 'pending' && a.shift_count ? <Badge text={`${a.shift_count} dienst${a.shift_count === 1 ? '' : 'en'}`} level="warning" /> : null}
          </View>
        </Row>
      ))}
    </>
  );
}

/** Verlofverzoeken die op een vervanger wachten, en lopende en geplande afwezigheid. */
export default function AbsencesScreen() {
  const t = useTheme();
  const pending = useAbsences({ status: 'pending', period: 'current' });
  const approved = useAbsences({ status: 'approved', period: 'current' });
  const refreshing = pending.isRefetching || approved.isRefetching;
  const refresh = async () => {
    await Promise.all([pending.refetch(), approved.refetch()]);
  };

  return (
    <ScrollView contentContainerStyle={styles.screen} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={t.primary} />}>
      <View style={{ marginBottom: spacing.lg }}>
        <Button title="Uitval of verlof melden" onPress={() => router.push('/absence/new')} />
      </View>
      {pending.isLoading || approved.isLoading ? <Loading /> : pending.error || approved.error ? (
        <ErrorView error={pending.error ?? approved.error} onRetry={refresh} />
      ) : (
        <>
          <Section title={`Verzoeken: vervanger nodig (${pending.data?.meta.total ?? 0})`}>
            <AbsenceRows items={pending.data?.data ?? []} empty="Geen openstaande verzoeken." />
          </Section>
          <Section title="Nu en gepland">
            <AbsenceRows items={approved.data?.data ?? []} empty="Niemand afwezig." />
          </Section>
        </>
      )}
    </ScrollView>
  );
}
