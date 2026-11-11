<x-app-layout title="Gebruikers">
    <x-slot name="header">
        <h1 class="page-title">Gebruikers</h1>
        <a href="{{ route('users.create') }}" class="btn">+ Gebruiker</a>
    </x-slot>

    <div class="card max-w-4xl overflow-x-auto">
        <table class="table">
            <thead class="bg-gray-50"><tr><th>Naam</th><th>E-mail</th><th>Rol</th><th></th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($users as $user)
                    <tr>
                        <td class="font-medium">{{ $user->name }} @if ($user->is(auth()->user()))<span class="chip">jij</span>@endif</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->roleLabel() }}</td>
                        <td class="text-right whitespace-nowrap">
                            <a href="{{ route('users.edit', $user) }}" class="text-sm text-teal-700 hover:underline">Wijzigen</a>
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
    <p class="mt-3 max-w-4xl text-xs text-gray-500">Planners en teamleiders hebben dezelfde rechten. Een inlog voor medewerkers volgt in een latere fase.</p>
</x-app-layout>
