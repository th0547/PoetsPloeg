<x-app-layout wide title="Planning">
    <x-slot name="scripts">
        @vite(['resources/js/planning.js'])
    </x-slot>

    <div x-data="planningBoard()" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-md shadow-sm" role="group" aria-label="Weergave">
                    <button type="button" @click="setView('employee')" :class="view === 'employee' ? 'bg-teal-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                            class="rounded-l-md border border-gray-300 px-3 py-1.5 text-sm font-medium">Per medewerker</button>
                    <button type="button" @click="setView('building')" :class="view === 'building' ? 'bg-teal-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                            class="-ml-px rounded-r-md border border-gray-300 px-3 py-1.5 text-sm font-medium">Per pand</button>
                </div>
                <div class="inline-flex rounded-md shadow-sm" role="group" aria-label="Periode">
                    <template x-for="(label, key) in { day: 'Dag', week: 'Week', month: 'Maand' }" :key="key">
                        <button type="button" @click="setRange(key)" x-text="label"
                                :class="[range === key ? 'bg-gray-800 text-white' : 'bg-white text-gray-700 hover:bg-gray-50', key === 'day' ? 'rounded-l-md' : '', key === 'month' ? 'rounded-r-md' : '', key !== 'day' ? '-ml-px' : '']"
                                class="border border-gray-300 px-3 py-1.5 text-sm font-medium"></button>
                    </template>
                </div>
                <div class="inline-flex items-center gap-1">
                    <button type="button" @click="shift(-1)" class="rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm hover:bg-gray-50" aria-label="Vorige">‹</button>
                    <button type="button" @click="goToday()" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Vandaag</button>
                    <button type="button" @click="shift(1)" class="rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm hover:bg-gray-50" aria-label="Volgende">›</button>
                    <input type="date" x-model="date" @change="load()" class="ms-1 rounded-md border-gray-300 py-1 text-sm shadow-sm">
                </div>
                <h1 class="text-lg font-semibold text-gray-800 ms-2" x-text="label"></h1>
                <span x-show="loading" class="text-sm text-gray-500">laden…</span>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600">
                <span class="inline-flex items-center gap-1"><span class="legend legend-ok"></span>ok</span>
                <span class="inline-flex items-center gap-1"><span class="legend legend-info"></span>kent pand niet</span>
                <span class="inline-flex items-center gap-1"><span class="legend legend-warning"></span>waarschuwing</span>
                <span class="inline-flex items-center gap-1"><span class="legend legend-danger"></span>conflict</span>
                <span class="inline-flex items-center gap-1"><span class="legend legend-open"></span>open</span>
                <span class="inline-flex items-center gap-1"><span class="legend legend-absence"></span>afwezig</span>
            </div>
        </div>

        <p class="text-xs text-gray-500" x-show="view === 'employee'">
            Sleep een dienst naar een andere medewerker om te ruilen, of van “Open diensten” naar een medewerker om in te plannen.
            <span x-show="range === 'day'">Horizontaal slepen wijzigt de tijd (per kwartier).</span>
            <span x-show="range !== 'day'">Horizontaal slepen verplaatst de dienst naar een andere dag (zelfde tijden).</span>
            Klik voor details en vervangers.
        </p>
        <p class="text-xs text-gray-500" x-show="view === 'building'">Sleep horizontaal om een dienst te verplaatsen <span x-text="range === 'day' ? 'in tijd' : 'naar een andere dag'"></span>. Klik op een dienst om medewerkers toe te wijzen.</p>

        <div class="rounded-lg bg-white shadow-sm overflow-hidden">
            <div x-ref="timeline" class="poetsploeg-timeline" :class="range === 'day' ? 'mode-time' : 'mode-slot mode-' + range"></div>
        </div>
        <p class="text-xs text-gray-500 lg:hidden">Tip: veeg horizontaal over de planning om door de tijd te scrollen; de namen blijven staan. Knijpen (of Ctrl + scrollen) zoomt in en uit.</p>
    </div>
</x-app-layout>
