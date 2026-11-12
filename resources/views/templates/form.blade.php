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
          class="max-w-4xl space-y-6">
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

        {{-- Herhaling: één of meer patronen, met daaronder een voorbeeld van wat er wordt ingepland.
             De beginstand gaat via @js() naar Alpine (JSON), nooit als losse tekst in een Alpine-attribuut. --}}
        <div class="card card-body space-y-5" x-data="recurrenceEditor(@js($recurrence))" @input.window="syncTaskTimes($event)">
            <div>
                <h2 class="font-semibold text-gray-800">Herhaling</h2>
                <p class="text-xs text-gray-500">Kies hoe vaak deze taak terugkomt. Met meerdere patronen maak je bijvoorbeeld “de ene week donderdag, de week erop maandag”. Onderaan zie je direct op welke dagen er wordt ingepland.</p>
            </div>

            <template x-for="(p, i) in patterns" :key="p.uid">
                <div class="space-y-4 rounded-lg border border-l-4 border-gray-200 p-4" :class="color(i).border">
                    {{-- Kop --}}
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 font-semibold text-gray-900">
                                <span class="h-3 w-3 shrink-0 rounded-sm" :class="color(i).dot"></span>
                                <span x-text="patterns.length > 1 ? 'Patroon ' + (i + 1) : 'Patroon'"></span>
                                <span class="chip" x-text="timesOf(p)"></span>
                            </p>
                            <p class="mt-0.5 text-sm text-gray-600" x-text="summary(p)"></p>
                        </div>
                        <button type="button" class="text-sm text-red-600 hover:underline" x-show="patterns.length > 1" @click="removePattern(i)">Verwijderen</button>
                    </div>

                    {{-- Verborgen velden voor het formulier --}}
                    <input type="hidden" :name="`patterns[${i}][recurrence]`" :value="recurrenceOf(p)">
                    <input type="hidden" :name="`patterns[${i}][repeat_every]`" :value="p.every" :disabled="p.mode === 'code'">
                    <template x-for="d in (p.mode === 'weekly' ? p.weekdays : [])" :key="d">
                        <input type="hidden" :name="`patterns[${i}][weekdays][]`" :value="d">
                    </template>
                    <input type="hidden" :name="`patterns[${i}][nth]`" :value="p.nth" :disabled="recurrenceOf(p) !== 'monthly_nth_weekday'">
                    <input type="hidden" :name="`patterns[${i}][weekday]`" :value="p.weekday" :disabled="recurrenceOf(p) !== 'monthly_nth_weekday'">
                    <input type="hidden" :name="`patterns[${i}][day_of_month]`" :value="p.dayOfMonth" :disabled="recurrenceOf(p) !== 'monthly_day'">
                    <input type="hidden" :name="`patterns[${i}][own_times]`" :value="p.ownTimes ? 1 : 0">

                    {{-- Per week of per maand --}}
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button type="button" class="rounded-lg border-2 px-3 py-2 text-left transition-colors" :class="choice(p.mode === 'weekly')" @click="setMode(p, 'weekly')" :aria-pressed="p.mode === 'weekly'">
                            <span class="font-semibold text-gray-900">Wekelijks</span>
                            <span class="block text-xs text-gray-600">Op vaste dagen van de week, ook om de week.</span>
                        </button>
                        <button type="button" class="rounded-lg border-2 px-3 py-2 text-left transition-colors" :class="choice(p.mode === 'monthly')" @click="setMode(p, 'monthly')" :aria-pressed="p.mode === 'monthly'">
                            <span class="font-semibold text-gray-900">Maandelijks</span>
                            <span class="block text-xs text-gray-600">Eén keer per maand of minder vaak.</span>
                        </button>
                    </div>

                    {{-- Wekelijks: dagen van de week --}}
                    <div x-show="p.mode === 'weekly'">
                        <span class="label">Op welke dagen?</span>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            @foreach ($short as $num => $label)
                                <button type="button" class="h-10 w-12 rounded-md border text-sm font-semibold uppercase transition-colors" title="{{ ucfirst($days[$num]) }}"
                                        :class="pill(p.weekdays.includes({{ $num }}))" :aria-pressed="p.weekdays.includes({{ $num }})" @click="toggleWeekday(p, {{ $num }})">{{ $label }}</button>
                            @endforeach
                            <span class="ms-1 text-xs text-gray-500">
                                Snel kiezen:
                                <button type="button" class="text-teal-700 hover:underline" @click="p.weekdays = [1, 2, 3, 4, 5]">ma t/m vr</button> ·
                                <button type="button" class="text-teal-700 hover:underline" @click="p.weekdays = [1, 2, 3, 4, 5, 6, 7]">elke dag</button>
                            </span>
                        </div>
                        <p class="field-error" x-show="p.mode === 'weekly' && !p.weekdays.length" x-cloak>Kies minimaal één dag.</p>
                    </div>

                    {{-- Maandelijks: op een weekdag of op een vaste datum --}}
                    <div x-show="p.mode === 'monthly'" x-cloak class="space-y-3">
                        <span class="label">Wanneer in de maand?</span>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="rounded-md border px-3 py-2 text-sm font-medium transition-colors" :class="pill(p.monthlyKind === 'nth')" @click="p.monthlyKind = 'nth'">Op een weekdag</button>
                            <button type="button" class="rounded-md border px-3 py-2 text-sm font-medium transition-colors" :class="pill(p.monthlyKind === 'day')" @click="p.monthlyKind = 'day'">Op een vaste datum</button>
                        </div>
                        <div x-show="p.monthlyKind === 'nth'" class="space-y-2 rounded-md bg-gray-50 p-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="w-8 text-sm text-gray-600">De</span>
                                @foreach ([1 => 'eerste', 2 => 'tweede', 3 => 'derde', 4 => 'vierde', -1 => 'laatste'] as $value => $label)
                                    <button type="button" class="rounded-md border px-3 py-1.5 text-sm font-medium transition-colors" :class="pill(p.nth === {{ $value }})" @click="p.nth = {{ $value }}">{{ $label }}</button>
                                @endforeach
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="w-8"></span>
                                @foreach ($short as $num => $label)
                                    <button type="button" class="h-9 w-11 rounded-md border text-sm font-semibold uppercase transition-colors" title="{{ ucfirst($days[$num]) }}"
                                            :class="pill(p.weekday === {{ $num }})" @click="p.weekday = {{ $num }}">{{ $label }}</button>
                                @endforeach
                                <span class="text-sm text-gray-600">van de maand</span>
                            </div>
                        </div>
                        <div x-show="p.monthlyKind === 'day'" class="rounded-md bg-gray-50 p-3">
                            <p class="text-sm text-gray-600">Op welke datum van de maand?</p>
                            <div class="mt-2 grid w-max grid-cols-7 gap-1">
                                @foreach (range(1, 31) as $n)
                                    <button type="button" class="h-9 w-9 rounded-md border text-sm transition-colors" :class="pill(p.dayOfMonth === {{ $n }})" @click="p.dayOfMonth = {{ $n }}">{{ $n }}</button>
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-gray-500" x-show="p.dayOfMonth > 28">Heeft een maand minder dagen? Dan wordt het de laatste dag van die maand.</p>
                        </div>
                    </div>

                    {{-- Hoe vaak --}}
                    <div>
                        <span class="label">Hoe vaak?</span>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <template x-for="preset in presets(p)" :key="p.mode + preset.every">
                                <button type="button" class="rounded-md border px-3 py-2 text-sm font-medium transition-colors" :class="pill(p.mode !== 'code' && !p.custom && p.every === preset.every)" @click="setEvery(p, preset.every)" x-text="preset.label"></button>
                            </template>
                            <button type="button" class="rounded-md border px-3 py-2 text-sm font-medium transition-colors" :class="pill(p.mode !== 'code' && p.custom)" @click="setCustomEvery(p)">Anders…</button>
                            <button type="button" class="rounded-md border px-3 py-2 text-sm font-medium transition-colors" :class="pill(p.mode === 'code')" @click="useCode(p)" title="Voor gevorderden: een eigen patroon met een code">Aangepast (code)…</button>
                            <span x-show="p.mode !== 'code' && p.custom" x-cloak class="inline-flex items-center gap-2 text-sm text-gray-700">
                                elke
                                <input type="number" min="1" max="52" x-model.number="p.every" aria-label="Aantal weken of maanden"
                                       class="w-20 rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                                <span x-text="unit(p)"></span>
                            </span>
                        </div>
                    </div>

                    {{-- Aangepast: eigen code (RRULE). Alleen tekst; de server controleert en normaliseert de code. --}}
                    <div x-show="p.mode === 'code'" x-cloak class="space-y-1">
                        <label class="label" :for="`rrule-${p.uid}`">Code (iCalendar RRULE)</label>
                        <input type="text" class="input font-mono" :id="`rrule-${p.uid}`" :name="`patterns[${i}][rrule]`" :disabled="p.mode !== 'code'"
                               x-model="p.rrule" @input="refreshCode(p)" maxlength="300" spellcheck="false" autocomplete="off" autocapitalize="characters"
                               placeholder="FREQ=WEEKLY;INTERVAL=2;BYDAY=TH" :aria-invalid="!!codeState(p).error">
                        <p class="text-xs text-red-700" x-show="codeState(p).error" x-text="codeState(p).error" role="alert"></p>
                        <p class="text-xs text-teal-800" x-show="!codeState(p).error && codeState(p).label" x-text="'✓ ' + codeState(p).label"></p>
                        <p class="text-xs text-gray-500">
                            Voor gevorderden: dezelfde notatie als Google Agenda en Outlook.
                            <a href="{{ route('help.recurrence') }}" target="_blank" rel="noopener" class="font-medium text-teal-700 hover:underline">Uitleg en voorbeelden ›</a>
                        </p>
                    </div>

                    {{-- Telt vanaf en tijden --}}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label" :for="`starts-on-${p.uid}`">Telt vanaf</label>
                            <input type="date" class="input" :id="`starts-on-${p.uid}`" :name="`patterns[${i}][starts_on]`" x-model="p.startsOn" @change="refreshCode(p, 0)">
                            <template x-if="anchorHint(p)">
                                <div class="mt-1 rounded-md px-2 py-1.5 text-xs" :class="anchorHint(p).warn ? 'bg-amber-50 text-amber-900' : 'text-gray-500'">
                                    <p x-text="anchorHint(p).text"></p>
                                    <button type="button" class="mt-1 font-semibold underline hover:text-amber-700" x-show="anchorHint(p).fix"
                                            @click="p.startsOn = anchorHint(p).fix.date" x-text="anchorHint(p).fix ? anchorHint(p).fix.label : ''"></button>
                                </div>
                            </template>
                        </div>
                        <div>
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                                <input type="checkbox" class="checkbox" x-model="p.ownTimes"> Andere tijden
                            </label>
                            <div class="mt-1 flex items-center gap-2" x-show="p.ownTimes" x-cloak>
                                <input type="time" class="input mt-0" :name="`patterns[${i}][start_time]`" x-model="p.startTime" :disabled="!p.ownTimes" aria-label="Begintijd van dit patroon">
                                <span class="text-gray-500">–</span>
                                <input type="time" class="input mt-0" :name="`patterns[${i}][end_time]`" x-model="p.endTime" :disabled="!p.ownTimes" aria-label="Eindtijd van dit patroon">
                            </div>
                            <p class="mt-1 text-xs text-gray-500" x-show="!p.ownTimes">Zelfde tijden als de taak (<span x-text="taskStart + '–' + taskEnd"></span>).</p>
                        </div>
                    </div>
                </div>
            </template>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="btn-secondary" @click="addPattern()" :disabled="patterns.length >= maxPatterns">+ Patroon toevoegen</button>
                <p class="text-xs text-gray-500" x-show="patterns.length < maxPatterns">Het nieuwe patroon begint een week later; handig voor een wisselend schema.</p>
                <p class="text-xs text-gray-500" x-show="patterns.length >= maxPatterns" x-cloak>Maximaal <span x-text="maxPatterns"></span> patronen per taak.</p>
            </div>

            {{-- Periode --}}
            <div class="grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <label class="label" for="valid_from">Vanaf</label>
                    <input id="valid_from" type="date" name="valid_from" class="input" x-model="validFrom" required>
                </div>
                <div class="sm:col-span-3">
                    <label class="label" for="valid_until">Tot en met (optioneel)</label>
                    <input id="valid_until" type="date" name="valid_until" class="input" x-model="validUntil">
                </div>
                <div class="sm:col-span-6 flex flex-wrap gap-6">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="skip_holidays" value="1" class="checkbox" x-model="skipHolidays"> Niet op feestdagen
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="active" value="1" class="checkbox" @checked(old('active', $template->active))> Actief
                    </label>
                </div>
            </div>

            {{-- Voorbeeld --}}
            <div class="rounded-lg border border-teal-200 bg-teal-50/60 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-800">Zo wordt het ingepland</p>
                <ul class="mt-1 space-y-0.5">
                    <template x-for="(p, i) in patterns" :key="'s' + p.uid">
                        <li class="flex items-start gap-2 text-sm text-gray-900">
                            <span class="mt-1 h-3 w-3 shrink-0 rounded-sm" :class="color(i).dot"></span>
                            <span><span class="font-semibold" x-text="summary(p)"></span> <span class="text-gray-600" x-text="'· ' + timesOf(p)"></span></span>
                        </li>
                    </template>
                </ul>
                <p class="mt-1 text-sm text-gray-600" x-text="periodLabel()"></p>
                <div class="mt-2 flex flex-wrap items-center gap-1 text-sm text-gray-700">
                    <span class="me-1">Eerstvolgende keren:</span>
                    <template x-for="item in next()" :key="item.key">
                        <span class="chip bg-white ring-1" :class="item.ring" x-text="item.label"></span>
                    </template>
                    <span class="text-gray-500" x-show="!next().length">geen (controleer de patronen en de periode)</span>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600">
                        <template x-for="(p, i) in patterns" :key="'l' + p.uid">
                            <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded-sm" :class="color(i).dot"></span><span x-text="patterns.length > 1 ? 'patroon ' + (i + 1) : 'wordt ingepland'"></span></span>
                        </template>
                        <span class="inline-flex items-center gap-1" x-show="skipHolidays"><span class="h-3 w-3 rounded-sm bg-amber-100 ring-1 ring-amber-300"></span>feestdag, wordt overgeslagen</span>
                    </div>
                    <div class="inline-flex items-center gap-1">
                        <button type="button" class="btn-secondary btn-sm" @click="previewOffset -= 3" :disabled="previewOffset <= 0" :class="previewOffset <= 0 ? 'opacity-40' : ''" aria-label="Vorige maanden">‹</button>
                        <button type="button" class="btn-secondary btn-sm" @click="previewOffset += 3" aria-label="Volgende maanden">›</button>
                    </div>
                </div>
                <div class="mt-2 grid gap-4 sm:grid-cols-3">
                    <template x-for="m in previewMonths()" :key="m.key">
                        <div class="rounded-md bg-white p-2 shadow-sm">
                            <p class="mb-1 text-center text-xs font-semibold capitalize text-gray-700" x-text="m.label"></p>
                            <div class="grid grid-cols-7 gap-0.5 text-center text-[11px]">
                                @foreach ($short as $label)
                                    <div class="font-medium uppercase text-gray-400">{{ $label }}</div>
                                @endforeach
                                <template x-for="(cell, ci) in m.cells" :key="m.key + ci">
                                    <div class="flex h-6 items-center justify-center rounded" :class="cell ? cell.classes : ''" :title="cell ? cell.title : ''" x-text="cell ? cell.day : ''"></div>
                                </template>
                            </div>
                        </div>
                    </template>
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
        <form method="POST" action="{{ route('buildings.templates.destroy', [$building, $template]) }}" class="mt-8" data-confirm="Taak verwijderen? Toekomstige (niet aangepaste) diensten van deze taak verdwijnen uit de planning.">
            @csrf @method('DELETE')
            <button type="submit" class="btn-danger">Taak verwijderen</button>
        </form>
    @endif
</x-app-layout>
