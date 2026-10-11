<x-app-layout title="Medewerkers">
    <x-slot name="header">
        <h1 class="page-title">Medewerkers <span class="text-base font-normal text-gray-500">({{ $employees->count() }})</span></h1>
        <div class="flex items-center gap-2">
            <form method="GET" class="flex items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Zoeken…" class="rounded-md border-gray-300 text-sm shadow-sm">
                <label class="inline-flex items-center gap-1 text-sm text-gray-600">
                    <input type="checkbox" name="inactief" value="1" class="checkbox" @checked(request()->boolean('inactief')) onchange="this.form.submit()"> ook inactief
                </label>
            </form>
            <a href="{{ route('employees.create') }}" class="btn">+ Medewerker</a>
        </div>
    </x-slot>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead class="bg-gray-50">
                <tr>
                    <th>Naam</th>
                    <th>Contact</th>
                    <th>Contracturen</th>
                    <th>Vaardigheden</th>
                    <th>Ingewerkt op</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($employees as $employee)
                    <tr class="hover:bg-gray-50 {{ $employee->active ? '' : 'opacity-60' }}">
                        <td>
                            <a href="{{ route('employees.edit', $employee) }}" class="inline-flex items-center gap-2 font-medium text-gray-900 hover:text-teal-700">
                                <span class="h-3 w-3 rounded-full" style="background: {{ $employee->color }}"></span>{{ $employee->name }}
                            </a>
                            @unless ($employee->active) <span class="chip">inactief</span> @endunless
                        </td>
                        <td class="text-gray-600">
                            <div>{{ $employee->phone }}</div>
                            <div class="text-xs">{{ $employee->email }}</div>
                        </td>
                        <td class="whitespace-nowrap text-gray-600">
                            @if ($employee->contract_hours_min !== null || $employee->contract_hours_max !== null)
                                {{ \App\Support\Planning::hours($employee->contract_hours_min ?? 0) }}–{{ $employee->contract_hours_max !== null ? \App\Support\Planning::hours($employee->contract_hours_max) : '∞' }} u/week
                            @else
                                —
                            @endif
                        </td>
                        <td><div class="flex flex-wrap gap-1">@foreach ($employee->skills as $skill)<span class="chip">{{ $skill->name }}</span>@endforeach</div></td>
                        <td><div class="flex flex-wrap gap-1">@foreach ($employee->buildings as $building)<span class="chip bg-teal-50 text-teal-800">{{ $building->name }}</span>@endforeach</div></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-gray-500">Nog geen medewerkers. <a href="{{ route('employees.create') }}" class="text-teal-700 hover:underline">Voeg de eerste toe</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
