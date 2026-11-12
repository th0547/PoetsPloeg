@php
    $withdrawn = $status === 'ingetrokken';
    $filtered = collect($filters)->filter()->isNotEmpty();
@endphp
<x-app-layout title="Afwezigheid">
    <x-slot name="header">
        <h1 class="page-title">Verlof, ziekte &amp; uitval</h1>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('absences.export', ['jaar' => now()->year]) }}" class="btn-secondary" title="Uren en dagen per medewerker per soort, voor de loonadministratie">Export {{ now()->year }} (CSV)</a>
            <a href="{{ route('absences.index', ['status' => 'pending']) }}" class="btn-secondary">Openstaande verzoeken</a>
            <a href="{{ route('absences.create') }}" class="btn">+ Afwezigheid / uitval</a>
        </div>
    </x-slot>

    {{-- Filter: zo is ook oude afwezigheid per medewerker en per soort terug te vinden. --}}
    <form method="GET" action="{{ route('absences.index') }}" class="card card-body mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <label class="label" for="f-medewerker">Medewerker</label>
            <select id="f-medewerker" name="medewerker" class="input">
                <option value="">Alle medewerkers</option>
                @foreach ($employees as $employee)
                    <option value="{{ $employee->id }}" @selected((int) ($filters['medewerker'] ?? 0) === $employee->id)>{{ $employee->name }}{{ $employee->active ? '' : ' (inactief)' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="f-soort">Soort</label>
            <select id="f-soort" name="soort" class="input">
                <option value="">Alle soorten</option>
                @foreach (\App\Support\Policy::current()->leaveTypeGroups(false) as $group => $types)
                    <optgroup label="{{ $group }}">
                        @foreach ($types as $value => $name)
                            <option value="{{ $value }}" @selected(($filters['soort'] ?? null) === $value)>{{ $name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="f-periode">Periode</label>
            <select id="f-periode" name="periode" class="input">
                @foreach (\App\Http\Controllers\AbsenceController::PERIODS as $value => $label)
                    <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="f-status">Status</label>
            <select id="f-status" name="status" class="input">
                <option value="">Alle (zonder ingetrokken)</option>
                @foreach (\App\Http\Controllers\AbsenceController::STATUS_FILTERS as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="btn">Filteren</button>
            @if ($filtered)
                <a href="{{ route('absences.index') }}" class="btn-secondary">Wissen</a>
            @endif
        </div>
    </form>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead class="bg-gray-50"><tr><th>Medewerker</th><th>Soort</th><th>Van</th><th>Tot en met</th><th>Uren</th><th>Notitie</th><th></th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($absences as $absence)
                    <tr class="{{ ! $withdrawn && $absence->starts_at->isPast() && $absence->ends_at->isFuture() ? 'bg-amber-50/50' : '' }} {{ $withdrawn ? 'text-gray-500' : '' }}">
                        <td class="font-medium"><a href="{{ route('employees.edit', $absence->employee) }}" class="hover:text-teal-700">{{ $absence->employee->name }}</a></td>
                        <td class="whitespace-nowrap">
                            <span class="chip {{ $absence->chipClass() }}">{{ $absence->typeLabel() }}</span>
                            @if ($withdrawn)
                                <span class="chip bg-gray-200 text-gray-700">ingetrokken</span>
                            @elseif ($absence->status !== 'approved')
                                <span class="chip {{ $absence->isPending() ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800' }}">{{ $absence->isPending() ? 'in afwachting' : 'afgewezen' }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">{{ $absence->starts_at->translatedFormat('D j M Y H:i') }}</td>
                        <td class="whitespace-nowrap">{{ $absence->ends_at->translatedFormat('D j M Y H:i') }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $absence->hoursLabel() }}</td>
                        <td class="text-gray-600">{{ $absence->note }}</td>
                        <td class="text-right whitespace-nowrap">
                            @if ($withdrawn)
                                <span class="text-xs text-gray-500">ingetrokken op {{ $absence->deleted_at->translatedFormat('j M Y') }}</span>
                            @else
                                <a href="{{ route('absences.show', $absence) }}" class="text-sm text-teal-700 hover:underline">{{ $absence->isPending() ? 'Vervangers zoeken' : 'Bekijken' }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-gray-500">
                        @if ($filtered || $period !== 'huidig')
                            Geen afwezigheid gevonden met dit filter.
                        @else
                            Geen afwezigheid gepland.
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $absences->withQueryString()->links() }}</div>
</x-app-layout>
