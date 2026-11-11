<?php

namespace App\Providers;

use App\Http\Controllers\OpenShiftController;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
        // Rechten op één plek. Nu hebben planners en teamleiders dezelfde rechten; zodra er een
        // medewerker-login komt, krijgt die alleen wat hier expliciet wordt toegestaan.
        Gate::define('plan', fn (User $user) => $user->canPlan());
        // Telefoonnummers van medewerkers, contactgegevens en toegangsinstructies van panden.
        Gate::define('view-contact-details', fn (User $user) => $user->canPlan());

        // Teller "Open diensten" in het menu (alleen voor ingelogde planners).
        View::composer('layouts.navigation', function ($view) {
            $view->with('openSlotCount', auth()->user()?->canPlan() ? OpenShiftController::upcomingOpenSlots() : 0);
        });
    }
}
