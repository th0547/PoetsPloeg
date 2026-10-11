<x-app-layout wide title="Kalender">
    <x-slot name="scripts">
        @vite(['resources/js/calendar.js'])
    </x-slot>

    <div class="rounded-lg bg-white p-2 sm:p-4 shadow-sm overflow-x-auto overscroll-x-contain">
        <div id="calendar" class="novon-calendar"></div>
    </div>
    <p class="mt-2 text-xs text-gray-500">Klik op een dienst voor details en vervangers. Diensten met een gestippelde rand hebben nog open plekken.</p>
</x-app-layout>
