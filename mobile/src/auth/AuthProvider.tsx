import { createContext, ReactNode, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AppState, Platform } from 'react-native';
import * as Device from 'expo-device';
import * as LocalAuthentication from 'expo-local-authentication';
import { useQueryClient } from '@tanstack/react-query';

import { api, configureClient } from '@/api/client';
import type { LoginResponse, TokenResponse, User } from '@/api/types';
import { tokenStore, unlockPreference } from './storage';

/** Na zo lang op de achtergrond vraagt de app opnieuw om Face ID. */
const RELOCK_AFTER_MS = 5 * 60 * 1000;

type Status = 'loading' | 'signedOut' | 'locked' | 'signedIn';

interface AuthContextValue {
  status: Status;
  user: User | null;
  /** Stap 1. Geeft een challenge terug als de code nog moet volgen. */
  signIn: (email: string, password: string) => Promise<{ challenge: string } | null>;
  /** Stap 2: code uit de authenticator-app of een herstelcode. */
  verifyCode: (challenge: string, code: string, recovery: boolean) => Promise<TokenResponse>;
  signOut: () => Promise<void>;
  unlock: () => Promise<boolean>;
  biometrics: { available: boolean; enabled: boolean; setEnabled: (on: boolean) => Promise<void> };
}

const AuthContext = createContext<AuthContextValue | null>(null);

async function biometricsAvailable(): Promise<boolean> {
  if (Platform.OS === 'web') return false;
  return (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync());
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<Status>('loading');
  const [user, setUser] = useState<User | null>(null);
  const [bioAvailable, setBioAvailable] = useState(false);
  const [bioEnabled, setBioEnabled] = useState(true);
  const token = useRef<string | null>(null);
  const backgroundSince = useRef<number | null>(null);

  const clear = useCallback(async () => {
    token.current = null;
    setUser(null);
    setStatus('signedOut');
    queryClient.clear();
    await tokenStore.clear();
  }, [queryClient]);

  // Het token voor elke API-aanroep; een 401 betekent: token verlopen of ingetrokken.
  useEffect(() => {
    configureClient(() => token.current, () => void clear());
  }, [clear]);

  // Bij het starten: token uit de sleutelhanger, dan (als dat kan) eerst Face ID.
  useEffect(() => {
    (async () => {
      const [saved, available, enabled] = await Promise.all([tokenStore.load(), biometricsAvailable(), unlockPreference.load()]);
      setBioAvailable(available);
      setBioEnabled(enabled);
      if (!saved) {
        setStatus('signedOut');
        return;
      }
      token.current = saved;
      setStatus(available && enabled ? 'locked' : 'signedIn');
      api<{ user: User }>('me').then((r) => setUser(r.user)).catch(() => {});
    })();
  }, []);

  // Terug uit de achtergrond na een tijdje: opnieuw vergrendelen.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (next) => {
      if (next === 'background') {
        backgroundSince.current = Date.now();
      } else if (next === 'active' && backgroundSince.current !== null) {
        const away = Date.now() - backgroundSince.current;
        backgroundSince.current = null;
        if (away > RELOCK_AFTER_MS && token.current && bioAvailable && bioEnabled) {
          setStatus('locked');
        }
      }
    });
    return () => sub.remove();
  }, [bioAvailable, bioEnabled]);

  const finish = useCallback(async (response: TokenResponse) => {
    token.current = response.token;
    await tokenStore.save(response.token);
    setUser(response.user);
    setStatus('signedIn');
  }, []);

  const signIn = useCallback(
    async (email: string, password: string) => {
      const deviceName = [Device.modelName ?? Platform.OS, Device.deviceName].filter(Boolean).join(' – ').slice(0, 100) || 'App';
      const response = await api<LoginResponse>('auth/login', {
        method: 'POST',
        anonymous: true,
        body: { email, password, device_name: deviceName },
      });
      if ('two_factor' in response) {
        return { challenge: response.challenge };
      }
      await finish(response);
      return null;
    },
    [finish],
  );

  const verifyCode = useCallback(
    async (challenge: string, code: string, recovery: boolean) => {
      const response = await api<TokenResponse>('auth/two-factor', {
        method: 'POST',
        anonymous: true,
        body: recovery ? { challenge, recovery_code: code } : { challenge, code },
      });
      await finish(response);
      return response;
    },
    [finish],
  );

  const signOut = useCallback(async () => {
    await api('auth/logout', { method: 'POST' }).catch(() => {});
    await clear();
  }, [clear]);

  const unlock = useCallback(async () => {
    const result = await LocalAuthentication.authenticateAsync({
      promptMessage: 'Ontgrendel PoetsPloeg',
      cancelLabel: 'Annuleren',
      fallbackLabel: 'Toegangscode gebruiken',
    });
    if (result.success) {
      setStatus('signedIn');
    }
    return result.success;
  }, []);

  const setEnabled = useCallback(async (on: boolean) => {
    setBioEnabled(on);
    await unlockPreference.save(on);
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      status,
      user,
      signIn,
      verifyCode,
      signOut,
      unlock,
      biometrics: { available: bioAvailable, enabled: bioEnabled, setEnabled },
    }),
    [status, user, signIn, verifyCode, signOut, unlock, bioAvailable, bioEnabled, setEnabled],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error('useAuth buiten AuthProvider');
  return value;
}
