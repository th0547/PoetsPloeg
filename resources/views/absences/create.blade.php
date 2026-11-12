@php
    $state = [
        'type' => old('type', 'ziek'),
        'asRequest' => (bool) old('as_request', false),
        'days' => array_values(old('days', [])),
        'unplanned' => \App\Support\Policy::current()->directTypes(),
    ];
@endphp
<x-app-layout title="Afwezigheid registreren">
    <x-slot name="header">
        <h1 class="page-title">Afwezigheid of uitval registreren</h1>
        <a href="{{ route('absences.index') }}" class="btn-secondary">‹ Terug</a>
    </x-slot>

    <form method="POST" action="{{ route('absences.store') }}" class="card card-body max-w-3xl space-y-5"
          x-data="absenceForm(@js($state))" @pointerup.window="dragEnd()" @pointercancel.window="dragEnd()">
        @csrf
        <p class="text-sm text-gray-600" x-show="!isRequest">De medewerker wordt automatisch van alle diensten op de gekozen dagen gehaald. Daarna zie je direct welke diensten open staan en wie kan invallen.</p>
        <p class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900" x-show="isRequest" x-cloak>
            <strong>Verzoek:</strong> de medewerker blijft voorlopig ingepland. Zoek per dienst een vervanger; zodra alle diensten vervangen zijn, wordt het automatisch goedgekeurd.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="employee_id">Medewerker</label>
                <select id="employee_id" name="employee_id" class="input" required>
                    <option value="">Kies…</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}" @selected((int) old('employee_id', $selected) === $employee->id)>{{ $employee->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="type">Soort</label>
                <select id="type" name="type" class="input" x-model="type">
                    @foreach (\App\Support\Policy::current()->leaveTypeGroups() as $group => $types)
                        <optgroup label="{{ $group }}">
                            @foreach ($types as $value => $name)
                                <option value="{{ $value }}" @selected(old('type', 'ziek') === $value)>{{ $name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">De soort wordt bewaard bij de medewerker, bijvoorbeeld voor wat er wordt doorbetaald.</p>
            </div>
            <div class="sm:col-span-2" x-show="canRequest">
                <label class="inline-flex items-start gap-2 text-sm">
                    <input type="checkbox" name="as_request" value="1" class="checkbox mt-0.5" x-model="asRequest" :disabled="!canRequest">
                    <span>
                        Als verzoek indienen
                        <span class="block text-xs text-gray-500">De medewerker blijft ingepland tot er voor elke dienst een vervanger is. Niet aangevinkt = direct registreren.</span>
                    </span>
                </label>
            </div>
        </div>

        @include('absences.partials.days-picker')

        <div>
            <label class="label" for="note">Notitie</label>
            <input id="note" name="note" class="input" value="{{ old('note') }}" placeholder="Optioneel">
        </div>

        <button type="submit" class="btn disabled:cursor-not-allowed disabled:opacity-50" :disabled="!days.length" x-text="submitLabel()">Registreren en vervangers zoeken</button>
    </form>
</x-app-layout>
