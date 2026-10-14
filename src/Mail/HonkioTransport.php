<?php

declare(strict_types=1);

namespace Honkio\Laravel\Mail;

use Honkio\Honkio;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
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

        $result = $this->client->emails->send($this->payload($email));

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
                $commercial = strtolower(trim($header->getBodyAsString())) === 'true';
                continue;
            }
            if (in_array(strtolower($name), self::OWN_HEADERS, true)) {
                continue;
            }
            $headers[$name] = $header->getBodyAsString();
        }
        if ($headers !== []) {
            $params['headers'] = $headers;
        }
        if ($commercial) {
            $params['is_commercial'] = true;
        }

        $attachments = array_map($this->attachment(...), $email->getAttachments());
        if ($attachments !== []) {
            $params['attachments'] = $attachments;
        }

        return $params;
    }

    /** @return array<string, string> */
    private function attachment(DataPart $part): array
    {
        $out = [
            'filename' => $part->getFilename() ?? 'attachment',
            'content' => base64_encode($part->getBody()),
            'content_type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
        ];
        if ($part->getPreparedHeaders()->getHeaderBody('Content-Disposition') === 'inline') {
            $out['content_id'] = $part->getContentId();
        }

        return $out;
    }

    public function __toString(): string
    {
        return 'honkio';
    }
}
