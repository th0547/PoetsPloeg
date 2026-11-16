{{-- Zijpaneel met details van een dienst; te openen via window.dispatchEvent(new CustomEvent('open-shift', {detail: {id}})) --}}
<div x-data="shiftPanel()" x-cloak class="no-print"
     @open-shift.window="open($event.detail.id, $event.detail.candidates, $event.detail.replace)"
     @close-shift-panel.window="close()" @keydown.escape.window="close()">
    <div x-show="visible" x-transition.opacity class="drawer-backdrop" @click="close()"></div>
    <aside x-show="visible" x-transition:enter="transition transform duration-200" x-transition:enter-start="translate-x-full"
           x-transition:leave="transition transform duration-150" x-transition:leave-end="translate-x-full"
           class="drawer" role="dialog" aria-label="Dienst">
        <div class="drawer-head" :style="shift ? 'border-top: 4px solid ' + shift.building.color : ''">
            <div x-show="shift" class="min-w-0">
                <a :href="shift?.building.url" class="text-xs font-semibold uppercase tracking-wide text-gray-500 hover:text-teal-700" x-text="shift?.building.name"></a>
                <h2 class="truncate text-lg font-semibold text-gray-900" x-text="shift?.title"></h2>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600">
                    <span class="inline-flex items-center gap-1"><x-icon name="calendar" class="text-gray-400" /><span x-text="shift?.date_label"></span></span>
                    <span class="inline-flex items-center gap-1 font-medium text-gray-800"><x-icon name="clock" class="text-gray-400" /><span x-text="shift?.start_time + '–' + shift?.end_time"></span></span>
                    <span class="text-gray-500" x-text="'(' + shift?.duration + ' u)'"></span>
                </p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <span class="badge badge-green" x-show="shift?.state === 'now'"><span class="dot dot-working"></span>Nu bezig</span>
                    <span class="badge badge-gray" x-show="shift?.state === 'done'">Afgelopen</span>
                    <span class="badge" :class="shift?.open_slots > 0 ? 'badge-orange' : 'badge-gray'"
                          x-text="shift?.open_slots > 0 ? shift.open_slots + ' van ' + shift.required_staff + ' open' : (shift?.assignments.length ?? 0) + '/' + (shift?.required_staff ?? 0) + ' bezet'"></span>
                    <span class="badge badge-sky" x-show="shift?.extra_staff > 0" title="Meer mensen ingepland dan de bezetting vraagt" x-text="'+' + shift?.extra_staff + ' extra'"></span>
                    <template x-for="s in shift?.skills ?? []" :key="s.id"><span class="badge badge-gray" x-text="s.name"></span></template>
                </div>
            </div>
            <div x-show="loading && !shift" class="text-sm text-gray-500">Laden…</div>
            <button type="button" class="btn-icon -me-2 shrink-0" @click="close()" aria-label="Sluiten"><x-icon name="x" class="icon-lg" /></button>
        </div>

        <div class="drawer-body" x-show="shift">
            <template x-if="notice">
                <div class="notice notice-success"><x-icon name="check-circle" class="mt-0.5 text-teal-600" /><span x-text="notice"></span></div>
            </template>
            <template x-if="error">
                <div class="notice notice-danger"><x-icon name="alert-circle" class="mt-0.5 text-red-600" /><span x-text="error"></span></div>
            </template>

            {{-- Bezetting --}}
            <section>
                <h3 class="drawer-section-title">Bezetting</h3>
                <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                    <template x-for="a in shift?.assignments ?? []" :key="a.employee_id">
                        <li class="px-3 py-2.5" :class="replace === a.employee_id ? 'bg-amber-50' : ''">
                            <div class="flex items-center gap-2.5">
                                <span class="avatar" :style="'--c:' + a.color + ';--ink:' + a.ink" x-text="a.initials"></span>
                                <div class="min-w-0 flex-1">
                                    <button type="button" class="block truncate text-left text-sm font-medium text-gray-900 hover:text-teal-700" :data-open-employee="a.employee_id" x-text="a.name"></button>
                                    <p class="flex flex-wrap items-center gap-x-2 text-xs text-gray-500">
                                        <span x-text="a.source"></span>
                                        <a x-show="a.phone" :href="'tel:' + a.phone" class="inline-flex items-center gap-1 text-teal-700 hover:underline"><x-icon name="phone" class="icon-sm" /><span x-text="a.phone"></span></a>
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <button type="button" class="btn-secondary btn-sm" @click="startReplace(a)" x-show="replace !== a.employee_id"><x-icon name="swap" class="icon-sm" />Vervang</button>
                                    <button type="button" class="btn-ghost btn-sm" @click="cancelReplace()" x-show="replace === a.employee_id">Annuleer</button>
                                    <button type="button" class="btn-icon text-gray-400 hover:!bg-red-50 hover:!text-red-600" @click="unassign(a)" title="Haal van dienst (plek komt open)" aria-label="Haal van dienst"><x-icon name="trash" /></button>
                                </div>
                            </div>
                            <template x-for="w in a.warnings" :key="w.type + w.message">
                                <p class="mt-1 flex items-start gap-1.5 ps-[2.6rem] text-xs" :class="levelClass(w.level)"><x-icon name="alert" class="icon-sm mt-0.5" /><span x-text="w.message"></span></p>
                            </template>
                        </li>
                    </template>
                    <template x-for="n in (shift?.open_slots ?? 0)" :key="'open' + n">
                        <li class="flex items-center gap-2.5 px-3 py-2.5">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-orange-300 text-orange-500"><x-icon name="plus" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-orange-700">Open plek</p>
                                <template x-if="shift?.fixed_missing?.[n - 1]">
                                    <p class="text-xs text-gray-500">Vaste kracht <button type="button" class="font-medium text-gray-700 hover:text-teal-700" :data-open-employee="shift.fixed_missing[n - 1].employee_id" x-text="shift.fixed_missing[n - 1].name"></button>: <span x-text="shift.fixed_missing[n - 1].reason.charAt(0).toLowerCase() + shift.fixed_missing[n - 1].reason.slice(1)"></span></p>
                                </template>
                            </div>
                        </li>
                    </template>
                    <li x-show="(shift?.assignments.length ?? 0) === 0 && (shift?.open_slots ?? 0) === 0" class="px-3 py-2.5 text-sm text-gray-500">Nog niemand ingepland.</li>
                </ul>
                <button type="button" class="btn-secondary btn-sm mt-2" x-show="candidates === null" @click="loadCandidates()">
                    <x-icon name="users" class="icon-sm" /><span x-text="isFull() ? 'Iemand extra inplannen' : 'Zoek medewerker'"></span>
                </button>
            </section>

            {{-- Kandidaten --}}
            <section x-show="candidates !== null" x-ref="candidates">
                <h3 class="drawer-section-title"><span x-text="candidatesTitle()"></span><span x-show="candidatesLoading" class="font-normal normal-case text-gray-400">zoeken…</span></h3>
                <p x-show="replacing()" class="notice notice-warning mb-2 !py-2 text-xs">De gekozen medewerker neemt de plek van <strong class="mx-1" x-text="replacing()?.name"></strong> direct over.</p>
                <p x-show="isFull()" class="notice notice-info mb-2 !py-2 text-xs">Deze dienst is volledig bezet. Je kunt toch iemand extra inplannen, bijvoorbeeld als extra hulp of om mee te kijken.</p>
                <div class="relative" x-show="(candidates?.candidates.length ?? 0) > 5">
                    <x-icon name="search" class="pointer-events-none absolute left-2.5 top-2.5 text-gray-400" />
                    <input type="search" x-model="query" placeholder="Zoek op naam…" class="w-full rounded-lg border-gray-300 py-2 ps-8 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500" aria-label="Zoek kandidaat">
                </div>
                <ul class="mt-2 space-y-2">
                    <template x-for="c in shownCandidates()" :key="c.id">
                        <li class="rounded-xl border px-3 py-2.5" :class="c.fit === 'best' ? 'border-teal-300 bg-teal-50/40' : 'border-gray-200'">
                            <div class="flex items-center gap-2.5">
                                <span class="avatar" :style="'--c:' + c.color + ';--ink:' + c.ink" x-text="c.initials"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        <button type="button" class="truncate text-sm font-medium text-gray-900 hover:text-teal-700" :data-open-employee="c.id" x-text="c.name"></button>
                                        <span class="badge" :class="fit(c).badge" x-text="fit(c).label"></span>
                                    </p>
                                    <a x-show="c.phone" :href="'tel:' + c.phone" class="inline-flex items-center gap-1 text-xs text-teal-700 hover:underline"><x-icon name="phone" class="icon-sm" /><span x-text="c.phone"></span></a>
                                </div>
                                <button type="button" class="btn btn-sm shrink-0" :class="c.fit === 'caution' ? '!bg-white !text-gray-700 border border-gray-300 hover:!bg-gray-50' : ''" @click="assign(c)" x-text="assignLabel()">Inplannen</button>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1 ps-[2.6rem]">
                                <template x-for="r in c.reasons" :key="r.text">
                                    <span class="badge" :class="r.ok === true ? 'badge-green' : (r.ok === false && !r.soft ? 'badge-red' : 'badge-gray')" x-text="(r.ok === true ? '✓ ' : (r.ok === false && !r.soft ? '✗ ' : '')) + r.text"></span>
                                </template>
                            </div>
                            <p class="mt-1.5 ps-[2.6rem] text-xs text-gray-500" x-show="c.day_shifts.length">
                                Die dag al: <span x-text="c.day_shifts.map(d => d.start + '–' + d.end + ' ' + d.building).join(' · ')"></span>
                            </p>
                        </li>
                    </template>
                </ul>
                <button type="button" class="link mt-2 text-xs" x-show="hiddenCount() > 0" @click="showAll = true" x-text="'Toon alle kandidaten (nog ' + hiddenCount() + ')'"></button>
                <p x-show="candidates && shownCandidates().length === 0 && !candidatesLoading" class="mt-2 text-sm text-gray-500">Geen beschikbare medewerkers gevonden.</p>
                <div x-show="candidates?.excluded.length" class="mt-3">
                    <button type="button" class="text-xs text-gray-500 hover:text-gray-800 hover:underline" @click="showExcluded = !showExcluded"
                            x-text="(showExcluded ? 'Verberg' : 'Toon') + ' wie niet kan (' + (candidates?.excluded.length ?? 0) + ')'"></button>
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
            <section>
                <h3 class="drawer-section-title">Pand</h3>
                <div class="space-y-1.5 rounded-xl bg-gray-50 px-3 py-3 text-sm">
                    <p x-show="shift?.building.address" class="flex items-start gap-2"><x-icon name="map-pin" class="mt-0.5 text-gray-400" /><span x-text="shift?.building.address"></span></p>
                    <p x-show="shift?.building.contact" class="flex items-start gap-2"><x-icon name="phone" class="mt-0.5 text-gray-400" /><span x-text="shift?.building.contact"></span></p>
                    <p x-show="shift?.building.access" class="flex items-start gap-2 whitespace-pre-line"><x-icon name="key" class="mt-0.5 text-gray-400" /><span x-text="shift?.building.access"></span></p>
                    <p x-show="shift?.template" class="text-gray-600">
                        Vaste taak: <span x-text="shift?.template?.recurrence"></span>
                        · <a :href="shift?.template?.url" class="link">taak bewerken</a>
                    </p>
                    <p x-show="shift?.is_modified && shift?.template" class="text-xs text-amber-700">Deze dienst is handmatig aangepast en wordt niet meer automatisch bijgewerkt vanuit de vaste taak.</p>
                </div>
                <p x-show="!editing && shift?.notes" class="mt-2 whitespace-pre-line rounded-xl border border-gray-200 px-3 py-2 text-sm text-gray-700" x-text="shift?.notes"></p>
            </section>

            {{-- Bewerken --}}
            <section>
                <button type="button" class="btn-ghost btn-sm -ms-2" @click="editing = !editing">
                    <x-icon name="edit" class="icon-sm" /><span x-text="editing ? 'Wijzigen sluiten' : 'Dienst wijzigen'"></span>
                </button>
                <form x-show="editing" class="mt-3 space-y-3" @submit.prevent="save()">
                    <div>
                        <label class="label !text-xs">Omschrijving</label>
                        <input type="text" x-model="form.title" class="input" required>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-3 sm:col-span-1">
                            <label class="label !text-xs">Datum</label>
                            <input type="date" x-model="form.date" class="input" required>
                        </div>
                        <div>
                            <label class="label !text-xs">Van</label>
                            <input type="time" x-model="form.start_time" class="input" required>
                        </div>
                        <div>
                            <label class="label !text-xs">Tot</label>
                            <input type="time" x-model="form.end_time" class="input" required>
                        </div>
                    </div>
                    <div>
                        <label class="label !text-xs">Aantal personen</label>
                        <input type="number" min="1" max="50" x-model.number="form.required_staff" class="input !w-24" required>
                    </div>
                    <div>
                        <label class="label !text-xs">Vereiste vaardigheden</label>
                        <div class="mt-1 flex flex-wrap gap-2">
                            <template x-for="s in shift?.all_skills ?? []" :key="s.id">
                                <label class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-2 py-1 text-xs">
                                    <input type="checkbox" :value="s.id" x-model.number="form.skills" class="checkbox">
                                    <span x-text="s.name"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                    <div>
                        <label class="label !text-xs">Notities</label>
                        <textarea x-model="form.notes" rows="3" class="input"></textarea>
                    </div>
                    <div class="flex items-center justify-between">
                        <button type="submit" class="btn">Opslaan</button>
                        <button type="button" class="btn-danger btn-sm" @click="cancelShift()">Dienst annuleren</button>
                    </div>
                </form>
            </section>
        </div>
    </aside>
</div>
