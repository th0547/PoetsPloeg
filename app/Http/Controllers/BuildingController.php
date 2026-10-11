<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BuildingController extends Controller
{
    public function index(): View
    {
        $buildings = Building::query()
            ->withCount(['taskTemplates', 'employees'])
            ->orderByDesc('active')->orderBy('name')
            ->get();

        return view('buildings.index', compact('buildings'));
    }

    public function create(): View
    {
        return view('buildings.form', [
            'building' => new Building(['active' => true, 'color' => sprintf('#%06x', random_int(0x222222, 0xcccccc))]),
            'employees' => Employee::active()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $building = Building::create($this->validated($request));
        $building->employees()->sync($request->input('employees', []));

        return redirect()->route('buildings.show', $building)->with('status', 'Pand aangemaakt. Voeg nu de vaste taken toe.');
    }

    public function show(Building $building): View
    {
        $building->load(['taskTemplates.skills', 'taskTemplates.employees', 'employees']);

        $upcoming = Shift::query()->active()
            ->where('building_id', $building->id)
            ->where('starts_at', '>=', now()->startOfDay())
            ->with('assignments.employee')
            ->orderBy('starts_at')
            ->limit(15)
            ->get();

        return view('buildings.show', compact('building', 'upcoming'));
    }

    public function edit(Building $building): View
    {
        return view('buildings.form', [
            'building' => $building->load('employees'),
            'employees' => Employee::where(fn ($q) => $q->where('active', true)->orWhereIn('id', $building->employees->pluck('id')))
                ->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Building $building): RedirectResponse
    {
        $building->update($this->validated($request));
        $building->employees()->sync($request->input('employees', []));

        return redirect()->route('buildings.show', $building)->with('status', 'Pand opgeslagen.');
    }

    public function destroy(Building $building): RedirectResponse
    {
        $building->delete();

        return redirect()->route('buildings.index')->with('status', 'Pand verwijderd.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'access_instructions' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'employees' => ['array'],
            'employees.*' => ['exists:employees,id'],
        ]);
        unset($data['employees']);
        $data['active'] = $request->boolean('active');

        return $data;
    }
}
