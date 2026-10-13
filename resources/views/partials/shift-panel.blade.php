{{-- Zijpaneel met details van een dienst; te openen via window.dispatchEvent(new CustomEvent('open-shift', {detail: {id}})) --}}
<div x-data="shiftPanel()" x-cloak @open-shift.window="open($event.detail.id, $event.detail.candidates, $event.detail.replace)" @keydown.escape.window="close()">
    <div x-show="visible" x-transition.opacity class="fixed inset-0 z-40 bg-gray-900/30" @click="close()"></div>
    <aside x-show="visible" x-transition:enter="transition transform duration-200" x-transition:enter-start="translate-x-full"
           x-transition:leave="transition transform duration-150" x-transition:leave-end="translate-x-full"
           class="fixed inset-y-0 right-0 z-50 w-full max-w-lg bg-white shadow-xl flex flex-col" role="dialog" aria-label="Dienst">
        <div class="flex items-start justify-between gap-4 border-b px-5 py-4" :style="shift ? 'border-top: 4px solid ' + shift.building.color : ''">
            <div x-show="shift">
                <a :href="shift?.building.url" class="text-xs font-semibold uppercase tracking-wide text-gray-500 hover:text-teal-700" x-text="shift?.building.name"></a>
                <h2 class="text-lg font-semibold text-gray-900" x-text="shift?.title"></h2>
                <p class="text-sm text-gray-600">
                    <span x-text="shift?.date_label"></span>,
                    <span x-text="shift?.start_time"></span>–<span x-text="shift?.end_time"></span>
                    (<span x-text="shift?.duration"></span> u)
                </p>
            </div>
            <div x-show="loading && !shift" class="text-sm text-gray-500">Laden…</div>
            <button type="button" class="text-2xl leading-none text-gray-400 hover:text-gray-700" @click="close()" aria-label="Sluiten">&times;</button>
        </div>

        <div class="flex-1 overflow-y-auto px-5 py-4 space-y-6" x-show="shift">
            <template x-if="notice">
                <div class="rounded-md bg-teal-50 border border-teal-200 px-3 py-2 text-sm text-teal-800" x-text="notice"></div>
            </template>
            <template x-if="replacing()">
                <div class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-900">
                    Vervanger zoeken voor <strong x-text="replacing().name"></strong> (verlofverzoek). De gekozen medewerker neemt de plek direct over.
                </div>
            </template>
            <template x-if="error">
                <div class="rounded-md bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-800" x-text="error"></div>
            </template>

            {{-- Bezetting --}}
            <section>
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">
                        Bezetting <span class="font-normal text-gray-500" x-text="'(' + (shift?.assignments.length ?? 0) + '/' + (shift?.required_staff ?? 0) + ')'"></span>
                    </h3>
                    <span x-show="shift?.open_slots > 0" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800" x-text="shift?.open_slots + ' open'"></span>
                </div>
                <ul class="mt-2 divide-y rounded-md border">
                    <template x-for="a in shift?.assignments ?? []" :key="a.employee_id">
                        <li class="px-3 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="h-3 w-3 rounded-full shrink-0" :style="'background:' + a.color"></span>
                                    <span class="font-medium truncate" x-text="a.name"></span>
                                    <span class="text-xs text-gray-500" x-text="a.source"></span>
                                </div>
                                <div class="flex items-center gap-3 shrink-0">
                                    <a x-show="a.phone" :href="'tel:' + a.phone" class="text-xs text-teal-700 hover:underline" x-text="a.phone"></a>
                                    <button type="button" class="text-xs text-red-600 hover:underline" @click="unassign(a)">Uitval / verwijderen</button>
                                </div>
                            </div>
                            <template x-for="w in a.warnings" :key="w.type">
                                <p class="mt-1 text-xs" :class="levelClass(w.level)" x-text="'⚠ ' + w.message"></p>
                            </template>
                        </li>
                    </template>
                    <li x-show="(shift?.assignments.length ?? 0) === 0" class="px-3 py-2 text-sm text-gray-500">Nog niemand ingepland.</li>
                </ul>
                <button type="button" class="mt-3 inline-flex items-center rounded-md bg-teal-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-700"
                        @click="loadCandidates()">Zoek vervanger / medewerker</button>
            </section>

            {{-- Vervangers --}}
            <section x-show="candidates !== null">
                <h3 class="text-sm font-semibold text-gray-900">Geschikte medewerkers</h3>
                <p class="text-xs text-gray-500">Gerangschikt op beschikbaarheid, vaardigheden, pandkennis en uren.</p>
                <div x-show="candidatesLoading" class="mt-2 text-sm text-gray-500">Zoeken…</div>
                <ul class="mt-2 space-y-2">
                    <template x-for="(c, i) in candidates?.candidates ?? []" :key="c.id">
                        <li class="rounded-md border px-3 py-2" :class="i === 0 ? 'border-teal-300 bg-teal-50/50' : ''">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full" :style="'background:' + c.color"></span>
                                    <span class="font-medium" x-text="c.name"></span>
                                    <a x-show="c.phone" :href="'tel:' + c.phone" class="text-xs text-teal-700 hover:underline" x-text="c.phone"></a>
                                </div>
                                <button type="button" class="rounded-md border border-teal-600 px-2 py-1 text-xs font-medium text-teal-700 hover:bg-teal-600 hover:text-white"
                                        :disabled="shift?.open_slots === 0 && !replacing()" :class="shift?.open_slots === 0 && !replacing() ? 'opacity-40 cursor-not-allowed' : ''"
                                        @click="assign(c.id, 'replacement')" x-text="replacing() ? 'Vervangt ' + replacing().name.split(' ')[0] : 'Inplannen'">Inplannen</button>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                                <template x-for="r in c.reasons">
                                    <span :class="r.ok === true ? 'text-teal-700' : (r.ok === false ? 'text-red-600' : 'text-gray-500')"
                                          x-text="(r.ok === true ? '✓ ' : (r.ok === false ? '✗ ' : '• ')) + r.text"></span>
                                </template>
                            </div>
                        </li>
                    </template>
                </ul>
                <p x-show="candidates && candidates.candidates.length === 0 && !candidatesLoading" class="mt-2 text-sm text-gray-500">Geen beschikbare medewerkers gevonden.</p>
                <div x-show="candidates?.excluded.length" class="mt-3">
                    <button type="button" class="text-xs text-gray-600 hover:underline" @click="showExcluded = !showExcluded"
                            x-text="(showExcluded ? 'Verberg' : 'Toon') + ' niet beschikbaar (' + (candidates?.excluded.length ?? 0) + ')'"></button>
                    <ul x-show="showExcluded" class="mt-2 space-y-1">
                        <template x-for="c in candidates?.excluded ?? []" :key="c.id">
                            <li class="text-xs text-gray-500">
                                <span class="font-medium text-gray-700" x-text="c.name"></span>:
                                <span x-text="c.warnings.filter(w => w.level === 'danger').map(w => w.message).join('; ')"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </section>

            {{-- Pandinfo --}}
            <section class="rounded-md bg-gray-50 px-3 py-3 text-sm space-y-1">
                <h3 class="text-sm font-semibold text-gray-900">Pand</h3>
                <p x-show="shift?.building.address" x-text="shift?.building.address"></p>
                <p x-show="shift?.building.contact"><span class="text-gray-500">Contact:</span> <span x-text="shift?.building.contact"></span></p>
                <p x-show="shift?.building.access" class="whitespace-pre-line"><span class="text-gray-500">Toegang:</span> <span x-text="shift?.building.access"></span></p>
                <p x-show="shift?.template" class="text-gray-600">
                    Vaste taak: <span x-text="shift?.template?.recurrence"></span>
                    · <a :href="shift?.template?.url" class="text-teal-700 hover:underline">sjabloon bewerken</a>
                </p>
                <p x-show="shift?.is_modified && shift?.template" class="text-xs text-amber-700">Deze dienst is handmatig aangepast en wordt niet meer automatisch bijgewerkt vanuit het sjabloon.</p>
            </section>

            {{-- Bewerken --}}
            <section>
                <button type="button" class="text-sm font-semibold text-gray-900 hover:text-teal-700" @click="editing = !editing">
                    <span x-text="editing ? '▾' : '▸'"></span> Dienst wijzigen
                </button>
                <form x-show="editing" class="mt-3 space-y-3" @submit.prevent="save()">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Omschrijving</label>
                        <input type="text" x-model="form.title" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500" required>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-3 sm:col-span-1">
                            <label class="block text-xs font-medium text-gray-700">Datum</label>
                            <input type="date" x-model="form.date" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm" required>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Van</label>
                            <input type="time" x-model="form.start_time" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm" required>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">Tot</label>
                            <input type="time" x-model="form.end_time" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm" required>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Aantal personen</label>
                        <input type="number" min="1" max="50" x-model.number="form.required_staff" class="mt-1 w-24 rounded-md border-gray-300 text-sm shadow-sm" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Vereiste vaardigheden</label>
                        <div class="mt-1 flex flex-wrap gap-2">
                            <template x-for="s in shift?.all_skills ?? []" :key="s.id">
                                <label class="inline-flex items-center gap-1 rounded border px-2 py-1 text-xs">
                                    <input type="checkbox" :value="s.id" x-model.number="form.skills" class="rounded border-gray-300 text-teal-600">
                                    <span x-text="s.name"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Notities</label>
                        <textarea x-model="form.notes" rows="3" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                    </div>
                    <div class="flex items-center justify-between">
                        <button type="submit" class="rounded-md bg-teal-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-teal-700">Opslaan</button>
                        <button type="button" class="text-sm text-red-600 hover:underline" @click="cancelShift()">Dienst annuleren</button>
                    </div>
                </form>
                <p x-show="!editing && shift?.notes" class="mt-2 whitespace-pre-line text-sm text-gray-700" x-text="shift?.notes"></p>
            </section>
        </div>
    </aside>
</div>
