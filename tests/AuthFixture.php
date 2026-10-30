<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Honkio\Auth\Jwt;

/** A fake Honkio Auth issuer for the Laravel tests: a real P-256 key and tokens signed with it, timed from now. */
final class AuthFixture
{
    public const ISSUER = 'https://login.example.ca';
    public const CLIENT_ID = 'hka_client_1';
    public const KID = 'key_1';

    public readonly \OpenSSLAsymmetricKey $key;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('openssl could not make a P-256 key');
        }
        $this->key = $key;
    }

    public function discovery(): Response
    {
        $iss = self::ISSUER;

        return self::json([
            'issuer' => $iss,
            'authorization_endpoint' => "$iss/authorize",
            'token_endpoint' => "$iss/token",
            'userinfo_endpoint' => "$iss/userinfo",
            'jwks_uri' => "$iss/.well-known/jwks.json",
            'end_session_endpoint' => "$iss/logout",
        ]);
    }

    public function jwks(): Response
    {
        $details = openssl_pkey_get_details($this->key);
        $pad = static fn (string $b): string => str_pad($b, 32, "\x00", STR_PAD_LEFT);

        return self::json(['keys' => [[
            'kty' => 'EC', 'crv' => 'P-256', 'use' => 'sig', 'alg' => 'ES256', 'kid' => self::KID,
            'x' => Jwt::b64urlEncode($pad($details['ec']['x'])),
            'y' => Jwt::b64urlEncode($pad($details['ec']['y'])),
        ]]]);
    }

    /** @param array<string, mixed> $override */
    public function idToken(array $override = []): string
    {
        $now = time();

        return $this->sign(array_merge([
            'iss' => self::ISSUER, 'sub' => 'usr_1', 'aud' => self::CLIENT_ID, 'iat' => $now, 'exp' => $now + 600,
            'sid' => 'ses_1', 'amr' => ['otp'], 'email' => 'ada@example.ca', 'email_verified' => true, 'nonce' => 'n-1',
        ], $override), ['alg' => 'ES256', 'kid' => self::KID]);
    }

    /** @param array<string, mixed> $override */
    public function accessToken(array $override = []): string
    {
        $now = time();

        return $this->sign(array_merge([
            'iss' => self::ISSUER, 'sub' => 'usr_1', 'aud' => self::CLIENT_ID, 'client_id' => self::CLIENT_ID,
            'scope' => 'openid email', 'sid' => 'ses_1', 'iat' => $now, 'exp' => $now + 600,
        ], $override), ['alg' => 'ES256', 'kid' => self::KID, 'typ' => 'at+jwt']);
    }

    /** @param array<string, mixed> $override */
    public function tokenResponse(array $override = []): Response
    {
        return self::json(array_merge([
            'access_token' => $this->accessToken(),
            'id_token' => $this->idToken(),
            'refresh_token' => 'hka_rt_new',
            'token_type' => 'Bearer',
            'expires_in' => 600,
            'scope' => 'openid email',
        ], $override));
    }

    /** @param array<mixed> $body */
    public static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header
     */
    private function sign(array $claims, array $header): string
    {
        $input = Jwt::b64urlEncode(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . Jwt::b64urlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($input, $der, $this->key, OPENSSL_ALGO_SHA256);
        $offset = 2;
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $raw .= str_pad(ltrim(substr($der, $offset + 2, $len), "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $input . '.' . Jwt::b64urlEncode($raw);
    }
}
