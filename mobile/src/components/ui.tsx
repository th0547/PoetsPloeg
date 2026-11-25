import { ReactNode } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  TextInputProps,
  View,
  ViewStyle,
} from 'react-native';

import type { EmployeeRef, Level } from '@/api/types';
import { spacing, Theme, useTheme } from '@/theme';

export function Section({ title, right, children }: { title?: string; right?: ReactNode; children: ReactNode }) {
  const t = useTheme();
  return (
    <View style={{ marginBottom: spacing.lg }}>
      {title ? (
        <View style={styles.sectionHead}>
          <Text style={[styles.sectionTitle, { color: t.muted }]}>{title.toUpperCase()}</Text>
          {right}
        </View>
      ) : null}
      <View style={[styles.card, { backgroundColor: t.card, borderColor: t.border }]}>{children}</View>
    </View>
  );
}

export function Row({ children, onPress, last }: { children: ReactNode; onPress?: () => void; last?: boolean }) {
  const t = useTheme();
  const style: ViewStyle = { padding: spacing.md, borderBottomWidth: last ? 0 : StyleSheet.hairlineWidth, borderColor: t.border };
  return onPress ? (
    <Pressable onPress={onPress} style={({ pressed }) => [style, pressed && { opacity: 0.6 }]} accessibilityRole="button">
      {children}
    </Pressable>
  ) : (
    <View style={style}>{children}</View>
  );
}

export function EmptyRow({ text }: { text: string }) {
  const t = useTheme();
  return <Text style={{ padding: spacing.md, color: t.muted }}>{text}</Text>;
}

export function Chip({ employee, small }: { employee: EmployeeRef | { name: string; initials: string; color: string | null; ink: string }; small?: boolean }) {
  const size = small ? 22 : 28;
  return (
    <View style={[styles.chip, { width: size, height: size, borderRadius: size / 2, backgroundColor: employee.color ?? '#6b7280' }]}>
      <Text style={{ color: employee.ink, fontSize: small ? 10 : 12, fontWeight: '600' }}>{employee.initials}</Text>
    </View>
  );
}

const levelColors = (t: Theme, level: Level | 'success' | 'neutral') =>
  ({
    danger: [t.danger, t.dangerBg],
    warning: [t.warning, t.warningBg],
    info: [t.info, t.infoBg],
    success: [t.success, t.successBg],
    neutral: [t.muted, t.background],
  })[level];

export function Badge({ text, level = 'neutral' }: { text: string; level?: Level | 'success' | 'neutral' }) {
  const t = useTheme();
  const [fg, bg] = levelColors(t, level);
  return (
    <View style={[styles.badge, { backgroundColor: bg }]}>
      <Text style={{ color: fg, fontSize: 12, fontWeight: '600' }}>{text}</Text>
    </View>
  );
}

export function Notice({ text, level = 'info' }: { text: string; level?: Level | 'success' }) {
  const t = useTheme();
  const [fg, bg] = levelColors(t, level);
  return (
    <View style={{ backgroundColor: bg, borderRadius: 10, padding: spacing.md, marginBottom: spacing.md }}>
      <Text style={{ color: fg }}>{text}</Text>
    </View>
  );
}

export function Button({
  title,
  onPress,
  variant = 'primary',
  loading,
  disabled,
}: {
  title: string;
  onPress: () => void;
  variant?: 'primary' | 'secondary' | 'danger';
  loading?: boolean;
  disabled?: boolean;
}) {
  const t = useTheme();
  const bg = variant === 'primary' ? t.primary : variant === 'danger' ? t.dangerBg : t.card;
  const fg = variant === 'primary' ? t.primaryText : variant === 'danger' ? t.danger : t.text;
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled || loading}
      accessibilityRole="button"
      style={({ pressed }) => [
        styles.button,
        { backgroundColor: bg, borderColor: variant === 'secondary' ? t.border : bg, opacity: disabled ? 0.5 : pressed ? 0.75 : 1 },
      ]}
    >
      {loading ? <ActivityIndicator color={fg} /> : <Text style={{ color: fg, fontWeight: '600', fontSize: 16 }}>{title}</Text>}
    </Pressable>
  );
}

export function Field({ label, error, ...props }: TextInputProps & { label: string; error?: string }) {
  const t = useTheme();
  return (
    <View style={{ marginBottom: spacing.md }}>
      <Text style={{ color: t.text, fontWeight: '500', marginBottom: spacing.xs }}>{label}</Text>
      <TextInput
        placeholderTextColor={t.muted}
        {...props}
        style={[styles.input, { color: t.text, backgroundColor: t.card, borderColor: error ? t.danger : t.border }]}
      />
      {error ? <Text style={{ color: t.danger, marginTop: spacing.xs }}>{error}</Text> : null}
    </View>
  );
}

export function Loading() {
  const t = useTheme();
  return (
    <View style={styles.center}>
      <ActivityIndicator color={t.primary} />
    </View>
  );
}

export function ErrorView({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const t = useTheme();
  const message = error instanceof Error ? error.message : 'Er ging iets mis.';
  return (
    <View style={[styles.center, { padding: spacing.xl }]}>
      <Text style={{ color: t.text, textAlign: 'center', marginBottom: spacing.md }}>{message}</Text>
      {onRetry ? <Button title="Opnieuw proberen" variant="secondary" onPress={onRetry} /> : null}
    </View>
  );
}

export const styles = StyleSheet.create({
  card: { borderRadius: 12, borderWidth: StyleSheet.hairlineWidth, overflow: 'hidden' },
  sectionHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: spacing.sm, paddingHorizontal: spacing.xs },
  sectionTitle: { fontSize: 12, fontWeight: '600', letterSpacing: 0.5 },
  chip: { alignItems: 'center', justifyContent: 'center' },
  badge: { borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2, alignSelf: 'flex-start' },
  button: { borderRadius: 10, borderWidth: 1, paddingVertical: 12, paddingHorizontal: spacing.lg, alignItems: 'center', justifyContent: 'center', minHeight: 48 },
  input: { borderWidth: 1, borderRadius: 10, paddingHorizontal: spacing.md, paddingVertical: 12, fontSize: 16 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  screen: { padding: spacing.lg, paddingBottom: spacing.xl * 2 },
});
