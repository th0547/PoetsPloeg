import { useColorScheme } from 'react-native';

// Kleuren in de stijl van de website (teal als hoofdkleur), licht en donker.
const light = {
  background: '#f3f4f6',
  card: '#ffffff',
  text: '#111827',
  muted: '#6b7280',
  border: '#e5e7eb',
  primary: '#0f766e',
  primaryText: '#ffffff',
  danger: '#b91c1c',
  dangerBg: '#fef2f2',
  warning: '#b45309',
  warningBg: '#fffbeb',
  info: '#1d4ed8',
  infoBg: '#eff6ff',
  success: '#047857',
  successBg: '#ecfdf5',
};

const dark: typeof light = {
  background: '#0b0f14',
  card: '#151b23',
  text: '#f3f4f6',
  muted: '#9ca3af',
  border: '#273140',
  primary: '#2dd4bf',
  primaryText: '#042f2e',
  danger: '#f87171',
  dangerBg: '#2a1215',
  warning: '#fbbf24',
  warningBg: '#2a2010',
  info: '#93c5fd',
  infoBg: '#0f1d33',
  success: '#34d399',
  successBg: '#0c2a20',
};

export type Theme = typeof light;

export function useTheme(): Theme {
  return useColorScheme() === 'dark' ? dark : light;
}

export const spacing = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24 };
