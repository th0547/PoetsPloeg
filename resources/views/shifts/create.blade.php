<x-app-layout title="Losse opdracht">
    <x-slot name="header">
        <h1 class="page-title">Losse opdracht inplannen</h1>
    </x-slot>

    <form method="POST" action="{{ route('shifts.store') }}" class="card card-body max-w-3xl space-y-4">
        @csrf
        <p class="text-sm text-gray-600">Voor eenmalig werk, zoals een opleveringsschoonmaak of extra schoonmaak na een evenement. Terugkerend werk leg je vast als vaste taak bij het pand.</p>
        <div class="grid gap-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label class="label" for="building_id">Pand</label>
                <select id="building_id" name="building_id" class="input" required>
                    <option value="">Kies…</option>
                    @foreach ($buildings as $building)
                        <option value="{{ $building->id }}" @selected((int) old('building_id', $selectedBuilding) === $building->id)>{{ $building->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-3">
                <label class="label" for="title">Omschrijving</label>
                <input id="title" name="title" class="input" value="{{ old('title') }}" placeholder="Bijv. Opleveringsschoonmaak" required>
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="date">Datum</label>
                <input id="date" type="date" name="date" class="input" value="{{ old('date', $date) }}" required>
            </div>
            <div class="sm:col-span-1">
                <label class="label" for="start_time">Van</label>
                <input id="start_time" type="time" name="start_time" class="input" value="{{ old('start_time', '09:00') }}" required>
            </div>
            <div class="sm:col-span-1">
                <label class="label" for="end_time">Tot</label>
                <input id="end_time" type="time" name="end_time" class="input" value="{{ old('end_time', '13:00') }}" required>
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="required_staff">Aantal personen</label>
                <input id="required_staff" type="number" min="1" max="50" name="required_staff" class="input" value="{{ old('required_staff', 1) }}" required>
            </div>
            <div class="sm:col-span-6">
                <span class="label">Vereiste vaardigheden</span>
                <div class="mt-1 flex flex-wrap gap-2">
                    @foreach ($skills as $skill)
                        <label class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm">
                            <input type="checkbox" name="skills[]" value="{{ $skill->id }}" class="checkbox" @checked(in_array($skill->id, old('skills', [])))> {{ $skill->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="sm:col-span-6">
                <span class="label">Direct inplannen (optioneel)</span>
                <p class="text-xs text-gray-500">Je kunt ook later via “Zoek vervanger / medewerker” de best passende medewerkers kiezen.</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    @foreach ($employees as $employee)
                        <label class="inline-flex items-center gap-1.5 text-sm">
                            <input type="checkbox" name="employees[]" value="{{ $employee->id }}" class="checkbox" @checked(in_array($employee->id, old('employees', [])))> {{ $employee->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="sm:col-span-6">
                <label class="label" for="notes">Notities</label>
                <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes') }}</textarea>
            </div>
        </div>
        <button type="submit" class="btn">Inplannen</button>
    </form>
</x-app-layout>
