<?php

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\Api\PlanningApiController;
use App\Http\Controllers\Api\ShiftApiController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\My\MyController;
use App\Http\Controllers\OpenShiftController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecurityTxtController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\TwoFactorResetController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Health-check voor de uitrol: controleert de database. Met ?commit=<sha> zegt hij ook of die commit
// draait (uit de image, zie docker/productie/Dockerfile), zonder de commit zelf te noemen. De
// uitrol-workflow is pas groen als de uitgerolde commit "juist" is.
Route::get('/gezond', HealthController::class)->name('health');

// Contact voor het melden van beveiligingsproblemen (RFC 9116).
Route::get('/.well-known/security.txt', SecurityTxtController::class)->name('security-txt');

Route::middleware(['auth', 'planner'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/planning', PlanningController::class)->name('planning');
    Route::get('/kalender', CalendarController::class)->name('calendar');
    Route::get('/open-diensten', OpenShiftController::class)->name('open-shifts');

    Route::get('/diensten/nieuw', [ShiftController::class, 'create'])->name('shifts.create');
    Route::post('/diensten', [ShiftController::class, 'store'])->name('shifts.store');

    Route::resource('medewerkers', EmployeeController::class)
        ->parameters(['medewerkers' => 'employee'])->names('employees')->except('show');
    Route::post('medewerkers/{employee}/uitnodiging', [EmployeeController::class, 'resendInvitation'])
        ->middleware('throttle:10,1')->name('employees.invitation');
    Route::delete('medewerkers/{employee}/inlog', [EmployeeController::class, 'revokeAccount'])->name('employees.account.destroy');

    Route::resource('panden', BuildingController::class)
        ->parameters(['panden' => 'building'])->names('buildings');

    Route::put('panden/{building}/bezetting', [BuildingController::class, 'updateStaffing'])->name('buildings.staffing');

    Route::resource('panden.taken', TaskTemplateController::class)
        ->parameters(['panden' => 'building', 'taken' => 'template'])
        ->names('buildings.templates')->except(['index', 'show'])->scoped();

    Route::get('logboek', [AuditLogController::class, 'index'])->middleware('can:view-audit-log')->name('audit-log');

    Route::get('hulp/herhaling', [HelpController::class, 'recurrence'])->name('help.recurrence');

    Route::resource('vaardigheden', SkillController::class)
        ->parameters(['vaardigheden' => 'skill'])->names('skills')->only(['index', 'store', 'update', 'destroy']);

    Route::post('gebruikers/{user}/tweestapsverificatie-reset', [TwoFactorResetController::class, 'user'])
        ->middleware('can:reset-two-factor')->name('users.two-factor.reset');
    Route::post('medewerkers/{employee}/tweestapsverificatie-reset', [TwoFactorResetController::class, 'employee'])
        ->middleware('can:reset-two-factor')->name('employees.two-factor.reset');

    Route::resource('gebruikers', UserController::class)
        ->parameters(['gebruikers' => 'user'])->names('users')->except('show');

    Route::post('afwezigheid/{absence}/afwijzen', [AbsenceController::class, 'reject'])->name('absences.reject');
    Route::resource('afwezigheid', AbsenceController::class)
        ->parameters(['afwezigheid' => 'absence'])->names('absences')->only(['index', 'create', 'store', 'show', 'destroy']);

    // JSON-endpoints voor de interactieve planning (sessie-authenticatie).
    Route::prefix('api')->name('api.')->group(function () {
        Route::get('planning', [PlanningApiController::class, 'timeline'])->name('planning');
        Route::post('planning/move', [PlanningApiController::class, 'move'])->name('planning.move');
        Route::get('calendar', [PlanningApiController::class, 'calendar'])->name('calendar');
        Route::post('recurrence/preview', [TaskTemplateController::class, 'preview'])
            ->middleware('throttle:60,1')->name('recurrence.preview');

        Route::get('shifts/{shift}', [ShiftApiController::class, 'show'])->name('shifts.show');
        Route::patch('shifts/{shift}', [ShiftApiController::class, 'update'])->name('shifts.update');
        Route::delete('shifts/{shift}', [ShiftApiController::class, 'destroy'])->name('shifts.destroy');
        Route::get('shifts/{shift}/candidates', [ShiftApiController::class, 'candidates'])->name('shifts.candidates');
        Route::post('shifts/{shift}/assignments', [ShiftApiController::class, 'assign'])->name('shifts.assign');
        Route::delete('shifts/{shift}/assignments/{employee}', [ShiftApiController::class, 'unassign'])->name('shifts.unassign');
    });
});

// Voor medewerkers met een eigen inlog: alleen hun eigen planning, verlof en beschikbaarheid.
Route::middleware(['auth', 'employee'])->prefix('mijn')->name('my.')->group(function () {
    Route::get('/', [MyController::class, 'planning'])->name('planning');
    Route::get('verlof', [MyController::class, 'absences'])->name('absences');
    Route::get('verlof/aanvragen', [MyController::class, 'createAbsence'])->name('absences.create');
    Route::post('verlof', [MyController::class, 'storeAbsence'])->name('absences.store');
    Route::delete('verlof/{absence}', [MyController::class, 'destroyAbsence'])->name('absences.destroy');
    Route::get('beschikbaarheid', [MyController::class, 'availability'])->name('availability');
    Route::put('beschikbaarheid', [MyController::class, 'updateAvailability'])->name('availability.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
