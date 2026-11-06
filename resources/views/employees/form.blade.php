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

            <div class="card card-body space-y-3" x-data="availabilityEditor(@js($patterns))">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="font-semibold text-gray-800">Beschikbaarheid</h2>
                        <p class="text-xs text-gray-500">
                            Wanneer <strong class="text-teal-700">kan</strong> en wanneer <strong class="text-red-700">kan</strong> deze medewerker <strong class="text-red-700">niet</strong> werken (bijv. school 08:00–15:00)?
                            “Kan niet” gaat altijd voor. Alleen “kan niet” ingevuld? Dan geldt de rest als beschikbaar.
                        </p>
                    </div>
                    <div class="inline-flex rounded-md shadow-sm" role="group" aria-label="Weergave">
                        <button type="button" @click="view = 'list'" :class="view === 'list' ? 'bg-gray-800 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                class="rounded-l-md border border-gray-300 px-3 py-1 text-xs font-medium">Lijst</button>
                        <button type="button" @click="view = 'week'" :class="view === 'week' ? 'bg-gray-800 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                class="-ml-px rounded-r-md border border-gray-300 px-3 py-1 text-xs font-medium">Weekkalender</button>
                    </div>
                </div>

                {{-- Lijst: gesorteerd op dag en tijd bij openen en na opslaan; tijdens bewerken blijven regels op hun plek.
                     De velden worden ook in de kalenderweergave meegestuurd. --}}
                <div x-show="view === 'list'" class="space-y-2">
                    <p x-show="!patterns.length" class="text-sm text-gray-500">Nog niets ingevuld (geen voorkeur).</p>
                    <template x-for="(p, i) in patterns" :key="i">
                        <div class="flex flex-wrap items-center gap-2 rounded-md border-l-4 px-2 py-1.5"
                             :class="p.type === 'unavailable' ? 'border-red-400 bg-red-50/60' : 'border-teal-400 bg-teal-50/40'">
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
                    <p class="text-xs text-gray-500">Herhaling “om de week”, “om de 3 weken” of “om de dag” telt vanaf de gekozen startdatum.</p>
                </div>

                {{-- Weekkalender: zelfde gegevens, per week bekeken --}}
                <div x-show="view === 'week'" x-cloak class="space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="inline-flex items-center gap-1">
                            <button type="button" class="btn-secondary btn-sm" @click="shiftWeek(-1)" aria-label="Vorige week">‹</button>
                            <button type="button" class="btn-secondary btn-sm" @click="thisWeek()">Deze week</button>
                            <button type="button" class="btn-secondary btn-sm" @click="shiftWeek(1)" aria-label="Volgende week">›</button>
                            <span class="ms-2 text-sm font-medium text-gray-700" x-text="weekLabel()"></span>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-gray-600">
                            <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded-sm bg-teal-200 ring-1 ring-teal-400"></span>kan wel</span>
                            <span class="inline-flex items-center gap-1"><span class="avail-blocked h-3 w-3 rounded-sm ring-1 ring-red-400"></span>kan niet</span>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <div class="grid min-w-[40rem] grid-cols-[3rem_repeat(7,minmax(0,1fr))] text-xs">
                            <div></div>
                            <template x-for="d in days()" :key="d.toISOString()">
                                <div class="border-b px-1 pb-1 text-center font-medium text-gray-700" x-text="dayLabel(d)"></div>
                            </template>
                            <div class="relative" :style="`height:${hourLabels().length * 1.75}rem`">
                                <template x-for="(h, i) in hourLabels()" :key="h">
                                    <div class="absolute right-1 -translate-y-1/2 text-gray-400" :style="`top:${(i / hourLabels().length) * 100}%`" x-text="String(h).padStart(2, '0') + ':00'"></div>
                                </template>
                            </div>
                            <template x-for="d in days()" :key="'c' + d.toISOString()">
                                <div class="relative border-l border-gray-100 bg-[linear-gradient(to_bottom,#f3f4f6_1px,transparent_1px)]"
                                     :style="`background-size:100% 1.75rem;height:${hourLabels().length * 1.75}rem`">
                                    <template x-for="(b, j) in blocks(d)" :key="j">
                                        <div class="absolute overflow-hidden rounded px-1 py-0.5 leading-tight"
                                             :class="b.type === 'unavailable' ? 'avail-blocked inset-x-0.5 z-10 text-red-800 ring-1 ring-red-400' : 'inset-x-1.5 bg-teal-100 text-teal-900 ring-1 ring-teal-300'"
                                             :style="b.style" :title="(b.type === 'unavailable' ? 'Kan niet ' : 'Kan wel ') + b.label" x-text="b.label"></div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">Wijzigen doe je in de lijst; de kalender laat zien wat er per week geldt.</p>
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
                                    @if ($absence->status !== 'approved')<span class="chip {{ $absence->isPending() ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800' }}">{{ $absence->isPending() ? 'in afwachting' : 'afgewezen' }}</span>@endif
                                    @if ($absence->status !== 'approved')<span class="chip {{ $absence->isPending() ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800' }}">{{ $absence->isPending() ? 'in afwachting' : 'afgewezen' }}</span>@endif
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
