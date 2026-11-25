import { useState } from 'react';
import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import { useAbsence, useRejectAbsence, useWithdrawAbsence } from '@/api/hooks';
import { CandidateList } from '@/components/CandidateList';
import { ShiftRow } from '@/components/ShiftRow';
import { Badge, Button, Chip, EmptyRow, ErrorView, Loading, Notice, Row, Section, styles } from '@/components/ui';
import { confirm, notify } from '@/lib/confirm';
import { spacing, useTheme } from '@/theme';

const STATE = {
  todo: { text: 'Vervanger nodig', level: 'warning' as const },
  open: { text: 'Plek open', level: 'danger' as const },
  done: { text: 'Geregeld', level: 'success' as const },
};

/**
 * Het vervangscherm van een afwezigheid of verlofverzoek: per dienst de stand, en per dienst
 * direct een vervanger kiezen. Bij een verzoek: afwijzen kan ook.
 */
export default function AbsenceScreen() {
  const t = useTheme();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const { data, error, isLoading, refetch, isRefetching } = useAbsence(id);
  const reject = useRejectAbsence(id);
  const withdraw = useWithdrawAbsence(id);
  const [open, setOpen] = useState<number | null>(null);

  if (isLoading) return <Loading />;
  if (error || !data) return <ErrorView error={error} onRetry={refetch} />;
  const { absence, shifts } = data;
  const pending = absence.status === 'pending';
  const todo = shifts.filter((s) => s.state !== 'done').length;

  const doReject = async () => {
    if (!(await confirm('Verzoek afwijzen?', `${absence.employee?.name} blijft ingepland op de diensten die nog niet vervangen waren.`, 'Afwijzen', true))) return;
    reject.mutate(undefined, { onSuccess: (r) => notify(r.message), onError: (e) => notify('Niet gelukt', e.message) });
  };

  const doWithdraw = async () => {
    if (!(await confirm('Afwezigheid intrekken?', 'Eerder vrijgemaakte diensten worden niet automatisch teruggezet.', 'Intrekken', true))) return;
    withdraw.mutate(undefined, { onSuccess: (r) => { notify(r.message); router.back(); }, onError: (e) => notify('Niet gelukt', e.message) });
  };

  return (
    <ScrollView contentContainerStyle={styles.screen} refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} tintColor={t.primary} />}>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginBottom: spacing.lg }}>
        {absence.employee ? <Chip employee={absence.employee} /> : null}
        <View style={{ flex: 1, gap: 2 }}>
          <Text style={{ color: t.text, fontSize: 20, fontWeight: '700' }}>{absence.employee?.name}</Text>
          <Text style={{ color: t.text }}>{absence.title}</Text>
          <Text style={{ color: t.muted }}>{absence.period}</Text>
        </View>
        <Badge text={absence.status_label} level={pending ? 'warning' : absence.status === 'approved' ? 'success' : 'neutral'} />
      </View>

      {absence.note ? <Notice text={absence.note} /> : null}
      {pending ? (
        <Notice
          level="warning"
          text={todo ? `Zoek voor ${todo} ${todo === 1 ? 'dienst' : 'diensten'} een vervanger. Daarna wordt het verlof automatisch goedgekeurd.` : 'Alle diensten zijn geregeld.'}
        />
      ) : null}

      <Section title={`Diensten (${shifts.length})`}>
        {shifts.length ? shifts.map(({ shift, state }, i) => (
          <View key={shift.id}>
            <ShiftRow shift={shift} last={i === shifts.length - 1 && state === 'done'} showDate={shift.date.slice(8, 10) + '-' + shift.date.slice(5, 7)} />
            {state !== 'done' ? (
              <Row last={i === shifts.length - 1} onPress={() => setOpen(open === shift.id ? null : shift.id)}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Badge text={STATE[state].text} level={STATE[state].level} />
                  <Text style={{ color: t.primary, fontWeight: '600' }}>{open === shift.id ? 'Sluiten' : 'Vervanger kiezen'}</Text>
                </View>
              </Row>
            ) : null}
          </View>
        )) : <EmptyRow text="Geen diensten in deze periode." />}
      </Section>

      {open !== null ? (
        <CandidateList
          shiftId={open}
          replaceEmployeeId={shifts.find((s) => s.shift.id === open)?.state === 'todo' ? absence.employee?.id : undefined}
          onDone={(message) => { setOpen(null); notify(message); }}
        />
      ) : null}

      <View style={{ gap: spacing.sm }}>
        {pending ? <Button title="Verzoek afwijzen" variant="danger" onPress={() => void doReject()} loading={reject.isPending} /> : null}
        {absence.status === 'approved' ? <Button title="Afwezigheid intrekken" variant="secondary" onPress={() => void doWithdraw()} loading={withdraw.isPending} /> : null}
      </View>
    </ScrollView>
  );
}
