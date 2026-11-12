{{-- Verloftellers en indicatief vakantiesaldo (issue #44). Verwacht $counters, $balance, $year, $showBalance, en optioneel $balanceForm (route voor beginsaldo). --}}
@php($n = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ','))
<div class="space-y-3">
    @if ($counters->isEmpty())
        <p class="text-sm text-gray-500">Geen verlof of afwezigheid in {{ $year }}.</p>
    @else
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">Soort</th><th class="py-1 text-right">Opgenomen</th><th class="py-1 text-right">Aangevraagd</th></tr></thead>
            <tbody class="divide-y">
                @foreach ($counters as $label => $c)
                    <tr>
                        <td class="py-1">{{ $label }}</td>
                        <td class="py-1 text-right tabular-nums">{{ $n($c['approved_hours']) }} u <span class="text-xs text-gray-500">({{ $c['approved_days'] }} {{ $c['approved_days'] === 1 ? 'dag' : 'dagen' }})</span></td>
                        <td class="py-1 text-right tabular-nums text-gray-500">{{ $c['pending_hours'] > 0 ? $n($c['pending_hours']).' u' : '–' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($showBalance)
        <div class="rounded-md bg-teal-50/70 p-3 text-sm">
            <p class="font-semibold text-gray-900">Vakantiesaldo {{ $year }}: {{ $n($balance['balance']) }} uur <span class="chip bg-white text-xs font-normal text-gray-600">indicatief</span></p>
            <dl class="mt-1 grid grid-cols-2 gap-x-3 text-xs text-gray-600">
                <dt>Beginsaldo</dt><dd class="text-right tabular-nums">{{ $balance['has_opening'] ? $n($balance['opening']).' u' : 'nog niet ingevuld' }}</dd>
                <dt>Opbouw contract ({{ $n($balance['contract_hours']) }} u/week × 52 × {{ $n($balance['percent']) }}%)</dt><dd class="text-right tabular-nums">+ {{ $n($balance['contract_accrual']) }} u</dd>
                @if ($balance['extra_hours'] > 0)
                    <dt>Opbouw extra uren ({{ $n($balance['extra_hours']) }} u)</dt><dd class="text-right tabular-nums">+ {{ $n($balance['extra_accrual']) }} u</dd>
                @endif
                <dt>Opgenomen vakantie</dt><dd class="text-right tabular-nums">− {{ $n($balance['taken']) }} u</dd>
                @if ($balance['pending'] > 0)
                    <dt>Nog aangevraagd</dt><dd class="text-right tabular-nums">({{ $n($balance['pending']) }} u)</dd>
                @endif
            </dl>
            <p class="mt-1 text-xs text-gray-500">Een schatting; de loonadministratie is leidend.</p>
        </div>
        @isset($balanceForm)
            <form method="POST" action="{{ $balanceForm }}" class="flex flex-wrap items-end gap-2 text-sm">
                @csrf @method('PUT')
                <input type="hidden" name="year" value="{{ $year }}">
                <div>
                    <label class="text-xs text-gray-600" for="opening_hours">Beginsaldo {{ $year }} (uit de loonadministratie)</label>
                    <input id="opening_hours" type="number" step="0.01" name="opening_hours" class="input mt-0 w-28" value="{{ $balance['has_opening'] ? $balance['opening'] : '' }}" required>
                </div>
                <button type="submit" class="btn-secondary btn-sm">Opslaan</button>
            </form>
        @endisset
    @endif
</div>
