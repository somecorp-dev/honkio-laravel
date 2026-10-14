<?php

declare(strict_types=1);

namespace Honkio\Laravel\Notifications;

final class HonkioMessage
{
    public ?string $from = null;

    public function __construct(public string $content = '')
    {
    }

    public static function create(string $content = ''): self
    {
        return new self($content);
    }

    /** The sending number (E.164). Defaults to config('honkio.sms_from'). */
    public function from(string $e164): self
    {
        $this->from = $e164;

        return $this;
    }

    public function content(string $content): self
    {
        $this->content = $content;

        return $this;
    }
}
