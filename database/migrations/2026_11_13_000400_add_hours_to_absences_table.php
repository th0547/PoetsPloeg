<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Verlofuren (issue #43): de uren die de medewerker in die periode normaal zou werken.
 * Bestaande registraties krijgen hun uren via `php artisan poetsploeg:leave-hours` (schatting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            $table->decimal('hours', 7, 2)->nullable()->after('ends_at');
            $table->unsignedSmallInteger('days')->nullable()->after('hours');
            $table->string('hours_source', 20)->nullable()->after('days'); // dienst | vaste bezetting | contract | gemengd | handmatig | schatting
        });

        // Bestaande registraties: eenmalig schatten. Mislukt dat, dan blijft de migratie gelukt
        // en kan het later met `php artisan poetsploeg:leave-hours`.
        rescue(fn () => Artisan::call('poetsploeg:leave-hours'), report: true);
    }

    public function down(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            $table->dropColumn(['hours', 'days', 'hours_source']);
        });
    }
};
