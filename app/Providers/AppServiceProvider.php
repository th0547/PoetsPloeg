<?php

namespace App\Providers;

use App\Http\Controllers\OpenShiftController;
use App\Models\User;
use App\Services\Audit;
use App\Support\Policy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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
        // Logboek (issue #23): alleen teamleiders.
        Gate::define('view-audit-log', fn (User $user) => $user->role === 'teamleider');
        // Beleidspagina (issue #42): lezen mag iedereen met planrechten, wijzigen volgens het beleid zelf.
        Gate::define('manage-policy', fn (User $user) => $user->canPlan()
            && (Policy::current()->get('privacy.edit_roles') === 'planners' || $user->role === 'teamleider'));
        // Tweestapsverificatie van een ander account resetten (issue #40): alleen teamleiders.
        Gate::define('reset-two-factor', fn (User $user) => $user->role === 'teamleider');

        // Inloggen en uitloggen in het logboek.
        Event::listen(Login::class, fn (Login $e) => Audit::log('auth.login', "{$e->user->name} ingelogd", $e->user, [], $e->user));
        Event::listen(Logout::class, fn (Logout $e) => $e->user ? Audit::log('auth.logout', "{$e->user->name} uitgelogd", $e->user, [], $e->user) : null);
        Event::listen(Failed::class, fn (Failed $e) => Audit::log('auth.failed', 'Inloggen mislukt', $e->user,
            ['email' => mb_substr((string) ($e->credentials['email'] ?? ''), 0, 255)]));

        // Teller "Open diensten" in het menu (alleen voor ingelogde planners).
        View::composer('layouts.navigation', function ($view) {
            $view->with('openSlotCount', auth()->user()?->canPlan() ? OpenShiftController::upcomingOpenSlots() : 0);
        });
    }
}
