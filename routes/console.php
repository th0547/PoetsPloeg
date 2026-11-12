<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\Schedule;

// Vul elke nacht de planning aan tot N weken vooruit (zie config/poetsploeg.php).
Schedule::command('poetsploeg:generate-shifts')->dailyAt('02:15');

// Elk uur: wie al 2 diensten op een pand heeft gedraaid, kent dat pand (zie config/poetsploeg.php).
Schedule::command('poetsploeg:learn-buildings')->hourly();

// Elk uur: medewerkers vragen hun beschikbaarheid te bevestigen (dag en tijd staan in het beleid).
Schedule::command('poetsploeg:availability-reminders')->hourlyAt(0);

// Elke nacht: oude regels uit het logboek opruimen (zie config/poetsploeg.php, audit_retention_months).
Schedule::command('model:prune', ['--model' => [AuditLog::class]])->dailyAt('03:10');
