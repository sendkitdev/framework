<?php

namespace Illuminate\Tests\Mail;

use Exception;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\SendKitTransport;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use SendKit\Client;
use SendKit\Emails;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailSendKitTransportTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testGetTransport(): void
    {
        $container = new Container;

        $container->singleton('config', function () {
            return new Repository([
                'services' => [
                    'sendkit' => [
                        'key' => 'sk_test_123',
                    ],
                ],
            ]);
        });

        $manager = new MailManager($container);

        $transport = $manager->createSymfonyTransport(['transport' => 'sendkit']);

        $this->assertInstanceOf(SendKitTransport::class, $transport);

        $this->assertSame('sendkit', (string) $transport);
    }

    public function testGetTransportWithLocalKey(): void
    {
        $container = new Container;

        $container->singleton('config', function () {
            return new Repository([
                'services' => [
                    'sendkit' => [
                        'key' => 'sk_services_key',
                    ],
                ],
            ]);
        });

        $manager = new MailManager($container);

        // When key is provided in the mailer config, it takes precedence.
        $transport = $manager->createSymfonyTransport([
            'transport' => 'sendkit',
            'key' => 'sk_local_key',
        ]);

        $this->assertInstanceOf(SendKitTransport::class, $transport);
    }

    public function testSend(): void
    {
        $message = new Email;
        $message->subject('Test subject');
        $message->html('<p>Hello</p>');
        $message->text('Hello');
        $message->sender('sender@example.com');
        $message->to('recipient@example.com');
        $message->cc('cc@example.com');
        $message->bcc('bcc@example.com');
        $message->replyTo(new Address('reply@example.com', 'Reply'));
        $message->getHeaders()->add(new MetadataHeader('campaign', 'welcome'));

        $capturedPayload = null;

        $emails = m::mock(Emails::class);
        $emails->shouldReceive('send')
            ->once()
            ->withArgs(function ($payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(['id' => 'sendkit-message-id']);

        $client = m::mock(Client::class);
        $client->shouldReceive('emails')->andReturn($emails);

        (new SendKitTransport($client))->send($message);

        $this->assertSame('sender@example.com', $capturedPayload['from']);
        $this->assertSame(['recipient@example.com'], $capturedPayload['to']);
        $this->assertSame('Test subject', $capturedPayload['subject']);
        $this->assertSame('<p>Hello</p>', $capturedPayload['html']);
        $this->assertSame('Hello', $capturedPayload['text']);
        $this->assertSame(['cc@example.com'], $capturedPayload['cc']);
        $this->assertSame(['bcc@example.com'], $capturedPayload['bcc']);
        $this->assertSame(['"Reply" <reply@example.com>'], $capturedPayload['reply_to']);
        $this->assertSame([['name' => 'campaign', 'value' => 'welcome']], $capturedPayload['tags']);
        $this->assertArrayNotHasKey('scheduled_at', $capturedPayload);
        $this->assertArrayNotHasKey('attachments', $capturedPayload);
    }

    public function testSendHtmlOnly(): void
    {
        $message = new Email;
        $message->subject('Test');
        $message->html('<p>Hello</p>');
        $message->sender('sender@example.com');
        $message->to('recipient@example.com');

        $capturedPayload = null;

        $emails = m::mock(Emails::class);
        $emails->shouldReceive('send')
            ->once()
            ->withArgs(function ($payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(['id' => 'html-id']);

        $client = m::mock(Client::class);
        $client->shouldReceive('emails')->andReturn($emails);

        (new SendKitTransport($client))->send($message);

        $this->assertSame('<p>Hello</p>', $capturedPayload['html']);
        $this->assertArrayNotHasKey('text', $capturedPayload);
    }

    public function testSendTextOnly(): void
    {
        $message = new Email;
        $message->subject('Test');
        $message->text('Hello');
        $message->sender('sender@example.com');
        $message->to('recipient@example.com');

        $capturedPayload = null;

        $emails = m::mock(Emails::class);
        $emails->shouldReceive('send')
            ->once()
            ->withArgs(function ($payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(['id' => 'text-id']);

        $client = m::mock(Client::class);
        $client->shouldReceive('emails')->andReturn($emails);

        (new SendKitTransport($client))->send($message);

        $this->assertSame('Hello', $capturedPayload['text']);
        $this->assertArrayNotHasKey('html', $capturedPayload);
    }

    public function testSendWithMultipleRecipients(): void
    {
        $message = new Email;
        $message->subject('Test subject');
        $message->text('Hello');
        $message->sender('sender@example.com');
        $message->to('one@example.com', 'two@example.com');

        $capturedPayload = null;

        $emails = m::mock(Emails::class);
        $emails->shouldReceive('send')
            ->once()
            ->withArgs(function ($payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(['data' => [['id' => 'first-id'], ['id' => 'second-id']]]);

        $client = m::mock(Client::class);
        $client->shouldReceive('emails')->andReturn($emails);

        (new SendKitTransport($client))->send($message);

        $this->assertSame(['one@example.com', 'two@example.com'], $capturedPayload['to']);
        $this->assertArrayNotHasKey('cc', $capturedPayload);
        $this->assertArrayNotHasKey('bcc', $capturedPayload);
    }

    public function testSendWithNamedAddresses(): void
    {
        $message = new Email;
        $message->subject('Test');
        $message->text('Hello');
        $message->from(new Address('sender@example.com', 'Sender Name'));
        $message->to(new Address('recipient@example.com', 'Recipient'));

        $capturedPayload = null;

        $emails = m::mock(Emails::class);
        $emails->shouldReceive('send')
            ->once()
            ->withArgs(function ($payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(['id' => 'named-id']);

        $client = m::mock(Client::class);
        $client->shouldReceive('emails')->andReturn($emails);

        (new SendKitTransport($client))->send($message);

        $this->assertSame('"Sender Name" <sender@example.com>', $capturedPayload['from']);
        $this->assertSame(['"Recipient" <recipient@example.com>'], $capturedPayload['to']);
    }

    public function testSendWithScheduledAt(): void
    {
        $message = new Email;
        $message->subject('Scheduled');
        $message->text('Hello');
        $message->sender('sender@example.com');
        $message->to('recipient@example.com');
        $message->getHeaders()->addTextHeader('X-SendKit-Scheduled-At', '2026-12-25T10:00:00Z');

        $capturedPayload = null;

        $emails = m::mock(Emails::class);
        $emails->shouldReceive('send')
            ->once()
            ->withArgs(function ($payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(['id' => 'scheduled-id']);

        $client = m::mock(Client::class);
        $client->shouldReceive('emails')->andReturn($emails);

        (new SendKitTransport($client))->send($message);

        $this->assertSame('2026-12-25T10:00:00Z', $capturedPayload['scheduled_at']);
        $this->assertArrayNotHasKey('X-SendKit-Scheduled-At', $capturedPayload['headers'] ?? []);
    }

    public function testSendError(): void
    {
        $message = new Email;
        $message->subject('Test');
        $message->text('Hello');
        $message->sender('sender@example.com');
        $message->to('recipient@example.com');

        $emails = m::mock(Emails::class);
        $emails->shouldReceive('send')
            ->once()
            ->andThrow(new Exception('Invalid API key'));

        $client = m::mock(Client::class);
        $client->shouldReceive('emails')->andReturn($emails);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Request to SendKit API failed. Reason: Invalid API key.');

        (new SendKitTransport($client))->send($message);
    }
}
