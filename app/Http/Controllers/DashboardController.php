<?php

namespace App\Http\Controllers;

use App\Services\TodayOverview;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vandaag: wie is nu waar, wat komt er nog, en wat vraagt actie (open plekken, verlofverzoeken, afwezigen).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, TodayOverview $overview): View
    {
        $day = rescue(fn () => CarbonImmutable::parse($request->query('datum', 'today')), CarbonImmutable::today(), false);

        return view('dashboard', $overview->build($day));
    }
}
