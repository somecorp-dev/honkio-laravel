<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Honkio\Exception\ApiException;
use Honkio\Exception\ConnectionException;
use Honkio\Exception\InternalServerException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Exception\UnprocessableEntityException;
use Honkio\Laravel\Mail\HonkioTransport;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
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

    public function test_commercial_header_is_case_insensitive(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_c']));

        Mail::to('sam@example.com')->send($this->mailable(
            fn (Mailable $m) => $m->withSymfonyMessage(
                fn (Email $e) => $e->getHeaders()->addTextHeader(HonkioTransport::COMMERCIAL_HEADER, ' TRUE '),
            ),
        ));

        $this->assertTrue($this->sentBody()['is_commercial']);
    }

    public function test_an_unrecognised_commercial_value_throws_before_sending(): void
    {
        $this->fakeApi();

        try {
            Mail::to('sam@example.com')->send($this->mailable(
                fn (Mailable $m) => $m->withSymfonyMessage(
                    fn (Email $e) => $e->getHeaders()->addTextHeader(HonkioTransport::COMMERCIAL_HEADER, '1'),
                ),
            ));
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('X-HonkIO-Commercial', $e->getMessage());
            $this->assertStringContainsString('"true" or "false"', $e->getMessage());
        }
        $this->assertCount(0, $this->history);
    }

    public function test_inline_image_referenced_by_name_keeps_that_name_as_content_id(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_i']));

        Mail::to('sam@example.com')->send(new class extends Mailable {
            public function build(): static
            {
                return $this->subject('Logo')->html('<img src="cid:logo">')->withSymfonyMessage(
                    fn (Email $e) => $e->embed('PNGDATA', 'logo', 'image/png'),
                );
            }
        });

        $att = $this->sentBody()['attachments'][0];
        $this->assertSame('logo', $att['content_id']);
        $this->assertSame(base64_encode('PNGDATA'), $att['content']);
    }

    public function test_laravel_embed_helper_content_id_matches_the_html(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_e']));

        Mail::send([], [], function ($message) {
            $cid = $message->embedData('PNGDATA', 'logo.png', 'image/png');
            $message->to('sam@example.com')->subject('Logo')->html('<img src="' . $cid . '">');
        });

        $body = $this->sentBody();
        $cid = $body['attachments'][0]['content_id'];
        $this->assertStringContainsString('cid:' . $cid, $body['html']);
    }

    public function test_inline_part_not_referenced_by_name_keeps_a_generated_content_id(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_g']));

        Mail::to('sam@example.com')->send(new class extends Mailable {
            public function build(): static
            {
                return $this->subject('Logo')->html('<p>none</p>')->withSymfonyMessage(
                    fn (Email $e) => $e->embed('PNGDATA', 'logo', 'image/png'),
                );
            }
        });

        $this->assertNotSame('logo', $this->sentBody()['attachments'][0]['content_id']);
    }

    public function test_non_ascii_custom_header_is_sent_decoded(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_h']));
        $value = 'Café crème ' . str_repeat('très bien ', 12);

        Mail::to('sam@example.com')->send($this->mailable(
            fn (Mailable $m) => $m->withSymfonyMessage(fn (Email $e) => $e->getHeaders()->addTextHeader('X-Note', $value)),
        ));

        $this->assertSame($value, $this->sentBody()['headers']['X-Note']);
    }

    public function test_duplicate_custom_header_sends_the_last_value(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_d']));

        Mail::to('sam@example.com')->send($this->mailable(
            fn (Mailable $m) => $m->withSymfonyMessage(function (Email $e) {
                $e->getHeaders()->addTextHeader('X-Tag', 'first');
                $e->getHeaders()->addTextHeader('X-Tag', 'second');
            }),
        ));

        $this->assertSame(['X-Tag' => 'second'], $this->sentBody()['headers']);
    }

    public function test_text_only_and_html_only_mailables(): void
    {
        $this->fakeApi($this->apiJson(['id' => 'em_t']), $this->apiJson(['id' => 'em_u']));

        Mail::send([], [], function ($message) {
            $message->to('sam@example.com')->subject('T')->text('only text');
        });
        Mail::to('sam@example.com')->send(new class extends Mailable {
            public function build(): static
            {
                return $this->subject('H')->html('<p>only html</p>');
            }
        });

        $text = $this->sentBody(0);
        $this->assertSame('only text', $text['text']);
        $this->assertArrayNotHasKey('html', $text);
        $html = $this->sentBody(1);
        $this->assertSame('<p>only html</p>', $html['html']);
        $this->assertArrayNotHasKey('text', $html);
    }

    public function test_server_errors_are_wrapped_in_a_transport_exception(): void
    {
        $this->fakeApi($this->apiJson(['code' => 'INTERNAL', 'message' => 'boom', 'statusCode' => 500], 500));

        try {
            Mail::to('sam@example.com')->send($this->mailable());
            $this->fail('Expected TransportException');
        } catch (TransportException $e) {
            $this->assertInstanceOf(InternalServerException::class, $e->getPrevious());
        }
    }

    public function test_connection_failures_are_wrapped_in_a_transport_exception(): void
    {
        $this->fakeApi(new ConnectException('timed out', new Request('POST', 'https://api.honkio.ca/v1/emails')));

        try {
            Mail::to('sam@example.com')->send($this->mailable());
            $this->fail('Expected TransportException');
        } catch (TransportException $e) {
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }
}
