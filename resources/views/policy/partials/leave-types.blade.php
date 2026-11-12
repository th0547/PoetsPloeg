{{-- Verlofsoorten op de beleidspagina. --}}
@php($defaults = config('poetsploeg-cao.leave_types'))
<div class="space-y-2" x-data="{ added: 0 }">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="font-semibold text-gray-800">Verlofsoorten</h2>
            <p class="text-xs text-gray-500">Standaard gevuld volgens de cao (art. 28–32) en de Wet arbeid en zorg. Standaardsoorten kun je aanpassen of uitzetten, niet verwijderen.</p>
        </div>
    </div>
    @foreach ($types as $key => $t)
        <details class="rounded-md border px-3 py-2 open:bg-gray-50/60">
            <summary class="flex cursor-pointer flex-wrap items-center gap-2 text-sm">
                <span class="font-medium {{ $t['active'] ? 'text-gray-900' : 'text-gray-400 line-through' }}">{{ $t['name'] }}</span>
                <span class="text-xs text-gray-500">{{ $t['group'] }} · {{ config('poetsploeg-cao.counts_as')[$t['counts_as']] ?? $t['counts_as'] }} · {{ $t['paid'] }}%{{ $t['max'] !== null ? ' · max '.rtrim(rtrim(number_format((float) $t['max'], 2, ',', ''), '0'), ',').' '.config('poetsploeg-cao.units')[$t['unit']].' '.config('poetsploeg-cao.periods')[$t['period']] : '' }}</span>
                @if ($t['custom'])
                    <span class="chip bg-sky-100 text-sky-800">eigen soort</span>
                @elseif ($t['unfavorable'])
                    <span class="chip bg-red-100 text-red-800">ten nadele</span>
                @elseif ($t['deviating'])
                    <span class="chip bg-amber-100 text-amber-800">afwijkend</span>
                @else
                    <span class="chip bg-teal-50 text-teal-800">cao</span>
                @endif
                <span class="text-xs text-gray-400">{{ $t['source'] }}</span>
            </summary>
            <div class="mt-3">
                @include('policy.partials.leave-type-fields', ['prefix' => "leave_types[{$key}]", 't' => $t, 'default' => $defaults[$key] ?? null])
            </div>
        </details>
    @endforeach

    @if ($canEdit)
        @foreach (range(1, 3) as $n)
            <fieldset x-show="added >= {{ $n }}" x-cloak :disabled="added < {{ $n }}" class="rounded-md border border-dashed border-teal-300 px-3 py-3">
                <legend class="px-1 text-sm font-medium text-teal-800">Nieuwe verlofsoort</legend>
                @include('policy.partials.leave-type-fields', ['prefix' => "leave_types[nieuw{$n}]", 't' => \App\Support\Policy::emptyLeaveType(), 'default' => null])
            </fieldset>
        @endforeach
        <button type="button" class="btn-secondary btn-sm" @click="added = Math.min(3, added + 1)" x-show="added < 3">+ Verlofsoort toevoegen</button>
    @endif
</div>
