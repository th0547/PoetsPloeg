import { Text, View } from 'react-native';

import { ApiError } from '@/api/client';
import { useAssign, useCandidates } from '@/api/hooks';
import type { Candidate } from '@/api/types';
import { confirm, notify } from '@/lib/confirm';
import { spacing, useTheme } from '@/theme';
import { Badge, Chip, EmptyRow, ErrorView, Loading, Row, Section } from './ui';

const FIT: Record<Candidate['fit'], { text: string; level: 'success' | 'warning' | 'neutral' }> = {
  best: { text: 'Beste keuze', level: 'success' },
  good: { text: 'Geschikt', level: 'success' },
  caution: { text: 'Let op', level: 'warning' },
  excluded: { text: 'Kan niet', level: 'neutral' },
};

/**
 * Geschikte vervangers voor een dienst, met redenen. Tik = inplannen; bij waarschuwingen eerst bevestigen.
 * Met replaceEmployeeId wordt die medewerker vervangen (bijv. bij een verlofverzoek).
 */
export function CandidateList({ shiftId, replaceEmployeeId, onDone }: { shiftId: number; replaceEmployeeId?: number; onDone?: (message: string) => void }) {
  const t = useTheme();
  const { data, error, isLoading, refetch } = useCandidates(shiftId);
  const assign = useAssign(shiftId);

  const pick = async (c: Candidate) => {
    const question = replaceEmployeeId ? `${c.name} als vervanger inplannen?` : `${c.name} inplannen?`;
    if (!(await confirm(question, c.reasons.map((r) => `${r.ok === false ? '✗' : r.ok ? '✓' : '·'} ${r.text}`).join('\n'), 'Inplannen'))) return;
    try {
      let result = await assign.mutateAsync({ employee_id: c.id, replace_employee_id: replaceEmployeeId });
      if ('needs_confirmation' in result) {
        const ok = await confirm('Toch inplannen?', result.warnings.map((w) => `• ${w.message}`).join('\n'), 'Toch inplannen');
        if (!ok) return;
        result = await assign.mutateAsync({ employee_id: c.id, replace_employee_id: replaceEmployeeId, confirm: true });
      }
      const approved = 'approved_requests' in result && result.approved_requests.length
        ? ` Verlof van ${result.approved_requests.join(', ')} is nu goedgekeurd.`
        : '';
      const message = `${c.name} is ingepland.${approved}`;
      if (onDone) onDone(message);
      else notify(message);
    } catch (e) {
      notify('Niet gelukt', e instanceof ApiError ? e.message : 'Er ging iets mis.');
    }
  };

  if (isLoading) return <Loading />;
  if (error || !data) return <ErrorView error={error} onRetry={refetch} />;

  const row = (c: Candidate, i: number, list: Candidate[]) => (
    <Row key={c.id} last={i === list.length - 1} onPress={c.fit === 'excluded' ? undefined : () => void pick(c)}>
      <View style={{ flexDirection: 'row', gap: spacing.sm, opacity: c.fit === 'excluded' ? 0.6 : 1 }}>
        <Chip employee={c} />
        <View style={{ flex: 1, gap: 2 }}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: spacing.sm }}>
            <Text style={{ color: t.text, fontWeight: '600', flexShrink: 1 }}>{c.name}</Text>
            <Badge text={FIT[c.fit].text} level={FIT[c.fit].level} />
          </View>
          {c.reasons.map((r, j) => (
            <Text key={j} style={{ color: r.ok === false && !r.soft ? t.danger : r.ok ? t.success : t.muted, fontSize: 13 }}>
              {r.ok === false ? '✗' : r.ok ? '✓' : '·'} {r.text}
            </Text>
          ))}
          {c.day_shifts.length ? (
            <Text style={{ color: t.muted, fontSize: 13 }}>Die dag al: {c.day_shifts.map((d) => `${d.start}–${d.end}`).join(', ')}</Text>
          ) : null}
        </View>
      </View>
    </Row>
  );

  return (
    <>
      <Section title={`Geschikt (${data.candidates.length})`}>
        {data.candidates.length ? data.candidates.map(row) : <EmptyRow text="Niemand beschikbaar zonder conflict." />}
      </Section>
      {data.excluded.length ? <Section title={`Kan niet (${data.excluded.length})`}>{data.excluded.map(row)}</Section> : null}
    </>
  );
}
