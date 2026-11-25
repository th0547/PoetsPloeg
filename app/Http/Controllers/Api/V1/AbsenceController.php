<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AbsenceResource;
use App\Http\Resources\V1\ShiftResource;
use App\Models\Absence;
use App\Services\AbsenceRegistration;
use App\Services\AbsenceService;
use App\Support\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Afwezigheid en verlofverzoeken in de app: uitval melden, vervangers zoeken (via shifts/{id}/candidates),
 * verzoeken afwijzen. Zelfde regels als de website (AbsenceRegistration, AbsenceService).
 */
class AbsenceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Absence::STATUSES))],
            'period' => ['nullable', Rule::in(['current', 'past', 'all'])],
            'employee_id' => ['nullable', 'integer'],
        ]);
        $period = $filters['period'] ?? 'current';

        $absences = Absence::query()->with('employee')->withCount('shifts')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($period === 'current', fn ($q) => $q->where('ends_at', '>=', now()->startOfDay()))
            ->when($period === 'past', fn ($q) => $q->where('ends_at', '<', now()->startOfDay()))
            ->orderBy('starts_at', $period === 'current' ? 'asc' : 'desc')
            ->paginate(50);

        return AbsenceResource::collection($absences);
    }

    public function store(Request $request, AbsenceRegistration $registration): JsonResponse
    {
        $data = $request->validate(...AbsenceRegistration::rules());
        ['absences' => $absences, 'message' => $message] = $registration->register($data);
        $absences->each->load('employee');

        return response()->json([
            'message' => $message,
            'absences' => AbsenceResource::collection($absences),
        ], 201);
    }

    /** Het vervangscherm: per dienst de stand; vervangers zoek je met shifts/{id}/candidates. */
    public function show(Absence $absence, AbsenceRegistration $registration): JsonResponse
    {
        $absence->load('employee');
        ['rows' => $rows, 'linked' => $linked] = $registration->replacements($absence);
        $absence->loadCount('shifts');

        return response()->json([
            'absence' => AbsenceResource::make($absence),
            'linked' => $linked,
            'shifts' => $rows->map(fn (array $row) => [
                'state' => $row['state'],
                'shift' => new ShiftResource($row['shift']),
            ])->values(),
        ]);
    }

    public function reject(Absence $absence, AbsenceService $service): JsonResponse
    {
        abort_unless($absence->isPending(), 422, 'Alleen een openstaand verlofverzoek kan worden afgewezen.');
        $service->reject($absence);

        return response()->json([
            'message' => 'Verlofverzoek afgewezen. '.$absence->employee->name.' blijft ingepland op de diensten die nog niet vervangen waren.',
            'absence' => AbsenceResource::make($absence->load('employee')),
        ]);
    }

    /** Intrekken: telt niet meer mee, blijft bewaard in de geschiedenis (zoals op de website). */
    public function destroy(Absence $absence): JsonResponse
    {
        $absence->delete();

        return response()->json([
            'message' => 'Afwezigheid ingetrokken. Controleer de planning: eerder vrijgemaakte diensten worden niet automatisch teruggezet.',
        ]);
    }

    /** Soorten afwezigheid uit het beleid, voor het formulier "Uitval melden". */
    public function leaveTypes(): JsonResponse
    {
        $direct = Policy::current()->directTypes();

        return response()->json([
            'leave_types' => Policy::current()->leaveTypes(true)->map(fn (array $t) => [
                'key' => $t['key'],
                'name' => $t['name'],
                'group' => $t['group'] ?? null,
                // Direct geregistreerde soorten (bijv. ziek) kunnen geen verzoek zijn.
                'can_be_request' => ! in_array($t['key'], $direct, true),
            ])->values(),
        ]);
    }
}
