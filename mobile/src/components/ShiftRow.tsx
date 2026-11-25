import { Text, View } from 'react-native';
import { router } from 'expo-router';

import type { Level, ShiftSummary, Warning } from '@/api/types';
import { spacing, useTheme } from '@/theme';
import { Badge, Chip, Row } from './ui';

export function worstLevel(warnings: Warning[]): Level | null {
  if (warnings.some((w) => w.level === 'danger')) return 'danger';
  if (warnings.some((w) => w.level === 'warning')) return 'warning';
  return null;
}

/** Een dienst in een lijst: tijd, pand, titel, wie er staat en wat er ontbreekt. Tik = dienstscherm. */
export function ShiftRow({ shift, last, showDate }: { shift: ShiftSummary; last?: boolean; showDate?: string }) {
  const t = useTheme();
  const warnings = (shift.assignments ?? []).flatMap((a) => a.warnings);
  const level = worstLevel(warnings);

  return (
    <Row last={last} onPress={() => router.push({ pathname: '/shift/[id]', params: { id: String(shift.id) } })}>
      <View style={{ flexDirection: 'row', gap: spacing.md }}>
        <View style={{ width: 4, borderRadius: 2, backgroundColor: shift.building?.color ?? t.border }} />
        <View style={{ flex: 1, gap: 4 }}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: spacing.sm }}>
            <Text style={{ color: t.text, fontWeight: '600', flexShrink: 1 }} numberOfLines={1}>
              {shift.building?.name ?? shift.title}
            </Text>
            <Text style={{ color: t.muted, fontVariant: ['tabular-nums'] }}>
              {showDate ? `${showDate} · ` : ''}
              {shift.start_time}–{shift.end_time}
            </Text>
          </View>
          {shift.building ? (
            <Text style={{ color: t.muted }} numberOfLines={1}>
              {shift.title}
            </Text>
          ) : null}
          <View style={{ flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 6, marginTop: 2 }}>
            {(shift.assignments ?? []).map((a) => (
              <View key={a.employee.id} style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
                <Chip employee={a.employee} small />
                <Text style={{ color: t.text }}>{a.employee.name.split(' ')[0]}</Text>
              </View>
            ))}
            {shift.open_slots > 0 ? <Badge text={shift.open_slots === 1 ? '1 plek open' : `${shift.open_slots} plekken open`} level="danger" /> : null}
            {level ? <Badge text={level === 'danger' ? 'Probleem' : 'Let op'} level={level} /> : null}
            {shift.cancelled ? <Badge text="Geannuleerd" /> : null}
          </View>
        </View>
      </View>
    </Row>
  );
}
