<?php

declare(strict_types=1);

namespace Honkio\Laravel\Mail;

use Honkio\Exception\ApiException;
use Honkio\Exception\ConnectionException;
use Honkio\Exception\InternalServerException;
use Honkio\Exception\InvalidArgumentException;
use Honkio\Honkio;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Sends Laravel mail through POST /v1/emails.
 *
 * Mail is transactional unless the message carries the header
 * X-HonkIO-Commercial: true, which is removed and sent as is_commercial.
 * An API refusal (for example EMAIL_COMMERCIAL_MULTI_RECIPIENT) propagates as
 * the SDK's exception.
 */
class HonkioTransport extends AbstractTransport
{
    public const COMMERCIAL_HEADER = 'X-HonkIO-Commercial';

    /** Headers the message model sets or HonkIO sets itself. */
    private const OWN_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'sender', 'return-path',
        'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding',
    ];

    public function __construct(private readonly Honkio $client)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $params = $this->payload($email);

        try {
            $result = $this->client->emails->send($params);
        } catch (ConnectionException | InternalServerException $e) {
            // Transient failures: Symfony's failover and round-robin
            // transports only move on from a TransportException.
            throw new TransportException($e->getMessage(), $e->getCode(), $e);
        }
        // Other API refusals (4xx, e.g. EMAIL_COMMERCIAL_MULTI_RECIPIENT)
        // propagate unchanged: another transport would refuse them too.

        $id = $result['id'] ?? null;
        if (is_string($id) && $id !== '') {
            $message->setMessageId($id);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Email $email): array
    {
        $params = [];

        $from = $email->getFrom();
        if ($from !== []) {
            $params['from'] = $from[0]->getName() !== ''
                ? $from[0]->getName() . ' <' . $from[0]->getAddress() . '>'
                : $from[0]->getAddress();
        }
        $recipients = [
            'to' => $email->getTo(),
            'cc' => $email->getCc(),
            'bcc' => $email->getBcc(),
            'reply_to' => $email->getReplyTo(),
        ];
        foreach ($recipients as $field => $addresses) {
            if ($addresses !== []) {
                $params[$field] = array_map(static fn (Address $a): string => $a->getAddress(), $addresses);
            }
        }
        if ($email->getSubject() !== null) {
            $params['subject'] = $email->getSubject();
        }
        if ($email->getHtmlBody() !== null) {
            $params['html'] = (string) $email->getHtmlBody();
        }
        if ($email->getTextBody() !== null) {
            $params['text'] = (string) $email->getTextBody();
        }

        $headers = [];
        $commercial = false;
        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();
            if (strcasecmp($name, self::COMMERCIAL_HEADER) === 0) {
                $commercial = $this->parseCommercial($header->getBodyAsString());
                continue;
            }
            if (in_array(strtolower($name), self::OWN_HEADERS, true)) {
                continue;
            }
            // The decoded value, so non-ASCII text is not sent MIME-encoded
            // or folded. A repeated header name keeps its last value.
            $headers[$name] = $header instanceof UnstructuredHeader ? $header->getValue() : $header->getBodyAsString();
        }
        if ($headers !== []) {
            $params['headers'] = $headers;
        }
        if ($commercial) {
            $params['is_commercial'] = true;
        }

        $attachments = array_map(fn (DataPart $part): array => $this->attachment($part, (string) $email->getHtmlBody()), $email->getAttachments());
        if ($attachments !== []) {
            $params['attachments'] = $attachments;
        }

        return $params;
    }

    /**
     * Only an explicit "true" or "false": anything else would silently send a
     * marketing message as transactional, skipping the consent check.
     */
    private function parseCommercial(string $value): bool
    {
        return match (strtolower(trim($value))) {
            'true' => true,
            'false' => false,
            default => throw new InvalidArgumentException(
                self::COMMERCIAL_HEADER . ' must be "true" or "false", got "' . $value . '".'
            ),
        };
    }

    /** @return array<string, string> */
    private function attachment(DataPart $part, string $html): array
    {
        $out = [
            'filename' => $part->getFilename() ?? 'attachment',
            'content' => base64_encode($part->getBody()),
            'content_type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
        ];
        if ($part->getPreparedHeaders()->getHeaderBody('Content-Disposition') === 'inline') {
            // An inline name the HTML references as cid:<name> is the id the
            // HTML expects; otherwise use the part's own generated id.
            $name = $part->getFilename();
            $referenced = $name !== null
                && preg_match('/^[A-Za-z0-9._@-]{1,100}$/', $name) === 1
                && str_contains($html, 'cid:' . $name);
            $out['content_id'] = $referenced ? $name : $part->getContentId();
        }

        return $out;
    }

    public function __toString(): string
    {
        return 'honkio';
    }
}
