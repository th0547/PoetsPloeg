<?php

use Illuminate\Support\Facades\Schedule;

// Vul elke nacht de planning aan tot N weken vooruit (zie config/poetsploeg.php).
Schedule::command('poetsploeg:generate-shifts')->dailyAt('02:15');

// Elk uur: wie al 2 diensten op een pand heeft gedraaid, kent dat pand (zie config/poetsploeg.php).
Schedule::command('poetsploeg:learn-buildings')->hourly();
