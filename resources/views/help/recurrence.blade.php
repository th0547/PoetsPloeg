<x-app-layout title="Hulp: herhaling met een code">
    <x-slot name="header">
        <div>
            <p class="text-sm text-gray-500">Hulp</p>
            <h1 class="page-title">Eigen herhaling met een code</h1>
        </div>
    </x-slot>

    <div class="max-w-3xl space-y-6 text-sm leading-relaxed text-gray-700"
         x-data="recurrencePlayground(@js(['previewUrl' => route('api.recurrence.preview')]))">

        <section class="card card-body space-y-3">
            <h2 class="text-base font-semibold text-gray-900">Wanneer gebruik je dit?</h2>
            <p>Bij een vaste taak kies je de herhaling meestal met knoppen: elke week op maandag, om de week, de eerste maandag van de maand. Lukt het daarmee niet, kies dan bij <strong>Hoe vaak?</strong> voor <strong>Aangepast (code)</strong> en beschrijf het patroon met een korte code.</p>
            <p>De code is de iCalendar-notatie (<em>RRULE</em>) die ook Google Agenda en Outlook gebruiken. Je kunt een taak ook meerdere patronen geven, elk met eigen tijden; vaak is dat eenvoudiger dan één ingewikkelde code.</p>
        </section>

        <section class="card card-body space-y-3">
            <h2 class="text-base font-semibold text-gray-900">Zo is een code opgebouwd</h2>
            <p>Een code bestaat uit onderdelen <code class="rounded bg-gray-100 px-1">NAAM=waarde</code>, gescheiden door een puntkomma. Meerdere waarden scheid je met een komma. Hoofd- of kleine letters maakt niet uit.</p>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead class="bg-gray-50"><tr><th>Onderdeel</th><th>Betekenis</th><th>Voorbeeld</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr><td class="font-mono">FREQ</td><td><strong>Verplicht.</strong> De basis: <span class="font-mono">DAILY</span> (dag), <span class="font-mono">WEEKLY</span> (week), <span class="font-mono">MONTHLY</span> (maand) of <span class="font-mono">YEARLY</span> (jaar).</td><td class="font-mono">FREQ=WEEKLY</td></tr>
                        <tr><td class="font-mono">INTERVAL</td><td>Om de hoeveel dagen, weken, maanden of jaren (1 t/m 99). Standaard 1.</td><td class="font-mono">INTERVAL=2</td></tr>
                        <tr><td class="font-mono">BYDAY</td><td>Dagen: <span class="font-mono">MO TU WE TH FR SA SU</span>. Met een getal ervoor bij maand of jaar: <span class="font-mono">1MO</span> = eerste maandag, <span class="font-mono">-1FR</span> = laatste vrijdag.</td><td class="font-mono">BYDAY=MO,TH</td></tr>
                        <tr><td class="font-mono">BYMONTHDAY</td><td>Datum in de maand, 1 t/m 31. <span class="font-mono">-1</span> is de laatste dag.</td><td class="font-mono">BYMONTHDAY=15</td></tr>
                        <tr><td class="font-mono">BYMONTH</td><td>Alleen in deze maanden, 1 (januari) t/m 12 (december).</td><td class="font-mono">BYMONTH=3,9</td></tr>
                        <tr><td class="font-mono">BYSETPOS</td><td>Kies uit de gevonden dagen per periode alleen de zoveelste. Samen met BYDAY bijvoorbeeld “de laatste werkdag”.</td><td class="font-mono">BYSETPOS=-1</td></tr>
                        <tr><td class="font-mono">BYWEEKNO</td><td>Alleen in deze weeknummers (alleen met <span class="font-mono">FREQ=YEARLY</span>).</td><td class="font-mono">BYWEEKNO=1,27</td></tr>
                        <tr><td class="font-mono">COUNT</td><td>Na zoveel keer stoppen (1 t/m 999).</td><td class="font-mono">COUNT=6</td></tr>
                        <tr><td class="font-mono">UNTIL</td><td>Stoppen na deze datum, als JJJJMMDD. Gebruik COUNT óf UNTIL.</td><td class="font-mono">UNTIL=20270630</td></tr>
                        <tr><td class="font-mono">WKST</td><td>Eerste dag van de week voor het tellen van weken. Standaard maandag; zelden nodig.</td><td class="font-mono">WKST=MO</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500">Uren en minuten (<span class="font-mono">BYHOUR</span>, <span class="font-mono">FREQ=HOURLY</span>) kunnen niet: de tijden stel je bij het patroon in. Andere tekens dan letters, cijfers en <span class="font-mono">= ; , -</span> worden geweigerd.</p>
        </section>

        <section class="card card-body space-y-3">
            <h2 class="text-base font-semibold text-gray-900">Vanaf wanneer telt het?</h2>
            <p>Elk patroon heeft een datum bij <strong>Telt vanaf</strong>. Daar begint de telling. Bij <span class="font-mono">FREQ=WEEKLY;INTERVAL=2</span> telt de week van die datum mee, daarna steeds een week niet. Dagen in die eerste week die vóór de datum vallen, worden overgeslagen.</p>
            <p>Zonder <span class="font-mono">BYDAY</span> of <span class="font-mono">BYMONTHDAY</span> neemt de code de dag van “Telt vanaf”: <span class="font-mono">FREQ=MONTHLY</span> vanaf 15 januari is elke 15e. Daarnaast gelden altijd de periode (Vanaf en Tot en met) en “Niet op feestdagen” van de taak.</p>
        </section>

        {{-- Laat een AI-assistent de code schrijven (issue #39). De vraag bevat alleen de uitleg hieronder en wat je zelf typt. --}}
        <section class="card card-body space-y-3" x-data="aiPrompt(@js(['base' => $aiPrompt, 'max' => $aiMaxWish]))">
            <h2 class="text-base font-semibold text-gray-900">Laat een AI-assistent de code schrijven</h2>
            <p>Beschrijf in gewone taal wat je wilt. De knop opent Claude of ChatGPT met een kant-en-klare vraag, met de regels van deze pagina erin. Plak de code die je terugkrijgt bij <strong>Aangepast (code)</strong>; het voorbeeld laat meteen zien of hij klopt.</p>
            <div>
                <label class="label" for="ai-wish">Wat wil je?</label>
                <textarea id="ai-wish" class="input" rows="2" x-model="wish" :maxlength="max" placeholder="Bijv. de tweede en vierde dinsdag van de maand, behalve in juli en augustus"></textarea>
                <p class="mt-1 text-xs text-gray-500">Je vraag gaat naar de AI-dienst die je kiest. Zet er geen namen, adressen of andere persoonsgegevens in.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="btn" :href="url('https://claude.ai/new?q=')" target="_blank" rel="noopener noreferrer">Open in Claude</a>
                <a class="btn-secondary" :href="url('https://chatgpt.com/?q=')" target="_blank" rel="noopener noreferrer">Open in ChatGPT</a>
                <button type="button" class="btn-secondary" @click="copy()" x-text="copied ? 'Gekopieerd' : 'Vraag kopiëren'">Vraag kopiëren</button>
            </div>
            <details class="text-xs text-gray-500">
                <summary class="cursor-pointer">Welke vraag wordt er gestuurd?</summary>
                <pre class="mt-2 whitespace-pre-wrap rounded bg-gray-50 p-2 font-mono" x-text="prompt()"></pre>
            </details>
        </section>

        <section class="card card-body space-y-3" id="proberen">
            <h2 class="text-base font-semibold text-gray-900">Proberen</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="label" for="try-code">Code</label>
                    <input id="try-code" x-ref="code" type="text" class="input font-mono" x-model="code" @input="refresh()" maxlength="300" spellcheck="false" autocomplete="off">
                </div>
                <div>
                    <label class="label" for="try-from">Telt vanaf</label>
                    <input id="try-from" type="date" class="input" x-model="startsOn" @change="refresh(0)">
                </div>
            </div>
            <p class="text-sm text-red-700" x-show="error" x-text="error" role="alert"></p>
            <div x-show="!error" class="space-y-2">
                <p class="font-semibold text-gray-900" x-text="label ? '✓ ' + label : ''"></p>
                <div class="flex flex-wrap gap-1">
                    <template x-for="d in dates" :key="d">
                        <span class="chip bg-teal-50 text-teal-800" x-text="d"></span>
                    </template>
                    <span class="text-gray-500" x-show="!loading && !dates.length">Geen datums in het komende jaar.</span>
                </div>
            </div>
        </section>

        <section class="card card-body space-y-3">
            <h2 class="text-base font-semibold text-gray-900">Voorbeelden</h2>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead class="bg-gray-50"><tr><th>Code</th><th>Betekent</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($examples as [$code, $meaning])
                            <tr>
                                <td class="whitespace-nowrap font-mono text-xs">{{ $code }}</td>
                                <td>{{ $meaning }}</td>
                                <td class="text-right"><button type="button" class="btn-secondary btn-sm" @click="use(@js($code)); document.getElementById('proberen').scrollIntoView({ behavior: 'smooth' })">Probeer</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500">Een “wisselend schema” (de ene week donderdag, de week erop maandag) maak je het makkelijkst met twee patronen “om de week”, elk met een eigen “Telt vanaf”.</p>
        </section>
    </div>
</x-app-layout>
