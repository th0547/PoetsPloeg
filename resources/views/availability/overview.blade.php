@php($n = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ','))
<x-app-layout title="Beschikbaarheid" :wide="true">
    <x-slot name="header">
        <div>
            <h1 class="page-title">Beschikbaarheid bevestigd</h1>
            <p class="text-sm text-gray-500">Medewerkers bevestigen elke week hun beschikbaarheid voor week {{ $target->isoWeek() }}. Het rooster van die week moet uiterlijk {{ $deadline->translatedFormat('j F') }} bekend zijn.</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if ($openForTarget->isNotEmpty())
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <strong>Nog niet bevestigd voor week {{ $target->isoWeek() }} ({{ $openForTarget->count() }}):</strong>
                {{ $openForTarget->pluck('name')->join(', ', ' en ') }}.
            </div>
        @endif

        <div class="card overflow-x-auto">
            <table class="table">
                <thead class="bg-gray-50">
                    <tr>
                        <th>Medewerker</th>
                        @foreach ($weeks as $week)
                            <th class="whitespace-nowrap text-center">Week {{ $week->isoWeek() }}<span class="block text-xs font-normal text-gray-500">{{ $week->translatedFormat('j M') }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($grid as $line)
                        @php($employee = $line['employee'])
                        <tr>
                            <td class="whitespace-nowrap">
                                <a href="{{ route('employees.edit', $employee) }}" class="font-medium text-gray-900 hover:text-teal-700">{{ $employee->name }}</a>
                                @if ($employee->contract_hours_min)<span class="block text-xs text-gray-500">contract {{ $n($employee->contract_hours_min) }} u</span>@endif
                            </td>
                            @foreach ($line['cells'] as $cell)
                                <td class="whitespace-nowrap text-center text-xs">
                                    @switch($cell['status'])
                                        @case('confirmed')
                                            <span class="chip bg-teal-50 text-teal-800" title="Bevestigd {{ $cell['row']->confirmed_at->format('d-m H:i') }}">bevestigd</span>
                                            @break
                                        @case('open')
                                            <span class="chip bg-amber-100 text-amber-800">open</span>
                                            @break
                                        @case('invited')
                                            <span class="chip bg-gray-100 text-gray-600" title="Uitgenodigd, maar nog geen wachtwoord ingesteld: vraag het zelf na">uitgenodigd</span>
                                            @break
                                        @default
                                            <span class="chip bg-gray-100 text-gray-600" title="Geen inlog: vraag het zelf na">geen inlog</span>
                                    @endswitch
                                    <span class="mt-1 block text-gray-600">{{ $n($cell['hours']) }} u beschikbaar</span>
                                    @if ($cell['below'])<span class="block text-red-700">onder contract</span>@endif
                                    @if ($cell['planned_below'])<span class="block text-orange-700" title="Het loon over de contracturen is toch verschuldigd">ingepland {{ $n($cell['planned']) }} u</span>@endif
                                    @if ($cell['row']?->changed_after_confirm)<span class="block text-sky-700">gewijzigd na bevestigen</span>@endif
                                    @if ($cell['row']?->explanation)<span class="block max-w-[10rem] truncate text-gray-500" title="{{ $cell['row']->explanation }}">“{{ $cell['row']->explanation }}”</span>@endif
                                    @if ($cell['row']?->other_employer)<span class="block max-w-[10rem] truncate text-gray-500" title="Andere werkgever: {{ $cell['row']->other_employer }}">elders: {{ $cell['row']->other_employer }}</span>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <section class="card card-body max-w-3xl space-y-2">
            <h2 class="font-semibold text-gray-800">Meer-uren (cao art. 13)</h2>
            <p class="text-sm text-gray-600">Werkt iemand in het vorige kwartaal gemiddeld minstens {{ \App\Support\Policy::current()->get('contract.extra_hours_threshold') }}% meer dan het contract, dan moet je een aangepast contract aanbieden. Uren voor vervanging tellen volgens de cao niet mee; controleer dat zelf.</p>
            @forelse ($extraHours as $x)
                <p class="text-sm"><a href="{{ route('employees.edit', $x['employee']) }}" class="font-medium text-teal-700 hover:underline">{{ $x['employee']->name }}</a>: gemiddeld {{ $n($x['average']) }} u per week, contract {{ $n($x['contract']) }} u (+{{ $n($x['percent']) }}%).</p>
            @empty
                <p class="text-sm text-gray-500">Niemand in het vorige kwartaal.</p>
            @endforelse
        </section>
    </div>
</x-app-layout>
