<x-app-layout title="Afwezigheid registreren">
    <x-slot name="header">
        <h1 class="page-title">Afwezigheid of uitval registreren</h1>
        <a href="{{ route('absences.index') }}" class="btn-secondary">‹ Terug</a>
    </x-slot>

    <form method="POST" action="{{ route('absences.store') }}" class="card card-body max-w-2xl space-y-4" x-data="{ wholeDays: {{ old('start_time') || old('end_time') ? 'false' : 'true' }} }">
        @csrf
        <p class="text-sm text-gray-600">De medewerker wordt automatisch van alle diensten in deze periode gehaald. Daarna zie je direct welke diensten open staan en wie kan invallen.</p>
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
                <select id="type" name="type" class="input">
                    @foreach (\App\Models\Absence::TYPES as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', 'ziek') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" class="checkbox" x-model="wholeDays"> Hele dagen
                </label>
            </div>
            <div>
                <label class="label" for="start_date">Van</label>
                <div class="flex gap-2">
                    <input id="start_date" type="date" name="start_date" class="input" value="{{ old('start_date', today()->toDateString()) }}" required>
                    <input type="time" name="start_time" class="input w-32" value="{{ old('start_time') }}" x-show="!wholeDays" :disabled="wholeDays">
                </div>
            </div>
            <div>
                <label class="label" for="end_date">Tot en met</label>
                <div class="flex gap-2">
                    <input id="end_date" type="date" name="end_date" class="input" value="{{ old('end_date', today()->toDateString()) }}" required>
                    <input type="time" name="end_time" class="input w-32" value="{{ old('end_time') }}" x-show="!wholeDays" :disabled="wholeDays">
                </div>
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="note">Notitie</label>
                <input id="note" name="note" class="input" value="{{ old('note') }}" placeholder="Optioneel">
            </div>
        </div>
        <button type="submit" class="btn">Registreren en vervangers zoeken</button>
    </form>
</x-app-layout>
