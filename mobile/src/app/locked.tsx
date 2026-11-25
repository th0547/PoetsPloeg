import { useEffect } from 'react';
import { Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAuth } from '@/auth/AuthProvider';
import { Button } from '@/components/ui';
import { spacing, useTheme } from '@/theme';

/** Vergrendeld: na het openen (of lang op de achtergrond) eerst Face ID of de toegangscode. */
export default function Locked() {
  const t = useTheme();
  const { unlock, signOut } = useAuth();

  useEffect(() => {
    void unlock();
  }, [unlock]);

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: t.background, justifyContent: 'center', padding: spacing.xl }}>
      <View style={{ gap: spacing.md, maxWidth: 420, width: '100%', alignSelf: 'center' }}>
        <Text style={{ fontSize: 28, fontWeight: '700', color: t.primary }}>PoetsPloeg</Text>
        <Text style={{ color: t.muted, marginBottom: spacing.lg }}>De app is vergrendeld.</Text>
        <Button title="Ontgrendelen" onPress={() => void unlock()} />
        <Button title="Uitloggen" variant="secondary" onPress={() => void signOut()} />
      </View>
    </SafeAreaView>
  );
}
