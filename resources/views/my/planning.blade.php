<x-app-layout title="Mijn planning">
    <x-slot name="header">
        <div>
            <h1 class="page-title">Mijn planning</h1>
            <p class="text-sm text-gray-500">{{ $from->translatedFormat('j M') }} t/m {{ $to->subDay()->translatedFormat('j M Y') }} · {{ $shiftCount }} {{ $shiftCount === 1 ? 'dienst' : 'diensten' }}, {{ rtrim(rtrim(number_format($hours, 2, ',', ''), '0'), ',') }} uur</p>
        </div>
        <div class="inline-flex items-center gap-1">
            <a href="{{ route('my.planning', ['vanaf' => $from->subWeeks(4)->toDateString()]) }}" class="btn-secondary btn-sm" aria-label="Vorige weken">‹</a>
            <a href="{{ route('my.planning') }}" class="btn-secondary btn-sm">Vandaag</a>
            <a href="{{ route('my.planning', ['vanaf' => $to->toDateString()]) }}" class="btn-secondary btn-sm" aria-label="Volgende weken">›</a>
        </div>
    </x-slot>

    <div class="max-w-3xl space-y-6">
        @foreach ($weeks as $week)
            @php($first = $week->first()['date'])
            <section class="card overflow-hidden">
                <h2 class="border-b bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700">Week {{ $first->isoWeek() }} <span class="font-normal text-gray-500">· {{ $first->translatedFormat('j M') }} – {{ $first->addDays(6)->translatedFormat('j M') }}</span></h2>
                <ul class="divide-y">
                    @foreach ($week as $day)
                        @php($isToday = $day['date']->isToday())
                        <li class="flex gap-4 px-4 py-2 {{ $isToday ? 'bg-teal-50/50' : '' }} {{ $day['date']->isPast() && ! $isToday ? 'text-gray-400' : '' }}">
                            <div class="w-20 shrink-0 text-sm">
                                <span class="font-medium {{ $isToday ? 'text-teal-700' : '' }}">{{ $day['date']->translatedFormat('D j M') }}</span>
                                @if ($isToday)<span class="block text-xs text-teal-700">vandaag</span>@endif
                            </div>
                            <div class="min-w-0 flex-1 space-y-2 text-sm">
                                @foreach ($day['absences'] as $absence)
                                    <div>
                                        <span class="chip {{ $absence->chipClass() }}">{{ $absence->typeLabel() }}</span>
                                        @if ($absence->isPending())<span class="chip bg-amber-100 text-amber-800">aangevraagd</span>@endif
                                    </div>
                                @endforeach
                                @foreach ($day['shifts'] as $shift)
                                    @php($colleagues = $shift->employees->where('id', '!=', $employee->id)->pluck('name'))
                                    <div class="border-l-4 ps-2" style="border-color: {{ $shift->building->color }}">
                                        <p><span class="font-semibold">{{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}</span> {{ $shift->building->name }}@if ($shift->title) · {{ $shift->title }}@endif</p>
                                        @if ($shift->building->address)
                                            <p class="text-xs text-gray-500">{{ $shift->building->address }}@if ($shift->building->city), {{ $shift->building->city }}@endif</p>
                                        @endif
                                        @if ($colleagues->isNotEmpty())
                                            <p class="text-xs text-gray-500">Met {{ $colleagues->join(', ', ' en ') }}</p>
                                        @endif
                                        @if ($shift->notes)<p class="text-xs text-gray-600">{{ $shift->notes }}</p>@endif
                                    </div>
                                @endforeach
                                @if ($day['shifts']->isEmpty() && $day['absences']->isEmpty())
                                    <span class="text-gray-400">–</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
        <p class="text-xs text-gray-500">Klopt er iets niet, of kun je een dienst niet draaien? Neem contact op met je planner. Verlof vraag je aan via <a href="{{ route('my.absences.create') }}" class="text-teal-700 hover:underline">Verlof aanvragen</a>.</p>
    </div>
</x-app-layout>
