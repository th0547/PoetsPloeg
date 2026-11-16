{{-- Zoekbalk (Ctrl+K): medewerkers met waar ze nu zijn, panden en acties. --}}
<div x-data="palette()" x-cloak class="no-print" @open-palette.window="open()">
    <div class="palette-backdrop" x-show="visible" x-transition.opacity @mousedown.self="close()" @keydown.escape.stop="close()"
         @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="choose()">
        <div class="palette" role="dialog" aria-label="Zoeken">
            <div class="flex items-center gap-3 px-4">
                <x-icon name="search" class="icon-lg text-gray-400" />
                <input type="text" x-ref="input" x-model.debounce.150ms="query" class="palette-input" placeholder="Zoek een medewerker, pand of actie…"
                       autocomplete="off" spellcheck="false" aria-label="Zoeken">
                <span class="kbd">Esc</span>
            </div>
            <div class="palette-list" x-ref="list">
                <p class="palette-group" x-show="employees.length" x-text="query.trim() ? 'Medewerkers' : 'Vandaag aan het werk'"></p>
                <template x-for="e in employees" :key="'e' + e.id">
                    <button type="button" class="palette-item" :class="indexOf('e' + e.id) === active ? 'is-active' : ''"
                            @click="choose({ type: 'employee', data: e })" @mousemove="active = indexOf('e' + e.id)">
                        <span class="avatar" :style="'--c:' + e.color + ';--ink:' + e.ink" x-text="e.initials"></span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2 text-sm font-medium text-gray-900"><span class="truncate" x-text="e.name"></span><span class="dot shrink-0" :class="'dot-' + e.state"></span></span>
                            <span class="block truncate text-xs text-gray-500"><span x-text="e.label"></span><span x-show="e.today.length > 1 || (e.today.length === 1 && e.state !== 'working' && e.state !== 'later')" x-text="' · ' + todayLine(e)"></span></span>
                        </span>
                        <x-icon name="chevron-right" class="text-gray-300" />
                    </button>
                </template>

                <p class="palette-group" x-show="buildings.length">Panden</p>
                <template x-for="b in buildings" :key="'b' + b.id">
                    <button type="button" class="palette-item" :class="indexOf('b' + b.id) === active ? 'is-active' : ''"
                            @click="choose({ type: 'building', data: b })" @mousemove="active = indexOf('b' + b.id)">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white" :style="'background:' + b.color"><x-icon name="building" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-900" x-text="b.name"></span>
                            <span class="block truncate text-xs text-gray-500" x-text="b.label"></span>
                        </span>
                        <x-icon name="chevron-right" class="text-gray-300" />
                    </button>
                </template>

                <p class="palette-group" x-show="actions().length">Acties</p>
                <template x-for="a in actions()" :key="'a' + a.url">
                    <button type="button" class="palette-item" :class="indexOf('a' + a.url) === active ? 'is-active' : ''"
                            @click="choose({ type: 'action', data: a })" @mousemove="active = indexOf('a' + a.url)">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600">
                            <svg class="icon" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use :href="'#i-' + a.icon"/></svg>
                        </span>
                        <span class="flex-1 text-sm text-gray-800" x-text="a.label"></span>
                    </button>
                </template>

                <p class="px-3 py-6 text-center text-sm text-gray-500" x-show="!loading && !items().length">Niets gevonden voor “<span x-text="query"></span>”.</p>
                <p class="px-3 py-4 text-center text-sm text-gray-500" x-show="!query.trim() && !employees.length && !loading">Er werkt vandaag niemand. Typ een naam om iemand op te zoeken.</p>
            </div>
            <div class="flex items-center gap-4 border-t border-gray-100 px-4 py-2 text-xs text-gray-500">
                <span><span class="kbd">↑</span> <span class="kbd">↓</span> kiezen</span>
                <span><span class="kbd">Enter</span> openen</span>
            </div>
        </div>
    </div>
</div>
