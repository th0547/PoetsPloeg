<x-guest-layout>
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <h1 class="text-lg font-semibold text-gray-800">Tweestapsverificatie</h1>

        <form method="POST" action="{{ route('two-factor.challenge') }}" class="mt-4 space-y-4">
            @csrf
            <div x-show="!recovery">
                <p class="text-sm text-gray-600">Vul de code van 6 cijfers uit je authenticator-app in.</p>
                <x-input-label for="code" value="Code" class="mt-3" />
                <x-text-input id="code" class="block mt-1 w-full tracking-widest" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" x-bind:disabled="recovery" autofocus />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>
            <div x-show="recovery" x-cloak>
                <p class="text-sm text-gray-600">Telefoon kwijt? Vul een van je herstelcodes in. Elke code werkt één keer.</p>
                <x-input-label for="recovery_code" value="Herstelcode" class="mt-3" />
                <x-text-input id="recovery_code" class="block mt-1 w-full" type="text" name="recovery_code" autocomplete="off" x-bind:disabled="!recovery" />
                <x-input-error :messages="$errors->get('recovery_code')" class="mt-2" />
            </div>
            <div class="flex items-center justify-between">
                <button type="button" class="text-sm text-gray-600 underline hover:text-gray-900" @click="recovery = !recovery" x-text="recovery ? 'Code uit de app gebruiken' : 'Herstelcode gebruiken'">Herstelcode gebruiken</button>
                <x-primary-button>Inloggen</x-primary-button>
            </div>
        </form>
        <p class="mt-6 text-xs text-gray-500">Geen toegang meer tot je app én geen herstelcodes? Vraag je teamleider om je tweestapsverificatie te resetten.</p>
    </div>
</x-guest-layout>
