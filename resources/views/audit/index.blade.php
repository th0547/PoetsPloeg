<x-app-layout title="Logboek">
    <x-slot name="header">
        <h1 class="page-title">Logboek</h1>
    </x-slot>

    <div class="space-y-4">
        <p class="max-w-3xl text-sm text-gray-600">Wie heeft wanneer persoonsgegevens ingezien (telefoonnummers, toegangsinstructies, verlofgeschiedenis), en wie heeft ingelogd of gebruikers en inlogs beheerd. Inzage wordt per persoon en onderwerp hoogstens één keer per 10 minuten vastgelegd. Regels worden na {{ $retention }} maanden verwijderd.</p>

        <form method="GET" class="card card-body grid gap-3 sm:grid-cols-5">
            <div>
                <label class="label" for="gebruiker">Wie</label>
                <select id="gebruiker" name="gebruiker" class="input">
                    <option value="">Iedereen</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}" @selected((int) ($filters['gebruiker'] ?? 0) === $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="actie">Actie</label>
                <select id="actie" name="actie" class="input">
                    <option value="">Alle</option>
                    @foreach (\App\Models\AuditLog::ACTIONS as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['actie'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="van">Van</label>
                <input id="van" type="date" name="van" class="input" value="{{ $filters['van'] ?? '' }}">
            </div>
            <div>
                <label class="label" for="tot">Tot en met</label>
                <input id="tot" type="date" name="tot" class="input" value="{{ $filters['tot'] ?? '' }}">
            </div>
            <div>
                <label class="label" for="zoek">Zoek in omschrijving</label>
                <input id="zoek" name="zoek" class="input" value="{{ $filters['zoek'] ?? '' }}" placeholder="bijv. naam of pand">
            </div>
            <div class="sm:col-span-5 flex gap-2">
                <button type="submit" class="btn">Filteren</button>
                <a href="{{ route('audit-log') }}" class="btn-secondary">Wissen</a>
            </div>
        </form>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead class="bg-gray-50"><tr><th>Wanneer</th><th>Wie</th><th>Actie</th><th>Omschrijving</th><th>IP</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $log->created_at->format('d-m-Y H:i') }}</td>
                            <td class="whitespace-nowrap text-sm">{{ $log->user_name ?? '–' }}</td>
                            <td class="whitespace-nowrap"><span class="chip {{ str_starts_with($log->action, 'auth.failed') ? 'bg-red-100 text-red-800' : ($log->action === 'pii.view' ? 'bg-sky-100 text-sky-800' : '') }}">{{ $log->actionLabel() }}</span></td>
                            <td class="text-sm">
                                {{ $log->description }}
                                @if ($log->meta)
                                    <span class="block text-xs text-gray-500">
                                        @foreach ($log->meta as $key => $value){{ $key }}: {{ is_array($value) ? implode(', ', $value) : $value }}@if (! $loop->last) · @endif @endforeach
                                    </span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-xs text-gray-500">{{ $log->ip }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-sm text-gray-500">Geen regels gevonden.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $logs->links() }}
    </div>
</x-app-layout>
