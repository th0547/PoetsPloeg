<?php

namespace App\Http\Controllers;

use App\Models\AvailabilityPattern;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Skill;
use App\Services\Audit;
use App\Services\AvailabilityEnforcer;
use App\Services\EmployeeAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function __construct(private AvailabilityEnforcer $enforcer, private EmployeeAccounts $accounts) {}

    public function index(Request $request): View
    {
        $employees = Employee::query()
            ->with(['skills:id,name', 'buildings:id,name', 'user'])
            ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->when(! $request->boolean('inactief'), fn ($q) => $q->where('active', true))
            ->orderBy('name')
            ->get();

        return view('employees.index', compact('employees'));
    }

    public function create(): View
    {
        return $this->form(new Employee(['active' => true, 'color' => sprintf('#%06x', random_int(0x222222, 0xCCCCCC))]));
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = DB::transaction(fn () => $this->save(new Employee, $request));
        $message = 'Medewerker aangemaakt.'.$this->accountMessage($employee, $request);

        return redirect()->route('employees.edit', $employee)->with('status', $message);
    }

    /** Na opslaan: medewerker van diensten buiten de (nieuwe) beschikbaarheid halen. */
    private function releaseMessage(Employee $employee, string $message): string
    {
        $released = $this->enforcer->release($employee->fresh());
        if ($released->isEmpty()) {
            return $message;
        }

        return $message.sprintf(' %s is van %d %s gehaald die buiten de beschikbaarheid vallen; die staan nu bij Open diensten.',
            $employee->name, $released->count(), $released->count() === 1 ? 'dienst' : 'diensten');
    }

    public function edit(Employee $employee): View
    {
        Audit::viewed($employee, "Medewerker {$employee->name}: contactgegevens en verlofgeschiedenis");

        return $this->form($employee);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        DB::transaction(fn () => $this->save($employee, $request));
        $message = 'Medewerker opgeslagen.'.$this->accountMessage($employee, $request);

        return redirect()->route('employees.edit', $employee)->with('status', $this->releaseMessage($employee, $message));
    }

    /** Na opslaan: account aanmaken (uitnodiging) of naam/e-mail van het bestaande account bijwerken. */
    private function accountMessage(Employee $employee, Request $request): string
    {
        $employee->load('user');
        if (! $employee->user && $request->boolean('invite')) {
            Audit::log('account.invited', "Inlog voor {$employee->name} aangemaakt en uitnodiging gestuurd", $employee);

            return $this->accounts->invite($employee)
                ? " Er is een uitnodiging gestuurd naar {$employee->email} om een wachtwoord in te stellen."
                : ' Het account is aangemaakt, maar de uitnodiging kon niet worden verstuurd (mailserver). Probeer het later opnieuw met "Opnieuw uitnodigen".';
        }

        return $this->accounts->sync($employee)
            ? " De uitnodiging is opnieuw verstuurd naar {$employee->email}."
            : '';
    }

    /** Uitnodiging opnieuw versturen, bijv. als de link verlopen is. */
    public function resendInvitation(Employee $employee): RedirectResponse
    {
        $user = $employee->user;
        abort_unless($user, 404);
        if (! $this->accounts->sendInvitation($user)) {
            return redirect()->route('employees.edit', $employee)
                ->withErrors(['invite' => 'De uitnodiging kon niet worden verstuurd: de mailserver is niet bereikbaar of weigert de mail. Probeer het later opnieuw.']);
        }

        return redirect()->route('employees.edit', $employee)
            ->with('status', "Nieuwe uitnodiging gestuurd naar {$user->email}. Een eerdere link werkt niet meer.");
    }

    /** Inlog intrekken: het account verdwijnt, de medewerker zelf en de geschiedenis blijven. */
    public function revokeAccount(Employee $employee): RedirectResponse
    {
        abort_unless($employee->user, 404);
        $employee->user->delete();
        Audit::log('account.revoked', "Inlog van {$employee->name} ingetrokken", $employee);

        return redirect()->route('employees.edit', $employee)
            ->with('status', "De inlog van {$employee->name} is ingetrokken. Je kunt later een nieuwe uitnodiging sturen.");
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        DB::transaction(function () use ($employee) {
            $employee->user?->delete();
            $employee->delete();
        });
        Audit::log('employee.deleted', "Medewerker {$employee->name} verwijderd (inclusief inlog en geschiedenis)", $employee);

        return redirect()->route('employees.index')->with('status', 'Medewerker verwijderd.');
    }

    private function form(Employee $employee): View
    {
        $employee->loadMissing(['skills', 'buildings', 'availabilityPatterns', 'user']);
        $absences = $employee->exists ? $employee->absences()->get() : collect();

        return view('employees.form', [
            'employee' => $employee,
            'skills' => Skill::orderBy('name')->get(),
            'buildings' => Building::orderBy('name')->get(),
            'patterns' => old('patterns', $employee->availabilityRows()),
            'absenceBlocks' => $employee->absenceBlocks($absences),
            // Lopende en geplande afwezigheid, en daaronder de volledige geschiedenis (nieuwste eerst).
            'absences' => $absences->where('ends_at', '>=', now())->values(),
            'absenceHistory' => $absences->where('ends_at', '<', now())->sortByDesc('starts_at')->values(),
        ]);
    }

    private function save(Employee $employee, Request $request): Employee
    {
        // Met een (nieuw) inlogaccount is het e-mailadres de inlognaam: verplicht en uniek.
        $account = $employee->user;
        $needsLogin = $account || $request->boolean('invite');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => array_filter([
                $needsLogin ? 'required' : 'nullable', 'email', 'max:255',
                $needsLogin ? Rule::unique('users', 'email')->ignore($account) : null,
            ]),
            'invite' => ['boolean'],
            'phone' => ['nullable', 'string', 'max:50'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'contract_hours_min' => ['nullable', 'numeric', 'min:0', 'max:80'],
            'contract_hours_max' => array_filter(['nullable', 'numeric', 'min:0', 'max:80', $request->filled('contract_hours_min') ? 'gte:contract_hours_min' : null]),
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['boolean'],
            'skills' => ['array'],
            'skills.*' => ['exists:skills,id'],
            'buildings' => ['array'],
            'buildings.*' => ['exists:buildings,id'],
            ...AvailabilityPattern::rules(),
        ], [
            'email.required' => 'Vul een e-mailadres in: daarmee logt de medewerker in.',
            'email.unique' => 'Dit e-mailadres is al in gebruik door een ander account.',
        ], [
            'contract_hours_max' => 'max. contracturen',
            'contract_hours_min' => 'min. contracturen',
        ]);

        $employee->fill(collect($data)->except(['skills', 'buildings', 'patterns', 'invite'])->all());
        $employee->active = $request->boolean('active');
        $employee->save();

        $employee->skills()->sync($data['skills'] ?? []);
        $employee->buildings()->sync($data['buildings'] ?? []);
        $employee->replaceAvailability($data['patterns'] ?? []);

        return $employee;
    }
}
