<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use Honkio\Exception\ApiException;
use Honkio\Exception\UnprocessableEntityException;
use Honkio\Laravel\Mail\HonkioTransport;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

class MailTransportTest extends TestCase
{
    private function mailable(?callable $configure = null): Mailable
    {
        return new class($configure) extends Mailable {
            public function __construct(private $configure)
            {
            }

            public function build(): static
            {
                $this->subject('Hello')->html('<p>Hi</p>')->withSymfonyMessage(fn (Email $e) => $e->text('Hi'));
                if ($this->configure !== null) {
                    ($this->configure)($this);
                }

                return $this;
            }
        };
    }

    public function test_plain_mailable_is_transactional(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_1', 'status' => 'queued']));

        $sent = Mail::to('sam@example.com')->send($this->mailable());

        $body = $this->sentBody();
        $this->assertSame('Acme <hello@example.ca>', $body['from']);
        $this->assertSame(['sam@example.com'], $body['to']);
        $this->assertSame('Hello', $body['subject']);
        $this->assertSame('<p>Hi</p>', $body['html']);
        $this->assertSame('Hi', $body['text']);
        $this->assertArrayNotHasKey('is_commercial', $body);
        $this->assertArrayNotHasKey('cc', $body);
        $this->assertSame('/v1/emails', $this->history[0]['request']->getUri()->getPath());
        $this->assertInstanceOf(SentMessage::class, $sent);
        $this->assertSame('em_1', $sent->getSymfonySentMessage()->getMessageId());
    }

    public function test_commercial_header_becomes_is_commercial_and_is_removed(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_2']));

        Mail::to('sam@example.com')->send($this->mailable(
            fn (Mailable $m) => $m->withSymfonyMessage(
                fn (Email $e) => $e->getHeaders()->addTextHeader(HonkioTransport::COMMERCIAL_HEADER, 'true'),
            ),
        ));

        $body = $this->sentBody();
        $this->assertTrue($body['is_commercial']);
        $headers = $body['headers'] ?? [];
        $this->assertArrayNotHasKey(HonkioTransport::COMMERCIAL_HEADER, $headers);
        $this->assertArrayNotHasKey('X-Honkio-Commercial', $headers);
    }

    public function test_commercial_header_set_to_false_stays_transactional(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_3']));

        Mail::to('sam@example.com')->send($this->mailable(
            fn (Mailable $m) => $m->withSymfonyMessage(
                fn (Email $e) => $e->getHeaders()->addTextHeader(HonkioTransport::COMMERCIAL_HEADER, 'false'),
            ),
        ));

        $body = $this->sentBody();
        $this->assertArrayNotHasKey('is_commercial', $body);
        $this->assertArrayNotHasKey('headers', $body);
    }

    public function test_cc_bcc_reply_to_and_custom_headers_are_mapped(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_4']));

        Mail::to(['a@example.com', 'b@example.com'])
            ->cc('c@example.com')
            ->bcc('d@example.com')
            ->send($this->mailable(
                fn (Mailable $m) => $m->replyTo('r@example.com')->withSymfonyMessage(
                    fn (Email $e) => $e->getHeaders()->addTextHeader('X-Campaign', 'spring'),
                ),
            ));

        $body = $this->sentBody();
        $this->assertSame(['a@example.com', 'b@example.com'], $body['to']);
        $this->assertSame(['c@example.com'], $body['cc']);
        $this->assertSame(['d@example.com'], $body['bcc']);
        $this->assertSame(['r@example.com'], $body['reply_to']);
        $this->assertSame(['X-Campaign' => 'spring'], $body['headers']);
    }

    public function test_attachments_are_base64(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_5']));

        Mail::to('sam@example.com')->send($this->mailable(
            fn (Mailable $m) => $m->attachData('hello pdf', 'a.pdf', ['mime' => 'application/pdf']),
        ));

        $this->assertSame(
            [['filename' => 'a.pdf', 'content' => base64_encode('hello pdf'), 'content_type' => 'application/pdf']],
            $this->sentBody()['attachments'],
        );
    }

    public function test_commercial_mail_to_two_recipients_surfaces_the_apis_refusal(): void
    {
        $this->fakeApi($this->apiJson(
            ['code' => 'EMAIL_COMMERCIAL_MULTI_RECIPIENT', 'message' => 'One recipient only.', 'statusCode' => 422],
            422,
        ));

        try {
            Mail::to(['a@example.com', 'b@example.com'])->send($this->mailable(
                fn (Mailable $m) => $m->withSymfonyMessage(
                    fn (Email $e) => $e->getHeaders()->addTextHeader(HonkioTransport::COMMERCIAL_HEADER, 'true'),
                ),
            ));
            $this->fail('The API refusal was swallowed.');
        } catch (\Throwable $e) {
            while ($e !== null && !$e instanceof ApiException) {
                $e = $e->getPrevious();
            }
            $this->assertInstanceOf(UnprocessableEntityException::class, $e);
            $this->assertSame('EMAIL_COMMERCIAL_MULTI_RECIPIENT', $e->errorCode);
        }

        // It was sent as commercial, never downgraded to transactional.
        $this->assertTrue($this->sentBody()['is_commercial']);
        $this->assertCount(1, $this->history);
    }
}
