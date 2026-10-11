<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class CalendarController extends Controller
{
    public function __invoke(): View
    {
        return view('calendar.index');
    }
}
