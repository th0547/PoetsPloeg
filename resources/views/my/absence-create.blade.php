@php
    $state = [
        'type' => old('type', 'verlof'),
        'asRequest' => true,
        'days' => array_values(old('days', [])),
        'unplanned' => \App\Models\Absence::UNPLANNED,
    ];
@endphp
<x-app-layout title="Verlof aanvragen">
    <x-slot name="header">
        <h1 class="page-title">Verlof aanvragen</h1>
        <a href="{{ route('my.absences') }}" class="btn-secondary">‹ Mijn verlof</a>
    </x-slot>

    <form method="POST" action="{{ route('my.absences.store') }}" class="card card-body max-w-3xl space-y-5"
          x-data="absenceForm(@js($state))" @pointerup.window="dragEnd()" @pointercancel.window="dragEnd()">
        @csrf
        <p class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Je blijft voorlopig ingepland. De planner zoekt voor elke dienst in deze periode een vervanger; zodra dat gelukt is, wordt je verlof automatisch goedgekeurd. Sta je niet ingepland, dan is het meteen goedgekeurd.
        </p>

        <div class="max-w-sm">
            <label class="label" for="type">Soort</label>
            <select id="type" name="type" class="input" x-model="type">
                @foreach (\App\Models\Absence::TYPE_GROUPS as $group => $values)
                    @php($values = array_values(array_intersect($values, array_keys($types))))
                    @if ($values)
                        <optgroup label="{{ $group }}">
                            @foreach ($values as $value)
                                <option value="{{ $value }}" @selected(old('type', 'verlof') === $value)>{{ $types[$value] }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">Ziek of een calamiteit? Meld dat direct bij je planner.</p>
        </div>

        @include('absences.partials.days-picker')

        <div>
            <label class="label" for="note">Toelichting</label>
            <input id="note" name="note" class="input" value="{{ old('note') }}" placeholder="Optioneel, bijv. waarom of wat je al hebt afgesproken">
        </div>

        <button type="submit" class="btn disabled:cursor-not-allowed disabled:opacity-50" :disabled="!days.length">Verlof aanvragen</button>
    </form>
</x-app-layout>
