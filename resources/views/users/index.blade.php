<x-app-layout title="Gebruikers">
    <x-slot name="header">
        <h1 class="page-title">Gebruikers</h1>
        <a href="{{ route('users.create') }}" class="btn">+ Gebruiker</a>
    </x-slot>

    <div class="card max-w-4xl overflow-x-auto">
        <table class="table">
            <thead class="bg-gray-50"><tr><th>Naam</th><th>E-mail</th><th>Rol</th><th>2FA</th><th></th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($users as $user)
                    <tr>
                        <td class="font-medium">{{ $user->name }} @if ($user->is(auth()->user()))<span class="chip">jij</span>@endif</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->roleLabel() }}</td>
                        <td>
                            @if ($user->hasTwoFactor())
                                <span class="chip bg-teal-50 text-teal-800">aan</span>
                            @else
                                <span class="chip bg-amber-100 text-amber-800" title="Wordt gevraagd bij de volgende keer inloggen">nog niet</span>
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <a href="{{ route('users.edit', $user) }}" class="text-sm text-teal-700 hover:underline">Wijzigen</a>
                            @if ($user->hasTwoFactor() && ! $user->is(auth()->user()))
                                @can('reset-two-factor')
                                    <form method="POST" action="{{ route('users.two-factor.reset', $user) }}" class="inline" data-confirm="Tweestapsverificatie van {{ $user->name }} resetten? Bij de volgende keer inloggen stelt {{ $user->name }} het opnieuw in.">
                                        @csrf
                                        <button type="submit" class="ms-3 text-sm text-amber-700 hover:underline">2FA resetten</button>
                                    </form>
                                @endcan
                            @endif
                            @unless ($user->is(auth()->user()))
                                <form method="POST" action="{{ route('users.destroy', $user) }}" class="inline" data-confirm="Gebruiker verwijderen?">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ms-3 text-sm text-red-600 hover:underline">Verwijderen</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-3 max-w-4xl text-xs text-gray-500">
        Planners en teamleiders hebben dezelfde rechten.
        Medewerkers met een eigen inlog ({{ $employeeAccounts }}) staan hier niet: die nodig je uit en beheer je bij <a href="{{ route('employees.index') }}" class="text-teal-700 hover:underline">Medewerkers</a>, onder "Inloggen".
    </p>
</x-app-layout>
