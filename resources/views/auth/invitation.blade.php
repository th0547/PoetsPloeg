<x-guest-layout>
    <h1 class="text-lg font-semibold text-gray-800">Wachtwoord instellen</h1>
    <p class="mt-1 text-sm text-gray-600">Kies een wachtwoord voor je account. Daarna log je in met je e-mailadres en dit wachtwoord.</p>

    <form method="POST" action="{{ route('invitation.store') }}" class="mt-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <x-input-label for="email" value="E-mailadres" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email', $email)" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" value="Wachtwoord" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autofocus autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" value="Herhaal wachtwoord" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>Wachtwoord instellen</x-primary-button>
        </div>
    </form>
</x-guest-layout>
