import { useState } from 'react';
import { Linking, RefreshControl, ScrollView, Text, View } from 'react-native';
import { router, Stack, useLocalSearchParams } from 'expo-router';

import { ApiError } from '@/api/client';
import { useCancelShift, useShift, useUnassign, useUpdateShift } from '@/api/hooks';
import type { ShiftDetail } from '@/api/types';
import { CandidateList } from '@/components/CandidateList';
import { Badge, Button, Chip, ErrorView, Field, Loading, Notice, Row, Section, styles } from '@/components/ui';
import { confirm, notify } from '@/lib/confirm';
import { spacing, useTheme } from '@/theme';

function EditForm({ shift, onClose }: { shift: ShiftDetail; onClose: () => void }) {
  const update = useUpdateShift(shift.id);
  const [form, setForm] = useState({
    date: shift.date,
    start_time: shift.start_time,
    end_time: shift.end_time,
    required_staff: String(shift.required_staff),
    notes: shift.notes ?? '',
  });
  const error = update.error instanceof ApiError ? update.error : null;
  const set = (key: keyof typeof form) => (value: string) => setForm({ ...form, [key]: value });

  const save = async () => {
    try {
      await update.mutateAsync({
        title: shift.title,
        date: form.date,
        start_time: form.start_time,
        end_time: form.end_time,
        required_staff: Number(form.required_staff) || 1,
        notes: form.notes || null,
        skills: shift.skills.map((s) => s.id),
      });
      onClose();
    } catch {
      // De fout staat bij de velden.
    }
  };

  return (
    <Section title="Wijzigen">
      <View style={{ padding: spacing.md }}>
        {error && !Object.keys(error.errors).length ? <Notice text={error.message} level="danger" /> : null}
        <Field label="Datum (JJJJ-MM-DD)" value={form.date} onChangeText={set('date')} error={error?.field('date')} />
        <View style={{ flexDirection: 'row', gap: spacing.md }}>
          <View style={{ flex: 1 }}><Field label="Van" value={form.start_time} onChangeText={set('start_time')} error={error?.field('start_time')} /></View>
          <View style={{ flex: 1 }}><Field label="Tot" value={form.end_time} onChangeText={set('end_time')} error={error?.field('end_time')} /></View>
        </View>
        <Field label="Aantal medewerkers" value={form.required_staff} onChangeText={set('required_staff')} keyboardType="number-pad" error={error?.field('required_staff')} />
        <Field label="Notities" value={form.notes} onChangeText={set('notes')} multiline error={error?.field('notes')} />
        <View style={{ gap: spacing.sm }}>
          <Button title="Opslaan" onPress={() => void save()} loading={update.isPending} />
          <Button title="Annuleren" variant="secondary" onPress={onClose} />
        </View>
      </View>
    </Section>
  );
}

