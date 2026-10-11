<?php

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\Api\PlanningApiController;
use App\Http\Controllers\Api\ShiftApiController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeployWebhookController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/deploy/webhook', DeployWebhookController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware('throttle:10,1')
    ->name('deploy.webhook');

Route::middleware(['auth', 'planner'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/planning', PlanningController::class)->name('planning');
    Route::get('/kalender', CalendarController::class)->name('calendar');

    Route::get('/diensten/nieuw', [ShiftController::class, 'create'])->name('shifts.create');
    Route::post('/diensten', [ShiftController::class, 'store'])->name('shifts.store');

    Route::resource('medewerkers', EmployeeController::class)
        ->parameters(['medewerkers' => 'employee'])->names('employees')->except('show');

    Route::resource('panden', BuildingController::class)
        ->parameters(['panden' => 'building'])->names('buildings');

    Route::resource('panden.taken', TaskTemplateController::class)
        ->parameters(['panden' => 'building', 'taken' => 'template'])
        ->names('buildings.templates')->except(['index', 'show'])->scoped();

    Route::resource('vaardigheden', SkillController::class)
        ->parameters(['vaardigheden' => 'skill'])->names('skills')->only(['index', 'store', 'update', 'destroy']);

    Route::resource('gebruikers', UserController::class)
        ->parameters(['gebruikers' => 'user'])->names('users')->except('show');

    Route::resource('afwezigheid', AbsenceController::class)
        ->parameters(['afwezigheid' => 'absence'])->names('absences')->only(['index', 'create', 'store', 'show', 'destroy']);

    // JSON-endpoints voor de interactieve planning (sessie-authenticatie).
    Route::prefix('api')->name('api.')->group(function () {
        Route::get('planning', [PlanningApiController::class, 'timeline'])->name('planning');
        Route::post('planning/move', [PlanningApiController::class, 'move'])->name('planning.move');
        Route::get('calendar', [PlanningApiController::class, 'calendar'])->name('calendar');

        Route::get('shifts/{shift}', [ShiftApiController::class, 'show'])->name('shifts.show');
        Route::patch('shifts/{shift}', [ShiftApiController::class, 'update'])->name('shifts.update');
        Route::delete('shifts/{shift}', [ShiftApiController::class, 'destroy'])->name('shifts.destroy');
        Route::get('shifts/{shift}/candidates', [ShiftApiController::class, 'candidates'])->name('shifts.candidates');
        Route::post('shifts/{shift}/assignments', [ShiftApiController::class, 'assign'])->name('shifts.assign');
        Route::delete('shifts/{shift}/assignments/{employee}', [ShiftApiController::class, 'unassign'])->name('shifts.unassign');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
