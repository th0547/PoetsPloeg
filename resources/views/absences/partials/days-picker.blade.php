{{-- Dagen kiezen in een kalender, met per dag begin- en eindtijd. Binnen een form met x-data="absenceForm(...)". --}}
@php($short = config('poetsploeg.weekdays_short'))
{{-- Kalender: dagen aanklikken --}}
<div>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <span class="label">Dagen</span>
            <p class="text-xs text-gray-500">Klik op één of meer dagen. Sleep over de dagen of gebruik Shift+klik voor een aaneengesloten periode.</p>
        </div>
        <div class="inline-flex items-center gap-1">
            <button type="button" class="btn-secondary btn-sm" @click="moveMonth(-1)" aria-label="Vorige maand">‹</button>
            <button type="button" class="btn-secondary btn-sm" @click="thisMonth()">Vandaag</button>
            <button type="button" class="btn-secondary btn-sm" @click="moveMonth(1)" aria-label="Volgende maand">›</button>
        </div>
    </div>
    <div class="mt-2 grid select-none gap-6 sm:grid-cols-2">
        <template x-for="(m, mi) in months()" :key="m.key">
            <div :class="mi === 1 ? 'hidden sm:block' : ''">
                <p class="mb-1 text-center text-sm font-semibold capitalize text-gray-800" x-text="m.label"></p>
                <div class="grid grid-cols-7 gap-1 text-center text-xs">
                    @foreach ($short as $label)
                        <div class="py-1 font-medium uppercase text-gray-400">{{ $label }}</div>
                    @endforeach
                    <template x-for="(cell, ci) in m.cells" :key="m.key + ci">
                        <div>
                            <template x-if="cell">
                                <button type="button" class="h-9 w-full rounded-md text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                        :class="dayClass(cell)" :aria-pressed="isSelected(cell.iso)" :aria-label="dayLabel(cell.iso)"
                                        @pointerdown="dayDown(cell, $event)" @pointerenter="dayEnter(cell)" @click="dayClick(cell, $event)"
                                        x-text="cell.day"></button>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>
</div>

{{-- Gekozen dagen: per dag een regel met begin- en eindtijd --}}
<div class="rounded-md border border-gray-200">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b bg-gray-50 px-3 py-2">
        <span class="text-sm font-medium text-gray-800" x-text="days.length === 1 ? '1 dag gekozen' : days.length + ' dagen gekozen'">0 dagen gekozen</span>
        <span class="flex flex-wrap gap-3">
            <button type="button" class="text-xs text-teal-700 hover:underline" x-show="days.length > 1 && timesDiffer()" @click="copyTimes()">Tijden van de eerste dag voor alle dagen</button>
            <button type="button" class="text-xs text-gray-600 hover:underline" x-show="days.length" @click="clear()">Alles wissen</button>
        </span>
    </div>
    <p class="px-3 py-4 text-sm text-gray-500" x-show="!days.length">Nog geen dagen gekozen. Klik hierboven in de kalender.</p>
    <ul class="divide-y">
        <template x-for="(day, i) in days" :key="day.date">
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                <input type="hidden" :name="`days[${i}][date]`" :value="day.date">
                <span class="w-40 text-sm font-medium text-gray-800" x-text="dayLabel(day.date)"></span>
                <span class="inline-flex items-center gap-1">
                    <input type="time" :name="`days[${i}][start_time]`" x-model="day.start_time" required aria-label="Begintijd"
                           class="rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                    <span class="text-sm text-gray-500">–</span>
                    <input type="time" :name="`days[${i}][end_time]`" x-model="day.end_time" required aria-label="Eindtijd"
                           class="rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                           :class="invalid(day) ? 'border-red-400 text-red-700' : ''">
                </span>
                <span class="chip bg-teal-50 text-teal-800" x-show="isWholeDay(day)">hele dag</span>
                <button type="button" class="text-xs text-teal-700 hover:underline" x-show="!isWholeDay(day)" @click="wholeDay(day)">hele dag</button>
                <span class="text-xs text-red-600" x-show="invalid(day)">Eindtijd moet na begintijd liggen</span>
                <button type="button" class="ms-auto rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-600" @click="remove(day.date)" title="Dag verwijderen" aria-label="Dag verwijderen">&times;</button>
            </li>
        </template>
    </ul>
    <div class="border-t bg-gray-50 px-3 py-2 text-xs text-gray-600" x-show="periods().length > 0" x-cloak>
        <span x-text="periods().length === 1 ? 'Wordt vastgelegd als één periode:' : 'Wordt vastgelegd als ' + periods().length + ' losse periodes:'"></span>
        <template x-for="p in periods()" :key="p.from">
            <span class="chip ms-1 mt-1 bg-white ring-1 ring-gray-200" x-text="periodLabel(p)"></span>
        </template>
    </div>
</div>
