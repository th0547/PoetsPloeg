{{-- Leesversie van het beleid voor medewerkers (issue #42 §9). Alleen onderwerpen met een tekst voor medewerkers. --}}
@php
    $cao = config('poetsploeg-cao');
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
@endphp
<div class="max-w-3xl space-y-6 text-sm leading-relaxed text-gray-700">
    <p>Dit zijn de regels en afspraken die gelden bij ons. Ze volgen de <strong>{{ $cao['version'] }}</strong>; waar wij afwijken staat dat erbij. Vragen? Neem contact op met je teamleider.</p>

    @if ($upcoming)
        <div class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sky-900">
            <strong>Vanaf {{ $upcoming['date']->translatedFormat('j F Y') }} verandert:</strong> {{ implode(', ', $upcoming['changed']) }}.
        </div>
    @endif

    @foreach (\App\Support\Policy::groups() as $group => $definition)
        @php
            $lines = collect($definition['items'])->keys()
                ->map(fn ($item) => ['key' => "{$group}.{$item}", 'text' => $policy->employeeText("{$group}.{$item}"), 'source' => $definition['items'][$item]['source']])
                ->filter(fn ($l) => $l['text']);
        @endphp
        @if ($lines->isNotEmpty())
            <section class="card card-body space-y-2">
                <h2 class="text-base font-semibold text-gray-900">{{ $definition['label'] }}</h2>
                <ul class="space-y-2">
                    @foreach ($lines as $line)
                        <li>
                            {{ $line['text'] }}
                            <span class="text-xs text-gray-400">({{ $line['source'] }})</span>
                            @if ($policy->isDeviating($line['key']))
                                <span class="chip bg-amber-100 text-amber-800">bedrijfsregeling</span>
                                @if ($note = $policy->employeeNote($line['key']))<span class="block text-xs text-gray-500">{{ $note }}</span>@endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach

    <section class="card card-body space-y-3">
        <h2 class="text-base font-semibold text-gray-900">Verlof</h2>
        <p class="text-xs text-gray-500">Verlof telt als de uren die je die dag normaal zou werken. <a href="{{ \Illuminate\Support\Facades\Route::has('my.absences.create') && auth()->user()?->isEmployee() ? route('my.absences.create') : '#' }}" class="text-teal-700 hover:underline">Verlof aanvragen ›</a></p>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="bg-gray-50"><tr><th>Soort</th><th>Hoeveel</th><th>Betaald</th><th>Aanvragen</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($policy->leaveTypes(true)->reject(fn ($t) => $t['counts_as'] === 'other') as $t)
                        <tr>
                            <td class="align-top">
                                <span class="font-medium text-gray-900">{{ $t['name'] }}</span>
                                @if ($t['deviating'] || $t['custom'])<span class="chip bg-amber-100 text-amber-800">bedrijfsregeling</span>@endif
                                @if ($t['explanation'])<span class="block text-xs text-gray-600">{{ $t['explanation'] }}</span>@endif
                                <span class="block text-xs text-gray-400">{{ $t['source'] }}</span>
                            </td>
                            <td class="whitespace-nowrap align-top">{{ $t['max'] !== null ? $fmt($t['max']).' '.$cao['units'][$t['unit']].' '.$cao['periods'][$t['period']] : '–' }}</td>
                            <td class="whitespace-nowrap align-top">{{ $t['counts_as'] === 'unavailable' ? 'n.v.t.' : ($t['paid'] > 0 ? $t['paid'].'%'.($t['paid_by'] === 'UWV' ? ' (UWV)' : '') : 'onbetaald') }}</td>
                            <td class="align-top text-xs">
                                @if ($t['direct'])
                                    Direct melden bij je planner
                                @elseif ($t['self_request'])
                                    Zelf aanvragen{{ $t['min_days_ahead'] > 0 ? ', minstens '.$t['min_days_ahead'].' dagen vooraf' : '' }}
                                @else
                                    Via je planner
                                @endif
                                @if ($t['refusal'] === 'no')<span class="block text-gray-500">Kan niet geweigerd worden</span>@elseif ($t['refusal'] === 'serious')<span class="block text-gray-500">Alleen bij zwaarwegend belang te weigeren</span>@endif
                                @if ($t['proof'])<span class="block text-gray-500">Bewijs nodig</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <p class="text-xs text-gray-500">De volledige cao vind je op <a href="https://www.ras.nl" class="text-teal-700 hover:underline" target="_blank" rel="noopener">ras.nl</a>. Bij verschil tussen deze pagina en de cao geldt de cao, tenzij wij gunstiger zijn.</p>
</div>
