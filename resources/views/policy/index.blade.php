@php
    $groups = \App\Support\Policy::groups();
    $cao = config('poetsploeg-cao');
    $weekdays = config('poetsploeg.weekdays');
    $fmt = fn ($v) => is_bool($v) ? ($v ? 'aan' : 'uit') : (is_float($v) ? rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') : (string) $v);
@endphp
<x-app-layout title="Beleid">
    <x-slot name="header">
        <div>
            <h1 class="page-title">Beleid</h1>
            <p class="text-sm text-gray-500">Standaard volgens de {{ $cao['version'] }}. Elke waarde kun je overschrijven.</p>
        </div>
        <a href="{{ route('policy.preview') }}" class="btn-secondary">Bekijken als medewerker</a>
    </x-slot>

    <form method="POST" action="{{ route('policy.update') }}" class="space-y-6" x-data="policyForm" @submit="dirty = false">
        @csrf @method('PUT')

        {{-- Overzicht: welke versie, wat wijkt af --}}
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="card card-body space-y-2 lg:col-span-2">
                <h2 class="font-semibold text-gray-800">Afwijkingen van de cao <span class="text-sm font-normal text-gray-500">({{ $shownDate->isToday() ? 'geldig vandaag' : 'versie vanaf '.$shownDate->translatedFormat('j F Y') }})</span></h2>
                @forelse ($deviations as $d)
                    <p class="text-sm"><span class="chip {{ $d['unfavorable'] ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800' }}">{{ $d['unfavorable'] ? 'ten nadele' : 'afwijkend' }}</span> {{ $d['group'] }}: {{ $d['label'] }}</p>
                @empty
                    <p class="text-sm text-teal-800">Alles staat op de cao-standaard.</p>
                @endforelse
                @if ($policy->version?->note)
                    <p class="text-xs text-gray-500">Toelichting: {{ $policy->version->note }}</p>
                @endif
            </div>
            <div class="card card-body space-y-2 text-sm">
                <h2 class="font-semibold text-gray-800">Versies</h2>
                @if ($upcoming)
                    <p class="rounded bg-sky-50 px-2 py-1 text-sky-900">Nieuwe versie gaat in op {{ $upcoming->valid_from->translatedFormat('j F Y') }}.</p>
                @endif
                <ul class="max-h-40 space-y-1 overflow-y-auto">
                    @forelse ($versions as $v)
                        <li><a href="{{ route('policy.index', ['versie' => $v->valid_from->toDateString()]) }}" class="text-teal-700 hover:underline">vanaf {{ $v->valid_from->format('d-m-Y') }}</a> <span class="text-xs text-gray-500">door {{ $v->author?->name ?? 'onbekend' }}</span></li>
                    @empty
                        <li class="text-gray-500">Nog geen eigen versies: alles volgens de cao.</li>
                    @endforelse
                </ul>
                <p class="text-xs text-gray-500">Bronnen: <code>docs/bronnen/</code> in de repository.</p>
            </div>
        </div>

        @unless ($canEdit)
            <p class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600">Je kunt het beleid bekijken maar niet wijzigen.</p>
        @endunless

        {{-- Tabs --}}
        <div class="card">
            <div class="flex flex-wrap gap-1 border-b px-3 pt-3" role="tablist">
                @foreach ($groups as $group => $definition)
                    <button type="button" class="rounded-t-md px-3 py-2 text-sm font-medium" role="tab"
                            :class="tab === @js($group) ? 'bg-teal-600 text-white' : 'text-gray-600 hover:bg-gray-100'" @click="tab = @js($group)">{{ $definition['label'] }}</button>
                @endforeach
                <button type="button" class="rounded-t-md px-3 py-2 text-sm font-medium" role="tab"
                        :class="tab === 'leave' ? 'bg-teal-600 text-white' : 'text-gray-600 hover:bg-gray-100'" @click="tab = 'leave'">Verlofsoorten</button>
            </div>

            <fieldset @disabled(! $canEdit) @input="dirty = true" class="card-body">
                @foreach ($groups as $group => $definition)
                    <section x-show="tab === @js($group)" @if (! $loop->first) x-cloak @endif data-group>
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="font-semibold text-gray-800">{{ $definition['label'] }}</h2>
                            @if ($canEdit)<button type="button" class="text-sm text-teal-700 hover:underline" @click="resetGroup($el.closest('[data-group]'))">Hele groep terug naar cao</button>@endif
                        </div>
                        <div class="divide-y">
                            @foreach ($definition['items'] as $item => $setting)
                                @php
                                    $key = "{$group}.{$item}";
                                    $id = 'p_'.$group.'_'.$item;
                                    $value = $policy->get($key);
                                    $default = \App\Support\Policy::cast($setting, $setting['default']);
                                    $deviating = $policy->isDeviating($key);
                                    $bad = $policy->isUnfavorable($key);
                                    $name = "settings[{$group}][{$item}]";
                                @endphp
                                <div class="grid gap-2 py-3 sm:grid-cols-12 sm:items-center">
                                    <div class="sm:col-span-5">
                                        <label for="{{ $id }}" class="text-sm font-medium text-gray-800">{{ $setting['label'] }}</label>
                                        <p class="text-xs text-gray-500">{{ $setting['source'] }}</p>
                                    </div>
                                    <div class="sm:col-span-4">
                                        @switch($setting['type'])
                                            @case('bool')
                                                <input type="hidden" name="{{ $name }}" value="0">
                                                <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" class="checkbox" @checked($value) data-default="{{ $default ? '1' : '0' }}" data-type="bool">
                                                @break
                                            @case('select')
                                                <select id="{{ $id }}" name="{{ $name }}" class="input mt-0" data-default="{{ $default }}">
                                                    @foreach ($setting['options'] as $option => $label)
                                                        <option value="{{ $option }}" @selected($value === $option)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                @break
                                            @case('weekday')
                                                <select id="{{ $id }}" name="{{ $name }}" class="input mt-0" data-default="{{ $default }}">
                                                    @foreach ($weekdays as $num => $day)
                                                        <option value="{{ $num }}" @selected($value === $num)>{{ ucfirst($day) }}</option>
                                                    @endforeach
                                                </select>
                                                @break
                                            @case('time')
                                                <input type="time" id="{{ $id }}" name="{{ $name }}" class="input mt-0 w-32" value="{{ $value }}" data-default="{{ $default }}">
                                                @break
                                            @default
                                                <span class="flex items-center gap-2">
                                                    <input type="number" id="{{ $id }}" name="{{ $name }}" class="input mt-0 w-28" value="{{ $value }}" step="{{ $setting['type'] === 'decimal' ? '0.01' : '1' }}" min="{{ $setting['min'] ?? '' }}" max="{{ $setting['max'] ?? '' }}" data-default="{{ $default }}">
                                                    <span class="text-sm text-gray-500">{{ $setting['unit'] ?? '' }}</span>
                                                </span>
                                        @endswitch
                                        <x-input-error :messages="$errors->get('settings.'.$group.'.'.$item)" class="mt-1" />
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 text-xs sm:col-span-3">
                                        @if ($bad)
                                            <span class="chip bg-red-100 text-red-800" title="Ten nadele van de werknemer">ten nadele</span>
                                        @elseif ($deviating)
                                            <span class="chip bg-amber-100 text-amber-800">afwijkend</span>
                                        @else
                                            <span class="chip bg-teal-50 text-teal-800">cao</span>
                                        @endif
                                        <span class="text-gray-500">cao: {{ $setting['type'] === 'select' ? ($setting['options'][$default] ?? $default) : ($setting['type'] === 'weekday' ? $weekdays[$default] : $fmt($default)) }}</span>
                                        @if ($canEdit && $deviating)
                                            <button type="button" class="text-teal-700 hover:underline" @click="reset(@js($id))">terugzetten</button>
                                        @endif
                                    </div>
                                    @if ($setting['employee'] && $deviating)
                                        <div class="sm:col-span-12">
                                            <label for="{{ $id }}_note" class="text-xs text-gray-600">Toelichting voor medewerkers (optioneel)</label>
                                            <input id="{{ $id }}_note" name="employee_notes[{{ $key }}]" class="input mt-0 text-sm" maxlength="500" value="{{ old("employee_notes.{$key}", $policy->employeeNote($key)) }}" placeholder="Bijv. waarom jullie hier anders doen dan de cao">
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                {{-- Verlofsoorten --}}
                <section x-show="tab === 'leave'" x-cloak>
                    @include('policy.partials.leave-types', ['types' => $policy->leaveTypes(), 'canEdit' => $canEdit])
                </section>
            </fieldset>
        </div>

        @if ($canEdit)
            <div class="card card-body grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="valid_from">Geldig vanaf</label>
                    <input type="date" id="valid_from" name="valid_from" class="input" value="{{ old('valid_from', max($shownDate, \Carbon\CarbonImmutable::today())->toDateString()) }}" min="{{ today()->toDateString() }}" required>
                    <p class="mt-1 text-xs text-gray-500">Het beleid vóór deze datum blijft gelden voor eerdere diensten en verlof.</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="note">Toelichting</label>
                    <textarea id="note" name="note" rows="2" class="input" placeholder="Verplicht als je ten nadele van de werknemer afwijkt">{{ old('note', $policy->version?->note) }}</textarea>
                    <x-input-error :messages="$errors->get('note')" class="mt-1" />
                </div>
                <div class="sm:col-span-3 flex flex-wrap items-center gap-3">
                    <button type="submit" class="btn">Beleid opslaan</button>
                    <button type="button" class="btn-secondary" @click="resetAll()">Alles terug naar cao</button>
                    <span class="text-xs text-amber-700" x-show="dirty" x-cloak>Niet opgeslagen wijzigingen</span>
                </div>
                <p class="sm:col-span-3 text-xs text-gray-500">Bij een algemeen verbindend verklaarde cao mag je meestal niet ten nadele van de werknemer afwijken. Een afwijking ten nadele kun je alleen opslaan met een toelichting; die komt in het logboek.</p>
            </div>
        @endif
    </form>
</x-app-layout>
