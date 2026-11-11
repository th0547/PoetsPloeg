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

            {{-- Eigen inlog: de medewerker ziet de eigen planning, vraagt verlof aan en geeft beschikbaarheid door. --}}
            <div class="card card-body space-y-3">
                <div>
                    <h2 class="font-semibold text-gray-800">Inloggen</h2>
                    <p class="text-xs text-gray-500">Met een eigen inlog ziet de medewerker de eigen planning, vraagt verlof aan en geeft beschikbaarheid door. Het e-mailadres hierboven is de inlognaam.</p>
                </div>
                @php($account = $employee->user)
                @if (in_array(config('mail.default'), ['log', 'array'], true))
                    <p class="rounded-md bg-red-50 px-3 py-2 text-xs text-red-800">Let op: er is nog geen mailserver ingesteld (MAIL_MAILER={{ config('mail.default') }}). Uitnodigingen worden niet echt verstuurd.</p>
                @endif
                @if (! $account)
                    <label class="inline-flex items-start gap-2 text-sm">
                        <input type="hidden" name="invite" value="0">
                        <input type="checkbox" name="invite" value="1" class="checkbox mt-0.5" @checked(old('invite'))>
                        <span>
                            Uitnodiging sturen om in te loggen
                            <span class="block text-xs text-gray-500">Na opslaan krijgt de medewerker een e-mail met een link om zelf een wachtwoord in te stellen (7 dagen geldig). Het account krijgt de rol Medewerker.</span>
                        </span>
                    </label>
                @elseif ($account->invitationPending())
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <span><strong>Uitgenodigd</strong> op {{ $account->email }}, nog geen wachtwoord ingesteld.</span>
                        <span class="flex gap-3">
                            <button type="submit" form="resend-invitation" class="text-sm font-medium text-teal-700 hover:underline">Opnieuw uitnodigen</button>
                            <button type="submit" form="revoke-account" class="text-sm text-red-600 hover:underline">Intrekken</button>
                        </span>
                    </div>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-teal-50 px-3 py-2 text-sm text-teal-900">
                        <span><strong>Kan inloggen</strong> met {{ $account->email }}@unless ($employee->active) <span class="text-amber-800">(niet zolang de medewerker inactief is)</span>@endunless.</span>
                        <span class="flex gap-3">
                            <button type="submit" form="resend-invitation" class="text-sm text-teal-700 hover:underline" title="Stuurt een link om een nieuw wachtwoord in te stellen">Nieuwe link sturen</button>
                            <button type="submit" form="revoke-account" class="text-sm text-red-600 hover:underline">Inlog intrekken</button>
                        </span>
                    </div>
                @endif
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

            @include('employees.partials.availability', [
                'self' => false,
                'absenceUrl' => $employee->exists ? route('absences.create', ['medewerker' => $employee->id]) : null,
            ])

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

                @if ($employee->user)
                    <form id="resend-invitation" method="POST" action="{{ route('employees.invitation', $employee) }}" class="hidden">@csrf</form>
                    <form id="revoke-account" method="POST" action="{{ route('employees.account.destroy', $employee) }}" class="hidden"
                          data-confirm="Inlog van {{ $employee->name }} intrekken? De medewerker kan dan niet meer inloggen; planning en geschiedenis blijven bewaard.">
                        @csrf @method('DELETE')
                    </form>
                @endif

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
