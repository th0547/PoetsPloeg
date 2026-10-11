<x-app-layout :title="$building->name">
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <span class="h-4 w-4 rounded" style="background: {{ $building->color }}"></span>
            <h1 class="page-title">{{ $building->name }}</h1>
            @unless ($building->active) <span class="chip">inactief</span> @endunless
        </div>
        <div class="flex gap-2">
            <a href="{{ route('buildings.index') }}" class="btn-secondary">‹ Panden</a>
            <a href="{{ route('buildings.edit', $building) }}" class="btn-secondary">Bewerken</a>
            <a href="{{ route('shifts.create', ['pand' => $building->id]) }}" class="btn-secondary">+ Losse opdracht</a>
        </div>
    </x-slot>

    <div x-data @shift-panel-closed.window="if ($event.detail.changed) window.location.reload()" class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <section class="card">
                <div class="flex items-center justify-between border-b px-4 py-3">
                    <div>
                        <h2 class="font-semibold text-gray-800">Vaste taken</h2>
                        <p class="text-xs text-gray-500">Uit deze taken worden de diensten automatisch ingepland ({{ config('novon.generate_weeks_ahead') }} weken vooruit).</p>
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
                                        {{ substr($template->start_time, 0, 5) }}–{{ substr($template->end_time, 0, 5) }}
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

            <section class="card">
                <div class="border-b px-4 py-3">
                    <h2 class="font-semibold text-gray-800">Eerstvolgende diensten</h2>
                </div>
                <ul class="divide-y">
                    @forelse ($upcoming as $shift)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-gray-50 cursor-pointer" onclick="openShift({{ $shift->id }})">
                            <span>
                                <span class="text-gray-600">{{ $shift->starts_at->translatedFormat('D j M') }} {{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}</span>
                                <span class="ms-2 font-medium">{{ $shift->title }}</span>
                            </span>
                            <span class="flex flex-wrap gap-1">
                                @foreach ($shift->assignments as $a)<span class="chip">{{ $a->employee->name }}</span>@endforeach
                                @if ($shift->openSlots())<span class="chip bg-orange-100 text-orange-800">{{ $shift->openSlots() }} open</span>@endif
                            </span>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-sm text-gray-500">Geen geplande diensten.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="card card-body space-y-2 text-sm">
                <h2 class="font-semibold text-gray-800">Pandinfo</h2>
                <p>{{ $building->fullAddress() ?: 'Geen adres' }}</p>
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
                        <a href="{{ route('employees.edit', $employee) }}" class="chip hover:bg-teal-50"><span class="me-1 h-2 w-2 rounded-full" style="background: {{ $employee->color }}"></span>{{ $employee->name }}</a>
                    @empty
                        <p class="text-sm text-gray-500">Nog niemand.</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
</x-app-layout>
