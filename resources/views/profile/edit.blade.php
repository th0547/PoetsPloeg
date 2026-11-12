<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <h2 class="text-lg font-medium text-gray-900">Tweestapsverificatie</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        @if ($user->hasTwoFactor())
                            Staat aan. Je hebt nog {{ $user->recoveryCodesLeft() }} herstelcodes.
                        @elseif ($user->requiresTwoFactor())
                            Verplicht voor jouw rol, nog niet ingesteld.
                        @else
                            Staat uit. Zet het aan voor extra beveiliging.
                        @endif
                    </p>
                    <a href="{{ route('two-factor.show') }}" class="btn-secondary mt-4 inline-flex">{{ $user->hasTwoFactor() ? 'Beheren' : 'Instellen' }}</a>
                </div>
            </div>

            @unless ($user->isEmployee())
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <div class="max-w-xl">
                        @include('profile.partials.delete-user-form')
                    </div>
                </div>
            @endunless
        </div>
    </div>
</x-app-layout>
