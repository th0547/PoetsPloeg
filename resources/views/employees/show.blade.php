@php
    $o = $overview;
    $status = $o['status'];
    $week = $o['week'];
    $max = $o['contract']['max'];
    $min = $o['contract']['min'];
    $over = $max !== null && $week['hours'] > $max;
    $under = $min !== null && $week['hours'] < $min;
    $stateBadge = ['working' => 'badge-green', 'later' => 'badge-sky', 'absent' => 'badge-red'][$status['state']] ?? 'badge-gray';
    $n = fn ($v) => \App\Support\Planning::hours((float) $v);
@endphp
<x-app-layout :title="$employee->name">
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-4">
            <x-avatar :name="$employee->name" :color="$employee->color" size="xl" />
            <div class="min-w-0">
                <h1 class="page-title truncate">{{ $employee->name }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-1.5">
                    <span class="badge {{ $stateBadge }}"><span class="dot dot-{{ $status['state'] }}"></span>{{ $status['label'] }}</span>
                    @unless ($employee->active) <span class="badge badge-gray">inactief</span> @endunless
                    @if ($employee->user)
                        <span class="badge {{ $employee->user->invitationPending() ? 'badge-amber' : 'badge-teal' }}">{{ $employee->user->invitationPending() ? 'uitgenodigd' : 'kan zelf inloggen' }}</span>
                    @endif
                </p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('employees.index') }}" class="btn-ghost"><x-icon name="chevron-left" />Medewerkers</a>
            <a href="{{ $o['urls']['planning'] }}" class="btn-secondary"><x-icon name="grid" />In planning</a>
            <a href="{{ $o['urls']['absence'] }}" class="btn-secondary"><x-icon name="user-x" />Afwezig melden</a>
            <a href="{{ $o['urls']['edit'] }}" class="btn"><x-icon name="edit" />Bewerken</a>
        </div>
    </x-slot>

    <div x-data @shift-panel-closed.window="if ($event.detail.changed) window.location.reload()" class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Week --}}
            <section class="card">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">Week {{ $week['number'] }} <span class="font-normal text-gray-500">· {{ $week['label'] }}</span></h2>
                        <p class="text-xs text-gray-500">{{ $week['shifts'] }} {{ $week['shifts'] === 1 ? 'dienst' : 'diensten' }}, {{ $week['hours_label'] }} uur. Klik op een dienst voor details of een vervanger.</p>
                    </div>
                    <div class="flex items-center gap-1">
                        <a href="{{ route('employees.show', [$employee, 'week' => $week['prev']]) }}" class="btn-icon border border-gray-300" aria-label="Vorige week"><x-icon name="chevron-left" /></a>
                        <a href="{{ route('employees.show', $employee) }}" class="btn-secondary btn-sm">Deze week</a>
                        <a href="{{ route('employees.show', [$employee, 'week' => $week['next']]) }}" class="btn-icon border border-gray-300" aria-label="Volgende week"><x-icon name="chevron-right" /></a>
                    </div>
                </div>
                <ul class="divide-y divide-gray-100">
                    @foreach ($o['days'] as $day)
                        <li class="flex gap-4 px-4 py-2.5 {{ $day['today'] ? 'bg-teal-50/60' : '' }}">
                            <div class="w-24 shrink-0 pt-1 text-sm">
                                <span class="font-medium {{ $day['today'] ? 'text-teal-700' : ($day['past'] ? 'text-gray-400' : 'text-gray-700') }}">{{ $day['label'] }}</span>
                                @if ($day['today'])<span class="block text-xs text-teal-700">vandaag</span>@endif
                                @if ($day['holiday'])<span class="block text-xs text-amber-700">{{ $day['holiday'] }}</span>@endif
                            </div>
                            <div class="min-w-0 flex-1 space-y-1.5">
                                @foreach ($day['absences'] as $absence)
                                    <p><span class="badge {{ $absence['kind'] === 'sickness' ? 'badge-red' : 'badge-gray' }}">{{ $absence['label'] }}</span>
                                        @if ($absence['pending'])<span class="badge badge-amber">aangevraagd</span>@endif</p>
                                @endforeach
                                @foreach ($day['shifts'] as $shift)
                                    <button type="button" data-open-shift="{{ $shift['id'] }}"
                                            class="flex w-full items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-left hover:border-teal-300 hover:bg-teal-50/40 {{ $shift['state'] === 'done' ? 'opacity-60' : '' }}"
                                            style="border-left: 4px solid {{ $shift['color'] }}">
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-medium text-gray-900"><span class="tabular-nums">{{ $shift['start'] }}–{{ $shift['end'] }}</span> · {{ $shift['building'] }}</span>
                                            <span class="block truncate text-xs text-gray-500">{{ $shift['title'] }}@if (count($shift['colleagues'])) · met {{ collect($shift['colleagues'])->join(', ', ' en ') }}@endif</span>
                                            @foreach ($shift['warnings'] as $warning)
                                                <span class="mt-0.5 flex items-center gap-1 text-xs {{ $shift['level'] === 'danger' ? 'text-red-600' : ($shift['level'] === 'warning' ? 'text-amber-700' : 'text-gray-500') }}"><x-icon name="alert" class="icon-sm" />{{ $warning }}</span>
                                            @endforeach
                                        </span>
                                        @if ($shift['state'] === 'now')<span class="badge badge-green">nu</span>@endif
                                        <span class="shrink-0 text-xs text-gray-400">{{ $shift['hours'] }} u</span>
                                    </button>
                                @endforeach
                                @if (! count($day['shifts']) && ! count($day['absences']))
                                    <p class="pt-1 text-sm text-gray-300">–</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Vaste taken --}}
            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">Vaste taken <span class="font-normal text-gray-500">({{ $fixedTemplates->count() }})</span></h2>
                    <p class="text-xs text-gray-500">Hier staat {{ $employee->name }} vast op ingepland.</p>
                </div>
                <ul class="divide-y divide-gray-100">
                    @forelse ($fixedTemplates as $row)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm">
                            <a href="{{ route('buildings.show', $row->template->building) }}" class="flex min-w-0 items-center gap-2 font-medium text-gray-900 hover:text-teal-700">
                                <span class="h-3 w-3 shrink-0 rounded" style="background: {{ $row->template->building->color }}"></span>
                                <span class="truncate">{{ $row->template->building->name }} · {{ $row->template->name }}</span>
                            </a>
                            <span class="text-xs text-gray-500">{{ $row->label }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-sm text-gray-500">Geen vaste taken. Wijs iemand toe bij een pand, onder Vaste bezetting.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="card card-body space-y-3">
                <h2 class="card-title">Contact</h2>
                @if (\App\Support\Phone::href($o['phone']))<a href="{{ \App\Support\Phone::href($o['phone']) }}" class="flex items-center gap-2 text-sm text-teal-700 hover:underline"><x-icon name="phone" />{{ $o['phone'] }}</a>@elseif ($o['phone'])<p class="flex items-center gap-2 text-sm text-gray-700"><x-icon name="phone" />{{ $o['phone'] }}</p>@endif
                @if ($o['email'])<a href="mailto:{{ $o['email'] }}" class="flex items-center gap-2 break-all text-sm text-teal-700 hover:underline"><x-icon name="mail" />{{ $o['email'] }}</a>@endif
                @if (! $o['phone'] && ! $o['email'])<p class="text-sm text-gray-500">Geen contactgegevens ingevuld.</p>@endif
                @if ($o['notes'])<p class="whitespace-pre-line border-t border-gray-100 pt-3 text-sm text-gray-600">{{ $o['notes'] }}</p>@endif
            </section>

            <section class="card card-body space-y-3">
                <h2 class="card-title">Uren deze week</h2>
                <div>
                    <div class="flex items-baseline justify-between">
                        <span class="text-2xl font-semibold tabular-nums">{{ $week['hours_label'] }} <span class="text-sm font-normal text-gray-500">{{ $max !== null ? '/ '.$n($max).' u' : 'u' }}</span></span>
                        @if ($over)<span class="text-xs font-medium text-red-600">boven contract</span>
                        @elseif ($under)<span class="text-xs font-medium text-amber-700">onder minimum ({{ $n($min) }} u)</span>@endif
                    </div>
                    @if ($max)
                        <div class="meter mt-2 {{ $over ? 'is-over' : ($under ? 'is-under' : '') }}"><span style="width: {{ min(100, $week['hours'] / $max * 100) }}%"></span></div>
                    @endif
                </div>
                <p class="text-xs text-gray-500">
                    Contract:
                    @if ($min !== null || $max !== null) {{ $n($min ?? 0) }}–{{ $max !== null ? $n($max) : '∞' }} uur per week @else niet ingevuld @endif
                </p>
            </section>

            <section class="card card-body space-y-3">
                <h2 class="card-title">Vaardigheden en panden</h2>
                <div class="flex flex-wrap gap-1">
                    @forelse ($o['skills'] as $skill)<span class="badge badge-gray">{{ $skill }}</span>@empty<span class="text-sm text-gray-500">Geen vaardigheden ingevuld.</span>@endforelse
                </div>
                <div class="flex flex-wrap gap-1">
                    @forelse ($o['buildings'] as $building)
                        <a href="{{ route('buildings.show', $building['id']) }}" class="badge badge-teal hover:underline"><span class="h-1.5 w-1.5 rounded-full" style="background: {{ $building['color'] }}"></span>{{ $building['name'] }}</a>
                    @empty
                        <span class="text-sm text-gray-500">Nog op geen enkel pand ingewerkt.</span>
                    @endforelse
                </div>
            </section>

            <section class="card card-body space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="card-title">Afwezigheid</h2>
                    <a href="{{ route('absences.index', ['medewerker' => $employee->id, 'periode' => 'alles']) }}" class="link text-xs">Geschiedenis</a>
                </div>
                @forelse ($o['absences'] as $absence)
                    <a href="{{ $absence['url'] }}" class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm hover:border-teal-300">
                        <span><span class="badge {{ $absence['kind'] === 'sickness' ? 'badge-red' : 'badge-gray' }}">{{ $absence['label'] }}</span>
                            @if ($absence['pending'])<span class="badge badge-amber">wacht op vervanger</span>@endif</span>
                        <span class="text-xs text-gray-500">{{ $absence['period'] }}</span>
                    </a>
                @empty
                    <p class="text-sm text-gray-500">Geen lopende of geplande afwezigheid.</p>
                @endforelse
                <div class="border-t border-gray-100 pt-3">
                    @include('partials.leave-counters', ['counters' => $counters, 'balance' => $balance, 'year' => $counterYear, 'showBalance' => true])
                </div>
            </section>
        </aside>
    </div>
</x-app-layout>