/** Een dienst: wie er staat, waarschuwingen, pand-informatie, vervanger zoeken, wijzigen of annuleren. */
export default function ShiftScreen() {
  const t = useTheme();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const { data: shift, error, isLoading, refetch, isRefetching } = useShift(id);
  const unassign = useUnassign(id);
  const cancel = useCancelShift(id);
  const [replacing, setReplacing] = useState<number | 'open' | null>(null);
  const [editing, setEditing] = useState(false);

  if (isLoading) return <Loading />;
  if (error || !shift) return <ErrorView error={error} onRetry={refetch} />;

  const remove = async (employeeId: number, name: string) => {
    if (!(await confirm(`${name} van deze dienst halen?`, 'De plek komt open te staan.', 'Weghalen', true))) return;
    unassign.mutate(employeeId, { onError: (e) => notify('Niet gelukt', e.message) });
  };

  const cancelShift = async () => {
    if (!(await confirm('Dienst annuleren?', `${shift.title} op ${shift.date_label}.`, 'Annuleren', true))) return;
    cancel.mutate(undefined, { onSuccess: () => router.back(), onError: (e) => notify('Niet gelukt', e.message) });
  };

  const done = (message: string) => {
    setReplacing(null);
    notify(message);
  };

  return (
    <ScrollView contentContainerStyle={styles.screen} refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={() => void refetch()} tintColor={t.primary} />}>
      <Stack.Screen options={{ title: shift.building.name }} />

      <View style={{ marginBottom: spacing.lg, gap: 4 }}>
        <Text style={{ color: t.text, fontSize: 22, fontWeight: '700' }}>{shift.title}</Text>
        <Text style={{ color: t.muted }}>{shift.date_label.charAt(0).toUpperCase() + shift.date_label.slice(1)}</Text>
        <Text style={{ color: t.text, fontSize: 17, fontVariant: ['tabular-nums'] }}>{shift.start_time}–{shift.end_time} · {shift.duration} uur</Text>
        <View style={{ flexDirection: 'row', gap: 6, flexWrap: 'wrap', marginTop: 4 }}>
          {shift.cancelled ? <Badge text="Geannuleerd" /> : null}
          {shift.open_slots > 0 ? <Badge text={`${shift.open_slots} van ${shift.required_staff} open`} level="danger" /> : <Badge text="Bezet" level="success" />}
          {shift.template ? <Badge text={shift.template.recurrence} level="info" /> : <Badge text="Losse dienst" />}
        </View>
      </View>

      {shift.fixed_missing.length ? (
        <Notice level="warning" text={`Vaste kracht: ${shift.fixed_missing.map((m) => [m.name, m.reason].filter(Boolean).join(' – ')).join('; ')}`} />
      ) : null}

      <Section title="Medewerkers">
        {shift.assignments.map((a) => (
          <Row key={a.employee_id}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.sm }}>
              <Chip employee={a} />
              <View style={{ flex: 1 }}>
                <Text style={{ color: t.text, fontWeight: '600' }}>{a.name}</Text>
                <Text style={{ color: t.muted }}>{a.source}</Text>
              </View>
              {a.phone ? <Text style={{ color: t.primary }} onPress={() => void Linking.openURL(`tel:${a.phone}`)}>Bellen</Text> : null}
            </View>
            {a.warnings.map((w, i) => (
              <Text key={i} style={{ color: w.level === 'danger' ? t.danger : w.level === 'warning' ? t.warning : t.muted, marginTop: 4 }}>• {w.message}</Text>
            ))}
            {shift.state !== 'done' && !shift.cancelled ? (
              <View style={{ flexDirection: 'row', gap: spacing.lg, marginTop: spacing.sm }}>
                <Text style={{ color: t.primary }} onPress={() => setReplacing(replacing === a.employee_id ? null : a.employee_id)}>Vervangen</Text>
                <Text style={{ color: t.danger }} onPress={() => void remove(a.employee_id, a.name)}>Weghalen</Text>
              </View>
            ) : null}
          </Row>
        ))}
        {shift.state !== 'done' && !shift.cancelled ? (
          <Row last onPress={() => setReplacing(replacing === 'open' ? null : 'open')}>
            <Text style={{ color: t.primary, fontWeight: '600' }}>{shift.open_slots > 0 ? 'Iemand inplannen' : 'Iemand extra inplannen'}</Text>
          </Row>
        ) : null}
      </Section>

      {replacing !== null ? (
        <>
          <Text style={{ color: t.text, fontWeight: '600', marginBottom: spacing.sm }}>
            {replacing === 'open' ? 'Kies wie je inplant' : `Vervanger voor ${shift.assignments.find((a) => a.employee_id === replacing)?.name}`}
          </Text>
          <CandidateList shiftId={id} replaceEmployeeId={replacing === 'open' ? undefined : replacing} onDone={done} />
        </>
      ) : null}

      <Section title="Pand">
        <Row>
          <Text style={{ color: t.text, fontWeight: '600' }}>{shift.building.name}</Text>
          {shift.building.address ? (
            <Text style={{ color: t.primary, marginTop: 2 }} onPress={() => void Linking.openURL(`https://maps.apple.com/?q=${encodeURIComponent(shift.building.address)}`)}>
              {shift.building.address}
            </Text>
          ) : null}
        </Row>
        {shift.building.contact ? <Row><Text style={{ color: t.muted }}>Contact</Text><Text style={{ color: t.text }}>{shift.building.contact}</Text></Row> : null}
        {shift.building.access ? <Row><Text style={{ color: t.muted }}>Toegang</Text><Text style={{ color: t.text }}>{shift.building.access}</Text></Row> : null}
        {shift.skills.length ? <Row><Text style={{ color: t.muted }}>Vaardigheden</Text><Text style={{ color: t.text }}>{shift.skills.map((s) => s.name).join(', ')}</Text></Row> : null}
        <Row last><Text style={{ color: t.muted }}>Notities</Text><Text style={{ color: t.text }}>{shift.notes || '–'}</Text></Row>
      </Section>

      {editing ? <EditForm shift={shift} onClose={() => setEditing(false)} /> : null}

      {!shift.cancelled && shift.state !== 'done' ? (
        <View style={{ gap: spacing.sm }}>
          {!editing ? <Button title="Dienst wijzigen" variant="secondary" onPress={() => setEditing(true)} /> : null}
          <Button title="Dienst annuleren" variant="danger" onPress={() => void cancelShift()} loading={cancel.isPending} />
        </View>
      ) : null}
    </ScrollView>
  );
}
