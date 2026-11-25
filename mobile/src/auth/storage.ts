import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

// Het token staat in de iOS-sleutelhanger / Android Keystore. In de webversie (alleen voor testen)
// in sessionStorage, zodat het verdwijnt als het tabblad sluit.
const KEY = 'poetsploeg.token';
const UNLOCK_KEY = 'poetsploeg.faceid';

const web = {
  get: (k: string) => (typeof sessionStorage === 'undefined' ? null : sessionStorage.getItem(k)),
  set: (k: string, v: string) => typeof sessionStorage !== 'undefined' && sessionStorage.setItem(k, v),
  del: (k: string) => typeof sessionStorage !== 'undefined' && sessionStorage.removeItem(k),
};

async function get(key: string): Promise<string | null> {
  return Platform.OS === 'web' ? web.get(key) : SecureStore.getItemAsync(key);
}

async function set(key: string, value: string): Promise<void> {
  if (Platform.OS === 'web') {
    web.set(key, value);
  } else {
    await SecureStore.setItemAsync(key, value, { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY });
  }
}

async function del(key: string): Promise<void> {
  if (Platform.OS === 'web') {
    web.del(key);
  } else {
    await SecureStore.deleteItemAsync(key);
  }
}

export const tokenStore = {
  load: () => get(KEY),
  save: (token: string) => set(KEY, token),
  clear: () => del(KEY),
};

/** Wil de gebruiker de app met Face ID / Touch ID ontgrendelen? Standaard aan. */
export const unlockPreference = {
  load: async () => (await get(UNLOCK_KEY)) !== 'uit',
  save: (on: boolean) => set(UNLOCK_KEY, on ? 'aan' : 'uit'),
};
