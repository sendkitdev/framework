<?php

namespace Illuminate\Mail\Transport;

use Exception;
use SendKit\Client;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class SendKitTransport extends AbstractTransport
{
    /**
     * Create a new SendKit transport instance.
     */
    public function __construct(protected Client $client)
    {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     *
     * @throws \Symfony\Component\Mailer\Exception\TransportException
     * @throws \Throwable
     */
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $envelope = $message->getEnvelope();

        $headers = [];
        $tags = [];

        $headersToBypass = ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type', 'x-sendkit-scheduled-at'];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if ($header instanceof MetadataHeader) {
                $tags[] = ['name' => $header->getKey(), 'value' => $header->getValue()];

                continue;
            }

            if (in_array($name, $headersToBypass, true)) {
                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        $attachments = [];

        if ($email->getAttachments()) {
            foreach ($email->getAttachments() as $attachment) {
                $attachmentHeaders = $attachment->getPreparedHeaders();
                $contentType = $attachmentHeaders->get('Content-Type')->getBody();
                $filename = $attachmentHeaders->getHeaderParameter('Content-Disposition', 'filename');

                if ($contentType == 'text/calendar') {
                    $content = $attachment->getBody();
                } else {
                    $content = str_replace("\r\n", '', $attachment->bodyToString());
                }

                $attachments[] = [
                    'content_type' => $contentType,
                    'content' => $content,
                    'filename' => $filename,
                ];
            }
        }

        $payload = [
            'from' => $envelope->getSender()->toString(),
            'to' => $this->stringifyAddresses($this->getRecipients($email, $envelope)),
            'subject' => $email->getSubject(),
        ];

        if ($email->getHtmlBody()) {
            $payload['html'] = $email->getHtmlBody();
        }

        if ($email->getTextBody()) {
            $payload['text'] = $email->getTextBody();
        }

        if ($email->getCc()) {
            $payload['cc'] = $this->stringifyAddresses($email->getCc());
        }

        if ($email->getBcc()) {
            $payload['bcc'] = $this->stringifyAddresses($email->getBcc());
        }

        if ($email->getReplyTo()) {
            $payload['reply_to'] = $this->stringifyAddresses($email->getReplyTo());
        }

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        if ($tags !== []) {
            $payload['tags'] = $tags;
        }

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        $scheduledAt = $email->getHeaders()->get('X-SendKit-Scheduled-At');

        if ($scheduledAt) {
            $payload['scheduled_at'] = $scheduledAt->getBodyAsString();
        }

        try {
            $result = $this->client->emails()->send($payload);
        } catch (Exception $exception) {
            throw new TransportException(
                sprintf('Request to SendKit API failed. Reason: %s.', $exception->getMessage()),
                is_int($exception->getCode()) ? $exception->getCode() : 0,
                $exception
            );
        }

        $messageId = $result['id'] ?? $result['data'][0]['id'] ?? null;

        if ($messageId) {
            $email->getHeaders()->addHeader('X-SendKit-Email-Id', $messageId);
        }
    }

    /**
     * Get the recipients without CC or BCC.
     */
    protected function getRecipients(Email $email, Envelope $envelope): array
    {
        return array_filter($envelope->getRecipients(), function (Address $address) use ($email) {
            return in_array($address, array_merge($email->getCc(), $email->getBcc()), true) === false;
        });
    }

    /**
     * Get the string representation of the transport.
     */
    public function __toString(): string
    {
        return 'sendkit';
    }
}
