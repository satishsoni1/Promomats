<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the support mailbox when a ticket is raised, and to the requester as an
 * acknowledgement with the reference and first-response target.
 */
class SupportTicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SupportTicket $ticket, public bool $forRequester = false) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $t = $this->ticket->loadMissing('user', 'document');
        $category = SupportTicket::CATEGORIES[$t->category] ?? $t->category;
        $priority = SupportTicket::PRIORITIES[$t->priority] ?? $t->priority;

        $mail = (new MailMessage)
            ->subject("[{$t->reference}] {$t->subject}")
            ->greeting($this->forRequester ? "Hello {$t->user->name}," : 'New helpdesk ticket')
            ->line($this->forRequester
                ? "We've received your request. Our first response is due by {$t->first_response_due_at->format('d M Y, H:i')}."
                : "{$t->user->name} ({$t->user->email}) raised a {$priority} priority ticket: {$category}.")
            ->line("Subject: {$t->subject}")
            ->line($t->description);

        if ($t->document) {
            $mail->line("Document: {$t->document->reference_no} - {$t->document->title}");
        }
        if ($t->page_url) {
            $mail->line("Page: {$t->page_url}");
        }

        return $mail->action('Open Helpdesk', route('helpdesk.index'));
    }
}
