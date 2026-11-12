<x-app-layout title="Mijn beschikbaarheid">
    <x-slot name="header">
        <h1 class="page-title">Mijn beschikbaarheid</h1>
    </x-slot>

    @if ($confirmEnabled)
        @php($n = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ','))
        <section id="bevestigen" class="card card-body mb-6 max-w-4xl space-y-3">
            <div>
                <h2 class="font-semibold text-gray-800">Beschikbaarheid bevestigen</h2>
                <p class="text-sm text-gray-600">Controleer per week of je beschikbaarheid klopt en bevestig die, zodat de planner het rooster op tijd rond kan maken. Je krijgt elke week een mail voor de week die dan aan de beurt is.
                    @if ($afterConfirm === 'locked') Na bevestigen kun je die week alleen via je planner wijzigen. @elseif ($afterConfirm === 'request') Wijzig je daarna nog iets, dan ziet je planner dat. @endif
                </p>
            </div>
            <ul class="divide-y">
                @foreach ($confirmWeeks as $w)
                    @php($row = $w['row'])
                    <li class="py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm">
                                <strong>Week {{ $w['start']->isoWeek() }}</strong>
                                <span class="text-gray-500">{{ $w['start']->translatedFormat('j M') }} – {{ $w['start']->addDays(6)->translatedFormat('j M') }}</span>
                                · beschikbaar <strong>{{ $n($w['hours']) }} uur</strong>
                                @if ($employee->contract_hours_min)<span class="text-gray-500">(contract {{ $n($employee->contract_hours_min) }} uur)</span>@endif
                            </p>
                            @if ($row?->isConfirmed())
                                <span class="chip bg-teal-50 text-teal-800">bevestigd {{ $row->confirmed_at->translatedFormat('j M') }}</span>
                            @else
                                <span class="chip bg-amber-100 text-amber-800">nog niet bevestigd</span>
                            @endif
                        </div>
                        @if ($w['below'] && ! $row?->isConfirmed())
                            <p class="mt-1 rounded bg-amber-50 px-2 py-1 text-xs text-amber-900">Je bent deze week minder beschikbaar dan je contracturen. Neem verlof op of overleg met je teamleider; bij bevestigen vragen we om een toelichting.</p>
                        @endif
                        @unless ($row?->isConfirmed())
                            <form method="POST" action="{{ route('my.availability.confirm') }}" class="mt-2 flex flex-wrap items-end gap-2">
                                @csrf
                                <input type="hidden" name="week" value="{{ $w['start']->toDateString() }}">
                                @if ($w['below'])
                                    <div class="min-w-0 flex-1">
                                        <label class="text-xs text-gray-600" for="explanation-{{ $loop->index }}">Toelichting</label>
                                        <input id="explanation-{{ $loop->index }}" name="explanation" class="input mt-0" maxlength="1000" placeholder="Bijv. afgesproken met mijn teamleider">
                                    </div>
                                @endif
                                @if ($otherEmployerField)
                                    <div class="min-w-0 flex-1">
                                        <label class="text-xs text-gray-600" for="other-{{ $loop->index }}">Werk bij een andere werkgever (optioneel)</label>
                                        <input id="other-{{ $loop->index }}" name="other_employer" class="input mt-0" maxlength="255" placeholder="Bijv. di en do 08:00–12:00">
                                    </div>
                                @endif
                                <button type="submit" class="btn btn-sm">Beschikbaarheid bevestigen</button>
                            </form>
                        @endunless
                    </li>
                @endforeach
            </ul>
            <x-input-error :messages="$errors->get('explanation')" />
        </section>
    @endif

    <form method="POST" action="{{ route('my.availability.update') }}" class="max-w-4xl space-y-4">
        @csrf @method('PUT')
        <p class="text-sm text-gray-600">Geef door wanneer je normaal gesproken wel en niet kunt werken. De planner houdt hier rekening mee bij het inplannen. Diensten waarvoor je al bent ingepland, blijven staan.</p>

        @include('employees.partials.availability', ['self' => true, 'absenceUrl' => route('my.absences.create')])

        <button type="submit" class="btn">Beschikbaarheid opslaan</button>
    </form>
</x-app-layout>
