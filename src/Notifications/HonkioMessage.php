<?php

declare(strict_types=1);

namespace Honkio\Laravel\Notifications;

final class HonkioMessage
{
    public ?string $from = null;
    /** A stored template's id or alias, sent in place of the text; see template(). */
    public ?string $template = null;
    /** @var array<string, string|int|float> */
    public array $variables = [];
    /** 'en' or 'fr'; null for the template's default language. */
    public ?string $language = null;

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

    /**
     * Send a stored template's SMS part instead of content: its id or alias,
     * values for its variables, and its language ('en' or 'fr', null for the
     * default). A language without an SMS part uses the default language's.
     *
     * @param array<string, string|int|float> $variables
     */
    public function template(string $idOrAlias, array $variables = [], ?string $language = null): self
    {
        $this->template = $idOrAlias;
        $this->variables = $variables;
        $this->language = $language;

        return $this;
    }
}
