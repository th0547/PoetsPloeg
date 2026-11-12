<?php

namespace App\Console\Commands;

use App\Models\AvailabilityWeek;
use App\Models\Employee;
use App\Notifications\ConfirmAvailability;
use App\Services\WeekAvailability;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Elk uur: op de ingestelde dag en tijd een mail "bevestig je beschikbaarheid" voor de doelweek, en na
 * X dagen één herinnering. Alleen voor actieve medewerkers met een (geactiveerde) inlog en zolang de
 * week niet is bevestigd (issue #45).
 */
class AvailabilityReminders extends Command
{
    protected $signature = 'poetsploeg:availability-reminders {--force : Nu versturen, ongeacht dag en tijd}';

    protected $description = 'Medewerkers vragen hun beschikbaarheid te bevestigen (en herinneren)';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $policy = Policy::at($now);
        if (! $policy->get('availability.confirm_weekly')) {
            $this->line('Beschikbaarheid bevestigen staat uit in het beleid.');

            return self::SUCCESS;
        }

        $week = WeekAvailability::targetWeek($now);
        $sendFirst = $this->option('force') || ($now->dayOfWeekIso === $policy->get('availability.confirm_mail_weekday')
            && $now->format('H') === substr($policy->get('availability.confirm_mail_time'), 0, 2));
        $sent = $reminded = 0;

        $employees = Employee::active()
            ->whereHas('user', fn ($q) => $q->whereNotNull('email_verified_at'))
            ->with('user')
            ->get();

        foreach ($employees as $employee) {
            // whereDate: SQLite bewaart de datum met tijd.
            $row = AvailabilityWeek::where('employee_id', $employee->id)->whereDate('week_start', $week)->first()
                ?? new AvailabilityWeek(['employee_id' => $employee->id, 'week_start' => $week->toDateString()]);
            if ($row->exists && $row->isConfirmed()) {
                continue;
            }
            if ($sendFirst && ! $row->mailed_at) {
                $this->notify($employee, new ConfirmAvailability($week));
                $row->mailed_at = $now;
                $row->save();
                $sent++;

                continue;
            }
            if ($row->mailed_at && ! $row->reminded_at && $row->mailed_at->lte($now->subDays($policy->get('availability.confirm_reminder_days')))) {
                $this->notify($employee, new ConfirmAvailability($week, reminder: true));
                $row->reminded_at = $now;
                $row->save();
                $reminded++;
            }
        }

        $this->info("Week van {$week->format('d-m-Y')}: {$sent} gevraagd, {$reminded} herinnerd.");

        return self::SUCCESS;
    }

    private function notify(Employee $employee, ConfirmAvailability $notification): void
    {
        rescue(fn () => $employee->user->notify($notification), report: true);
    }
}
