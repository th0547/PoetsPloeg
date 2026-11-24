@php
    $days = config('poetsploeg.weekdays');
    $short = config('poetsploeg.weekdays_short');
    $selectedSkills = collect(old('skills', $template->skills->pluck('id')))->map(fn ($i) => (int) $i)->all();
@endphp
<x-app-layout :title="$template->exists ? $template->name : 'Nieuwe taak'">
    <x-slot name="header">
        <div>
            <p class="text-sm text-gray-500">{{ $building->name }}</p>
            <h1 class="page-title">{{ $template->exists ? $template->name : 'Nieuwe vaste taak' }}</h1>
        </div>
        <a href="{{ route('buildings.show', $building) }}" class="btn-secondary">‹ Terug naar pand</a>
    </x-slot>

    {{-- Herhaling en vaste bezetting: één Alpine-component op het formulier (resources/js/recurrence.js en staffing.js).
         De beginstand gaat via @js() naar Alpine (JSON), nooit als losse tekst in een Alpine-attribuut. --}}
    <form method="POST" action="{{ $template->exists ? route('buildings.templates.update', [$building, $template]) : route('buildings.templates.store', $building) }}"
          class="max-w-4xl space-y-6" x-data="recurrenceEditor(@js($recurrence))">
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
                    <label class="label" for="required_staff">Aantal personen per dienst</label>
                    <input id="required_staff" type="number" min="1" max="50" name="required_staff" class="input" x-model.number="requiredStaff" value="{{ old('required_staff', $template->required_staff) }}" required>
                </div>
                <div class="sm:col-span-6">
                    <label class="label" for="description">Omschrijving / werkzaamheden</label>
                    <textarea id="description" name="description" rows="3" class="input" placeholder="Bijv. stofzuigen kantoren, sanitair, pantry, prullenbakken legen">{{ old('description', $template->description) }}</textarea>
                </div>
            </div>
        </div>

        {{-- Herhaling: één of meer patronen, elk met eigen dagen, tijden en vaste bezetting, met daaronder een
             voorbeeld van wat er wordt ingepland. --}}
        <div class="card card-body space-y-5" id="bezetting">
            <div>
                <h2 class="font-semibold text-gray-800">Wanneer en door wie</h2>
                <p class="text-xs text-gray-500">Per patroon kies je de dagen, de tijden en wie er vast op staat. Met meerdere patronen maak je bijvoorbeeld “ma, wo en vr Henk, di en do Jan” of “de ene week donderdag, de week erop maandag”. Onderaan zie je direct op welke dagen er wordt ingepland.</p>
            </div>

            @error('staffing')
                <div class="notice notice-warning">
                    <x-icon name="alert" class="mt-0.5 text-amber-600" />
                    <div>
                        <p class="font-medium">Niet iedereen kan alle diensten van zijn of haar patroon draaien:</p>
                        <ul class="ms-5 mt-1 list-disc">@foreach ($errors->get('staffing') as $message)<li>{{ $message }}</li>@endforeach</ul>
                        <label class="mt-2 inline-flex items-center gap-2 font-medium"><input type="checkbox" class="checkbox" x-model="staffConfirmed"> Toch zo opslaan; open plekken vul ik zelf in</label>
                    </div>
                </div>
            @enderror
            <input type="hidden" name="confirm_staffing" :value="staffConfirmed ? 1 : 0" value="0">

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
                    <input type="hidden" :name="`patterns[${i}][id]`" :value="p.id" :disabled="!p.id">
                    <input type="hidden" :name="`patterns[${i}][recurrence]`" :value="recurrenceOf(p)">
                    <input type="hidden" :name="`patterns[${i}][repeat_every]`" :value="p.every" :disabled="p.mode === 'code'">
                    <template x-for="d in (p.mode === 'weekly' ? p.weekdays : [])" :key="d">
                        <input type="hidden" :name="`patterns[${i}][weekdays][]`" :value="d">
                    </template>
                    <input type="hidden" :name="`patterns[${i}][nth]`" :value="p.nth" :disabled="recurrenceOf(p) !== 'monthly_nth_weekday'">
                    <input type="hidden" :name="`patterns[${i}][weekday]`" :value="p.weekday" :disabled="recurrenceOf(p) !== 'monthly_nth_weekday'">
                    <input type="hidden" :name="`patterns[${i}][day_of_month]`" :value="p.dayOfMonth" :disabled="recurrenceOf(p) !== 'monthly_day'">
                    <template x-for="id in p.employees" :key="'emp' + id">
                        <input type="hidden" :name="`patterns[${i}][employees][]`" :value="id">
                    </template>

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

                    {{-- Tijden en telt vanaf --}}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <span class="label">Van – tot</span>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <input type="time" class="input mt-0 w-32" :name="`patterns[${i}][start_time]`" x-model="p.startTime" required :aria-label="`Begintijd patroon ${i + 1}`">
                                <span class="text-gray-500">–</span>
                                <input type="time" class="input mt-0 w-32" :name="`patterns[${i}][end_time]`" x-model="p.endTime" required :aria-label="`Eindtijd patroon ${i + 1}`">
                            </div>
                            <p class="mt-1 text-xs text-gray-500" x-show="p.startTime && p.endTime && p.endTime <= p.startTime" x-cloak>Eindtijd vóór begintijd: tot de volgende dag.</p>
                            @foreach (['start_time', 'end_time'] as $field)
                                @foreach ($errors->get('patterns.*.'.$field) as $key => $messages)
                                    <p class="field-error" x-show="i === {{ (int) explode('.', $key)[1] }}" x-cloak>{{ $messages[0] }}</p>
                                @endforeach
                            @endforeach
                        </div>
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
                    </div>

                    {{-- Vaste bezetting van dit patroon --}}
                    <div class="space-y-2 border-t border-gray-100 pt-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="label">Vaste bezetting</span>
                            <span class="text-xs text-gray-500" x-show="staffTotal(i) !== null" x-cloak>
                                getoetst op <span x-text="staffTotal(i)"></span> <span x-text="staffTotal(i) === 1 ? 'dienst' : 'diensten'"></span> in de komende {{ config('poetsploeg.generate_weeks_ahead') }} weken
                                <span x-show="staffLoading" class="text-gray-400">· bijwerken…</span>
                            </span>
                        </div>
                        <ul class="space-y-1.5" x-show="p.employees.length">
                            <template x-for="(id, n) in p.employees" :key="'chip' + id">
                                <li class="flex items-start gap-2.5 rounded-lg border px-3 py-2"
                                    :class="staffResult(i, id)?.status && staffResult(i, id).status !== 'ok' ? 'border-amber-300 bg-amber-50/60' : 'border-teal-300 bg-teal-50/50'">
                                    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="staffDot(i, id)"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-x-2 text-sm font-medium text-gray-900">
                                            <span x-text="staffEmployee(id).name"></span>
                                            <span class="text-xs font-normal text-teal-700" x-show="staffEmployee(id).known" title="Ingewerkt op dit pand">✓ kent pand</span>
                                            <span class="text-xs font-normal text-amber-700" x-show="n >= requiredStaff" x-cloak>reserve: komt alleen in beeld als iemand anders niet kan</span>
                                        </span>
                                        <span class="block text-xs font-medium" :class="staffCls(i, id)" x-text="staffResult(i, id)?.summary ?? ''"></span>
                                        <span class="block text-xs text-gray-600" x-show="staffDetail(i, id)" x-text="staffDetail(i, id)"></span>
                                    </span>
                                    <button type="button" class="text-sm text-gray-400 hover:text-red-600" @click="removeStaff(p, id)" :aria-label="`${staffEmployee(id).name} weghalen`">✕</button>
                                </li>
                            </template>
                        </ul>
                        <div class="flex flex-wrap items-center gap-2">
                            <select class="input mt-0 w-auto max-w-full" @change="addStaff(p, $event)" :aria-label="`Vaste kracht toevoegen aan patroon ${i + 1}`">
                                <option value="" x-text="p.employees.length ? '+ Nog iemand toevoegen…' : '+ Vaste kracht kiezen…'"></option>
                                <template x-for="e in staffCandidates(i)" :key="'opt' + e.id">
                                    <option :value="e.id" x-text="staffOption(i, e)"></option>
                                </template>
                            </select>
                            <span class="text-xs text-gray-500" x-show="!p.employees.length">Geen vaste kracht: de diensten van dit patroon komen bij Open diensten.</span>
                        </div>
                    </div>
                </div>
            </template>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="btn-secondary" @click="addPattern()" :disabled="patterns.length >= maxPatterns">+ Patroon toevoegen</button>
                <p class="text-xs text-gray-500" x-show="patterns.length < maxPatterns">Een nieuw wekelijks patroon krijgt de werkdagen die nog vrij zijn (handig voor “om de dag”); bij om de week begint het een week later.</p>
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
