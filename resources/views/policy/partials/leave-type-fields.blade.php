{{-- Velden van één verlofsoort. $prefix = naam in het formulier, $t = huidige waarden, $default = cao-standaard of null. --}}
@php
    $cao = config('poetsploeg-cao');
    $f = fn ($field) => "{$prefix}[{$field}]";
    $err = fn ($field) => $errors->get(str_replace(['[', ']'], ['.', ''], $prefix).'.'.$field);
    $hint = fn ($field) => $default !== null && ($default[$field] ?? null) !== null && \App\Support\Policy::castLeaveField($field, $default[$field]) !== \App\Support\Policy::castLeaveField($field, $t[$field] ?? null)
        ? 'cao: '.(is_bool($default[$field]) ? ($default[$field] ? 'ja' : 'nee') : $default[$field]) : null;
@endphp
<div class="grid gap-3 sm:grid-cols-4">
    <div class="sm:col-span-2">
        <label class="text-xs font-medium text-gray-700">Naam</label>
        <input name="{{ $f('name') }}" class="input mt-0" value="{{ $t['name'] }}" maxlength="60">
        <x-input-error :messages="$err('name')" class="mt-1" />
    </div>
    <div>
        <label class="text-xs font-medium text-gray-700">Groep (in keuzelijsten)</label>
        <input name="{{ $f('group') }}" class="input mt-0" value="{{ $t['group'] }}" maxlength="40">
    </div>
    <div>
        <label class="text-xs font-medium text-gray-700">Telt als</label>
        <select name="{{ $f('counts_as') }}" class="input mt-0">
            @foreach ($cao['counts_as'] as $value => $label)<option value="{{ $value }}" @selected($t['counts_as'] === $value)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="text-xs font-medium text-gray-700">Doorbetaling (%)</label>
        <input type="number" name="{{ $f('paid') }}" class="input mt-0" min="0" max="100" value="{{ $t['paid'] }}">
        <span class="text-xs text-amber-700">{{ $hint('paid') }}</span>
    </div>
    <div>
        <label class="text-xs font-medium text-gray-700">Betaald door</label>
        <select name="{{ $f('paid_by') }}" class="input mt-0">
            <option value="werkgever" @selected($t['paid_by'] === 'werkgever')>Werkgever</option>
            <option value="UWV" @selected($t['paid_by'] === 'UWV')>UWV</option>
        </select>
    </div>
    <div class="sm:col-span-2">
        <label class="text-xs font-medium text-gray-700">Maximum (leeg = geen)</label>
        <div class="flex gap-2">
            <input type="number" step="0.01" name="{{ $f('max') }}" class="input mt-0 w-24" min="0" value="{{ $t['max'] }}">
            <select name="{{ $f('unit') }}" class="input mt-0">
                @foreach ($cao['units'] as $value => $label)<option value="{{ $value }}" @selected($t['unit'] === $value)>{{ $label }}</option>@endforeach
            </select>
            <select name="{{ $f('period') }}" class="input mt-0">
                @foreach ($cao['periods'] as $value => $label)<option value="{{ $value }}" @selected($t['period'] === $value)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <span class="text-xs text-amber-700">{{ $hint('max') }}</span>
    </div>
    <div>
        <label class="text-xs font-medium text-gray-700">Minimaal dagen vooraf</label>
        <input type="number" name="{{ $f('min_days_ahead') }}" class="input mt-0" min="0" max="365" value="{{ $t['min_days_ahead'] }}">
        <span class="text-xs text-amber-700">{{ $hint('min_days_ahead') }}</span>
    </div>
    <div>
        <label class="text-xs font-medium text-gray-700">Mag de werkgever weigeren?</label>
        <select name="{{ $f('refusal') }}" class="input mt-0">
            @foreach ($cao['refusal'] as $value => $label)<option value="{{ $value }}" @selected($t['refusal'] === $value)>{{ $label }}</option>@endforeach
        </select>
        <span class="text-xs text-amber-700">{{ $hint('refusal') }}</span>
    </div>
    <div class="flex flex-col gap-1 text-sm sm:col-span-2">
        @foreach (['active' => 'Actief (te kiezen)', 'self_request' => 'Medewerker mag zelf aanvragen', 'direct' => 'Altijd direct registreren (geen verzoek, geen vervanger nodig)', 'without_shift' => 'Ook op een dag zonder dienst', 'proof' => 'Bewijs vereist', 'note_required' => 'Toelichting verplicht'] as $field => $label)
            <label class="inline-flex items-center gap-2">
                <input type="hidden" name="{{ $f($field) }}" value="0">
                <input type="checkbox" name="{{ $f($field) }}" value="1" class="checkbox" @checked($t[$field])> {{ $label }}
                <span class="text-xs text-amber-700">{{ $hint($field) }}</span>
            </label>
        @endforeach
    </div>
    <div class="sm:col-span-4">
        <label class="text-xs font-medium text-gray-700">Uitleg voor medewerkers</label>
        <input name="{{ $f('explanation') }}" class="input mt-0" maxlength="500" value="{{ $t['explanation'] }}">
    </div>
</div>
