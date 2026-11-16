{{-- Dienst die een vervanger nodig heeft, met direct de beste kandidaten (resources/js/replace-card.js).
     Verwacht: $shift (met building en assignments.employee), $replace (id van wie vervangen wordt, of null),
     $button (tekst van de knop voor alle kandidaten) en optioneel $showDate en $missing (zie FixedStaffCheck::missingOn). --}}
@php
    $replace ??= null;
    $showDate ??= true;
    $button ??= 'Zoek vervanger';
    $missing ??= [];
@endphp
<li class="overflow-hidden" x-data="replaceCard({{ $shift->id }}, {{ $replace ?? 'null' }})" style="border-left: 4px solid {{ $shift->building->color }}">
    <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
        <div class="min-w-0">
            <p class="text-sm">
                <span class="font-semibold tabular-nums text-gray-900">@if ($showDate){{ $shift->starts_at->translatedFormat('D j M') }} · @endif{{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}</span>
                <a href="{{ route('buildings.show', $shift->building) }}" class="ms-1.5 font-medium text-gray-900 hover:text-teal-700">{{ $shift->building->name }}</a>
                <span class="text-gray-500">· {{ $shift->title }}</span>
            </p>
            <div class="mt-1.5 flex flex-wrap items-center gap-1">
                @foreach ($shift->assignments as $a)
                    <button type="button" data-open-employee="{{ $a->employee_id }}"
                            class="chip hover:bg-gray-200 {{ $a->employee_id === $replace ? 'bg-amber-100 text-amber-900 line-through decoration-amber-500' : '' }}">
                        <span class="me-1 h-2 w-2 rounded-full" style="background: {{ $a->employee->color }}"></span>{{ $a->employee->name }}
                    </button>
                @endforeach
                @if ($shift->openSlots() > 0)
                    <span class="chip bg-orange-100 text-orange-800" x-show="!done">{{ $shift->openSlots() }} van {{ $shift->required_staff }} open</span>
                @endif
                <span class="chip bg-teal-100 text-teal-800" x-show="done" x-cloak>✓ <span class="ms-1" x-text="done"></span>&nbsp;ingepland</span>
            </div>
            @foreach ($missing ?? [] as $m)
                <p class="mt-1 text-xs text-gray-500" x-show="!done">Vaste kracht <button type="button" class="font-medium text-gray-700 hover:text-teal-700" data-open-employee="{{ $m['employee_id'] }}">{{ $m['name'] }}</button>: {{ lcfirst($m['reason']) }}</p>
            @endforeach
        </div>
        <button type="button" class="btn-secondary btn-sm shrink-0" @click="more()" x-show="!done">{{ $button }}</button>
    </div>

    <div class="border-t border-gray-100 bg-gray-50/70 px-4 py-3" x-show="!done">
        <p class="text-xs text-gray-500" x-show="loading && !loaded">Kandidaten zoeken…</p>
        <p class="text-xs text-red-600" x-show="error" x-text="error" x-cloak></p>
        <p class="text-sm text-gray-600" x-show="loaded && candidates.length === 0" x-cloak>
            Niemand is op dit moment vrij. Bekijk via “{{ $button }}” wie waarom niet kan, of verplaats de dienst.
        </p>
        <div class="grid gap-2 md:grid-cols-3" x-show="candidates.length" x-cloak>
            <template x-for="c in candidates" :key="c.id">
                <div class="flex flex-col rounded-xl border bg-white p-3" :class="c.fit === 'best' ? 'border-teal-300 ring-1 ring-teal-200' : 'border-gray-200'">
                    <div class="flex items-center gap-2">
                        <span class="avatar avatar-sm" :style="'--c:' + c.color + ';--ink:' + c.ink" x-text="c.initials"></span>
                        <button type="button" class="min-w-0 flex-1 truncate text-left text-sm font-medium text-gray-900 hover:text-teal-700" :data-open-employee="c.id" x-text="c.name"></button>
                        <span class="badge shrink-0" :class="fit(c).badge" x-text="fit(c).label"></span>
                    </div>
                    <p class="mt-1.5 text-xs text-emerald-700" x-show="strengths(c).length" x-text="'✓ ' + strengths(c).join(' · ')"></p>
                    <p class="mt-0.5 text-xs text-red-600" x-show="concerns(c).length" x-text="'✗ ' + concerns(c).join(' · ')"></p>
                    <p class="mt-0.5 text-xs text-gray-500" x-text="[...notes(c), weekLine(c)].filter(Boolean).join(' · ')"></p>
                    <p class="mt-0.5 text-xs text-gray-500" x-show="c.day_shifts.length" x-text="'Die dag al: ' + dayLine(c)"></p>
                    <div class="mt-auto flex items-center justify-between gap-2 pt-2.5">
                        <a x-show="c.phone" :href="'tel:' + c.phone" class="inline-flex items-center gap-1 text-xs text-teal-700 hover:underline"><x-icon name="phone" class="icon-sm" /><span x-text="c.phone"></span></a>
                        <span x-show="!c.phone"></span>
                        <button type="button" class="btn btn-sm" :disabled="busy !== null" @click="assign(c)">Inplannen</button>
                    </div>
                </div>
            </template>
        </div>
        <p class="mt-2 text-xs text-gray-500" x-show="total > candidates.length" x-cloak>
            En nog <span x-text="total - candidates.length"></span> andere kandidaten via “{{ $button }}”.
        </p>
    </div>
</li>
