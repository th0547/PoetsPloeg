import { Pressable, Text, View } from 'react-native';
import Ionicons from '@expo/vector-icons/Ionicons';

import { relativeDayLabel, todayIso } from '@/lib/dates';
import { spacing, useTheme } from '@/theme';

/** Vorige / volgende dag (of week) met in het midden de datum; tik op de datum = terug naar vandaag. */
export function DayNav({ label, onPrev, onNext, onToday }: { label: string; onPrev: () => void; onNext: () => void; onToday?: () => void }) {
  const t = useTheme();
  const button = (name: 'chevron-back' | 'chevron-forward', onPress: () => void, a11y: string) => (
    <Pressable onPress={onPress} hitSlop={12} accessibilityLabel={a11y} style={{ padding: spacing.sm }}>
      <Ionicons name={name} size={22} color={t.primary} />
    </Pressable>
  );
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: spacing.lg }}>
      {button('chevron-back', onPrev, 'Vorige')}
      <Pressable onPress={onToday} disabled={!onToday} style={{ flex: 1, alignItems: 'center' }}>
        <Text style={{ color: t.text, fontSize: 17, fontWeight: '600' }}>{label.charAt(0).toUpperCase() + label.slice(1)}</Text>
      </Pressable>
      {button('chevron-forward', onNext, 'Volgende')}
    </View>
  );
}

export function dayNavLabel(date: string): string {
  return relativeDayLabel(date, todayIso());
}
