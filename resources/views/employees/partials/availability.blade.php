{{-- Beschikbaarheid: weekpatroon en afwijkende dagen. Gebruikt op de medewerkerpagina én door de medewerker zelf. --}}
@php($days = config('poetsploeg.weekdays'))
<div class="card card-body space-y-4" x-data="availabilityEditor(@js($patterns), @js($absenceBlocks), @js($availabilityDays ?? []), @js(isset($employee) ? $employee->contract_hours_min : null))">
    <input type="hidden" name="days_form" value="1">
    <div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-gray-800">Beschikbaarheid <span x-show="newMode" x-cloak class="chip ms-1 align-middle bg-sky-100 text-sky-800">proefversie</span></h2>
            <button type="button" class="btn-secondary btn-sm" @click="toggleNew()" x-text="newMode ? '← Terug naar gewone invoer' : '✨ Test nieuwe invoer'">✨ Test nieuwe invoer</button>
        </div>
        <p class="text-xs text-gray-500">
            @if ($self)
            Je vaste weekpatroon: wanneer <strong class="text-teal-700">kun</strong> je normaal gesproken wel, en wanneer <strong class="text-red-700">niet</strong> (bijv. school 08:00–15:00)?
        @else
            Het vaste weekpatroon: wanneer <strong class="text-teal-700">kan</strong> deze medewerker normaal gesproken wel, en wanneer <strong class="text-red-700">kan</strong> hij of zij <strong class="text-red-700">niet</strong> (bijv. school 08:00–15:00)?
        @endif
        </p>
    </div>

    {{-- Weekpatroon, afwijkende dagen en afwezigheid --}}
    <div class="grid gap-3 sm:grid-cols-3" x-show="!newMode">
        <div class="rounded-md border border-teal-200 bg-teal-50/60 px-3 py-2 text-sm text-teal-900">
            <p class="font-semibold">↻ Weekpatroon</p>
            <p class="mt-0.5 text-xs">Wat steeds terugkomt, bijv. “elke maandag vanaf 17:00”. Geef een tijdvak een <strong>periode</strong> als het maar een tijd geldt, zoals het rooster van een schoolblok.</p>
        </div>
        <div class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">
            <p class="font-semibold">📅 Eén dag anders</p>
            <p class="mt-0.5 text-xs">Klik in het weekoverzicht op <strong>Anders</strong> bij een dag, bijv. bij een wisselend schoolrooster of een tentamen. Die dag geldt dan alleen wat je daar invult.</p>
        </div>
        <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            <p class="font-semibold">Vakantie, ziekte of afspraak?</p>
            <p class="mt-0.5 text-xs">
                Dat leg je vast als afwezigheid. <strong>Afwezigheid gaat altijd vóór</strong> op je beschikbaarheid.
                @if ($absenceUrl)
                    <a href="{{ $absenceUrl }}" class="font-medium underline hover:text-amber-700">{{ $self ? 'Verlof aanvragen ›' : 'Afwezigheid registreren ›' }}</a>
                @endif
            </p>
        </div>
    </div>

    {{-- Proef "nieuwe invoer": één rooster met twee standen. Werkt op dezelfde gegevens als de gewone invoer. --}}
    <div class="space-y-3" x-show="newMode" x-cloak>
        <p class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
            <strong>Nieuwe invoer (proef).</strong> Sleep in het rooster om aan te geven wanneer {{ $self ? 'je' : 'de medewerker' }} wel of niet kan. Op een telefoon: tik op een vakje, of houd even vast en sleep.
            Opslaan werkt hetzelfde als altijd, en terugschakelen kan op elk moment zonder iets kwijt te raken.
            @if ($absenceUrl)
                Vakantie, ziekte of een afspraak leg je vast als afwezigheid: <a href="{{ $absenceUrl }}" class="font-medium underline">{{ $self ? 'verlof aanvragen ›' : 'afwezigheid registreren ›' }}</a>
            @endif
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-gray-600">Ik pas aan:</span>
            <button type="button" class="btn-sm rounded-md border px-2 py-1 font-medium" @click="scope = 'p'"
                    :class="scope === 'p' ? 'border-teal-600 bg-teal-600 text-white' : 'border-gray-300 bg-white text-gray-700'" :aria-pressed="scope === 'p'">↻ Elke week</button>
            <button type="button" class="btn-sm rounded-md border px-2 py-1 font-medium" @click="scope = 'w'"
                    :class="scope === 'w' ? 'border-sky-600 bg-sky-600 text-white' : 'border-gray-300 bg-white text-gray-700'" :aria-pressed="scope === 'w'">📅 Alleen deze week</button>
            <span class="inline-flex items-center gap-1" x-show="scope === 'w'">
                <button type="button" class="btn-secondary btn-sm" @click="shiftWeek(-1)" aria-label="Vorige week">‹</button>
                <button type="button" class="btn-secondary btn-sm" @click="thisWeek()">Deze week</button>
                <button type="button" class="btn-secondary btn-sm" @click="shiftWeek(1)" aria-label="Volgende week">›</button>
                <span class="ms-1 text-sm font-medium text-gray-700" x-text="weekLabel()"></span>
            </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-gray-600">Kwast:</span>
            <button type="button" class="btn-sm rounded-md border px-2 py-1 font-medium" @click="brush = 'available'"
                    :class="brush === 'available' ? 'border-teal-600 bg-teal-400 text-teal-950' : 'border-gray-300 bg-white text-gray-700'" :aria-pressed="brush === 'available'">Kan wel</button>
            <button type="button" class="btn-sm rounded-md border px-2 py-1 font-medium" @click="brush = 'unavailable'"
                    :class="brush === 'unavailable' ? 'avail-blocked border-red-500 text-red-900' : 'border-gray-300 bg-white text-gray-700'" :aria-pressed="brush === 'unavailable'">Kan niet</button>
            <button type="button" class="btn-sm rounded-md border px-2 py-1 font-medium" @click="brush = 'erase'"
                    :class="brush === 'erase' ? 'border-gray-600 bg-gray-200 text-gray-900' : 'border-gray-300 bg-white text-gray-700'" :aria-pressed="brush === 'erase'">Wissen</button>
            <span class="rounded px-2 py-0.5 text-sm font-semibold" :class="belowContract() ? 'bg-amber-100 text-amber-900' : 'text-gray-800'">
                <span x-text="gridTotalText()"></span><span x-show="belowContract()"> · onder contract</span>
            </span>
        </div>

        <div class="select-none text-xs" data-avgrid style="-webkit-touch-callout: none"
             @pointerdown="gridDown($event)" @pointermove.window="gridMove($event)" @pointerup.window="endPaint()" @pointercancel.window="endPaint()"
             @touchstart="touchStart($event)" @touchmove="touchMove($event)" @touchend="touchEnd()" @touchcancel="touchEnd(true)">
            <div class="flex">
                <div class="w-10 shrink-0"></div>
                <template x-for="(d, di) in days()" :key="di">
                    <button type="button" class="mx-px mb-0.5 min-w-0 flex-1 truncate rounded py-0.5 text-center font-semibold"
                            :disabled="scope === 'p' || isPast(d)" @click="toggleDayOff(d)"
                            :title="scope === 'w' && !isPast(d) ? (dayRule(d) ? 'Tik: weer volgens het patroon' : 'Tik: hele dag niet') : null"
                            :class="scope === 'w' && dayRule(d) ? 'bg-sky-100 text-sky-900 outline-dashed outline-1 outline-sky-500' : (scope === 'w' && !isPast(d) ? 'text-gray-700 hover:bg-gray-100' : 'text-gray-500')"
                            x-text="gridDayLabel(d, di)"></button>
                </template>
            </div>
            <template x-for="(row, r) in grid()" :key="r">
                <div class="flex" x-show="r >= firstRow()">
                    <div class="w-10 shrink-0 pe-1 text-right text-[10px] leading-none text-gray-400" x-text="rowLabel(r)"></div>
                    <template x-for="(c, d) in row" :key="d">
                        <div class="mx-px h-3.5 flex-1 cursor-crosshair" :data-r="r" :data-d="d"
                             :class="[cellClass(c, d), r % 2 === 0 ? 'mt-px rounded-t-sm' : 'rounded-b-sm']" :title="c && c.n ? c.n : null"></div>
                    </template>
                </div>
            </template>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
            <span x-show="scope === 'p'">Wat je hier sleept geldt elke week. De regels hieronder volgen mee.</span>
            <span x-show="scope === 'w'">Wat je hier sleept geldt alleen op die datum. Tik op een dag voor “hele dag niet”; nog een tik zet de dag terug naar het patroon. Gestippeld = wijkt af.</span>
            <button type="button" class="underline hover:text-teal-700" x-show="firstRow() > 0 || showNight" @click="showNight = !showNight" x-text="showNight ? 'Nacht verbergen' : 'Toon 00:00–06:00'"></button>
        </div>

        {{-- Het weekpatroon als regels: hetzelfde patroon als het rooster in de stand "Elke week". --}}
        <div class="space-y-2">
            <h3 class="text-sm font-semibold text-gray-800">↻ Weekpatroon als regels</h3>
            <p x-show="!patternRules().length" class="text-sm text-gray-500">Nog geen weekpatroon. Sleep in het rooster in de stand “Elke week”, of voeg een regel toe.</p>
            <template x-for="rule in patternRules()" :key="rule.key">
                <div class="flex flex-wrap items-center gap-2 rounded-md border-l-4 px-2 py-1.5"
                     :class="rule.type === 'unavailable' ? 'border-red-400 bg-red-50/60' : 'border-teal-400 bg-teal-50/40'">
                    <span class="w-16 text-sm font-semibold" :class="rule.type === 'unavailable' ? 'text-red-700' : 'text-teal-700'" x-text="rule.type === 'unavailable' ? 'Kan niet' : 'Kan wel'"></span>
                    <span class="inline-flex gap-0.5">
                        @foreach ($days as $num => $day)
                            <button type="button" class="w-8 rounded border py-0.5 text-xs font-semibold" @click="toggleRuleDay(rule, {{ $num }})"
                                    :aria-pressed="rule.days.includes({{ $num }})" title="{{ ucfirst($day) }}"
                                    :class="rule.days.includes({{ $num }}) ? (rule.type === 'unavailable' ? 'border-red-400 bg-red-100 text-red-900' : 'border-teal-500 bg-teal-100 text-teal-900') : 'border-gray-300 bg-white text-gray-400'">{{ mb_substr($day, 0, 2) }}</button>
                        @endforeach
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <select aria-label="Van" class="rounded-md border-gray-300 py-1 text-sm shadow-sm" @change="setRuleTimes(rule, Number($event.target.value), rule.e)">
                            <template x-for="o in timeOptions().slice(0, -1)" :key="o.value"><option :value="o.value" :selected="o.value === rule.s" x-text="o.label"></option></template>
                        </select>
                        <span class="text-sm text-gray-500">–</span>
                        <select aria-label="Tot" class="rounded-md border-gray-300 py-1 text-sm shadow-sm" @change="setRuleTimes(rule, rule.s, Number($event.target.value))">
                            <template x-for="o in timeOptions().slice(1)" :key="o.value"><option :value="o.value" :selected="o.value === rule.e" x-text="o.label"></option></template>
                        </select>
                    </span>
                    <input type="text" :value="rule.note" @change="setRuleNote(rule, $event.target.value)" maxlength="100" aria-label="Omschrijving"
                           :placeholder="rule.type === 'unavailable' ? 'Reden, bijv. school' : 'Opmerking'"
                           class="min-w-0 flex-1 basis-32 rounded-md border-gray-300 py-1 text-sm shadow-sm">
                    <button type="button" @click="removeRule(rule)" class="rounded p-1 text-gray-400 hover:bg-white hover:text-red-600" title="Verwijderen" aria-label="Verwijderen">&times;</button>
                </div>
            </template>
            <div class="flex flex-wrap gap-2 pt-1">
                <button type="button" class="btn-secondary btn-sm" @click="addRule('available')">+ Kan wel</button>
                <button type="button" class="btn-secondary btn-sm text-red-700" @click="addRule('unavailable')">+ Kan niet</button>
                <button type="button" class="btn-secondary btn-sm" @click="addComplex()">+ Regel met herhaling of periode</button>
            </div>
        </div>

        {{-- Afwijkende dagen in het kort; aanpassen doe je in het rooster. --}}
        <div class="space-y-1">
            <h3 class="text-sm font-semibold text-gray-800">📅 Afwijkende dagen</h3>
            <p x-show="!editableDays().length" class="text-sm text-gray-500">Geen afwijkende dagen. Kies “Alleen deze week” en sleep in een dag, of tik op de dag.</p>
            <template x-for="r in editableDays()" :key="'n' + r.date">
                <div class="flex flex-wrap items-center gap-2 rounded-md border border-sky-200 bg-sky-50/50 px-2 py-1 text-sm">
                    <button type="button" class="font-medium text-sky-900 underline" @click="goToDay(r.date)" x-text="dayTitle(r)"></button>
                    <span class="min-w-0 flex-1 text-gray-700" x-text="daySummary(r)"></span>
                    <button type="button" class="text-xs text-gray-500 underline hover:text-red-600" @click="resetDay(r.date)">Terug naar weekpatroon</button>
                </div>
            </template>
        </div>
    </div>

    {{-- Weekoverzicht: per dag een tijdbalk --}}
    <div class="space-y-2" x-show="!newMode">
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
                <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded-sm border border-dashed border-sky-600 bg-white"></span>alleen deze dag</span>
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
                    <div class="ms-1 w-24 shrink-0"></div>
                </div>
                <template x-for="d in days()" :key="d.toISOString()">
                    <div class="flex items-center border-t border-gray-100 py-0.5">
                        <div class="w-24 shrink-0 pe-2 font-medium" :class="isToday(d) ? 'text-teal-700' : 'text-gray-700'">
                            <span x-text="dayLabel(d)"></span>
                            <span x-show="dayRule(d)" x-cloak class="block whitespace-nowrap text-[10px] font-normal text-sky-700">📅 deze dag anders</span>
                        </div>
                        <div class="relative h-7 flex-1 overflow-hidden rounded"
                             :class="[{ 'bg-gray-100': restOn(d) === 'unavailable', 'bg-teal-50': restOn(d) === 'available', 'bg-gray-50': restOn(d) === 'none' }, dayRule(d) ? 'outline-dashed outline-1 outline-sky-500' : '']">
                            <template x-for="t in hourTicks()" :key="'g' + t.label">
                                <span class="absolute inset-y-0 w-px bg-white/80" :style="`left:${t.left}%`"></span>
                            </template>
                            <template x-for="b in blocks(d)" :key="b.index">
                                <button type="button" class="absolute inset-y-0.5 overflow-hidden whitespace-nowrap rounded px-1 text-left leading-6"
                                        :class="b.type === 'unavailable' ? 'avail-blocked z-10 text-red-900 ring-1 ring-red-400' : 'bg-teal-300 text-teal-950 ring-1 ring-teal-500'"
                                        :style="b.style" :title="b.title + ' – klik om te wijzigen'" x-text="b.label" @click="b.specific ? focusDay(dayRule(d).date) : focus(b.index)"></button>
                            </template>
                            <template x-for="(a, ai) in absencesOn(d)" :key="'a' + ai">
                                <div class="avail-absent absolute inset-y-0 z-20 overflow-hidden whitespace-nowrap px-1 font-medium leading-7 text-gray-800 ring-1 ring-gray-400"
                                     :style="a.style" :title="a.label + ' – gaat vóór het weekpatroon'" x-text="a.label"></div>
                            </template>
                        </div>
                        <div class="ms-1 flex w-24 shrink-0 justify-end gap-0.5">
                            <template x-if="!isPast(d) && !dayRule(d)">
                                <button type="button" class="rounded px-1.5 text-xs leading-6 text-sky-700 hover:bg-sky-50"
                                        title="Alleen op deze datum andere tijden" @click="editDay(d)">Anders</button>
                            </template>
                            <template x-if="!isPast(d) && dayRule(d)">
                                <button type="button" class="whitespace-nowrap rounded px-1.5 text-xs leading-6 text-gray-500 hover:bg-gray-100"
                                        title="Deze dag weer volgens het weekpatroon" @click="resetDay(dayRule(d).date)">↻ Patroon</button>
                            </template>
                            <button type="button" class="w-7 rounded text-base leading-6 text-gray-400 hover:bg-teal-50 hover:text-teal-700"
                                    title="Tijdvak toevoegen aan het weekpatroon op deze weekdag" aria-label="Tijdvak toevoegen aan het weekpatroon op deze weekdag" @click="add('available', ((d.getDay() + 6) % 7) + 1)">+</button>
                        </div>
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
        <div x-show="weekHasDayRules()" x-cloak class="flex flex-wrap items-center gap-2 rounded-md bg-sky-50 px-3 py-2 text-xs text-sky-900">
            <span>Afwijkende dagen van deze week kopiëren naar</span>
            <label class="sr-only" for="copy-until">Tot en met</label>
            <input id="copy-until" type="date" x-model="copyUntil" class="rounded-md border-gray-300 py-0.5 text-xs shadow-sm" :min="weekStartIso(1)">
            <span x-text="copyUntil ? '(elke week t/m die datum)' : '(leeg = alleen volgende week)'"></span>
            <button type="button" class="btn-secondary btn-sm" @click="copyWeek()">Kopiëren</button>
        </div>
    </div>

    {{-- Tijdvakken: gesorteerd op dag en tijd bij openen en na opslaan; tijdens bewerken blijven regels op hun plek. --}}
    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-gray-800" x-show="!newMode || hasComplex()" x-text="newMode ? 'Regels met herhaling of periode' : '↻ Weekpatroon'">↻ Weekpatroon</h3>
        <p x-show="!patterns.length && !newMode" class="text-sm text-gray-500">Nog geen tijdvakken. Voeg hieronder toe wat elke week terugkomt.</p>
        <template x-for="(p, i) in patterns" :key="i">
            <div class="flex flex-wrap items-center gap-2 rounded-md border-l-4 px-2 py-1.5 transition-shadow" :data-pattern="i" x-show="!newMode || isComplex(p)"
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
                <button type="button" x-show="!p.showPeriod" @click="p.showPeriod = true" class="text-xs text-gray-500 underline hover:text-teal-700">periode</button>
                <span x-show="p.showPeriod" x-cloak class="inline-flex flex-wrap items-center gap-1 text-xs text-gray-600">
                    <span>geldig van</span>
                    <input type="date" :name="`patterns[${i}][valid_from]`" x-model="p.valid_from" aria-label="Geldig van" class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                    <span>t/m</span>
                    <input type="date" :name="`patterns[${i}][valid_until]`" x-model="p.valid_until" :min="p.valid_from" aria-label="Geldig tot en met" class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                    <button type="button" @click="p.valid_from = null; p.valid_until = null; p.showPeriod = false" class="rounded p-1 text-gray-400 hover:text-red-600" title="Periode weghalen" aria-label="Periode weghalen">&times;</button>
                </span>
                <button type="button" @click="remove(i)" class="rounded p-1 text-gray-400 hover:bg-white hover:text-red-600" title="Verwijderen" aria-label="Verwijderen">&times;</button>
            </div>
        </template>
        <div class="flex flex-wrap gap-2 pt-1" x-show="!newMode">
            <button type="button" class="btn-secondary btn-sm" @click="add('available')">+ Kan wel</button>
            <button type="button" class="btn-secondary btn-sm text-red-700" @click="add('unavailable')">+ Kan niet</button>
            <button type="button" class="btn-secondary btn-sm" x-show="patterns.length && !isDaily(patterns[patterns.length - 1])" @click="copyToWeekdays()">
                Laatste regel kopiëren naar ma t/m vr
            </button>
        </div>
        <p class="text-xs text-gray-500" x-show="!newMode || hasComplex()">Herhaling “om de week”, “om de 3 weken” of “om de dag” telt vanaf de gekozen startdatum; blader hierboven door de weken om te zien wanneer het geldt.</p>
    </div>

    {{-- Afwijkende dagen: vervangen die dag het weekpatroon. --}}
    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-gray-800" x-show="!newMode">📅 Afwijkende dagen</h3>
        <p x-show="!newMode && !editableDays().length" class="text-sm text-gray-500">Geen afwijkende dagen. Klik in het weekoverzicht op <strong>Anders</strong> bij een dag.</p>
        <template x-for="(r, j) in editableDays()" :key="r.date">
            <div class="space-y-2 rounded-md border border-sky-200 bg-sky-50/50 px-2 py-2 transition-shadow" :data-day="r.date" x-show="!newMode"
                 :class="highlightDay === r.date ? 'ring-2 ring-sky-500' : ''">
                <input type="hidden" :name="`days[${j}][date]`" :value="r.date">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-gray-800" x-text="dayTitle(r)"></span>
                    <select :name="`days[${j}][mode]`" x-model="r.mode" @change="modeChanged(r)" aria-label="Wat geldt er deze dag?"
                            class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                        @foreach (\App\Models\AvailabilityDay::MODES as $value => $label)
                            @continue($value === 'pattern')
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="text" x-show="r.mode !== 'slots'" :name="`days[${j}][note]`" x-model="r.note" maxlength="100" aria-label="Omschrijving"
                           placeholder="Opmerking, bijv. tentamen" class="min-w-0 flex-1 basis-32 rounded-md border-gray-300 py-1 text-sm shadow-sm">
                    <button type="button" class="ms-auto text-xs text-gray-500 underline hover:text-red-600" @click="resetDay(r.date)">Terug naar weekpatroon</button>
                </div>
                <template x-if="r.mode === 'slots'">
                    <div class="space-y-1">
                        <template x-for="(slot, k) in r.slots" :key="k">
                            <div class="flex flex-wrap items-center gap-2 rounded border-l-4 bg-white px-2 py-1" :class="slot.type === 'unavailable' ? 'border-red-400' : 'border-teal-400'">
                                <select :name="`days[${j}][slots][${k}][type]`" x-model="slot.type" aria-label="Kan wel of kan niet"
                                        class="rounded-md border-gray-300 py-1 text-sm font-medium shadow-sm" :class="slot.type === 'unavailable' ? 'text-red-700' : 'text-teal-700'">
                                    @foreach (\App\Models\AvailabilityPattern::TYPES as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="time" :name="`days[${j}][slots][${k}][start_time]`" x-model="slot.start_time" required aria-label="Van" class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                                <span class="text-sm text-gray-500">–</span>
                                <input type="time" :name="`days[${j}][slots][${k}][end_time]`" x-model="slot.end_time" required aria-label="Tot" class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                                <input type="text" :name="`days[${j}][slots][${k}][note]`" x-model="slot.note" maxlength="100" aria-label="Omschrijving"
                                       :placeholder="slot.type === 'unavailable' ? 'Reden, bijv. school' : 'Opmerking'" class="min-w-0 flex-1 basis-32 rounded-md border-gray-300 py-1 text-sm shadow-sm">
                                <button type="button" @click="r.slots.splice(k, 1)" class="rounded p-1 text-gray-400 hover:text-red-600" title="Verwijderen" aria-label="Verwijderen">&times;</button>
                            </div>
                        </template>
                        <div class="flex flex-wrap gap-2" x-show="r.slots.length < {{ \App\Models\AvailabilityDay::MAX_SLOTS }}">
                            <button type="button" class="btn-secondary btn-sm" @click="addSlot(r, 'available')">+ Kan wel</button>
                            <button type="button" class="btn-secondary btn-sm text-red-700" @click="addSlot(r, 'unavailable')">+ Kan niet</button>
                        </div>
                        <p class="text-xs text-gray-500" x-text="r.slots.some((s) => s.type === 'available') ? 'Buiten de groene tijden: deze dag niet beschikbaar.' : 'Buiten de rode tijden: deze dag beschikbaar.'"></p>
                    </div>
                </template>
            </div>
        </template>
        @php($dayErrors = collect($errors->getMessages())->filter(fn ($m, $k) => str_starts_with($k, 'days'))->flatten()->unique()->values()->all())
        <x-input-error :messages="$dayErrors" />
    </div>
</div>
