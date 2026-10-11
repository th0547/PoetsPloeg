<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PlanningController extends Controller
{
    public function __invoke(): View
    {
        return view('planning.index');
    }
}
