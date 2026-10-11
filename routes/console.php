<?php

use Illuminate\Support\Facades\Schedule;

// Vul elke nacht de planning aan tot N weken vooruit (zie config/novon.php).
Schedule::command('novon:generate-shifts')->dailyAt('02:15');
