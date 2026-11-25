<?php

use App\Http\Controllers\Api\EmployeeApiController;
use App\Http\Controllers\Api\PlanningApiController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\ShiftApiController;
use App\Http\Controllers\Api\V1\AbsenceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\OpenShiftController;
use App\Http\Controllers\Api\V1\TodayController;
use App\Http\Middleware\EnsureAppAccess;
use Illuminate\Support\Facades\Route;

/*
| API voor de app van de planners: /api/v1/... met een token (Laravel Sanctum), zie docs/api.md.
| Waar het kan, gebruikt de app dezelfde controllers als het roosterbord op de website.
*/

Route::name('api.v1.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');
    Route::post('auth/two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:10,1')->name('auth.two-factor');

    Route::middleware(['auth:sanctum', EnsureAppAccess::class, 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::get('today', TodayController::class)->name('today');
        Route::get('open-shifts', OpenShiftController::class)->name('open-shifts');

        Route::get('planning', [PlanningApiController::class, 'timeline'])->name('planning');
        Route::post('planning/move', [PlanningApiController::class, 'move'])->name('planning.move');
        Route::get('search', SearchController::class)->name('search');
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/{employee}', [EmployeeApiController::class, 'show'])->name('employees.show');

        Route::get('shifts/{shift}', [ShiftApiController::class, 'show'])->name('shifts.show');
        Route::patch('shifts/{shift}', [ShiftApiController::class, 'update'])->name('shifts.update');
        Route::delete('shifts/{shift}', [ShiftApiController::class, 'destroy'])->name('shifts.destroy');
        Route::get('shifts/{shift}/candidates', [ShiftApiController::class, 'candidates'])->name('shifts.candidates');
        Route::post('shifts/{shift}/assignments', [ShiftApiController::class, 'assign'])->name('shifts.assign');
        Route::delete('shifts/{shift}/assignments/{employee}', [ShiftApiController::class, 'unassign'])->name('shifts.unassign');

        Route::get('leave-types', [AbsenceController::class, 'leaveTypes'])->name('leave-types');
        Route::get('absences', [AbsenceController::class, 'index'])->name('absences.index');
        Route::post('absences', [AbsenceController::class, 'store'])->name('absences.store');
        Route::get('absences/{absence}', [AbsenceController::class, 'show'])->name('absences.show');
        Route::post('absences/{absence}/reject', [AbsenceController::class, 'reject'])->name('absences.reject');
        Route::delete('absences/{absence}', [AbsenceController::class, 'destroy'])->name('absences.destroy');
    });
});
