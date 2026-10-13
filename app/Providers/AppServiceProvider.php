<?php

namespace App\Providers;

use App\Http\Controllers\OpenShiftController;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Teller "Open diensten" in het menu (alleen voor ingelogde planners).
        View::composer('layouts.navigation', function ($view) {
            $view->with('openSlotCount', auth()->user()?->canPlan() ? OpenShiftController::upcomingOpenSlots() : 0);
        });
    }
}
