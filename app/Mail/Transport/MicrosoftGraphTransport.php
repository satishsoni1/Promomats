<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends mail through Microsoft Graph's /users/{sender}/sendMail endpoint using an
 * app-only (client credentials) OAuth2 token, instead of SMTP - used because
 * outbound mail for this tenant goes through Graph rather than SMTP AUTH.
 * Registered as the 'graph' mail transport (see config/mail.php and
 * MailConfigServiceProvider::boot(), which calls Mail::extend()).
 *
 * $ccAll, when set, is appended as a CC on every message this transport sends -
 * a standing "is mail actually going out" check inbox, not per-notification
 * behaviour, so it lives here rather than in each Notification class.
 */
class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(
        protected string $tenantId,
        protected string $clientId,
        protected string $clientSecret,
        protected string $sender,
        protected ?string $ccAll = null,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();

        if (! $email instanceof Email) {
            throw new TransportException('The Microsoft Graph transport only supports Symfony Email messages.');
        }

        $ccAddresses = $email->getCc();
        if ($this->ccAll && ! collect($ccAddresses)->contains(fn (Address $a) => strcasecmp($a->getAddress(), $this->ccAll) === 0)) {
            $ccAddresses[] = new Address($this->ccAll);
        }

        $payload = [
            'message' => array_filter([
                'subject' => (string) $email->getSubject(),
                'body' => $this->bodyPayload($email),
                'toRecipients' => $this->recipients($email->getTo()),
                'ccRecipients' => $this->recipients($ccAddresses),
                'bccRecipients' => $this->recipients($email->getBcc()),
                'from' => $this->recipient(new Address($this->sender)),
                'attachments' => $this->attachments($email),
            ]),
            'saveToSentItems' => true,
        ];

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post('https://graph.microsoft.com/v1.0/users/' . rawurlencode($this->sender) . '/sendMail', $payload);

        if ($response->failed()) {
            throw new TransportException("Microsoft Graph sendMail failed ({$response->status()}): {$response->body()}");
        }
    }

    /**
     * App-only OAuth2 token via the client credentials grant, cached for most of
     * its lifetime so a burst of outgoing mail doesn't request a fresh token per
     * message.
     */
    protected function accessToken(): string
    {
        return Cache::remember('ms_graph_mail_token', now()->addMinutes(50), function () {
            $response = Http::asForm()->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);

            if ($response->failed()) {
                throw new TransportException("Microsoft Graph token request failed ({$response->status()}): {$response->body()}");
            }

            return $response->json('access_token');
        });
    }

    protected function bodyPayload(Email $email): array
    {
        if ($html = $email->getHtmlBody()) {
            return ['contentType' => 'HTML', 'content' => $html];
        }

        return ['contentType' => 'Text', 'content' => (string) $email->getTextBody()];
    }

    /**
     * @param  Address[]  $addresses
     */
    protected function recipients(array $addresses): array
    {
        return array_values(array_map(fn (Address $a) => $this->recipient($a), $addresses));
    }

    protected function recipient(Address $address): array
    {
        return ['emailAddress' => array_filter([
            'address' => $address->getAddress(),
            'name' => $address->getName() ?: null,
        ])];
    }

    protected function attachments(Email $email): array
    {
        return collect($email->getAttachments())->map(fn ($attachment) => [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $attachment->getFilename() ?? 'attachment',
            'contentType' => $attachment->getMediaType() . '/' . $attachment->getMediaSubtype(),
            'contentBytes' => base64_encode($attachment->getBody()),
        ])->values()->all();
    }

    public function __toString(): string
    {
        return 'graph';
    }
}
