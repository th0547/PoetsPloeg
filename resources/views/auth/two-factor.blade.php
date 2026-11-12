<x-app-layout title="Tweestapsverificatie">
    <x-slot name="header">
        <h1 class="page-title">Tweestapsverificatie</h1>
        <a href="{{ route('profile.edit') }}" class="btn-secondary">‹ Mijn profiel</a>
    </x-slot>

    <div class="max-w-2xl space-y-6">
        @if ($user->hasTwoFactor())
            <div class="card card-body space-y-3">
                <p class="flex items-center gap-2 font-semibold text-gray-900"><span class="chip bg-teal-50 text-teal-800">aan</span> Tweestapsverificatie staat aan</p>
                <p class="text-sm text-gray-600">Bij het inloggen vragen we na je wachtwoord om de code uit je authenticator-app. Ingesteld op {{ $user->two_factor_confirmed_at->translatedFormat('j F Y') }}. Je hebt nog {{ $user->recoveryCodesLeft() }} herstelcodes.</p>

                @if ($recoveryCodes)
                    <div class="rounded-md border border-amber-300 bg-amber-50 p-3">
                        <p class="text-sm font-semibold text-amber-900">Je herstelcodes. Bewaar ze nu, ze worden maar één keer getoond.</p>
                        <p class="text-xs text-amber-900">Met een herstelcode log je in als je je telefoon kwijt bent. Elke code werkt één keer.</p>
                        <ul class="mt-2 grid grid-cols-2 gap-1 font-mono text-sm">
                            @foreach ($recoveryCodes as $code)<li>{{ $code }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('two-factor.recovery-codes') }}" data-confirm="Nieuwe herstelcodes maken? De oude werken dan niet meer.">
                        @csrf
                        <button type="submit" class="btn-secondary">Nieuwe herstelcodes</button>
                    </form>
                    @unless ($user->requiresTwoFactor())
                        <form method="POST" action="{{ route('two-factor.destroy') }}" data-confirm="Tweestapsverificatie uitzetten? Je account is dan minder goed beveiligd.">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-danger">Uitzetten</button>
                        </form>
                    @endunless
                </div>
                @if ($user->requiresTwoFactor())
                    <p class="text-xs text-gray-500">Voor jouw rol is tweestapsverificatie verplicht. Nieuwe telefoon? Vraag je teamleider om te resetten en stel het daarna opnieuw in.</p>
                @endif
            </div>
        @else
            <div class="card card-body space-y-4">
                <p class="text-sm text-gray-600">
                    @if ($user->requiresTwoFactor())
                        <strong>Verplicht voor jouw rol.</strong> Je ziet persoonsgegevens van medewerkers en panden; met een tweede stap is je account veel beter beveiligd.
                    @else
                        Met tweestapsverificatie is je account beter beveiligd: na je wachtwoord vul je een code uit een app op je telefoon in.
                    @endif
                </p>
                <ol class="list-decimal space-y-3 ps-5 text-sm text-gray-700">
                    <li>Installeer een authenticator-app op je telefoon, bijvoorbeeld Google Authenticator, Microsoft Authenticator of de app van je wachtwoordbeheerder.</li>
                    <li>
                        Scan deze QR-code met de app.
                        <img src="data:image/svg+xml;base64,{{ base64_encode($qr) }}" alt="QR-code voor je authenticator-app" class="my-2 h-48 w-48 rounded border bg-white p-1">
                        <span class="block text-xs text-gray-500">Lukt scannen niet? Vul deze sleutel in de app in:</span>
                        <code class="mt-1 block break-all rounded bg-gray-100 px-2 py-1 font-mono text-sm">{{ trim(chunk_split($secret, 4, ' ')) }}</code>
                    </li>
                    <li>
                        Vul de code van 6 cijfers in die de app nu laat zien.
                        <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-2 flex flex-wrap items-start gap-2">
                            @csrf
                            <div>
                                <label for="code" class="sr-only">Code</label>
                                <input id="code" name="code" class="input mt-0 w-36 tracking-widest" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus>
                                <x-input-error :messages="$errors->get('code')" class="mt-1" />
                            </div>
                            <button type="submit" class="btn">Bevestigen</button>
                        </form>
                    </li>
                </ol>
            </div>
        @endif
    </div>
</x-app-layout>
