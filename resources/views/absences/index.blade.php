<x-app-layout title="Afwezigheid">
    <x-slot name="header">
        <h1 class="page-title">Verlof, ziekte &amp; uitval</h1>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('absences.index', ['status' => 'pending']) }}" class="btn-secondary">Openstaande verzoeken</a>
            <a href="{{ route('absences.index', $past ? [] : ['verleden' => 1]) }}" class="btn-secondary">{{ $past ? 'Huidig en gepland' : 'Verleden' }}</a>
            <a href="{{ route('absences.create') }}" class="btn">+ Afwezigheid / uitval</a>
        </div>
    </x-slot>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead class="bg-gray-50"><tr><th>Medewerker</th><th>Soort</th><th>Van</th><th>Tot en met</th><th>Notitie</th><th></th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($absences as $absence)
                    <tr class="{{ $absence->starts_at->isPast() && $absence->ends_at->isFuture() ? 'bg-amber-50/50' : '' }}">
                        <td class="font-medium"><a href="{{ route('employees.edit', $absence->employee) }}" class="hover:text-teal-700">{{ $absence->employee->name }}</a></td>
                        <td class="whitespace-nowrap">
                            <span class="chip {{ $absence->type === 'ziek' ? 'bg-red-100 text-red-800' : '' }}">{{ $absence->typeLabel() }}</span>
                            @if ($absence->status !== 'approved')
                                <span class="chip {{ $absence->isPending() ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800' }}">{{ $absence->isPending() ? 'in afwachting' : 'afgewezen' }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">{{ $absence->starts_at->translatedFormat('D j M Y H:i') }}</td>
                        <td class="whitespace-nowrap">{{ $absence->ends_at->translatedFormat('D j M Y H:i') }}</td>
                        <td class="text-gray-600">{{ $absence->note }}</td>
                        <td class="text-right whitespace-nowrap">
                            <a href="{{ route('absences.show', $absence) }}" class="text-sm text-teal-700 hover:underline">{{ $absence->isPending() ? 'Vervangers zoeken' : 'Bekijken' }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-gray-500">Geen afwezigheid {{ $past ? 'in het verleden' : 'gepland' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $absences->withQueryString()->links() }}</div>
</x-app-layout>
