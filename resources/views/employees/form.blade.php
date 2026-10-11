@php($days = config('novon.weekdays'))
<x-app-layout :title="$employee->exists ? $employee->name : 'Nieuwe medewerker'">
    <x-slot name="header">
        <h1 class="page-title">{{ $employee->exists ? $employee->name : 'Nieuwe medewerker' }}</h1>
        <a href="{{ route('employees.index') }}" class="btn-secondary">‹ Alle medewerkers</a>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ $employee->exists ? route('employees.update', $employee) : route('employees.store') }}" class="lg:col-span-2 space-y-6">
            @csrf
            @if ($employee->exists) @method('PUT') @endif

            <div class="card card-body space-y-4">
                <h2 class="font-semibold text-gray-800">Gegevens</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="label" for="name">Naam</label>
                        <input id="name" name="name" class="input" value="{{ old('name', $employee->name) }}" required autofocus>
                    </div>
                    <div>
                        <label class="label" for="phone">Telefoon</label>
                        <input id="phone" name="phone" class="input" value="{{ old('phone', $employee->phone) }}">
                    </div>
                    <div>
                        <label class="label" for="email">E-mail</label>
                        <input id="email" type="email" name="email" class="input" value="{{ old('email', $employee->email) }}">
                    </div>
                    <div>
                        <label class="label" for="contract_hours_min">Contracturen per week (min.)</label>
                        <input id="contract_hours_min" type="number" step="0.5" min="0" max="80" name="contract_hours_min" class="input" value="{{ old('contract_hours_min', $employee->contract_hours_min) }}">
                    </div>
                    <div>
                        <label class="label" for="contract_hours_max">Contracturen per week (max.)</label>
                        <input id="contract_hours_max" type="number" step="0.5" min="0" max="80" name="contract_hours_max" class="input" value="{{ old('contract_hours_max', $employee->contract_hours_max) }}">
                    </div>
                    <div>
                        <label class="label" for="color">Kleur in planning</label>
                        <input id="color" type="color" name="color" class="mt-1 h-9 w-20 rounded border-gray-300" value="{{ old('color', $employee->color) }}">
                    </div>
                    <div class="flex items-end">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="hidden" name="active" value="0">
                            <input type="checkbox" name="active" value="1" class="checkbox" @checked(old('active', $employee->active))> Actief (inplanbaar)
                        </label>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="notes">Notities</label>
                        <textarea id="notes" name="notes" rows="2" class="input">{{ old('notes', $employee->notes) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="card card-body space-y-3">
                <div>
                    <h2 class="font-semibold text-gray-800">Vaardigheden</h2>
                    <p class="text-xs text-gray-500">Welke taken kan deze medewerker uitvoeren? <a href="{{ route('skills.index') }}" class="text-teal-700 hover:underline">Vaardigheden beheren</a></p>
                </div>
                @php($selectedSkills = collect(old('skills', $employee->skills->pluck('id')))->map(fn ($i) => (int) $i)->all())
                <div class="flex flex-wrap gap-2">
                    @forelse ($skills as $skill)
                        <label class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm">
                            <input type="checkbox" name="skills[]" value="{{ $skill->id }}" class="checkbox" @checked(in_array($skill->id, $selectedSkills))> {{ $skill->name }}
                        </label>
                    @empty
                        <p class="text-sm text-gray-500">Nog geen vaardigheden aangemaakt.</p>
                    @endforelse
                </div>
            </div>

            <div class="card card-body space-y-3">
                <div>
                    <h2 class="font-semibold text-gray-800">Pandkennis</h2>
                    <p class="text-xs text-gray-500">Op welke panden is deze medewerker ingewerkt (sleutel/alarm, werkwijze)? Deze medewerkers krijgen voorrang als vervanger.</p>
                </div>
                @php($selectedBuildings = collect(old('buildings', $employee->buildings->pluck('id')))->map(fn ($i) => (int) $i)->all())
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse ($buildings as $building)
                        <label class="inline-flex items-center gap-1.5 text-sm {{ $building->active ? '' : 'text-gray-400' }}">
                            <input type="checkbox" name="buildings[]" value="{{ $building->id }}" class="checkbox" @checked(in_array($building->id, $selectedBuildings))> {{ $building->name }}
                        </label>
                    @empty
                        <p class="text-sm text-gray-500">Nog geen panden aangemaakt.</p>
                    @endforelse
                </div>
            </div>

            <div class="card card-body space-y-3" x-data="{ patterns: @js($patterns) }">
                <div>
                    <h2 class="font-semibold text-gray-800">Beschikbaarheid (vast weekpatroon)</h2>
                    <p class="text-xs text-gray-500">Wanneer kan deze medewerker normaal werken? Meerdere tijdvakken per dag zijn mogelijk. Leeg = geen voorkeur opgegeven.</p>
                </div>
                <template x-for="(p, i) in patterns" :key="i">
                    <div class="flex flex-wrap items-center gap-2">
                        <select :name="`patterns[${i}][weekday]`" x-model.number="p.weekday" class="rounded-md border-gray-300 text-sm shadow-sm">
                            @foreach ($days as $num => $day)
                                <option value="{{ $num }}">{{ ucfirst($day) }}</option>
                            @endforeach
                        </select>
                        <input type="time" :name="`patterns[${i}][start_time]`" x-model="p.start_time" class="rounded-md border-gray-300 text-sm shadow-sm" required>
                        <span class="text-sm text-gray-500">tot</span>
                        <input type="time" :name="`patterns[${i}][end_time]`" x-model="p.end_time" class="rounded-md border-gray-300 text-sm shadow-sm" required>
                        <button type="button" class="text-sm text-red-600 hover:underline" @click="patterns.splice(i, 1)">verwijderen</button>
                    </div>
                </template>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn-secondary btn-sm" @click="patterns.push({ weekday: 1, start_time: '17:00', end_time: '22:00' })">+ Tijdvak</button>
                    <button type="button" class="btn-secondary btn-sm"
                            @click="const last = patterns[patterns.length - 1] ?? { start_time: '17:00', end_time: '22:00' }; patterns = [1,2,3,4,5].map(d => ({ weekday: d, start_time: last.start_time, end_time: last.end_time }))">
                        Ma t/m vr met dezelfde tijden
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <button type="submit" class="btn">Opslaan</button>
            </div>
        </form>

        <aside class="space-y-6">
            @if ($employee->exists)
                <div class="card card-body space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold text-gray-800">Verlof &amp; afwezigheid</h2>
                        <a href="{{ route('absences.create', ['medewerker' => $employee->id]) }}" class="text-sm text-teal-700 hover:underline">+ Toevoegen</a>
                    </div>
                    <ul class="divide-y text-sm">
                        @forelse ($absences as $absence)
                            <li class="py-2">
                                <a href="{{ route('absences.show', $absence) }}" class="hover:text-teal-700">
                                    <span class="chip {{ $absence->type === 'ziek' ? 'bg-red-100 text-red-800' : '' }}">{{ $absence->typeLabel() }}</span>
                                    {{ $absence->starts_at->format('d-m-Y H:i') }} t/m {{ $absence->ends_at->format('d-m-Y H:i') }}
                                </a>
                                @if ($absence->note)<p class="text-xs text-gray-500">{{ $absence->note }}</p>@endif
                            </li>
                        @empty
                            <li class="py-2 text-gray-500">Geen recente of geplande afwezigheid.</li>
                        @endforelse
                    </ul>
                </div>

                <div class="card card-body space-y-2">
                    <h2 class="font-semibold text-gray-800">Planning</h2>
                    <a href="{{ route('planning', ['view' => 'employee', 'range' => 'week']) }}" class="text-sm text-teal-700 hover:underline">Bekijk in de weekplanning</a>
                </div>

                <form method="POST" action="{{ route('employees.destroy', $employee) }}" onsubmit="return confirm('Medewerker definitief verwijderen? Alle toewijzingen verdwijnen ook. Tip: zet de medewerker liever op inactief.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn-danger w-full">Medewerker verwijderen</button>
                </form>
            @else
                <div class="card card-body text-sm text-gray-600">
                    Na het opslaan kun je verlof en afwezigheid voor deze medewerker vastleggen.
                </div>
            @endif
        </aside>
    </div>
</x-app-layout>
