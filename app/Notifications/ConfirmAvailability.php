<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Wekelijkse vraag om de beschikbaarheid van een week te bevestigen (issue #45). */
class ConfirmAvailability extends Notification
{
    public function __construct(public CarbonImmutable $weekStart, public bool $reminder = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name', 'PoetsPloeg');
        $week = 'week '.$this->weekStart->isoWeek().' ('.$this->weekStart->translatedFormat('j M').' – '.$this->weekStart->addDays(6)->translatedFormat('j M').')';

        return (new MailMessage)
            ->subject(($this->reminder ? 'Herinnering: ' : '')."Bevestig je beschikbaarheid voor {$week}")
            ->greeting("Hoi {$notifiable->name},")
            ->line("Wil je je beschikbaarheid voor {$week} controleren en bevestigen? Dan kan de planner het rooster op tijd rond maken.")
            ->action('Beschikbaarheid bevestigen', route('my.availability').'#bevestigen')
            ->line('Klopt er iets niet? Pas je weekpatroon aan of geef een dag door als "Niet beschikbaar".')
            ->salutation("Groet,\n{$app}");
    }
}
