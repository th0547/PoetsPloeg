@php
    $state = [
        'type' => old('type', 'verlof'),
        'asRequest' => true,
        'days' => array_values(old('days', [])),
        'unplanned' => \App\Support\Policy::current()->directTypes(),
        'shiftDays' => $shiftDays,
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
                @foreach (\App\Support\Policy::current()->leaveTypeGroups() as $group => $groupTypes)
                    @php($values = array_values(array_intersect(array_keys($groupTypes), array_keys($types))))
                    @if ($values)
                        <optgroup label="{{ $group }}">
                            @foreach ($values as $value)
                                <option value="{{ $value }}" @selected(old('type', 'verlof') === $value)>{{ $types[$value] }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">Ziek of een calamiteit? Meld dat direct bij je planner. <a href="{{ route('my.policy') }}" class="text-teal-700 hover:underline">Welke regels gelden?</a></p>
        </div>

        @include('absences.partials.days-picker')
        <p class="-mt-3 flex items-center gap-2 text-xs text-gray-600"><span class="inline-block h-2 w-2 rounded-full bg-orange-500"></span> Op deze dagen sta je ingepland: kies daar een verlofsoort. Op andere dagen kun je ook “Niet beschikbaar” doorgeven.</p>

        @if ($errors->has('confirm_zero') || old('confirm_zero'))
            <label class="flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                <input type="hidden" name="confirm_zero" value="0">
                <input type="checkbox" name="confirm_zero" value="1" class="checkbox mt-0.5" @checked(old('confirm_zero'))>
                <span>Ik wil toch verlof aanvragen voor deze dag(en), ook al werk ik dan normaal niet.</span>
            </label>
        @endif

        <div>
            <label class="label" for="note">Toelichting</label>
            <input id="note" name="note" class="input" value="{{ old('note') }}" placeholder="Optioneel, bijv. waarom of wat je al hebt afgesproken">
        </div>

        <button type="submit" class="btn disabled:cursor-not-allowed disabled:opacity-50" :disabled="!days.length">Verlof aanvragen</button>
    </form>
</x-app-layout>
