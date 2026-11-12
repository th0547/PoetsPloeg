<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'users' => User::whereIn('role', User::STAFF_ROLES)->orderBy('name')->get(),
            'employeeAccounts' => User::where('role', 'medewerker')->count(),
        ]);
    }

    public function create(): View
    {
        return view('users.form', ['user' => new User(['role' => 'teamleider'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = User::create($data);
        Audit::log('user.created', "Gebruiker {$user->name} ({$user->roleLabel()}) aangemaakt", $user);

        return redirect()->route('users.index')->with('status', 'Gebruiker aangemaakt.');
    }

    public function edit(User $user): View|RedirectResponse
    {
        return $this->employeeAccountRedirect($user) ?? view('users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        if ($redirect = $this->employeeAccountRedirect($user)) {
            return $redirect;
        }
        $data = $this->validated($request, $user);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if ($user->is($request->user()) && $data['role'] !== $user->role) {
            return back()->withErrors(['role' => 'Je kunt je eigen rol niet wijzigen.']);
        }
        $user->fill($data);
        $changed = array_keys(array_diff_key($user->getDirty(), array_flip(['remember_token'])));
        $user->save();
        if ($changed) {
            Audit::log('user.updated', "Gebruiker {$user->name} gewijzigd", $user,
                ['velden' => array_values(array_map(fn ($f) => $f === 'password' ? 'wachtwoord' : $f, $changed))]);
        }

        return redirect()->route('users.index')->with('status', 'Gebruiker bijgewerkt.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'Je kunt jezelf niet verwijderen.']);
        }
        if ($redirect = $this->employeeAccountRedirect($user)) {
            return $redirect;
        }
        $user->delete();
        Audit::log('user.deleted', "Gebruiker {$user->name} ({$user->roleLabel()}) verwijderd", $user);

        return redirect()->route('users.index')->with('status', 'Gebruiker verwijderd.');
    }

    /** Medewerker-accounts horen bij een medewerker en worden daar beheerd (uitnodigen, intrekken). */
    private function employeeAccountRedirect(User $user): ?RedirectResponse
    {
        if (! $user->isEmployee()) {
            return null;
        }

        return $user->employee
            ? redirect()->route('employees.edit', $user->employee)->with('status', 'Een medewerker-account beheer je bij de medewerker, onder "Inloggen".')
            : redirect()->route('users.index');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::in(User::STAFF_ROLES)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
        ]);
    }
}
