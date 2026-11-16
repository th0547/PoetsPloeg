{{-- Medewerkerpaneel: waar is iemand nu, hoe ziet de dag en de week eruit. Openen met data-open-employee="id". --}}
<div x-data="employeePanel()" x-cloak class="no-print"
     @open-employee.window="open($event.detail.id, $event.detail.date)"
     @close-employee-panel.window="close()" @keydown.escape.window="close()">
    <div x-show="visible" x-transition.opacity class="drawer-backdrop" @click="close()"></div>
    <aside x-show="visible" x-transition:enter="transition transform duration-200" x-transition:enter-start="translate-x-full"
           x-transition:leave="transition transform duration-150" x-transition:leave-end="translate-x-full"
           class="drawer" role="dialog" aria-label="Medewerker">
        <div class="drawer-head">
            <div class="flex min-w-0 items-center gap-3" x-show="employee">
                <span class="avatar avatar-lg" :style="'--c:' + employee?.color + ';--ink:' + employee?.ink" x-text="employee?.initials"></span>
                <div class="min-w-0">
                    <h2 class="truncate text-lg font-semibold text-gray-900" x-text="employee?.name"></h2>
                    <p class="mt-0.5"><span class="badge" :class="stateBadge()"><span class="dot" :class="'dot-' + employee?.status.state"></span><span x-text="employee?.status.label"></span></span>
                        <span class="badge badge-gray ms-1" x-show="employee && !employee.active">inactief</span></p>
                </div>
            </div>
            <div x-show="loading && !employee" class="text-sm text-gray-500">Laden…</div>
            <button type="button" class="btn-icon -me-2 shrink-0" @click="close()" aria-label="Sluiten"><x-icon name="x" class="icon-lg" /></button>
        </div>

        <div class="drawer-body" x-show="employee">
            <template x-if="error">
                <div class="notice notice-danger"><x-icon name="alert-circle" class="mt-0.5 text-red-600" /><span x-text="error"></span></div>
            </template>

            {{-- Snel bereiken en acties --}}
            <div class="flex flex-wrap gap-2">
                <a x-show="employee?.phone" :href="'tel:' + employee?.phone" class="btn-secondary btn-sm"><x-icon name="phone" class="icon-sm" /><span x-text="employee?.phone"></span></a>
                <a x-show="employee?.email" :href="'mailto:' + employee?.email" class="btn-secondary btn-sm"><x-icon name="mail" class="icon-sm" />Mail</a>
                <a :href="employee?.urls.absence" class="btn-secondary btn-sm"><x-icon name="user-x" class="icon-sm" />Afwezig melden</a>
            </div>

            {{-- Vandaag --}}
            <section x-show="employee?.week.current">
                <h3 class="drawer-section-title">Vandaag</h3>
                <div class="rounded-xl border border-gray-200">
                    <template x-for="a in today()?.absences ?? []" :key="a.label">
                        <p class="border-b border-gray-100 px-3 py-2 text-sm"><span class="badge" :class="a.kind === 'sickness' ? 'badge-red' : 'badge-gray'" x-text="a.label"></span>
                            <span class="badge badge-amber ms-1" x-show="a.pending">aangevraagd</span></p>
                    </template>
                    <ul class="divide-y divide-gray-100">
                        <template x-for="s in today()?.shifts ?? []" :key="s.id">
                            <li>
                                <button type="button" class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-gray-50" @click="openShift(s.id)">
                                    <span class="h-9 w-1 shrink-0 rounded-full" :style="'background:' + s.color"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium text-gray-900"><span x-text="s.start + '–' + s.end"></span> · <span x-text="s.building"></span></span>
                                        <span class="block truncate text-xs text-gray-500"><span x-text="s.title"></span><span x-show="s.colleagues.length" x-text="' · met ' + s.colleagues.join(', ')"></span></span>
                                    </span>
                                    <span class="badge badge-green" x-show="s.state === 'now'">nu</span>
                                    <span class="badge badge-gray" x-show="s.state === 'done'">klaar</span>
                                    <span class="text-amber-600" x-show="s.level === 'warning' || s.level === 'danger'" :title="s.warnings.join('\n')"><x-icon name="alert" /></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                    <p class="px-3 py-3 text-sm text-gray-500" x-show="!(today()?.shifts.length) && !(today()?.absences.length)">Geen diensten vandaag.</p>
                </div>
            </section>

            {{-- Week --}}
            <section>
                <h3 class="drawer-section-title">
                    <span x-text="'Week ' + employee?.week.number"></span>
                    <span class="flex items-center gap-1 normal-case">
                        <button type="button" class="btn-icon !h-7 !w-7" @click="week(employee.week.prev)" aria-label="Vorige week"><x-icon name="chevron-left" /></button>
                        <span class="text-xs font-normal text-gray-500" x-text="employee?.week.label"></span>
                        <button type="button" class="btn-icon !h-7 !w-7" @click="week(employee.week.next)" aria-label="Volgende week"><x-icon name="chevron-right" /></button>
                    </span>
                </h3>
                <div class="mb-3 rounded-xl bg-gray-50 px-3 py-2.5">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="font-medium text-gray-900" x-text="meter().label"></span>
                        <span class="text-xs" :class="meter().cls === 'is-over' ? 'text-red-600' : 'text-amber-700'" x-text="contractNote()"></span>
                    </div>
                    <div class="meter mt-1.5" :class="meter().cls"><span :style="'width:' + meter().width + '%'"></span></div>
                </div>
                <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200" :class="loading ? 'opacity-60' : ''">
                    <template x-for="d in employee?.days ?? []" :key="d.date">
                        <li class="flex gap-3 px-3 py-2" :class="d.today ? 'bg-teal-50/60' : ''">
                            <span class="w-16 shrink-0 pt-0.5 text-xs font-medium" :class="d.today ? 'text-teal-700' : (d.past ? 'text-gray-400' : 'text-gray-600')" x-text="d.label"></span>
                            <span class="min-w-0 flex-1 space-y-1 text-sm" :class="d.past && !d.today ? 'text-gray-400' : ''">
                                <template x-for="a in d.absences" :key="a.label">
                                    <span class="block"><span class="badge" :class="a.kind === 'sickness' ? 'badge-red' : 'badge-gray'" x-text="a.label"></span><span class="badge badge-amber ms-1" x-show="a.pending">aangevraagd</span></span>
                                </template>
                                <template x-for="s in d.shifts" :key="s.id">
                                    <button type="button" class="flex w-full items-center gap-2 rounded text-left hover:text-teal-700" @click="openShift(s.id)">
                                        <span class="h-2 w-2 shrink-0 rounded-full" :style="'background:' + s.color"></span>
                                        <span class="shrink-0 font-medium tabular-nums" x-text="s.start + '–' + s.end"></span>
                                        <span class="truncate" x-text="s.building"></span>
                                        <span class="text-amber-600" x-show="s.level === 'warning' || s.level === 'danger'" :title="s.warnings.join('\n')"><x-icon name="alert" class="icon-sm" /></span>
                                    </button>
                                </template>
                                <span class="block text-xs text-amber-700" x-show="d.holiday" x-text="d.holiday"></span>
                                <span class="text-gray-300" x-show="!d.shifts.length && !d.absences.length && !d.holiday">–</span>
                            </span>
                        </li>
                    </template>
                </ul>
            </section>

            {{-- Afwezigheid --}}
            <section x-show="employee?.absences.length">
                <h3 class="drawer-section-title">Afwezigheid</h3>
                <ul class="space-y-1.5">
                    <template x-for="a in employee?.absences ?? []" :key="a.id">
                        <li><a :href="a.url" class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm hover:border-teal-300">
                            <span><span class="badge" :class="a.kind === 'sickness' ? 'badge-red' : 'badge-gray'" x-text="a.label"></span>
                                <span class="badge badge-amber ms-1" x-show="a.pending">wacht op vervanger</span></span>
                            <span class="text-xs text-gray-500" x-text="a.period"></span>
                        </a></li>
                    </template>
                </ul>
            </section>

            {{-- Kan en kent --}}
            <section x-show="employee?.skills.length || employee?.buildings.length">
                <h3 class="drawer-section-title">Vaardigheden en panden</h3>
                <div class="flex flex-wrap gap-1">
                    <template x-for="s in employee?.skills ?? []" :key="s"><span class="badge badge-gray" x-text="s"></span></template>
                    <template x-for="b in employee?.buildings ?? []" :key="b.id"><span class="badge badge-teal"><span class="h-1.5 w-1.5 rounded-full" :style="'background:' + b.color"></span><span x-text="b.name"></span></span></template>
                </div>
            </section>

            <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-4">
                <a :href="employee?.urls.planning" class="btn-secondary btn-sm"><x-icon name="grid" class="icon-sm" />Bekijk in planning</a>
                <a :href="employee?.urls.profile" class="btn-secondary btn-sm"><x-icon name="user" class="icon-sm" />Profiel</a>
                <a :href="employee?.urls.edit" class="btn-ghost btn-sm"><x-icon name="edit" class="icon-sm" />Bewerken</a>
            </div>
        </div>
    </aside>
</div>
