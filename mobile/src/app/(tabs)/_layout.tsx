import { ComponentProps } from 'react';
import type { ColorValue } from 'react-native';
import { Tabs } from 'expo-router';
import Ionicons from '@expo/vector-icons/Ionicons';

import { useTheme } from '@/theme';

type IconName = ComponentProps<typeof Ionicons>['name'];

const icon = (name: IconName) =>
  function TabIcon({ color, size }: { color: ColorValue; size: number }) {
    return <Ionicons name={name} color={String(color)} size={size} />;
  };

export default function TabsLayout() {
  const t = useTheme();
  return (
    <Tabs
      screenOptions={{
        tabBarActiveTintColor: t.primary,
        tabBarInactiveTintColor: t.muted,
        tabBarStyle: { backgroundColor: t.card, borderTopColor: t.border },
        headerStyle: { backgroundColor: t.card },
        headerTitleStyle: { color: t.text },
        sceneStyle: { backgroundColor: t.background },
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Vandaag', tabBarIcon: icon('today-outline') }} />
      <Tabs.Screen name="rooster" options={{ title: 'Rooster', tabBarIcon: icon('calendar-outline') }} />
      <Tabs.Screen name="open" options={{ title: 'Open', tabBarIcon: icon('alert-circle-outline') }} />
      <Tabs.Screen name="afwezig" options={{ title: 'Afwezig', tabBarIcon: icon('medkit-outline') }} />
      <Tabs.Screen name="meer" options={{ title: 'Meer', tabBarIcon: icon('ellipsis-horizontal') }} />
    </Tabs>
  );
}
