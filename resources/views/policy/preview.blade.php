<x-app-layout title="Beleid voor medewerkers">
    <x-slot name="header">
        <div>
            <p class="text-sm text-gray-500">Zo zien medewerkers het</p>
            <h1 class="page-title">Regels en afspraken</h1>
        </div>
        <a href="{{ route('policy.index') }}" class="btn-secondary">‹ Beleid</a>
    </x-slot>
    @unless ($policy->get('privacy.visible_to_employees'))
        <p class="mb-4 max-w-3xl rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">Deze pagina is nu niet zichtbaar voor medewerkers (instelling bij Privacy en rechten).</p>
    @endunless
    @include('policy.partials.read')
</x-app-layout>
