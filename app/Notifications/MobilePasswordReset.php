<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MobilePasswordReset extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Reset your MDRRMO password')
            ->line('Open Forgot password in the MDRRMO app and enter this reset code:')
            ->line($this->token)->line('The code expires after 60 minutes. If you did not request this, ignore this message.');
    }
}
