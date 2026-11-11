{{-- Vaste beschikbaarheid (weekpatroon). Gebruikt op de medewerkerpagina én door de medewerker zelf. --}}
@php($days = config('poetsploeg.weekdays'))
<div class="card card-body space-y-4" x-data="availabilityEditor(@js($patterns), @js($absenceBlocks))">
    <div>
        <h2 class="font-semibold text-gray-800">Vaste beschikbaarheid <span class="chip ms-1 align-middle bg-teal-50 text-teal-800">↻ komt steeds terug</span></h2>
        <p class="text-xs text-gray-500">
            @if ($self)
            Je vaste weekpatroon: wanneer <strong class="text-teal-700">kun</strong> je normaal gesproken wel, en wanneer <strong class="text-red-700">niet</strong> (bijv. school 08:00–15:00)?
        @else
            Het vaste weekpatroon: wanneer <strong class="text-teal-700">kan</strong> deze medewerker normaal gesproken wel, en wanneer <strong class="text-red-700">kan</strong> hij of zij <strong class="text-red-700">niet</strong> (bijv. school 08:00–15:00)?
        @endif
        </p>
    </div>

    {{-- Vast patroon hier, eenmalige afwijkingen via afwezigheid --}}
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-md border border-teal-200 bg-teal-50/60 px-3 py-2 text-sm text-teal-900">
            <p class="font-semibold">↻ Hier: alleen wat steeds terugkomt</p>
            <p class="mt-0.5 text-xs">Bijvoorbeeld “elke maandag vanaf 17:00” of “om de week op vrijdag niet”. Dit geldt elke week opnieuw, tot je het aanpast.</p>
        </div>
        <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            <p class="font-semibold">Eenmalig anders? Gebruik afwezigheid</p>
            <p class="mt-0.5 text-xs">
                Vakantie, ziekte, een afspraak of één dag niet kunnen leg je vast als afwezigheid. <strong>Afwezigheid gaat altijd vóór</strong> op dit weekpatroon.
                @if ($absenceUrl)
                    <a href="{{ $absenceUrl }}" class="font-medium underline hover:text-amber-700">{{ $self ? 'Verlof aanvragen ›' : 'Afwezigheid registreren ›' }}</a>
                @endif
            </p>
        </div>
    </div>

    {{-- Weekoverzicht: per dag een tijdbalk --}}
    <div class="space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="inline-flex items-center gap-1">
                <button type="button" class="btn-secondary btn-sm" @click="shiftWeek(-1)" aria-label="Vorige week">‹</button>
                <button type="button" class="btn-secondary btn-sm" @click="thisWeek()" :class="isThisWeek() ? 'bg-gray-100' : ''">Deze week</button>
                <button type="button" class="btn-secondary btn-sm" @click="shiftWeek(1)" aria-label="Volgende week">›</button>
                <span class="ms-2 text-sm font-medium text-gray-700" x-text="weekLabel()"></span>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600">
                <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded-sm bg-teal-300 ring-1 ring-teal-500"></span>kan wel</span>
                <span class="inline-flex items-center gap-1"><span class="avail-blocked h-3 w-3 rounded-sm ring-1 ring-red-400"></span>kan niet</span>
                <span class="inline-flex items-center gap-1"><span class="avail-absent h-3 w-3 rounded-sm ring-1 ring-gray-400"></span>afwezig (gaat vóór)</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <div class="min-w-[36rem] text-xs">
                <div class="flex">
                    <div class="w-24 shrink-0"></div>
                    <div class="relative h-4 flex-1">
                        <template x-for="t in hourTicks()" :key="t.label">
                            <span class="absolute -translate-x-1/2 text-gray-400" :style="`left:${t.left}%`" x-text="t.label"></span>
                        </template>
                    </div>
                    <div class="w-8 shrink-0"></div>
                </div>
                <template x-for="d in days()" :key="d.toISOString()">
                    <div class="flex items-center border-t border-gray-100 py-0.5">
                        <div class="w-24 shrink-0 pe-2 font-medium" :class="isToday(d) ? 'text-teal-700' : 'text-gray-700'" x-text="dayLabel(d)"></div>
                        <div class="relative h-7 flex-1 overflow-hidden rounded"
                             :class="{ 'bg-gray-100': restIs() === 'unavailable', 'bg-teal-50': restIs() === 'available', 'bg-gray-50': restIs() === 'none' }">
                            <template x-for="t in hourTicks()" :key="'g' + t.label">
                                <span class="absolute inset-y-0 w-px bg-white/80" :style="`left:${t.left}%`"></span>
                            </template>
                            <template x-for="b in blocks(d)" :key="b.index">
                                <button type="button" class="absolute inset-y-0.5 overflow-hidden whitespace-nowrap rounded px-1 text-left leading-6"
                                        :class="b.type === 'unavailable' ? 'avail-blocked z-10 text-red-900 ring-1 ring-red-400' : 'bg-teal-300 text-teal-950 ring-1 ring-teal-500'"
                                        :style="b.style" :title="b.title + ' – klik om te wijzigen'" x-text="b.label" @click="focus(b.index)"></button>
                            </template>
                            <template x-for="(a, ai) in absencesOn(d)" :key="'a' + ai">
                                <div class="avail-absent absolute inset-y-0 z-20 overflow-hidden whitespace-nowrap px-1 font-medium leading-7 text-gray-800 ring-1 ring-gray-400"
                                     :style="a.style" :title="a.label + ' – gaat vóór het weekpatroon'" x-text="a.label"></div>
                            </template>
                        </div>
                        <button type="button" class="ms-1 w-7 shrink-0 rounded text-base leading-6 text-gray-400 hover:bg-teal-50 hover:text-teal-700"
                                title="Tijdvak toevoegen op deze dag" aria-label="Tijdvak toevoegen op deze dag" @click="add('available', ((d.getDay() + 6) % 7) + 1)">+</button>
                    </div>
                </template>
            </div>
        </div>
        <p class="text-xs text-gray-500">
            <span x-show="restIs() === 'none'">Nog niets ingevuld: {{ $self ? 'je geldt' : 'de medewerker geldt' }} op alle tijden als beschikbaar (geen voorkeur).</span>
            <span x-show="restIs() === 'available'" x-cloak>Buiten de rode blokken {{ $self ? 'geld je' : 'geldt de medewerker' }} als beschikbaar.</span>
            <span x-show="restIs() === 'unavailable'" x-cloak>Buiten de groene blokken (grijs) {{ $self ? 'geld je' : 'geldt de medewerker' }} als niet beschikbaar. Rood (“kan niet”) gaat altijd vóór groen.</span>
            <span x-show="weekHasAbsence()" x-cloak>In deze week staat afwezigheid: die gaat vóór het patroon.</span>
        </p>
    </div>

    {{-- Tijdvakken: gesorteerd op dag en tijd bij openen en na opslaan; tijdens bewerken blijven regels op hun plek. --}}
    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-gray-800">Tijdvakken</h3>
        <p x-show="!patterns.length" class="text-sm text-gray-500">Nog geen tijdvakken. Voeg hieronder toe wat elke week terugkomt.</p>
        <template x-for="(p, i) in patterns" :key="i">
            <div class="flex flex-wrap items-center gap-2 rounded-md border-l-4 px-2 py-1.5 transition-shadow" :data-pattern="i"
                 :class="[p.type === 'unavailable' ? 'border-red-400 bg-red-50/60' : 'border-teal-400 bg-teal-50/40', highlight === i ? 'ring-2 ring-teal-500' : '']">
                <select :name="`patterns[${i}][type]`" x-model="p.type" aria-label="Kan wel of kan niet"
                        class="rounded-md border-gray-300 py-1 text-sm font-medium shadow-sm" :class="p.type === 'unavailable' ? 'text-red-700' : 'text-teal-700'">
                    @foreach (\App\Models\AvailabilityPattern::TYPES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select :name="`patterns[${i}][repeat]`" x-model="p.repeat" @change="repeatChanged(p)" aria-label="Herhaling"
                        class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                    @foreach (\App\Models\AvailabilityPattern::REPEATS as $value => [$unit, $every, $label])
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select :name="`patterns[${i}][weekday]`" x-model.number="p.weekday" x-show="!isDaily(p)" aria-label="Dag"
                        class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                    @foreach ($days as $num => $day)
                        <option value="{{ $num }}">{{ ucfirst($day) }}</option>
                    @endforeach
                </select>
                <label x-show="needsStart(p)" class="inline-flex items-center gap-1 text-xs text-gray-600">
                    <span x-text="isDaily(p) ? 'vanaf' : 'vanaf week van'"></span>
                    <input type="date" :name="`patterns[${i}][starts_on]`" x-model="p.starts_on" :disabled="!needsStart(p)" aria-label="Vanaf"
                           class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                </label>
                <span class="inline-flex items-center gap-1">
                    <input type="time" :name="`patterns[${i}][start_time]`" x-model="p.start_time" required aria-label="Van"
                           class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                    <span class="text-sm text-gray-500">–</span>
                    <input type="time" :name="`patterns[${i}][end_time]`" x-model="p.end_time" required aria-label="Tot"
                           class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                </span>
                <input type="text" :name="`patterns[${i}][note]`" x-model="p.note" maxlength="100" aria-label="Omschrijving"
                       :placeholder="p.type === 'unavailable' ? 'Reden, bijv. school' : 'Opmerking'"
                       class="min-w-0 flex-1 basis-32 rounded-md border-gray-300 py-1 text-sm shadow-sm">
                <button type="button" @click="remove(i)" class="rounded p-1 text-gray-400 hover:bg-white hover:text-red-600" title="Verwijderen" aria-label="Verwijderen">&times;</button>
            </div>
        </template>
        <div class="flex flex-wrap gap-2 pt-1">
            <button type="button" class="btn-secondary btn-sm" @click="add('available')">+ Kan wel</button>
            <button type="button" class="btn-secondary btn-sm text-red-700" @click="add('unavailable')">+ Kan niet</button>
            <button type="button" class="btn-secondary btn-sm" x-show="patterns.length && !isDaily(patterns[patterns.length - 1])" @click="copyToWeekdays()">
                Laatste regel kopiëren naar ma t/m vr
            </button>
        </div>
        <p class="text-xs text-gray-500">Herhaling “om de week”, “om de 3 weken” of “om de dag” telt vanaf de gekozen startdatum; blader hierboven door de weken om te zien wanneer het geldt.</p>
    </div>
</div>
