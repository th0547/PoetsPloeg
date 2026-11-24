@php
    $stateBadge = ['working' => 'badge-green', 'later' => 'badge-sky', 'absent' => 'badge-red'];
@endphp
<x-app-layout title="Medewerkers">
    <x-slot name="header">
        <div>
            <h1 class="page-title">Medewerkers <span class="text-base font-normal text-gray-500">({{ $employees->count() }})</span></h1>
            <p class="text-sm text-gray-500">Klik op een naam voor waar iemand nu is, de week en contactgegevens.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" class="flex flex-wrap items-center gap-3">
                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute left-2.5 top-2.5 text-gray-400" />
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Zoek op naam…" class="input mt-0 !w-56 !ps-8" aria-label="Zoek medewerker">
                </div>
                <label class="inline-flex items-center gap-1.5 text-sm text-gray-600">
                    <input type="checkbox" name="inactief" value="1" class="checkbox" @checked(request()->boolean('inactief')) data-auto-submit> ook inactief
                </label>
            </form>
            <a href="{{ route('employees.create') }}" class="btn"><x-icon name="plus" />Medewerker</a>
        </div>
    </x-slot>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Naam</th>
                    <th>Nu</th>
                    <th>Deze week</th>
                    <th>Vaardigheden</th>
                    <th>Ingewerkt op</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($employees as $employee)
                    @php
                        $now = $status[$employee->id] ?? null;
                        $hours = (float) ($weekHours[$employee->id] ?? 0);
                        $max = $employee->contract_hours_max;
                        $min = $employee->contract_hours_min;
                        $over = $max !== null && $hours > $max;
                        $under = $min !== null && $hours < $min;
                    @endphp
                    <tr class="hover:bg-gray-50 {{ $employee->active ? '' : 'opacity-60' }}">
                        <td class="!align-middle">
                            <div class="flex items-center gap-3">
                                <x-avatar :name="$employee->name" :color="$employee->color" />
                                <div class="min-w-0">
                                    <a href="{{ route('employees.show', $employee) }}" data-open-employee="{{ $employee->id }}" class="font-medium text-gray-900 hover:text-teal-700">{{ $employee->name }}</a>
                                    @unless ($employee->active) <span class="badge badge-gray">inactief</span> @endunless
                                    @if ($employee->user)
                                        @if ($employee->user->invitationPending())
                                            <span class="badge badge-amber" title="Uitgenodigd, nog geen wachtwoord ingesteld">uitgenodigd</span>
                                        @else
                                            <span class="badge badge-teal" title="Kan zelf inloggen">inlog</span>
                                        @endif
                                    @endif
                                    <p class="text-xs text-gray-500">
                                        @if (\App\Support\Phone::href($employee->phone))<a href="{{ \App\Support\Phone::href($employee->phone) }}" class="hover:text-teal-700">{{ $employee->phone }}</a>@elseif ($employee->phone){{ $employee->phone }}@endif
                                        @if ($employee->phone && $employee->email) · @endif{{ $employee->email }}
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="!align-middle">
                            @if ($now && $employee->active)
                                <span class="badge {{ $stateBadge[$now['state']] ?? 'badge-gray' }}"><span class="dot dot-{{ $now['state'] }}"></span>{{ $now['label'] }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap !align-middle">
                            <div class="w-28">
                                <p class="text-xs tabular-nums {{ $over ? 'font-semibold text-red-600' : 'text-gray-700' }}">
                                    {{ \App\Support\Planning::hours($hours) }}{{ $max !== null ? ' / '.\App\Support\Planning::hours($max) : '' }} u
                                </p>
                                @if ($max)
                                    <div class="meter mt-1 {{ $over ? 'is-over' : ($under ? 'is-under' : '') }}" title="{{ $min !== null ? 'Contract '.\App\Support\Planning::hours($min).'–'.\App\Support\Planning::hours($max).' uur per week' : '' }}"><span style="width: {{ min(100, $hours / $max * 100) }}%"></span></div>
                                @endif
                            </div>
                        </td>
                        <td class="!align-middle"><div class="flex max-w-[16rem] flex-wrap gap-1">@foreach ($employee->skills as $skill)<span class="badge badge-gray">{{ $skill->name }}</span>@endforeach</div></td>
                        <td class="!align-middle">
                            @if ($employee->buildings->isNotEmpty())
                                <span class="text-sm text-gray-600" title="{{ $employee->buildings->pluck('name')->implode(', ') }}">
                                    {{ $employee->buildings->take(2)->pluck('name')->implode(', ') }}@if ($employee->buildings->count() > 2) <span class="text-gray-400">+{{ $employee->buildings->count() - 2 }}</span>@endif
                                </span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-right !align-middle">
                            <a href="{{ route('employees.edit', $employee) }}" class="btn-ghost btn-sm" title="Gegevens, beschikbaarheid en verlof bewerken"><x-icon name="edit" class="icon-sm" />Bewerken</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-8 text-center text-gray-500">
                        @if (request('q')) Geen medewerkers gevonden voor “{{ request('q') }}”.
                        @else Nog geen medewerkers. <a href="{{ route('employees.create') }}" class="link">Voeg de eerste toe</a>. @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
