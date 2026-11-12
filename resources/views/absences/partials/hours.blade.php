{{-- Verlofuren van een afwezigheid, met aanpassen voor planners (issue #43). --}}
<section class="card card-body flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="font-semibold text-gray-800">Uren</h2>
        <p class="text-sm text-gray-700"><strong>{{ $absence->hoursLabel() }}</strong>@if ($absence->days) op {{ $absence->days }} {{ $absence->days === 1 ? 'dag' : 'dagen' }}@endif</p>
        <p class="text-xs text-gray-500">
            @if ($absence->hours_source)
                Bepaald op basis van {{ \App\Services\LeaveHours::SOURCES[$absence->hours_source] ?? $absence->hours_source }}.
            @elseif (($absence->leaveType()['counts_as'] ?? null) === 'unavailable')
                Niet beschikbaar telt niet als verlof.
            @else
                Geen uren: de medewerker werkt in deze periode normaal niet.
            @endif
            Verlof telt als de uren die de medewerker normaal zou werken.
        </p>
    </div>
    <form method="POST" action="{{ route('absences.hours', $absence) }}" class="flex items-end gap-2">
        @csrf @method('PATCH')
        <div>
            <label class="text-xs text-gray-600" for="hours">Uren</label>
            <input id="hours" type="number" step="0.25" min="0" name="hours" class="input mt-0 w-24" value="{{ $absence->hours }}" required>
        </div>
        <div>
            <label class="text-xs text-gray-600" for="days">Dagen</label>
            <input id="days" type="number" min="0" name="days" class="input mt-0 w-20" value="{{ $absence->days }}">
        </div>
        <button type="submit" class="btn-secondary">Aanpassen</button>
    </form>
</section>
