<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SkillController extends Controller
{
    public function index(): View
    {
        return view('skills.index', ['skills' => Skill::withCount('employees')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:skills,name']]);
        Skill::create($data);

        return back()->with('status', 'Vaardigheid toegevoegd.');
    }

    public function update(Request $request, Skill $skill): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('skills', 'name')->ignore($skill)]]);
        $skill->update($data);

        return back()->with('status', 'Vaardigheid bijgewerkt.');
    }

    public function destroy(Skill $skill): RedirectResponse
    {
        $skill->delete();

        return back()->with('status', 'Vaardigheid verwijderd.');
    }
}
