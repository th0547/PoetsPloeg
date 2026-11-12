<?php

namespace App\Support;

use App\Models\PolicyVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Het geldende beleid op een datum (issue #42): cao-standaard uit config/poetsploeg-cao.php plus de
 * afwijkingen uit de versie die op die datum geldt. Gebruik Policy::current() of Policy::at($datum).
 */
class Policy
{
    /** @var array<string, self> */
    private static array $cache = [];

    /** Volgorde van "weigeren" van gunstig naar ongunstig voor de werknemer. */
    private const REFUSAL_ORDER = ['no' => 0, 'serious' => 1, 'yes' => 2];

    public function __construct(public readonly ?PolicyVersion $version) {}

    public static function current(): self
    {
        return self::at(CarbonImmutable::today());
    }

    public static function at(\DateTimeInterface|string|null $date = null): self
    {
        $day = CarbonImmutable::parse($date ?? 'today')->toDateString();
        if (! isset(self::$cache[$day])) {
            $version = rescue(fn () => PolicyVersion::query()
                ->whereDate('valid_from', '<=', $day)
                ->orderByDesc('valid_from')
                ->first(), null, false);
            self::$cache[$day] = new self($version);
        }

        return self::$cache[$day];
    }

    /** Na opslaan van een nieuwe versie. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    // --- Instellingen ---

    /** @return array<string, array{label: string, items: array<string, array>}> */
    public static function groups(): array
    {
        return config('poetsploeg-cao.groups');
    }

    public static function definition(string $key): ?array
    {
        [$group, $item] = array_pad(explode('.', $key, 2), 2, null);

        return config("poetsploeg-cao.groups.{$group}.items.{$item}");
    }

    public static function default(string $key): mixed
    {
        return self::definition($key)['default'] ?? null;
    }

    /** Waarde van een instelling, bijv. Policy::current()->get('roster.notice_weeks'). */
    public function get(string $key): mixed
    {
        $definition = self::definition($key);
        if (! $definition) {
            throw new \InvalidArgumentException("Onbekende beleidsinstelling: {$key}");
        }
        $value = $this->version?->settings[$key] ?? $definition['default'];

        return self::cast($definition, $value);
    }

    public function isDeviating(string $key): bool
    {
        return $this->version !== null && array_key_exists($key, $this->version->settings ?? [])
            && self::cast(self::definition($key), $this->version->settings[$key]) !== self::cast(self::definition($key), self::default($key));
    }

    /** Afwijking ten nadele van de werknemer? */
    public function isUnfavorable(string $key): bool
    {
        return self::unfavorable(self::definition($key), $this->get($key));
    }

    public static function unfavorable(?array $definition, mixed $value): bool
    {
        if (! $definition || empty($definition['favorable'])) {
            return false;
        }
        $value = self::cast($definition, $value);
        $default = self::cast($definition, $definition['default']);
        if ($definition['type'] === 'bool') {
            return $definition['favorable'] === 'higher' ? ($default && ! $value) : (! $default && $value);
        }

        return $definition['favorable'] === 'higher' ? $value < $default : $value > $default;
    }

    /** Tekst voor de leesversie voor medewerkers, of null. */
    public function employeeText(string $key): ?string
    {
        $definition = self::definition($key);
        $template = $definition['employee'] ?? null;
        if (! $template) {
            return null;
        }
        $value = $this->get($key);
        if ($definition['type'] === 'bool') {
            return $value ? $template : null;
        }
        $shown = match ($definition['type']) {
            'select' => mb_strtolower($definition['options'][$value] ?? (string) $value),
            'decimal' => rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ','),
            default => (string) $value,
        };

        return str_replace(':value', $shown, $template);
    }

    public function employeeNote(string $key): ?string
    {
        return $this->version?->employee_notes[$key] ?? null;
    }

    public static function cast(array $definition, mixed $value): mixed
    {
        return match ($definition['type']) {
            'int', 'weekday' => (int) $value,
            'decimal' => round((float) $value, 2),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }

    // --- Verlofsoorten ---

    /** @return Collection<string, array> sleutel => velden (met 'key', 'custom', 'deviating', 'unfavorable') */
    public function leaveTypes(bool $activeOnly = false): Collection
    {
        $defaults = config('poetsploeg-cao.leave_types');
        $overrides = $this->version?->leave_types ?? [];

        return collect(array_keys($defaults + $overrides))
            ->mapWithKeys(function (string $key) use ($defaults, $overrides) {
                $default = $defaults[$key] ?? null;
                $type = array_merge($default ?? self::emptyLeaveType(), $overrides[$key] ?? []);
                $type['key'] = $key;
                $type['custom'] = $default === null;
                $type['deviating'] = $default !== null && self::leaveTypeDiffers($default, $type);
                $type['unfavorable'] = $default !== null && self::leaveTypeUnfavorable($default, $type);

                return [$key => $type];
            })
            ->when($activeOnly, fn ($c) => $c->filter(fn ($t) => $t['active']));
    }

    public function leaveType(string $key): ?array
    {
        return $this->leaveTypes()->get($key);
    }

    public function leaveTypeName(string $key): string
    {
        return $this->leaveType($key)['name'] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Voor keuzelijsten: [groep => [sleutel => naam]].
     *
     * @param  callable(array): bool|null  $filter
     */
    public function leaveTypeGroups(bool $activeOnly = true, ?callable $filter = null): array
    {
        return $this->leaveTypes($activeOnly)
            ->when($filter, fn ($c) => $c->filter($filter))
            ->groupBy('group')
            ->map(fn ($types) => $types->pluck('name', 'key')->all())
            ->all();
    }

    /** Soorten die je altijd direct registreert, nooit als verzoek (bijv. ziek, calamiteit). */
    public function directTypes(): array
    {
        return $this->leaveTypes()->filter(fn ($t) => $t['direct'])->keys()->values()->all();
    }

    /** Soorten die een medewerker zelf kan aanvragen: [sleutel => naam]. */
    public function requestableTypes(): array
    {
        // Direct geregistreerde soorten alleen als ze op een vrije dag horen (Niet beschikbaar).
        return $this->leaveTypes(true)->filter(fn ($t) => $t['self_request'] && (! $t['direct'] || $t['without_shift']))->pluck('name', 'key')->all();
    }

    public static function emptyLeaveType(): array
    {
        return [
            'name' => '', 'group' => 'Overig', 'counts_as' => 'other', 'paid' => 0, 'paid_by' => 'werkgever', 'unit' => 'hours',
            'max' => null, 'period' => 'event', 'self_request' => false, 'direct' => false, 'min_days_ahead' => 0,
            'refusal' => 'yes', 'proof' => false, 'note_required' => false, 'without_shift' => false,
            'source' => 'bedrijfsbeleid', 'explanation' => '', 'active' => true,
        ];
    }

    /** Velden van een verlofsoort die je kunt instellen, met type. */
    public const LEAVE_FIELDS = [
        'name' => 'string', 'group' => 'string', 'counts_as' => 'string', 'paid' => 'int', 'paid_by' => 'string',
        'unit' => 'string', 'max' => 'nullable_decimal', 'period' => 'string', 'self_request' => 'bool', 'direct' => 'bool',
        'min_days_ahead' => 'int', 'refusal' => 'string', 'proof' => 'bool', 'note_required' => 'bool',
        'without_shift' => 'bool', 'explanation' => 'string', 'active' => 'bool',
    ];

    public static function castLeaveField(string $field, mixed $value): mixed
    {
        return match (self::LEAVE_FIELDS[$field] ?? 'string') {
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'nullable_decimal' => $value === null || $value === '' ? null : round((float) $value, 2),
            default => (string) $value,
        };
    }

    public static function leaveTypeDiffers(array $default, array $type): bool
    {
        foreach (array_keys(self::LEAVE_FIELDS) as $field) {
            if (self::castLeaveField($field, $default[$field] ?? null) !== self::castLeaveField($field, $type[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** Ongunstiger dan de cao/wet: minder betaald, lager maximum, uitgezet, niet meer zelf aanvragen, eerder weigeren, langer vooraf. */
    public static function leaveTypeUnfavorable(array $default, array $type): bool
    {
        $legal = str_contains($default['source'] ?? '', 'cao') || str_contains($default['source'] ?? '', 'Wazo');
        if (! $legal) {
            return false;
        }
        $max = fn ($v) => $v === null || $v === '' ? INF : (float) $v;

        return (int) $type['paid'] < (int) $default['paid']
            || $max($type['max']) < $max($default['max'])
            || ($default['active'] && ! filter_var($type['active'], FILTER_VALIDATE_BOOLEAN))
            || ($default['self_request'] && ! filter_var($type['self_request'], FILTER_VALIDATE_BOOLEAN))
            || (self::REFUSAL_ORDER[$type['refusal']] ?? 2) > (self::REFUSAL_ORDER[$default['refusal']] ?? 2)
            || (int) $type['min_days_ahead'] > (int) $default['min_days_ahead']
            || (! $default['proof'] && filter_var($type['proof'], FILTER_VALIDATE_BOOLEAN));
    }
}
