import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { useAuth } from '@/auth/AuthProvider';
import { Button, Field, Notice } from '@/components/ui';
import { spacing, useTheme } from '@/theme';

/** Inloggen in twee stappen, net als op de website: wachtwoord, dan de code uit de authenticator-app. */
export default function SignIn() {
  const t = useTheme();
  const { signIn, verifyCode } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [challenge, setChallenge] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [recovery, setRecovery] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);

  const run = async (fn: () => Promise<void>) => {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Er ging iets mis.'));
    } finally {
      setBusy(false);
    }
  };

  const submitPassword = () =>
    run(async () => {
      const result = await signIn(email.trim(), password);
      if (result) {
        setChallenge(result.challenge);
        setPassword('');
      }
    });

  const submitCode = () =>
    run(async () => {
      try {
        await verifyCode(challenge!, code.trim(), recovery);
      } catch (e) {
        // De tijd voor de code is verstreken: terug naar stap 1.
        if (e instanceof ApiError && e.field('challenge')) setChallenge(null);
        throw e;
      }
    });

  const general = error && !error.field('email') && !error.field('code') && !error.field('recovery_code') ? error.field('challenge') ?? error.message : null;

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: t.background }}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', padding: spacing.xl }} keyboardShouldPersistTaps="handled">
          <View style={{ width: '100%', maxWidth: 420, alignSelf: 'center' }}>
            <Text style={{ fontSize: 30, fontWeight: '700', color: t.primary, marginBottom: spacing.xs }}>PoetsPloeg</Text>
            <Text style={{ color: t.muted, marginBottom: spacing.xl }}>Planning voor planners en teamleiders</Text>

            {general ? <Notice text={general} level="danger" /> : null}

            {challenge === null ? (
              <>
                <Field
                  label="E-mailadres"
                  value={email}
                  onChangeText={setEmail}
                  autoCapitalize="none"
                  autoComplete="email"
                  keyboardType="email-address"
                  textContentType="username"
                  error={error?.field('email')}
                />
                <Field
                  label="Wachtwoord"
                  value={password}
                  onChangeText={setPassword}
                  secureTextEntry
                  autoComplete="current-password"
                  textContentType="password"
                  onSubmitEditing={submitPassword}
                />
                <Button title="Inloggen" onPress={submitPassword} loading={busy} disabled={!email || !password} />
              </>
            ) : (
              <>
                <Text style={{ color: t.text, marginBottom: spacing.md }}>
                  {recovery
                    ? 'Vul een van je herstelcodes in.'
                    : 'Vul de code van 6 cijfers in uit je authenticator-app.'}
                </Text>
                <Field
                  label={recovery ? 'Herstelcode' : 'Code'}
                  value={code}
                  onChangeText={setCode}
                  autoFocus
                  autoCapitalize="none"
                  keyboardType={recovery ? 'default' : 'number-pad'}
                  textContentType="oneTimeCode"
                  autoComplete="one-time-code"
                  maxLength={recovery ? 30 : 6}
                  onSubmitEditing={submitCode}
                  error={error?.field(recovery ? 'recovery_code' : 'code')}
                />
                <Button title="Bevestigen" onPress={submitCode} loading={busy} disabled={!code} />
                <Pressable onPress={() => { setRecovery(!recovery); setCode(''); setError(null); }} style={{ padding: spacing.md, alignItems: 'center' }}>
                  <Text style={{ color: t.primary }}>{recovery ? 'Code uit de app gebruiken' : 'Telefoon kwijt? Herstelcode gebruiken'}</Text>
                </Pressable>
                <Pressable onPress={() => { setChallenge(null); setCode(''); setError(null); }} style={{ padding: spacing.sm, alignItems: 'center' }}>
                  <Text style={{ color: t.muted }}>Ander account</Text>
                </Pressable>
              </>
            )}
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
