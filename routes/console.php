<?php

use Illuminate\Support\Facades\Schedule;

// Vul elke nacht de planning aan tot N weken vooruit (zie config/novon.php).
Schedule::command('novon:generate-shifts')->dailyAt('02:15');

// Elk uur: wie al 2 diensten op een pand heeft gedraaid, kent dat pand (zie config/novon.php).
Schedule::command('novon:learn-buildings')->hourly();
