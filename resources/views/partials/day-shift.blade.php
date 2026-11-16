{{-- Eén dienst in de dagagenda (Vandaag). Verwacht $shift, $warnings ([shift][employee]) en $state: now, later of done. --}}
@php
    $all = collect($warnings[$shift->id] ?? [])->flatten(1);
    $level = \App\Support\Planning::worstLevel($all->all());
    $minutesLeft = $state === 'now' ? (int) now()->diffInMinutes($shift->ends_at) : null;
    $progress = $state === 'now' ? min(100, max(0, $shift->starts_at->diffInMinutes(now()) / max(1, $shift->starts_at->diffInMinutes($shift->ends_at)) * 100)) : null;
@endphp
<li class="group relative flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 hover:bg-gray-50 {{ $state === 'done' ? 'opacity-60' : '' }}"
    style="border-left: 4px solid {{ $shift->building->color }}" data-open-shift="{{ $shift->id }}" @if ($shift->openSlots() > 0) data-candidates @endif>
    <div class="w-24 shrink-0">
        <p class="text-sm font-semibold tabular-nums text-gray-900">{{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}</p>
        @if ($state === 'now')
            <p class="text-xs text-emerald-700">nog {{ $minutesLeft >= 60 ? intdiv($minutesLeft, 60).' u '.($minutesLeft % 60).' min' : $minutesLeft.' min' }}</p>
        @endif
    </div>
    <div class="min-w-0 flex-1 basis-48">
        <p class="truncate text-sm font-medium text-gray-900">{{ $shift->building->name }}</p>
        <p class="truncate text-xs text-gray-500">{{ $shift->title }}@if ($shift->building->city) · {{ $shift->building->city }}@endif</p>
        @if ($state === 'now')
            <div class="meter mt-1.5 max-w-[14rem] !h-1"><span class="!bg-emerald-500" style="width: {{ $progress }}%"></span></div>
        @endif
    </div>
    <div class="flex flex-wrap items-center justify-end gap-1.5">
        @foreach ($shift->assignments as $a)
            @php($w = $warnings[$shift->id][$a->employee_id] ?? [])
            @php($l = \App\Support\Planning::worstLevel($w))
            <button type="button" data-open-employee="{{ $a->employee_id }}" title="{{ collect($w)->pluck('message')->implode("\n") ?: 'Bekijk '.$a->employee->name }}"
                    class="inline-flex items-center gap-1.5 rounded-full py-0.5 pe-2.5 ps-0.5 text-xs font-medium hover:ring-2 hover:ring-teal-200
                           {{ $l === 'danger' ? 'bg-red-100 text-red-800' : ($l === 'warning' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-800') }}">
                <x-avatar :name="$a->employee->name" :color="$a->employee->color" size="xs" />{{ $a->employee->name }}
                @if ($l === 'danger' || $l === 'warning')<x-icon name="alert" class="icon-sm" />@endif
            </button>
        @endforeach
        @if ($shift->openSlots() > 0)
            <span class="badge badge-orange">{{ $shift->openSlots() }} open</span>
        @endif
        @if ($shift->extraStaff() > 0)
            <span class="badge badge-sky" title="Meer mensen ingepland dan de bezetting vraagt">+{{ $shift->extraStaff() }} extra</span>
        @endif
    </div>
</li>
