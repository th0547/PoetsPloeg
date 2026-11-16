@php
    $isRequest = $absence->status !== 'approved' || $absence->decided_at !== null;
    $total = $rows->count();
    $done = $rows->where('state', 'done')->count();
    $todo = $total - $done;
    $name = $absence->employee->name;
    $statusBadge = ['pending' => 'badge-amber', 'approved' => 'badge-teal', 'rejected' => 'badge-red'][$absence->status];
@endphp
<x-app-layout :title="$isRequest ? 'Verlofverzoek' : 'Afwezigheid'">
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-3">
            <x-avatar :name="$name" :color="$absence->employee->color" size="lg" />
            <div class="min-w-0">
                <h1 class="page-title">
                    <button type="button" class="hover:text-teal-700" data-open-employee="{{ $absence->employee_id }}">{{ $name }}</button>
                    <span class="font-normal text-gray-500">· {{ $absence->isPending() || $absence->status === 'rejected' ? $absence->requestLabel() : $absence->typeLabel() }}</span>
                </h1>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600">
                    @if ($isRequest)<span class="badge {{ $statusBadge }}">{{ $absence->statusLabel() }}</span>@endif
                    <span>{{ $absence->periodLabel() }}</span>
                </p>
                @if ($absence->note)<p class="mt-0.5 text-sm text-gray-500">{{ $absence->note }}</p>@endif
            </div>
        </div>
        <a href="{{ route('absences.index') }}" class="btn-ghost"><x-icon name="chevron-left" />Alle afwezigheid</a>
    </x-slot>

    <div class="max-w-5xl space-y-6"
         x-data="{ total: {{ $total }}, done: {{ $done }} }"
         @replace-card-filled.window="done = Math.min(total, done + 1); if ($event.detail.approved || done >= total) setTimeout(() => window.location.reload(), 1200)"
         @shift-panel-closed.window="if ($event.detail.changed) window.location.reload()">

        {{-- Waar staan we --}}
        @if ($absence->isPending())
            <div class="notice notice-warning">
                <x-icon name="clock" class="mt-0.5 text-amber-600" />
                <div>
                    <p class="font-medium">Dit verlof wordt pas goedgekeurd als er voor alle diensten een vervanger is.</p>
                    <p class="mt-0.5">Kies hieronder per dienst een vervanger; die neemt de plek van {{ $name }} in één keer over.</p>
                </div>
            </div>
        @elseif ($absence->status === 'rejected')
            <div class="notice notice-danger">
                <x-icon name="alert-circle" class="mt-0.5 text-red-600" />
                <span>Afgewezen{{ $absence->decided_at ? ' op '.$absence->decided_at->translatedFormat('j F H:i') : '' }}. {{ $name }} blijft ingepland op de diensten die nog niet vervangen waren.</span>
            </div>
        @elseif ($isRequest)
            <div class="notice notice-success">
                <x-icon name="check-circle" class="mt-0.5 text-teal-600" />
                <span>Goedgekeurd{{ $absence->decided_at ? ' op '.$absence->decided_at->translatedFormat('j F H:i') : '' }}: alle diensten zijn vervangen.</span>
            </div>
        @endif

        {{-- Diensten met kandidaten --}}
        <section class="card overflow-hidden">
            <div class="card-head">
                <div>
                    <h2 class="card-title">
                        @if ($linked) Diensten van {{ $name }} in deze periode @else Open diensten in deze periode @endif
                        <span class="font-normal text-gray-500">({{ $total }})</span>
                    </h2>
                    <p class="text-xs text-gray-500">
                        @if ($total === 0)
                            Er stonden geen diensten in deze periode.
                        @elseif ($absence->status === 'rejected')
                            Het verzoek is afgewezen; er hoeft niets vervangen te worden.
                        @else
                            Per dienst zie je de beste kandidaten. Eén klik op Inplannen en de plek is gevuld.
                        @endif
                    </p>
                </div>
                @if ($total > 0)
                    <div class="w-full sm:w-56">
                        <p class="text-right text-xs font-medium text-gray-700"><span x-text="done">{{ $done }}</span> van {{ $total }} {{ $linked ? 'ingevuld' : 'gevuld' }}</p>
                        <div class="meter mt-1"><span :style="'width:' + (total ? done / total * 100 : 0) + '%'" style="width: {{ $total ? $done / $total * 100 : 0 }}%"></span></div>
                    </div>
                @endif
            </div>

            <ul class="divide-y divide-gray-200">
                @forelse ($rows as $row)
                    @php($shift = $row['shift'])
                    @if ($row['state'] === 'todo' && $absence->isPending())
                        @include('partials.replace-card', ['shift' => $shift, 'replace' => $absence->employee_id, 'button' => 'Zoek vervanger'])
                    @elseif ($row['state'] === 'open' && ! $shift->cancelled_at)
                        @include('partials.replace-card', ['shift' => $shift, 'replace' => null, 'button' => $isRequest ? 'Plek invullen' : 'Zoek vervanger'])
                    @else
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5" style="border-left: 4px solid {{ $shift->building->color }}">
                            <div class="min-w-0">
                                <p class="text-sm">
                                    <span class="tabular-nums text-gray-600">{{ $shift->starts_at->translatedFormat('D j M') }} · {{ $shift->starts_at->format('H:i') }}–{{ $shift->ends_at->format('H:i') }}</span>
                                    <span class="ms-1.5 font-medium">{{ $shift->building->name }}</span>
                                    <span class="text-gray-500">· {{ $shift->title }}</span>
                                </p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($shift->assignments as $a)
                                        <span class="chip {{ $a->employee_id === $absence->employee_id ? 'bg-amber-100 text-amber-900' : '' }}">{{ $a->employee->name }}</span>
                                    @endforeach
                                    @if ($row['state'] === 'done')
                                        <span class="chip bg-teal-100 text-teal-800">{{ $shift->cancelled_at ? 'Geannuleerd' : '✓ Vervangen' }}</span>
                                    @endif
                                </div>
                            </div>
                            @unless ($shift->cancelled_at)
                                <button type="button" class="btn-ghost btn-sm" data-open-shift="{{ $shift->id }}">Bekijk dienst</button>
                            @endunless
                        </li>
                    @endif
                @empty
                    <li class="empty"><x-icon name="check-circle" class="icon-lg text-teal-600" />Geen diensten die een vervanger nodig hebben.</li>
                @endforelse
            </ul>
        </section>

        @include('absences.partials.hours')

        <div class="flex flex-wrap gap-3">
            @if ($absence->isPending())
                <form method="POST" action="{{ route('absences.reject', $absence) }}" data-confirm="Verlofverzoek afwijzen? {{ $name }} blijft dan ingepland op de diensten die nog niet vervangen zijn." data-confirm-label="Afwijzen">
                    @csrf
                    <button type="submit" class="btn-danger">Verzoek afwijzen</button>
                </form>
            @endif
            <form method="POST" action="{{ route('absences.destroy', $absence) }}" data-confirm="Deze afwezigheid intrekken? Eerder vrijgemaakte diensten en ingeplande vervangers blijven zoals ze zijn. De registratie blijft bewaard in de geschiedenis." data-confirm-label="Intrekken">
                @csrf @method('DELETE')
                <button type="submit" class="btn-secondary">{{ $isRequest ? 'Intrekken' : 'Afwezigheid intrekken' }}</button>
            </form>
        </div>
    </div>
</x-app-layout>
