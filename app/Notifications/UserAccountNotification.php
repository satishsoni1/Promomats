<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserAccountNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $event, // account_created | roles_updated
        public ?User $actor = null,
        public ?string $rolesSummary = null,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray($notifiable): array
    {
        return [
            'event' => $this->event,
            'actor' => $this->actor?->name,
            'roles' => $this->rolesSummary,
            'message' => $this->buildMessage(),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->buildSubject())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->buildMessage())
            ->action('Go to VODO', url('/dashboard'))
            ->line('This is an automated notification from VODO.');
    }

    protected function buildSubject(): string
    {
        return match ($this->event) {
            'account_created' => 'Your VODO account has been created',
            'roles_updated' => 'Your VODO roles have been updated',
            default => 'VODO account update',
        };
    }

    protected function buildMessage(): string
    {
        return match ($this->event) {
            'account_created' => "An administrator" . ($this->actor ? " ({$this->actor->name})" : '') . " has created a VODO account for you. Your administrator will share your temporary password separately - please change it after your first login.",
            'roles_updated' => "Your roles have been updated to: {$this->rolesSummary}.",
            default => 'There has been an update to your VODO account.',
        };
    }
}
