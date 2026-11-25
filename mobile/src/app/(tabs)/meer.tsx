import { Linking, ScrollView, Switch, Text, View } from 'react-native';
import Constants from 'expo-constants';

import { API_URL } from '@/api/client';
import { useAuth } from '@/auth/AuthProvider';
import { Button, Row, Section, styles } from '@/components/ui';
import { spacing, useTheme } from '@/theme';

/** Profiel, Face ID, uitloggen, en een link naar de website voor alles wat de app (nog) niet kan. */
export default function MoreScreen() {
  const t = useTheme();
  const { user, signOut, biometrics } = useAuth();

  return (
    <ScrollView contentContainerStyle={styles.screen}>
      <Section title="Ingelogd als">
        <Row last>
          <Text style={{ color: t.text, fontWeight: '600' }}>{user?.name ?? '…'}</Text>
          <Text style={{ color: t.muted }}>{user ? `${user.email} · ${user.role_label}` : ''}</Text>
        </Row>
      </Section>

      {biometrics.available ? (
        <Section title="Beveiliging">
          <Row last>
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
              <Text style={{ color: t.text, flex: 1 }}>Ontgrendelen met Face ID / Touch ID</Text>
              <Switch value={biometrics.enabled} onValueChange={(on) => void biometrics.setEnabled(on)} />
            </View>
          </Row>
        </Section>
      ) : null}

      <Section title="Website">
        <Row onPress={() => void Linking.openURL(`${API_URL}/`)}>
          <Text style={{ color: t.primary }}>PoetsPloeg openen in de browser</Text>
          <Text style={{ color: t.muted }}>Voor medewerkers, panden, taken, beleid en het roosterbord.</Text>
        </Row>
        <Row last onPress={() => void Linking.openURL(`${API_URL}/profile`)}>
          <Text style={{ color: t.primary }}>Mijn profiel en tweestapsverificatie</Text>
        </Row>
      </Section>

      <View style={{ gap: spacing.sm }}>
        <Button title="Uitloggen" variant="danger" onPress={() => void signOut()} />
        <Text style={{ color: t.muted, textAlign: 'center', marginTop: spacing.md, fontSize: 12 }}>
          Versie {Constants.expoConfig?.version ?? '?'} · {API_URL.replace(/^https?:\/\//, '')}
        </Text>
      </View>
    </ScrollView>
  );
}
