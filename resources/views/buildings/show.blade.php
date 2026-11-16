<x-app-layout :title="$building->name">
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white" style="background: {{ $building->color }}"><x-icon name="building" class="icon-lg" /></span>
            <div class="min-w-0">
                <h1 class="page-title truncate">{{ $building->name }} @unless ($building->active) <span class="badge badge-gray align-middle">inactief</span> @endunless</h1>
                <p class="truncate text-sm text-gray-500">{{ $building->fullAddress() ?: 'Geen adres' }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('buildings.index') }}" class="btn-ghost"><x-icon name="chevron-left" />Panden</a>
            <a href="{{ route('planning', ['view' => 'building', 'range' => 'week', 'q' => $building->name]) }}" class="btn-secondary"><x-icon name="grid" />In planning</a>
            <a href="{{ route('shifts.create', ['pand' => $building->id]) }}" class="btn-secondary"><x-icon name="plus" />Losse opdracht</a>
            <a href="{{ route('buildings.edit', $building) }}" class="btn"><x-icon name="edit" />Bewerken</a>
        </div>
    </x-slot>

    {{-- Nieuw pand: wat moet er nog gebeuren voordat de diensten vanzelf in de planning staan? --}}
    @php
        $hasTasks = $building->taskTemplates->isNotEmpty();
        $hasStaff = $building->taskTemplates->contains(fn ($t) => $t->employees->isNotEmpty());
    @endphp
    @unless ($hasTasks && $hasStaff)
        <ol class="card mb-6 grid gap-px overflow-hidden bg-gray-200 sm:grid-cols-3">
            <li class="flex items-start gap-3 bg-white p-4">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-teal-600 text-white"><x-icon name="check" /></span>
                <div><p class="text-sm font-semibold text-gray-900">1. Pandgegevens</p><p class="text-xs text-gray-500">Adres, contact en toegang staan erin.</p></div>
            </li>
            <li class="flex items-start gap-3 bg-white p-4">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $hasTasks ? 'bg-teal-600 text-white' : 'bg-amber-100 text-amber-800 ring-2 ring-amber-300' }}">@if ($hasTasks)<x-icon name="check" />@else<span class="text-xs font-bold">2</span>@endif</span>
                <div>
                    <p class="text-sm font-semibold text-gray-900">2. Vaste taken</p>
                    <p class="text-xs text-gray-500">Wat gebeurt er wanneer? Bijvoorbeeld dagelijkse schoonmaak ma t/m vr 18:00–20:00.</p>
                    @unless ($hasTasks)<a href="{{ route('buildings.templates.create', $building) }}" class="btn btn-sm mt-2"><x-icon name="plus" class="icon-sm" />Eerste taak toevoegen</a>@endunless
                </div>
            </li>
            <li class="flex items-start gap-3 bg-white p-4">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $hasStaff ? 'bg-teal-600 text-white' : ($hasTasks ? 'bg-amber-100 text-amber-800 ring-2 ring-amber-300' : 'bg-gray-100 text-gray-400') }}">@if ($hasStaff)<x-icon name="check" />@else<span class="text-xs font-bold">3</span>@endif</span>
                <div>
                    <p class="text-sm font-semibold text-gray-900">3. Vaste bezetting</p>
                    <p class="text-xs text-gray-500">Wie staat er vast op? Je ziet direct of iemand op die tijden kan.</p>
                    @if ($hasTasks && ! $hasStaff)<a href="#bezetting" class="btn btn-sm mt-2">Vaste krachten kiezen</a>@endif
                </div>
            </li>
        </ol>
    @endunless

    <div x-data @shift-panel-closed.window="if ($event.detail.changed) window.location.reload()" class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <section class="card">
                <div class="flex items-center justify-between border-b px-4 py-3">
                    <div>
                        <h2 class="font-semibold text-gray-800">Vaste taken</h2>
                        <p class="text-xs text-gray-500">Uit deze taken worden de diensten automatisch ingepland ({{ config('poetsploeg.generate_weeks_ahead') }} weken vooruit).</p>
                    </div>
                    <a href="{{ route('buildings.templates.create', $building) }}" class="btn btn-sm">+ Taak</a>
                </div>
                <ul class="divide-y">
                    @forelse ($building->taskTemplates as $template)
                        <li class="px-4 py-3 {{ $template->active ? '' : 'opacity-60' }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <a href="{{ route('buildings.templates.edit', [$building, $template]) }}" class="font-medium text-gray-900 hover:text-teal-700">{{ $template->name }}</a>
                                    @unless ($template->active) <span class="chip">inactief</span> @endunless
                                    <p class="text-sm text-gray-600">
                                        {{ $template->recurrenceLabel() }},
                                        {{ substr($template->start_time, 0, 5) }}–{{ substr($template->end_time, 0, 5) }}@if ($template->patterns->contains(fn ($p) => $p->hasOwnTimes())) (standaard)@endif
                                        · {{ $template->required_staff }} {{ $template->required_staff === 1 ? 'persoon' : 'personen' }}
                                    </p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach ($template->skills as $skill)<span class="chip">{{ $skill->name }}</span>@endforeach
                                    </div>
                                </div>
                                <div class="text-right text-xs text-gray-500">
                                    <p>Vaste bezetting:</p>
                                    <p class="text-sm text-gray-800">{{ $template->employees->pluck('name')->implode(', ') ?: '—' }}</p>
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-gray-500">
                            Nog geen vaste taken. <a href="{{ route('buildings.templates.create', $building) }}" class="text-teal-700 hover:underline">Voeg een taak toe</a>, bijvoorbeeld “Dagelijkse schoonmaak ma t/m vr 18:00–20:00”.
                        </li>
                    @endforelse
                </ul>
            </section>

        </div>

        <aside class="space-y-6">
            <section class="card card-body space-y-2 text-sm">
                <h2 class="font-semibold text-gray-800">Pandinfo</h2>
                @if ($building->contact_name || $building->contact_phone || $building->contact_email)
                    <p><span class="text-gray-500">Contact:</span> {{ $building->contact_name }}
                        @if ($building->contact_phone)<a href="tel:{{ $building->contact_phone }}" class="text-teal-700 hover:underline">{{ $building->contact_phone }}</a>@endif
                        @if ($building->contact_email)<br><a href="mailto:{{ $building->contact_email }}" class="text-teal-700 hover:underline">{{ $building->contact_email }}</a>@endif
                    </p>
                @endif
                @if ($building->access_instructions)
                    <div><p class="text-gray-500">Toegang:</p><p class="whitespace-pre-line">{{ $building->access_instructions }}</p></div>
                @endif
                @if ($building->notes)
                    <div><p class="text-gray-500">Notities:</p><p class="whitespace-pre-line">{{ $building->notes }}</p></div>
                @endif
            </section>
            <section class="card card-body space-y-2">
                <h2 class="font-semibold text-gray-800">Ingewerkt ({{ $building->employees->count() }})</h2>
                <div class="flex flex-wrap gap-1">
                    @forelse ($building->employees as $employee)
                        <a href="{{ route('employees.show', $employee) }}" data-open-employee="{{ $employee->id }}" class="chip hover:bg-teal-50"><span class="me-1 h-2 w-2 rounded-full" style="background: {{ $employee->color }}"></span>{{ $employee->name }}</a>
                    @empty
                        <p class="text-sm text-gray-500">Nog niemand.</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>

    {{-- Weekrooster: per werkdag wie er op welke taak staat --}}
    <section class="card mt-6">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
            <div>
                <h2 class="font-semibold text-gray-800">Weekrooster · week {{ $weekStart->isoWeek() }}</h2>
                <p class="text-xs text-gray-500">{{ $weekStart->translatedFormat('j M') }} – {{ $weekStart->addDays(6)->translatedFormat('j M Y') }}. Klik op een dienst om medewerkers toe te wijzen of een vervanger te zoeken.</p>
            </div>
            <div class="flex items-center gap-1">
                <a href="{{ route('buildings.show', [$building, 'week' => $weekStart->subWeek()->toDateString()]) }}#weekrooster" class="btn-secondary btn-sm" aria-label="Vorige week">‹</a>
                <a href="{{ route('buildings.show', $building) }}#weekrooster" class="btn-secondary btn-sm">Deze week</a>
                <a href="{{ route('buildings.show', [$building, 'week' => $weekStart->addWeek()->toDateString()]) }}#weekrooster" class="btn-secondary btn-sm" aria-label="Volgende week">›</a>
            </div>
        </div>
        <div class="overflow-x-auto" id="weekrooster">
            @php
                $rows = $building->taskTemplates->filter(fn ($t) => $t->active || $grid->has($t->id))->map(fn ($t) => [
                    'key' => $t->id,
                    'name' => $t->name,
                    'time' => $t->timesLabel(),
                ])->values();
                if ($grid->has('los')) {
                    $rows->push(['key' => 'los', 'name' => 'Losse opdrachten', 'time' => '']);
                }
            @endphp
            @if ($rows->isEmpty())
                <p class="px-4 py-6 text-sm text-gray-500">Nog geen taken voor dit pand.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="sticky left-0 z-10 bg-gray-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Taak</th>
                            @foreach ($days as $day)
                                <th class="min-w-[9rem] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide {{ $day->isToday() ? 'text-teal-700' : 'text-gray-500' }}">
                                    {{ $day->translatedFormat('D j M') }}
                                    @if (isset($holidays[$day->toDateString()]))
                                        <span class="block normal-case font-normal text-amber-700">🎉 {{ $holidays[$day->toDateString()] }}</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="sticky left-0 z-10 w-36 min-w-[8rem] bg-white px-3 py-2 align-top sm:w-auto sm:min-w-[12rem]">
                                    <div class="font-medium text-gray-900">{{ $row['name'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $row['time'] }}</div>
                                </td>
                                @foreach ($days as $day)
                                    <td class="px-2 py-2 align-top {{ $day->isToday() ? 'bg-teal-50/40' : '' }}">
                                        @forelse ($grid->get($row['key'], collect())->get($day->toDateString(), []) as $shift)
                                            <button type="button" data-open-shift="{{ $shift->id }}" @if ($shift->openSlots()) data-candidates @endif
                                                    class="mb-1 block w-full rounded-md border px-2 py-1 text-left hover:border-teal-400 hover:bg-teal-50 {{ $shift->openSlots() ? 'border-dashed border-orange-300 bg-orange-50/50' : 'border-gray-200' }}">
                                                @if ($row['key'] === 'los' || $shift->starts_at->format('H:i').'–'.$shift->ends_at->format('H:i') !== $row['time'])
                                                    <span class="block text-xs text-gray-500">{{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}{{ $row['key'] === 'los' ? ' · '.$shift->title : '' }}</span>
                                                @endif
                                                <span class="flex flex-wrap gap-1">
                                                    @foreach ($shift->assignments as $a)
                                                        <span class="chip whitespace-nowrap"><span class="me-1 h-2 w-2 rounded-full" style="background: {{ $a->employee->color }}"></span>{{ $a->employee->name }}</span>
                                                    @endforeach
                                                    @if ($shift->openSlots())
                                                        <span class="chip whitespace-nowrap bg-orange-100 text-orange-800">{{ $shift->openSlots() }} open</span>
                                                    @endif
                                                    @if ($shift->extraStaff())
                                                        <span class="chip whitespace-nowrap bg-sky-100 text-sky-800" title="Meer mensen ingepland dan de bezetting vraagt">+{{ $shift->extraStaff() }} extra</span>
                                                    @endif
                                                </span>
                                            </button>
                                        @empty
                                            <span class="text-gray-300">—</span>
                                        @endforelse
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    {{-- Bezettingstabel: vaste bezetting van alle taken in één keer --}}
    @if ($building->taskTemplates->isNotEmpty())
        @php
            $hasOld = session()->hasOldInput('fixed') || session()->hasOldInput('staff');
            $showAllStaff = $hasOld || empty($knownIds)
                || $staffEmployees->contains(fn ($e) => ! in_array($e->id, $knownIds) && $building->taskTemplates->flatMap->employees->contains('id', $e->id));
        @endphp
        <section class="card mt-6" id="bezetting"
                 x-data="staffingTable(@js(['url' => route('api.staffing.check'), 'buildingId' => $building->id, 'showAll' => $showAllStaff]))">
            <form method="POST" action="{{ route('buildings.staffing', $building) }}">
                @csrf @method('PUT')
                <div class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                    <div>
                        <h2 class="font-semibold text-gray-800">Vaste bezetting</h2>
                        <p class="text-xs text-gray-500">Vink per taak aan wie er vast op staat. Het bolletje laat zien of iemand de diensten van de komende {{ config('poetsploeg.generate_weeks_ahead') }} weken kan draaien:
                            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>kan alles</span>,
                            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-500"></span>deels of let op</span>,
                            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-red-500"></span>kan niet</span>. Houd de muis erboven voor de reden.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        @if (count($knownIds) && $staffEmployees->count() > count($knownIds))
                            <label class="inline-flex items-center gap-1.5 text-sm text-gray-600">
                                <input type="checkbox" class="checkbox" x-model="showAll"> Toon alle medewerkers
                            </label>
                        @endif
                        <button type="submit" class="btn btn-sm">Bezetting opslaan</button>
                    </div>
                </div>
                @error('staffing')
                    <div class="notice notice-warning m-4">
                        <x-icon name="alert" class="mt-0.5 text-amber-600" />
                        <div>
                            <p class="font-medium">Niet iedereen kan alle diensten draaien:</p>
                            <ul class="ms-5 mt-1 list-disc">@foreach ($errors->get('staffing') as $message)<li>{{ $message }}</li>@endforeach</ul>
                            <p class="mt-1">Wie niet kan wordt op die diensten niet ingepland; de plek komt dan bij Open diensten.</p>
                            <button type="submit" name="confirm_staffing" value="1" class="btn btn-sm mt-2">Toch opslaan</button>
                        </div>
                    </div>
                @enderror
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="sticky left-0 z-10 bg-gray-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Taak</th>
                                <th class="px-2 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Pers.</th>
                                @foreach ($staffEmployees as $employee)
                                    @php($known = in_array($employee->id, $knownIds))
                                    <th class="px-2 py-2 text-center text-xs font-medium text-gray-700" @unless ($known) x-show="showAll" @endunless>
                                        <span class="inline-flex items-center gap-1 whitespace-nowrap">
                                            <span class="h-2 w-2 rounded-full" style="background: {{ $employee->color }}"></span>{{ $employee->name }}
                                        </span>
                                        @unless ($known)<span class="block font-normal text-gray-400">nog niet ingewerkt</span>@endunless
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($building->taskTemplates as $template)
                                @php($fixedIds = $hasOld ? array_map('intval', (array) old('fixed.'.$template->id, [])) : $template->employees->pluck('id')->all())
                                <tr x-data="{ staff: {{ (int) old('staff.'.$template->id, $template->required_staff) }}, checked: {{ count($fixedIds) }} }" class="{{ $template->active ? '' : 'opacity-60' }}">
                                    <td class="sticky left-0 z-10 w-40 min-w-[9rem] bg-white px-3 py-2 align-top sm:w-auto sm:min-w-[14rem]">
                                        <div class="font-medium text-gray-900">{{ $template->name }}</div>
                                        <div class="text-xs text-gray-500">{{ $template->timesLabel() }} · {{ $template->recurrenceLabel() }}</div>
                                        <p x-show="checked > staff" x-cloak class="mt-1 text-xs text-amber-700">
                                            Meer vinkjes dan personen: alleen de eerste <span x-text="staff"></span> worden ingepland.
                                            <button type="button" class="underline" @click="staff = checked">Aantal = <span x-text="checked"></span></button>
                                        </p>
                                    </td>
                                    <td class="px-2 py-2 align-top">
                                        <input type="number" min="1" max="50" name="staff[{{ $template->id }}]" x-model.number="staff"
                                               class="w-16 rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500" aria-label="Aantal personen {{ $template->name }}">
                                    </td>
                                    @foreach ($staffEmployees as $employee)
                                        <td class="px-2 py-2 text-center align-top" @unless (in_array($employee->id, $knownIds)) x-show="showAll" @endunless
                                            :title="title({{ $template->id }}, {{ $employee->id }})">
                                            <span class="inline-flex flex-col items-center gap-1">
                                                <input type="checkbox" name="fixed[{{ $template->id }}][]" value="{{ $employee->id }}" class="checkbox h-5 w-5"
                                                       aria-label="{{ $employee->name }} op {{ $template->name }}"
                                                       data-template="{{ $template->id }}" data-task="{{ $template->name }}"
                                                       @checked(in_array($employee->id, $fixedIds))
                                                       @change="checked += $event.target.checked ? 1 : -1">
                                                <span class="h-2 w-2 rounded-full" :class="dot({{ $template->id }}, {{ $employee->id }})"></span>
                                            </span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t px-4 py-3">
                    <button type="submit" class="btn">Bezetting opslaan</button>
                </div>
            </form>
        </section>
    @endif
</x-app-layout>
