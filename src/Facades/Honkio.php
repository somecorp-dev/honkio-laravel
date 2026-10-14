<?php

declare(strict_types=1);

namespace Honkio\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The shared HonkIO client. The client exposes its resources as properties,
 * so the facade hands them out as argument-free methods:
 * `Honkio::messages()->send([...])`, `Honkio::emails()->send([...])`.
 *
 * @method static \Honkio\Resource\Emails emails()
 * @method static \Honkio\Resource\Messages messages()
 * @method static \Honkio\Resource\Batch batch()
 * @method static \Honkio\Resource\Domains domains()
 * @method static \Honkio\Resource\Suppressions suppressions()
 * @method static \Honkio\Resource\Templates templates()
 * @method static \Honkio\Resource\PhoneNumbers phoneNumbers()
 * @method static \Honkio\Resource\Consents consents()
 * @method static \Honkio\Resource\Webhooks webhooks()
 *
 * @see \Honkio\Honkio
 */
class Honkio extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Honkio\Honkio::class;
    }

    /**
     * @param string $method
     * @param array<mixed> $args
     */
    public static function __callStatic($method, $args)
    {
        $client = static::getFacadeRoot();
        if (is_object($client) && $args === [] && property_exists($client, $method)) {
            return $client->{$method};
        }

        return parent::__callStatic($method, $args);
    }
}
