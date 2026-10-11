<x-app-layout :title="$building->exists ? $building->name : 'Nieuw pand'">
    <x-slot name="header">
        <h1 class="page-title">{{ $building->exists ? $building->name.' bewerken' : 'Nieuw pand' }}</h1>
        <a href="{{ $building->exists ? route('buildings.show', $building) : route('buildings.index') }}" class="btn-secondary">‹ Terug</a>
    </x-slot>

    <form method="POST" action="{{ $building->exists ? route('buildings.update', $building) : route('buildings.store') }}" class="space-y-6 max-w-4xl">
        @csrf
        @if ($building->exists) @method('PUT') @endif

        <div class="card card-body space-y-4">
            <h2 class="font-semibold text-gray-800">Pandinfo</h2>
            <div class="grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-4">
                    <label class="label" for="name">Naam</label>
                    <input id="name" name="name" class="input" value="{{ old('name', $building->name) }}" required autofocus>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="color">Kleur</label>
                    <input id="color" type="color" name="color" class="mt-1 h-9 w-20 rounded border-gray-300" value="{{ old('color', $building->color) }}">
                </div>
                <div class="sm:col-span-3">
                    <label class="label" for="address">Adres</label>
                    <input id="address" name="address" class="input" value="{{ old('address', $building->address) }}">
                </div>
                <div class="sm:col-span-1">
                    <label class="label" for="postal_code">Postcode</label>
                    <input id="postal_code" name="postal_code" class="input" value="{{ old('postal_code', $building->postal_code) }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="city">Plaats</label>
                    <input id="city" name="city" class="input" value="{{ old('city', $building->city) }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="contact_name">Contactpersoon</label>
                    <input id="contact_name" name="contact_name" class="input" value="{{ old('contact_name', $building->contact_name) }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="contact_phone">Telefoon contact</label>
                    <input id="contact_phone" name="contact_phone" class="input" value="{{ old('contact_phone', $building->contact_phone) }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="contact_email">E-mail contact</label>
                    <input id="contact_email" type="email" name="contact_email" class="input" value="{{ old('contact_email', $building->contact_email) }}">
                </div>
                <div class="sm:col-span-6">
                    <label class="label" for="access_instructions">Toegang (sleutel, alarmcode-locatie, ingang)</label>
                    <textarea id="access_instructions" name="access_instructions" rows="3" class="input">{{ old('access_instructions', $building->access_instructions) }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">Tip: zet geen alarmcodes zelf in de tool, maar wel waar ze te vinden zijn.</p>
                </div>
                <div class="sm:col-span-6">
                    <label class="label" for="notes">Notities</label>
                    <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes', $building->notes) }}</textarea>
                </div>
                <div class="sm:col-span-6">
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="active" value="1" class="checkbox" @checked(old('active', $building->active))> Actief (diensten worden ingepland)
                    </label>
                </div>
            </div>
        </div>

        <div class="card card-body space-y-3">
            <div>
                <h2 class="font-semibold text-gray-800">Ingewerkte medewerkers</h2>
                <p class="text-xs text-gray-500">Wie kent dit pand? Zij krijgen voorrang als vervanger.</p>
            </div>
            @php($selected = collect(old('employees', $building->employees?->pluck('id') ?? []))->map(fn ($i) => (int) $i)->all())
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                @forelse ($employees as $employee)
                    <label class="inline-flex items-center gap-1.5 text-sm">
                        <input type="checkbox" name="employees[]" value="{{ $employee->id }}" class="checkbox" @checked(in_array($employee->id, $selected))> {{ $employee->name }}
                    </label>
                @empty
                    <p class="text-sm text-gray-500">Nog geen medewerkers.</p>
                @endforelse
            </div>
        </div>

        <button type="submit" class="btn">Opslaan</button>
    </form>

    @if ($building->exists)
        <form method="POST" action="{{ route('buildings.destroy', $building) }}" class="mt-8 max-w-4xl" onsubmit="return confirm('Pand definitief verwijderen, inclusief alle taken en diensten? Tip: zet het pand liever op inactief.')">
            @csrf @method('DELETE')
            <button type="submit" class="btn-danger">Pand verwijderen</button>
        </form>
    @endif
</x-app-layout>
