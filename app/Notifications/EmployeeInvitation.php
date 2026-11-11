<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Uitnodiging voor een nieuwe medewerker: met de link stelt die zelf een wachtwoord in. */
class EmployeeInvitation extends Notification
{
    public function __construct(public string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name', 'PoetsPloeg');
        $url = route('invitation.show', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)
            ->subject("Je account voor {$app}")
            ->greeting("Hoi {$notifiable->name},")
            ->line("Er is een account voor je aangemaakt in {$app}. Daar zie je je eigen planning, vraag je verlof aan en geef je je beschikbaarheid door.")
            ->action('Wachtwoord instellen', $url)
            ->line('Je logt daarna in met dit e-mailadres: '.$notifiable->getEmailForPasswordReset())
            ->line('Deze link is 7 dagen geldig. Is hij verlopen? Vraag je teamleider om een nieuwe uitnodiging.')
            ->salutation("Groet,\n{$app}");
    }
}
