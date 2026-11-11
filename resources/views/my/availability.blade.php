<x-app-layout title="Mijn beschikbaarheid">
    <x-slot name="header">
        <h1 class="page-title">Mijn beschikbaarheid</h1>
    </x-slot>

    <form method="POST" action="{{ route('my.availability.update') }}" class="max-w-4xl space-y-4">
        @csrf @method('PUT')
        <p class="text-sm text-gray-600">Geef door wanneer je normaal gesproken wel en niet kunt werken. De planner houdt hier rekening mee bij het inplannen. Diensten waarvoor je al bent ingepland, blijven staan.</p>

        @include('employees.partials.availability', ['self' => true, 'absenceUrl' => route('my.absences.create')])

        <button type="submit" class="btn">Beschikbaarheid opslaan</button>
    </form>
</x-app-layout>
