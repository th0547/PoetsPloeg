<x-app-layout wide flush title="Planning">
    <x-slot name="scripts">
        @vite(['resources/js/board/index.js'])
    </x-slot>

    <div x-data="planningBoard()" class="board-page">
        <div class="board-toolbar">
            {{-- Periode. Op een telefoon: ‹ titel Vandaag › op één regel. --}}
            <button type="button" class="tb-prev btn-icon border border-gray-300 bg-white no-print" @click="step(-1)" aria-label="Vorige periode" title="Vorige (←)"><x-icon name="chevron-left" /></button>
            <button type="button" class="tb-today btn-secondary !py-1.5 no-print" @click="goToday()" title="Naar vandaag (T)">Vandaag</button>
            <button type="button" class="tb-next btn-icon border border-gray-300 bg-white no-print" @click="step(1)" aria-label="Volgende periode" title="Volgende (→)"><x-icon name="chevron-right" /></button>
            <div class="tb-title relative">
                <button type="button" class="board-title" @click="pickDate()" title="Kies een datum" x-text="label">Planning</button>
                <input type="date" x-ref="picker" x-model="date" @change="load()" class="pointer-events-none absolute bottom-0 left-0 h-0 w-0 opacity-0" tabindex="-1" aria-label="Datum">
            </div>
            <span x-show="loading" x-cloak class="tb-loading text-sm text-gray-400">laden…</span>

            <div class="tb-tools ms-auto flex flex-wrap items-center gap-2">
                <div class="segmented no-print" role="group" aria-label="Periode">
                    <button type="button" @click="setRange('day')" :class="range === 'day' && view !== 'calendar' ? 'is-active' : ''" title="Dag (D)">Dag</button>
                    <button type="button" @click="setRange('week')" :class="range === 'week' && view !== 'calendar' ? 'is-active' : ''" title="Week (W)">Week</button>
                    <button type="button" @click="setRange('month')" :class="range === 'month' && view !== 'calendar' ? 'is-active' : ''" title="Maand (M)">Maand</button>
                </div>
                <div class="segmented no-print" role="group" aria-label="Weergave">
                    <button type="button" @click="setView('employee')" :class="view === 'employee' ? 'is-active' : ''" title="Per medewerker"><x-icon name="users" class="sm:hidden" /><span class="sr-only sm:not-sr-only">Per medewerker</span></button>
                    <button type="button" @click="setView('building')" :class="view === 'building' ? 'is-active' : ''" title="Per pand"><x-icon name="building" class="sm:hidden" /><span class="sr-only sm:not-sr-only">Per pand</span></button>
                    <button type="button" @click="setView('calendar')" :class="view === 'calendar' ? 'is-active' : ''" title="Maandkalender"><x-icon name="calendar" class="sm:hidden" /><span class="sr-only sm:not-sr-only">Kalender</span></button>
                </div>

                <div class="board-search no-print">
                    <x-icon name="search" />
                    <input type="search" x-ref="search" x-model.debounce.120ms="q" :placeholder="view === 'building' ? 'Zoek pand…' : 'Zoek medewerker…'" aria-label="Zoeken in de planning">
                </div>

                {{-- Filters --}}
                <div class="relative no-print" @click.outside="filterOpen = false" @keydown.escape="filterOpen = false">
                    <button type="button" class="btn-secondary !py-1.5" @click="filterOpen = !filterOpen" :aria-expanded="filterOpen">
                        <x-icon name="sliders" />Filters
                        <span class="rounded-full bg-teal-600 px-1.5 text-xs font-semibold text-white" x-show="filterCount() > 0" x-text="filterCount()"></span>
                    </button>
                    <div class="board-pop right-0" x-show="filterOpen" x-cloak x-transition.origin.top.right>
                        <h3>Tonen</h3>
                        <label class="pop-check"><input type="checkbox" class="checkbox" :checked="filters.issues" @change="setFilter('issues', $event.target.checked)"> Alleen aandachtspunten <span class="text-xs text-gray-400">(open of waarschuwing)</span></label>
                        <label class="pop-check"><input type="checkbox" class="checkbox" :checked="filters.withShifts" @change="setFilter('withShifts', $event.target.checked)"> Alleen rijen met diensten</label>
                        <label class="pop-check" x-show="view === 'employee'"><input type="checkbox" class="checkbox" :checked="filters.availability" @change="setFilter('availability', $event.target.checked)"> “Kan niet”-tijden tonen</label>

                        <div x-show="view === 'employee'">
                            <h3>Sorteren</h3>
                            <label class="pop-check"><input type="radio" name="board-sort" class="checkbox !rounded-full" :checked="sort === 'name'" @change="setSort('name')"> Op naam</label>
                            <label class="pop-check"><input type="radio" name="board-sort" class="checkbox !rounded-full" :checked="sort === 'hours'" @change="setSort('hours')"> Meeste uren eerst</label>
                            <label class="pop-check"><input type="radio" name="board-sort" class="checkbox !rounded-full" :checked="sort === 'now'" @change="setSort('now')"> Nu aan het werk eerst</label>
                        </div>

                        <h3>Panden</h3>
                        <template x-for="b in buildingOptions" :key="b.id">
                            <label class="pop-check"><input type="checkbox" class="checkbox" :checked="filters.buildings.includes(b.id)" @change="toggle('buildings', b.id)">
                                <span class="pop-swatch" :style="'background:' + b.color"></span><span class="truncate" x-text="b.name"></span></label>
                        </template>

                        <div x-show="view === 'employee' && skillOptions.length">
                            <h3>Vaardigheden</h3>
                            <template x-for="s in skillOptions" :key="s.id">
                                <label class="pop-check"><input type="checkbox" class="checkbox" :checked="filters.skills.includes(s.id)" @change="toggle('skills', s.id)"> <span x-text="s.name"></span></label>
                            </template>
                        </div>
                        <button type="button" class="link mt-3 text-sm" x-show="filterCount() > 0" @click="clearFilters()">Filters wissen</button>
                    </div>
                </div>

                {{-- Legenda en afdrukken --}}
                <div class="relative no-print" @click.outside="moreOpen = false" @keydown.escape="moreOpen = false">
                    <button type="button" class="btn-icon border border-gray-300 bg-white" @click="moreOpen = !moreOpen" :aria-expanded="moreOpen" aria-label="Legenda en meer"><x-icon name="help" /></button>
                    <div class="board-pop right-0" x-show="moreOpen" x-cloak x-transition.origin.top.right>
                        <h3>Legenda</h3>
                        <p class="legend-row"><span class="legend-box"></span>Dienst: de kleur is die van het pand</p>
                        <p class="legend-row"><span class="legend-box" style="background:#fff7ed;border:1px dashed #fb923c;border-left:3px solid #0d9488"></span>Open plek: nog iemand nodig</p>
                        <p class="legend-row"><span class="legend-box" style="box-shadow:0 0 0 1.5px #f59e0b"></span>Waarschuwing, bijv. boven contracturen</p>
                        <p class="legend-row"><span class="legend-box" style="box-shadow:0 0 0 1.5px #ef4444"></span>Conflict: dubbel ingepland of afwezig</p>
                        <p class="legend-row"><span class="legend-box" style="border:0;background:repeating-linear-gradient(135deg,rgb(148 163 184 / .35) 0 6px,rgb(148 163 184 / .1) 6px 12px)"></span>Afwezig (geel: verlof aangevraagd)</p>
                        <p class="legend-row"><span class="dot dot-working mx-2"></span>Nu bezig</p>
                        <h3>Zo werkt het</h3>
                        <ul class="space-y-1 text-xs text-gray-600">
                            <li>Klik op een dienst voor details en vervangers.</li>
                            <li>Sleep een dienst naar een andere medewerker of dag. Tijdens het slepen zie je wie geschikt is.</li>
                            <li>In de dagweergave sleep je ook de tijd en de randen (per kwartier).</li>
                            <li>Klik op een naam voor iemands dag en week.</li>
                            <li>Toetsen: <span class="kbd">←</span> <span class="kbd">→</span> <span class="kbd">T</span> <span class="kbd">D</span> <span class="kbd">W</span> <span class="kbd">M</span> <span class="kbd">/</span></li>
                        </ul>
                        <button type="button" class="btn-secondary btn-sm mt-3" data-print @click="moreOpen = false"><x-icon name="printer" class="icon-sm" />Afdrukken</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Samenvatting van de periode; de gekleurde chips filteren op aandachtspunten. --}}
        <div class="board-summary" x-show="loaded" x-cloak>
            <span class="sum-chip"><b x-text="summary.shifts"></b> <span x-text="summary.shifts === 1 ? 'dienst' : 'diensten'"></span></span>
            <button type="button" class="sum-chip is-orange" :class="filters.issues ? 'is-on' : ''" x-show="summary.open > 0" @click="setFilter('issues', !filters.issues)" title="Toon alleen aandachtspunten">
                <b x-text="summary.open"></b> open</button>
            <button type="button" class="sum-chip is-red" :class="filters.issues ? 'is-on' : ''" x-show="summary.conflicts > 0" @click="setFilter('issues', !filters.issues)" title="Toon alleen aandachtspunten">
                <b x-text="summary.conflicts"></b> <span x-text="summary.conflicts === 1 ? 'conflict' : 'conflicten'"></span></button>
            <button type="button" class="sum-chip is-amber" :class="filters.issues ? 'is-on' : ''" x-show="summary.warnings > 0" @click="setFilter('issues', !filters.issues)" title="Toon alleen aandachtspunten">
                <b x-text="summary.warnings"></b> <span x-text="summary.warnings === 1 ? 'waarschuwing' : 'waarschuwingen'"></span></button>
            <span class="sum-chip is-ok" x-show="summary.shifts > 0 && !summary.open && !summary.conflicts && !summary.warnings"><x-icon name="check" class="icon-sm" />alles bezet, geen waarschuwingen</span>
            <span class="sum-chip" x-show="summary.absent > 0"><b x-text="summary.absent"></b> afwezig</span>
            <a class="sum-chip is-amber" href="{{ route('absences.index', ['status' => 'pending']) }}" x-show="summary.requests > 0"><b x-text="summary.requests"></b> <span x-text="summary.requests === 1 ? 'verlofverzoek' : 'verlofverzoeken'"></span></a>
            <button type="button" class="link ms-1 text-xs no-print" x-show="filterCount() > 0 || q" @click="clearFilters()">Filters wissen</button>
        </div>

        <div class="board-scroll" x-ref="scroll" :class="loading ? 'is-loading' : ''">
            <div x-ref="grid" class="board-grid"></div>
            <div x-show="!loaded" class="empty">Planning laden…</div>
        </div>
        <div x-ref="hover" class="hover-card" hidden></div>
    </div>
</x-app-layout>
