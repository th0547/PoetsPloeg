@php($days = config('poetsploeg.weekdays'))
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

            <div class="card card-body space-y-4" x-data="availabilityEditor(@js($patterns), @js($absenceBlocks))">
                <div>
                    <h2 class="font-semibold text-gray-800">Vaste beschikbaarheid <span class="chip ms-1 align-middle bg-teal-50 text-teal-800">↻ komt steeds terug</span></h2>
                    <p class="text-xs text-gray-500">
                        Het vaste weekpatroon: wanneer <strong class="text-teal-700">kan</strong> deze medewerker normaal gesproken wel, en wanneer <strong class="text-red-700">kan</strong> hij of zij <strong class="text-red-700">niet</strong> (bijv. school 08:00–15:00)?
                    </p>
                </div>

                {{-- Vast patroon hier, eenmalige afwijkingen via afwezigheid --}}
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-md border border-teal-200 bg-teal-50/60 px-3 py-2 text-sm text-teal-900">
                        <p class="font-semibold">↻ Hier: alleen wat steeds terugkomt</p>
                        <p class="mt-0.5 text-xs">Bijvoorbeeld “elke maandag vanaf 17:00” of “om de week op vrijdag niet”. Dit geldt elke week opnieuw, tot je het aanpast.</p>
                    </div>
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <p class="font-semibold">Eenmalig anders? Gebruik afwezigheid</p>
                        <p class="mt-0.5 text-xs">
                            Vakantie, ziekte, een afspraak of één dag niet kunnen leg je vast als afwezigheid. <strong>Afwezigheid gaat altijd vóór</strong> op dit weekpatroon.
                            @if ($employee->exists)
                                <a href="{{ route('absences.create', ['medewerker' => $employee->id]) }}" class="font-medium underline hover:text-amber-700">Afwezigheid registreren ›</a>
                            @endif
                        </p>
                    </div>
                </div>

                {{-- Weekoverzicht: per dag een tijdbalk --}}
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="inline-flex items-center gap-1">
                            <button type="button" class="btn-secondary btn-sm" @click="shiftWeek(-1)" aria-label="Vorige week">‹</button>
                            <button type="button" class="btn-secondary btn-sm" @click="thisWeek()" :class="isThisWeek() ? 'bg-gray-100' : ''">Deze week</button>
                            <button type="button" class="btn-secondary btn-sm" @click="shiftWeek(1)" aria-label="Volgende week">›</button>
                            <span class="ms-2 text-sm font-medium text-gray-700" x-text="weekLabel()"></span>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600">
                            <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded-sm bg-teal-300 ring-1 ring-teal-500"></span>kan wel</span>
                            <span class="inline-flex items-center gap-1"><span class="avail-blocked h-3 w-3 rounded-sm ring-1 ring-red-400"></span>kan niet</span>
                            <span class="inline-flex items-center gap-1"><span class="avail-absent h-3 w-3 rounded-sm ring-1 ring-gray-400"></span>afwezig (gaat vóór)</span>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <div class="min-w-[36rem] text-xs">
                            <div class="flex">
                                <div class="w-24 shrink-0"></div>
                                <div class="relative h-4 flex-1">
                                    <template x-for="t in hourTicks()" :key="t.label">
                                        <span class="absolute -translate-x-1/2 text-gray-400" :style="`left:${t.left}%`" x-text="t.label"></span>
                                    </template>
                                </div>
                                <div class="w-8 shrink-0"></div>
                            </div>
                            <template x-for="d in days()" :key="d.toISOString()">
                                <div class="flex items-center border-t border-gray-100 py-0.5">
                                    <div class="w-24 shrink-0 pe-2 font-medium" :class="isToday(d) ? 'text-teal-700' : 'text-gray-700'" x-text="dayLabel(d)"></div>
                                    <div class="relative h-7 flex-1 overflow-hidden rounded"
                                         :class="{ 'bg-gray-100': restIs() === 'unavailable', 'bg-teal-50': restIs() === 'available', 'bg-gray-50': restIs() === 'none' }">
                                        <template x-for="t in hourTicks()" :key="'g' + t.label">
                                            <span class="absolute inset-y-0 w-px bg-white/80" :style="`left:${t.left}%`"></span>
                                        </template>
                                        <template x-for="b in blocks(d)" :key="b.index">
                                            <button type="button" class="absolute inset-y-0.5 overflow-hidden whitespace-nowrap rounded px-1 text-left leading-6"
                                                    :class="b.type === 'unavailable' ? 'avail-blocked z-10 text-red-900 ring-1 ring-red-400' : 'bg-teal-300 text-teal-950 ring-1 ring-teal-500'"
                                                    :style="b.style" :title="b.title + ' – klik om te wijzigen'" x-text="b.label" @click="focus(b.index)"></button>
                                        </template>
                                        <template x-for="(a, ai) in absencesOn(d)" :key="'a' + ai">
                                            <div class="avail-absent absolute inset-y-0 z-20 overflow-hidden whitespace-nowrap px-1 font-medium leading-7 text-gray-800 ring-1 ring-gray-400"
                                                 :style="a.style" :title="a.label + ' – gaat vóór het weekpatroon'" x-text="a.label"></div>
                                        </template>
                                    </div>
                                    <button type="button" class="ms-1 w-7 shrink-0 rounded text-base leading-6 text-gray-400 hover:bg-teal-50 hover:text-teal-700"
                                            title="Tijdvak toevoegen op deze dag" aria-label="Tijdvak toevoegen op deze dag" @click="add('available', ((d.getDay() + 6) % 7) + 1)">+</button>
                                </div>
                            </template>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">
                        <span x-show="restIs() === 'none'">Nog niets ingevuld: de medewerker geldt op alle tijden als beschikbaar (geen voorkeur).</span>
                        <span x-show="restIs() === 'available'" x-cloak>Buiten de rode blokken geldt de medewerker als beschikbaar.</span>
                        <span x-show="restIs() === 'unavailable'" x-cloak>Buiten de groene blokken (grijs) geldt de medewerker als niet beschikbaar. Rood (“kan niet”) gaat altijd vóór groen.</span>
                        <span x-show="weekHasAbsence()" x-cloak>In deze week staat afwezigheid: die gaat vóór het patroon.</span>
                    </p>
                </div>

                {{-- Tijdvakken: gesorteerd op dag en tijd bij openen en na opslaan; tijdens bewerken blijven regels op hun plek. --}}
                <div class="space-y-2">
                    <h3 class="text-sm font-semibold text-gray-800">Tijdvakken</h3>
                    <p x-show="!patterns.length" class="text-sm text-gray-500">Nog geen tijdvakken. Voeg hieronder toe wat elke week terugkomt.</p>
                    <template x-for="(p, i) in patterns" :key="i">
                        <div class="flex flex-wrap items-center gap-2 rounded-md border-l-4 px-2 py-1.5 transition-shadow" :data-pattern="i"
                             :class="[p.type === 'unavailable' ? 'border-red-400 bg-red-50/60' : 'border-teal-400 bg-teal-50/40', highlight === i ? 'ring-2 ring-teal-500' : '']">
                            <select :name="`patterns[${i}][type]`" x-model="p.type" aria-label="Kan wel of kan niet"
                                    class="rounded-md border-gray-300 py-1 text-sm font-medium shadow-sm" :class="p.type === 'unavailable' ? 'text-red-700' : 'text-teal-700'">
                                @foreach (\App\Models\AvailabilityPattern::TYPES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <select :name="`patterns[${i}][repeat]`" x-model="p.repeat" @change="repeatChanged(p)" aria-label="Herhaling"
                                    class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                                @foreach (\App\Models\AvailabilityPattern::REPEATS as $value => [$unit, $every, $label])
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <select :name="`patterns[${i}][weekday]`" x-model.number="p.weekday" x-show="!isDaily(p)" aria-label="Dag"
                                    class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                                @foreach ($days as $num => $day)
                                    <option value="{{ $num }}">{{ ucfirst($day) }}</option>
                                @endforeach
                            </select>
                            <label x-show="needsStart(p)" class="inline-flex items-center gap-1 text-xs text-gray-600">
                                <span x-text="isDaily(p) ? 'vanaf' : 'vanaf week van'"></span>
                                <input type="date" :name="`patterns[${i}][starts_on]`" x-model="p.starts_on" :disabled="!needsStart(p)" aria-label="Vanaf"
                                       class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                            </label>
                            <span class="inline-flex items-center gap-1">
                                <input type="time" :name="`patterns[${i}][start_time]`" x-model="p.start_time" required aria-label="Van"
                                       class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                                <span class="text-sm text-gray-500">–</span>
                                <input type="time" :name="`patterns[${i}][end_time]`" x-model="p.end_time" required aria-label="Tot"
                                       class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                            </span>
                            <input type="text" :name="`patterns[${i}][note]`" x-model="p.note" maxlength="100" aria-label="Omschrijving"
                                   :placeholder="p.type === 'unavailable' ? 'Reden, bijv. school' : 'Opmerking'"
                                   class="min-w-0 flex-1 basis-32 rounded-md border-gray-300 py-1 text-sm shadow-sm">
                            <button type="button" @click="remove(i)" class="rounded p-1 text-gray-400 hover:bg-white hover:text-red-600" title="Verwijderen" aria-label="Verwijderen">&times;</button>
                        </div>
                    </template>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <button type="button" class="btn-secondary btn-sm" @click="add('available')">+ Kan wel</button>
                        <button type="button" class="btn-secondary btn-sm text-red-700" @click="add('unavailable')">+ Kan niet</button>
                        <button type="button" class="btn-secondary btn-sm" x-show="patterns.length && !isDaily(patterns[patterns.length - 1])" @click="copyToWeekdays()">
                            Laatste regel kopiëren naar ma t/m vr
                        </button>
                    </div>
                    <p class="text-xs text-gray-500">Herhaling “om de week”, “om de 3 weken” of “om de dag” telt vanaf de gekozen startdatum; blader hierboven door de weken om te zien wanneer het geldt.</p>
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

                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nu en gepland</h3>
                        <ul class="divide-y text-sm">
                            @forelse ($absences as $absence)
                                @include('employees.partials.absence-row')
                            @empty
                                <li class="py-2 text-gray-500">Geen lopende of geplande afwezigheid.</li>
                            @endforelse
                        </ul>
                    </div>

                    {{-- De geschiedenis blijft altijd bewaard en is hier volledig terug te vinden. --}}
                    <div>
                        <div class="flex items-center justify-between">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Geschiedenis ({{ $absenceHistory->count() }})</h3>
                            <a href="{{ route('absences.index', ['medewerker' => $employee->id, 'periode' => 'alles']) }}" class="text-xs text-teal-700 hover:underline">Alles bekijken en filteren ›</a>
                        </div>
                        @if ($absenceHistory->isEmpty())
                            <p class="py-2 text-sm text-gray-500">Nog geen eerdere afwezigheid.</p>
                        @else
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($absenceHistory->where('status', 'approved')->groupBy('type') as $type => $items)
                                    <span class="chip {{ $items->first()->chipClass() }}" title="Aantal registraties in de geschiedenis">{{ $items->first()->typeLabel() }} {{ $items->count() }}×</span>
                                @endforeach
                            </div>
                            <ul class="mt-1 max-h-96 divide-y overflow-y-auto text-sm">
                                @foreach ($absenceHistory->groupBy(fn ($a) => $a->starts_at->year) as $year => $items)
                                    <li class="sticky top-0 bg-white py-1 text-xs font-semibold text-gray-500">{{ $year }}</li>
                                    @foreach ($items as $absence)
                                        @include('employees.partials.absence-row')
                                    @endforeach
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="card card-body space-y-2">
                    <h2 class="font-semibold text-gray-800">Planning</h2>
                    <a href="{{ route('planning', ['view' => 'employee', 'range' => 'week']) }}" class="text-sm text-teal-700 hover:underline">Bekijk in de weekplanning</a>
                </div>

                <form method="POST" action="{{ route('employees.destroy', $employee) }}" data-confirm="Medewerker definitief verwijderen? Alle toewijzingen én de verlof- en afwezigheidsgeschiedenis verdwijnen ook. Tip: zet de medewerker liever op inactief, dan blijft alles bewaard.">
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
