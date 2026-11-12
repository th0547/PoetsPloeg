<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Logboek voor teamleiders: wie heeft wanneer persoonsgegevens ingezien of beheeracties gedaan. */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'gebruiker' => ['nullable', 'integer'],
            'actie' => ['nullable', Rule::in(array_keys(AuditLog::ACTIONS))],
            'van' => ['nullable', 'date'],
            'tot' => ['nullable', 'date'],
            'zoek' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->when($filters['gebruiker'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['actie'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($filters['van'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['tot'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($filters['zoek'] ?? null, fn ($q, $t) => $q->where('description', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->latest('created_at')->latest('id')
            ->paginate(100)
            ->withQueryString();

        return view('audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'retention' => (int) config('poetsploeg.audit_retention_months'),
        ]);
    }
}
