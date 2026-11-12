<?php

namespace App\Http\Controllers;

use App\Support\RecurrenceAiPrompt;
use Illuminate\View\View;

/** Hulppagina's voor gevorderde functies. */
class HelpController extends Controller
{
    /** Voorbeelden op de hulppagina "Eigen herhaling met een code". */
    public const RECURRENCE_EXAMPLES = [
        ['FREQ=WEEKLY;INTERVAL=2;BYDAY=TH', 'Om de week op donderdag'],
        ['FREQ=WEEKLY;BYDAY=MO,WE,FR', 'Elke week op maandag, woensdag en vrijdag'],
        ['FREQ=MONTHLY;BYDAY=1MO', 'Elke maand op de eerste maandag'],
        ['FREQ=MONTHLY;BYDAY=-1FR', 'Elke maand op de laatste vrijdag'],
        ['FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1', 'Elke maand op de laatste werkdag'],
        ['FREQ=MONTHLY;INTERVAL=3;BYMONTHDAY=15', 'Elk kwartaal op de 15e'],
        ['FREQ=MONTHLY;BYMONTHDAY=-1', 'Elke maand op de laatste dag'],
        ['FREQ=YEARLY;BYMONTH=3,9;BYDAY=1MO', 'In maart en september op de eerste maandag'],
        ['FREQ=DAILY;INTERVAL=3', 'Elke drie dagen'],
        ['FREQ=WEEKLY;BYDAY=TU;COUNT=6', 'Zes dinsdagen achter elkaar, daarna stoppen'],
        ['FREQ=WEEKLY;BYDAY=SA;UNTIL=20270331', 'Elke zaterdag tot en met 31 maart 2027'],
    ];

    public function recurrence(): View
    {
        return view('help.recurrence', [
            'examples' => self::RECURRENCE_EXAMPLES,
            'aiPrompt' => RecurrenceAiPrompt::base(),
            'aiMaxWish' => RecurrenceAiPrompt::MAX_WISH,
        ]);
    }
}
