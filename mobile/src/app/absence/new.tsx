import { useMemo, useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, Switch, Text, View } from 'react-native';
import { router } from 'expo-router';

import { ApiError } from '@/api/client';
import { useEmployees, useLeaveTypes, useRegisterAbsence } from '@/api/hooks';
import { Button, Chip, ErrorView, Field, Loading, Notice, Row, Section, styles } from '@/components/ui';
import { daysForRange, validateRange } from '@/lib/absenceDays';
import { notify } from '@/lib/confirm';
import { shiftDays, todayIso } from '@/lib/dates';
import { spacing, useTheme } from '@/theme';

/** Uitval of verlof melden: medewerker, soort, periode. Daarna door naar het vervangscherm. */
export default function NewAbsenceScreen() {
  const t = useTheme();
  const employees = useEmployees();
  const leaveTypes = useLeaveTypes();
  const register = useRegisterAbsence();

  const [search, setSearch] = useState('');
  const [employeeId, setEmployeeId] = useState<number | null>(null);
  const [type, setType] = useState('ziek');
  const [from, setFrom] = useState(todayIso());
  const [to, setTo] = useState(todayIso());
  const [wholeDay, setWholeDay] = useState(true);
  const [start, setStart] = useState('08:00');
  const [end, setEnd] = useState('12:00');
  const [asRequest, setAsRequest] = useState(false);
  const [note, setNote] = useState('');
  const [formError, setFormError] = useState<string | null>(null);

  const list = useMemo(() => employees.data?.data ?? [], [employees.data]);
  const employee = list.find((e) => e.id === employeeId);
  const matches = useMemo(() => list.filter((e) => e.name.toLowerCase().includes(search.trim().toLowerCase())).slice(0, 8), [list, search]);
  // De meest gebruikte soorten eerst; de rest in de volgorde van het beleid.
  const types = useMemo(() => {
    const all = leaveTypes.data?.leave_types ?? [];
    const first = ['ziek', 'verlof', 'huisarts'];
    return [...first.flatMap((k) => all.filter((lt) => lt.key === k)), ...all.filter((lt) => !first.includes(lt.key))];
  }, [leaveTypes.data]);
  const selectedType = types.find((lt) => lt.key === type);
  const times = wholeDay ? undefined : { start, end };
  const serverError = register.error instanceof ApiError ? register.error : null;

  const submit = async () => {
    const problem = !employeeId ? 'Kies een medewerker.' : validateRange(from, to, times);
    setFormError(problem);
    if (problem || !employeeId) return;
    try {
      const result = await register.mutateAsync({
        employee_id: employeeId,
        type,
        as_request: asRequest && !!selectedType?.can_be_request,
        days: daysForRange(from, to, times),
        note: note || undefined,
      });
      notify(result.message);
      const first = result.absences[0];
      if (result.absences.length === 1 && first) {
        router.replace({ pathname: '/absence/[id]', params: { id: String(first.id) } });
      } else {
        router.back();
      }
    } catch {
      // Melding staat boven het formulier.
    }
  };

  if (employees.isLoading || leaveTypes.isLoading) return <Loading />;
  if (employees.error || leaveTypes.error) return <ErrorView error={employees.error ?? leaveTypes.error} onRetry={() => { void employees.refetch(); void leaveTypes.refetch(); }} />;

  const pill = (active: boolean, label: string, onPress: () => void) => (
    <Pressable key={label} onPress={onPress} style={{ paddingHorizontal: 12, paddingVertical: 8, borderRadius: 999, borderWidth: 0.5, borderColor: t.border, backgroundColor: active ? t.primary : t.card }}>
      <Text style={{ color: active ? t.primaryText : t.text }}>{label}</Text>
    </Pressable>
  );

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
      <ScrollView contentContainerStyle={styles.screen} keyboardShouldPersistTaps="handled">
        {formError || serverError ? <Notice level="danger" text={formError ?? serverError!.message} /> : null}

        <Section title="Medewerker">
          {employee ? (
            <Row last onPress={() => setEmployeeId(null)}>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.sm }}>
                <Chip employee={employee} />
                <Text style={{ color: t.text, fontWeight: '600', flex: 1 }}>{employee.name}</Text>
                <Text style={{ color: t.primary }}>Wijzigen</Text>
              </View>
            </Row>
          ) : (
            <View style={{ padding: spacing.md }}>
              <Field label="Zoeken" value={search} onChangeText={setSearch} placeholder="Naam" autoFocus />
              {matches.map((e) => (
                <Pressable key={e.id} onPress={() => setEmployeeId(e.id)} style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.sm, paddingVertical: 8 }}>
                  <Chip employee={e} small />
                  <Text style={{ color: t.text }}>{e.name}</Text>
                </Pressable>
              ))}
            </View>
          )}
        </Section>

        <Section title="Soort">
          <View style={{ padding: spacing.md, flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm }}>
            {types.map((lt) => pill(type === lt.key, lt.name, () => setType(lt.key)))}
          </View>
        </Section>

        <Section title="Periode">
          <View style={{ padding: spacing.md }}>
            <View style={{ flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.md }}>
              {pill(from === todayIso() && to === todayIso(), 'Vandaag', () => { setFrom(todayIso()); setTo(todayIso()); })}
              {pill(from === shiftDays(todayIso(), 1) && to === from, 'Morgen', () => { const d = shiftDays(todayIso(), 1); setFrom(d); setTo(d); })}
            </View>
            <View style={{ flexDirection: 'row', gap: spacing.md }}>
              <View style={{ flex: 1 }}><Field label="Van" value={from} onChangeText={(v) => { setFrom(v); if (to < v) setTo(v); }} placeholder="JJJJ-MM-DD" error={serverError?.field('days')} /></View>
              <View style={{ flex: 1 }}><Field label="Tot en met" value={to} onChangeText={setTo} placeholder="JJJJ-MM-DD" /></View>
            </View>
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: wholeDay ? 0 : spacing.md }}>
              <Text style={{ color: t.text }}>Hele dag{from !== to ? 'en' : ''}</Text>
              <Switch value={wholeDay} onValueChange={setWholeDay} />
            </View>
            {!wholeDay ? (
              <View style={{ flexDirection: 'row', gap: spacing.md }}>
                <View style={{ flex: 1 }}><Field label="Van (tijd)" value={start} onChangeText={setStart} placeholder="UU:MM" /></View>
                <View style={{ flex: 1 }}><Field label="Tot (tijd)" value={end} onChangeText={setEnd} placeholder="UU:MM" /></View>
              </View>
            ) : null}
          </View>
        </Section>

        {selectedType?.can_be_request ? (
          <Section>
            <Row last>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}>
                <View style={{ flex: 1 }}>
                  <Text style={{ color: t.text, fontWeight: '500' }}>Als verlofverzoek</Text>
                  <Text style={{ color: t.muted }}>De medewerker blijft ingepland tot er voor elke dienst een vervanger is.</Text>
                </View>
                <Switch value={asRequest} onValueChange={setAsRequest} />
              </View>
            </Row>
          </Section>
        ) : null}

        <Field label="Notitie (optioneel)" value={note} onChangeText={setNote} multiline />
        <Button title={asRequest && selectedType?.can_be_request ? 'Verzoek indienen' : 'Registreren'} onPress={() => void submit()} loading={register.isPending} />
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
