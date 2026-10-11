<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('users.form', ['user' => new User(['role' => 'teamleider'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        User::create($data);

        return redirect()->route('users.index')->with('status', 'Gebruiker aangemaakt.');
    }

    public function edit(User $user): View
    {
        return view('users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if ($user->is($request->user()) && $data['role'] !== $user->role) {
            return back()->withErrors(['role' => 'Je kunt je eigen rol niet wijzigen.']);
        }
        $user->update($data);

        return redirect()->route('users.index')->with('status', 'Gebruiker bijgewerkt.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'Je kunt jezelf niet verwijderen.']);
        }
        $user->delete();

        return redirect()->route('users.index')->with('status', 'Gebruiker verwijderd.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
        ]);
    }
}
