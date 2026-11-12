<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Melding als tweestapsverificatie van een account aan, uit of gereset wordt. */
class TwoFactorChanged extends Notification
{
    public function __construct(public string $what, public ?string $by = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name', 'PoetsPloeg');

        return (new MailMessage)
            ->subject("Tweestapsverificatie {$this->what} – {$app}")
            ->greeting("Hoi {$notifiable->name},")
            ->line("De tweestapsverificatie van je account is {$this->what}".($this->by ? " door {$this->by}" : '').'.')
            ->line('Was jij dit niet? Neem dan direct contact op met je teamleider.')
            ->salutation("Groet,\n{$app}");
    }
}
