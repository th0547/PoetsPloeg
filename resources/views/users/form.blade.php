<x-app-layout :title="$user->exists ? $user->name : 'Nieuwe gebruiker'">
    <x-slot name="header">
        <h1 class="page-title">{{ $user->exists ? $user->name : 'Nieuwe gebruiker' }}</h1>
        <a href="{{ route('users.index') }}" class="btn-secondary">‹ Gebruikers</a>
    </x-slot>

    <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card card-body max-w-xl space-y-4">
        @csrf
        @if ($user->exists) @method('PUT') @endif
        <div>
            <label class="label" for="name">Naam</label>
            <input id="name" name="name" class="input" value="{{ old('name', $user->name) }}" required autofocus>
        </div>
        <div>
            <label class="label" for="email">E-mail (inlognaam)</label>
            <input id="email" type="email" name="email" class="input" value="{{ old('email', $user->email) }}" required>
        </div>
        <div>
            <label class="label" for="role">Rol</label>
            <select id="role" name="role" class="input">
                @foreach (\App\Models\User::ROLES as $value => $label)
                    <option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="password">Wachtwoord {{ $user->exists ? '(leeg laten = niet wijzigen)' : '' }}</label>
            <input id="password" type="password" name="password" class="input" autocomplete="new-password" @required(! $user->exists)>
        </div>
        <div>
            <label class="label" for="password_confirmation">Herhaal wachtwoord</label>
            <input id="password_confirmation" type="password" name="password_confirmation" class="input" autocomplete="new-password">
        </div>
        <button type="submit" class="btn">Opslaan</button>
    </form>
</x-app-layout>
