<x-app-layout title="Vaardigheden">
    <x-slot name="header">
        <h1 class="page-title">Vaardigheden</h1>
    </x-slot>

    <div class="max-w-2xl space-y-4">
        <p class="text-sm text-gray-600">Vaardigheden koppel je aan medewerkers en aan taken. Bij uitval worden alleen vervangers met de juiste vaardigheden als geschikt voorgesteld.</p>

        <form method="POST" action="{{ route('skills.store') }}" class="card card-body flex items-end gap-2">
            @csrf
            <div class="flex-1">
                <label class="label" for="name">Nieuwe vaardigheid</label>
                <input id="name" name="name" class="input" placeholder="Bijv. Glasbewassing, Vloeronderhoud, Sanitair" required>
            </div>
            <button type="submit" class="btn">Toevoegen</button>
        </form>

        <div class="card overflow-hidden">
            <ul class="divide-y">
                @forelse ($skills as $skill)
                    <li class="flex items-center gap-2 px-4 py-2" x-data="{ edit: false }">
                        <form method="POST" action="{{ route('skills.update', $skill) }}" class="flex flex-1 items-center gap-2">
                            @csrf @method('PUT')
                            <span x-show="!edit" class="flex-1">{{ $skill->name }} <span class="text-xs text-gray-500">({{ $skill->employees_count }} medewerkers)</span></span>
                            <input x-show="edit" x-cloak name="name" value="{{ $skill->name }}" class="input mt-0 flex-1">
                            <button x-show="edit" x-cloak type="submit" class="btn btn-sm">Opslaan</button>
                            <button type="button" class="text-sm text-gray-600 hover:underline" @click="edit = !edit" x-text="edit ? 'Annuleren' : 'Wijzigen'"></button>
                        </form>
                        <form method="POST" action="{{ route('skills.destroy', $skill) }}" data-confirm="Vaardigheid verwijderen?">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-sm text-red-600 hover:underline">Verwijderen</button>
                        </form>
                    </li>
                @empty
                    <li class="px-4 py-4 text-sm text-gray-500">Nog geen vaardigheden.</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-app-layout>
