@php
    $days = config('poetsploeg.weekdays');
    $short = config('poetsploeg.weekdays_short');
    $selectedSkills = collect(old('skills', $template->skills->pluck('id')))->map(fn ($i) => (int) $i)->all();
    $selectedEmployees = collect(old('employees', $template->employees->pluck('id')))->map(fn ($i) => (int) $i)->all();
    $sortedEmployees = $employees->sortBy(fn ($e) => [in_array($e->id, $knownIds) ? 0 : 1, $e->name]);
@endphp
<x-app-layout :title="$template->exists ? $template->name : 'Nieuwe taak'">
    <x-slot name="header">
        <div>
            <p class="text-sm text-gray-500">{{ $building->name }}</p>
            <h1 class="page-title">{{ $template->exists ? $template->name : 'Nieuwe vaste taak' }}</h1>
        </div>
        <a href="{{ route('buildings.show', $building) }}" class="btn-secondary">‹ Terug naar pand</a>
    </x-slot>

    <form method="POST" action="{{ $template->exists ? route('buildings.templates.update', [$building, $template]) : route('buildings.templates.store', $building) }}"
          class="max-w-4xl space-y-6"
          x-data="{ recurrence: @js(old('recurrence', $template->recurrence)), every: @js((int) old('repeat_every', $template->repeat_every)) }">
        @csrf
        @if ($template->exists) @method('PUT') @endif

        <div class="card card-body space-y-4">
            <h2 class="font-semibold text-gray-800">Taak</h2>
            <div class="grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-4">
                    <label class="label" for="name">Naam</label>
                    <input id="name" name="name" class="input" value="{{ old('name', $template->name) }}" placeholder="Bijv. Dagelijkse schoonmaak, Glasbewassing" required autofocus>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="required_staff">Aantal personen</label>
                    <input id="required_staff" type="number" min="1" max="50" name="required_staff" class="input" value="{{ old('required_staff', $template->required_staff) }}" required>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="start_time">Van</label>
                    <input id="start_time" type="time" name="start_time" class="input" value="{{ old('start_time', substr($template->start_time, 0, 5)) }}" required>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="end_time">Tot</label>
                    <input id="end_time" type="time" name="end_time" class="input" value="{{ old('end_time', substr($template->end_time, 0, 5)) }}" required>
                    <p class="mt-1 text-xs text-gray-500">Eindtijd vóór begintijd = tot de volgende dag.</p>
                </div>
                <div class="sm:col-span-6">
                    <label class="label" for="description">Omschrijving / werkzaamheden</label>
                    <textarea id="description" name="description" rows="3" class="input" placeholder="Bijv. stofzuigen kantoren, sanitair, pantry, prullenbakken legen">{{ old('description', $template->description) }}</textarea>
                </div>
            </div>
        </div>

        <div class="card card-body space-y-4">
            <h2 class="font-semibold text-gray-800">Herhaling</h2>
            <div class="grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <label class="label" for="recurrence">Patroon</label>
                    <select id="recurrence" name="recurrence" class="input" x-model="recurrence">
                        @foreach (\App\Models\TaskTemplate::RECURRENCES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-3">
                    <label class="label" for="repeat_every">Elke</label>
                    <div class="flex items-center gap-2">
                        <input id="repeat_every" type="number" min="1" max="52" name="repeat_every" class="input w-24" x-model.number="every" required>
                        <span class="mt-1 text-sm text-gray-600" x-text="recurrence === 'weekly' ? (every === 1 ? 'week' : 'weken') : (every === 1 ? 'maand' : 'maanden')"></span>
                    </div>
                </div>

                <div class="sm:col-span-6" x-show="recurrence === 'weekly'">
                    <span class="label">Op</span>
                    @php($selectedDays = collect(old('weekdays', $template->weekdays ?? []))->map(fn ($i) => (int) $i)->all())
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach ($short as $num => $label)
                            <label class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm">
                                <input type="checkbox" name="weekdays[]" value="{{ $num }}" class="checkbox" @checked(in_array($num, $selectedDays))> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="sm:col-span-6 grid gap-4 sm:grid-cols-2" x-show="recurrence === 'monthly_nth_weekday'">
                    <div>
                        <label class="label" for="nth">Welke</label>
                        <select id="nth" name="nth" class="input" :disabled="recurrence !== 'monthly_nth_weekday'">
                            @foreach ([1 => 'Eerste', 2 => 'Tweede', 3 => 'Derde', 4 => 'Vierde', -1 => 'Laatste'] as $value => $label)
                                <option value="{{ $value }}" @selected((int) old('nth', $template->nth) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="weekday">Weekdag</label>
                        <select id="weekday" name="weekday" class="input" :disabled="recurrence !== 'monthly_nth_weekday'">
                            @foreach ($days as $num => $label)
                                <option value="{{ $num }}" @selected((int) old('weekday', $template->weekday) === $num)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="sm:col-span-2" x-show="recurrence === 'monthly_day'">
                    <label class="label" for="day_of_month">Dag van de maand</label>
                    <input id="day_of_month" type="number" min="1" max="31" name="day_of_month" class="input" value="{{ old('day_of_month', $template->day_of_month) }}" :disabled="recurrence !== 'monthly_day'">
                </div>

                <div class="sm:col-span-3">
                    <label class="label" for="valid_from">Vanaf</label>
                    <input id="valid_from" type="date" name="valid_from" class="input" value="{{ old('valid_from', $template->valid_from?->toDateString()) }}" required>
                    <p class="mt-1 text-xs text-gray-500">Bij “elke N weken/maanden” wordt vanaf deze datum geteld.</p>
                </div>
                <div class="sm:col-span-3">
                    <label class="label" for="valid_until">Tot en met (optioneel)</label>
                    <input id="valid_until" type="date" name="valid_until" class="input" value="{{ old('valid_until', $template->valid_until?->toDateString()) }}">
                </div>
                <div class="sm:col-span-6 flex flex-wrap gap-6">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="skip_holidays" value="1" class="checkbox" @checked(old('skip_holidays', $template->skip_holidays))> Niet op feestdagen
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="active" value="1" class="checkbox" @checked(old('active', $template->active))> Actief
                    </label>
                </div>
            </div>
        </div>

        <div class="card card-body space-y-3">
            <div>
                <h2 class="font-semibold text-gray-800">Vereiste vaardigheden</h2>
                <p class="text-xs text-gray-500">Vervangers moeten deze vaardigheden hebben.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @forelse ($skills as $skill)
                    <label class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm">
                        <input type="checkbox" name="skills[]" value="{{ $skill->id }}" class="checkbox" @checked(in_array($skill->id, $selectedSkills))> {{ $skill->name }}
                    </label>
                @empty
                    <p class="text-sm text-gray-500">Nog geen vaardigheden. <a href="{{ route('skills.index') }}" class="text-teal-700 hover:underline">Maak ze hier aan</a>.</p>
                @endforelse
            </div>
        </div>

        <div class="card card-body space-y-3">
            <div>
                <h2 class="font-semibold text-gray-800">Vaste bezetting</h2>
                <p class="text-xs text-gray-500">Deze medewerkers worden automatisch op elke dienst van deze taak gezet (maximaal het aantal personen). Bij verlof blijft de plek open en kun je een vervanger zoeken.</p>
            </div>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($sortedEmployees as $employee)
                    <label class="inline-flex items-center gap-1.5 text-sm">
                        <input type="checkbox" name="employees[]" value="{{ $employee->id }}" class="checkbox" @checked(in_array($employee->id, $selectedEmployees))>
                        {{ $employee->name }}
                        @if (in_array($employee->id, $knownIds))<span class="text-xs text-teal-700" title="Ingewerkt op dit pand">✓ pand</span>@endif
                    </label>
                @endforeach
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn">Opslaan en inplannen</button>
            @if ($template->exists)
                <p class="text-xs text-gray-500">Toekomstige diensten worden bijgewerkt. Handmatig aangepaste diensten blijven ongewijzigd.</p>
            @endif
        </div>
    </form>

    @if ($template->exists)
        <form method="POST" action="{{ route('buildings.templates.destroy', [$building, $template]) }}" class="mt-8" onsubmit="return confirm('Taak verwijderen? Toekomstige (niet aangepaste) diensten van deze taak verdwijnen uit de planning.')">
            @csrf @method('DELETE')
            <button type="submit" class="btn-danger">Taak verwijderen</button>
        </form>
    @endif
</x-app-layout>
