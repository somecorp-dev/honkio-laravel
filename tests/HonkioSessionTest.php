<?php

declare(strict_types=1);

namespace Honkio\Laravel\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Honkio\Laravel\Auth\HonkioSession;
use Honkio\Laravel\Auth\HonkioUser;
use Illuminate\Foundation\Auth\User as EloquentUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * The honkio.auth middleware and the honkio guard apply the outage rule:
 * signed-in users stay signed in while Honkio can't be reached (for at most
 * 24 hours past the access token's expiry), and are signed out as soon as
 * Honkio refuses the refresh.
 */
class HonkioSessionTest extends TestCase
{
    private AuthFixture $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->issuer = new AuthFixture();

        Route::middleware(['web', 'honkio.auth'])->get('/private', fn () => request()->attributes->get('honkio_auth_offline') ? 'offline' : 'ok');
        Route::middleware(['web', 'auth:honkio'])->get('/guarded', fn () => Auth::guard('honkio')->user()?->getAuthIdentifier());
        Route::get('/login', fn () => 'sign in')->name('login');
    }

    /** @return array<string, mixed> the session holding a token set that expired $ago seconds ago (negative: still valid) */
    private function signedIn(int $ago): array
    {
        return [HonkioSession::SESSION_KEY => [
            'tokens' => [
                'access_token' => 'old.access.token',
                'refresh_token' => 'hka_rt_old',
                'id_token' => 'old.id.token',
                'expires_at' => time() - $ago,
                'sub' => 'usr_1',
                'scope' => 'openid email',
            ],
            'claims' => ['sub' => 'usr_1', 'email' => 'ada@example.ca', 'org_id' => 'org_1'],
        ]];
    }

    private function down(): ConnectException
    {
        return new ConnectException('Connection refused', new Request('POST', AuthFixture::ISSUER . '/token'));
    }

    public function test_the_middleware_is_registered(): void
    {
        $this->assertArrayHasKey('honkio.auth', $this->app['router']->getMiddleware());
    }

    public function test_no_session_redirects_to_login_and_json_gets_401(): void
    {
        $this->fakeIssuer();
        $this->get('/private')->assertRedirect('/login');
        $this->getJson('/private')->assertStatus(401);
    }

    public function test_an_unexpired_token_passes_without_calling_honkio(): void
    {
        $this->fakeIssuer();
        $this->withSession($this->signedIn(-300))->get('/private')->assertOk()->assertSeeText('ok');
        $this->assertCount(0, $this->issuerHistory);
    }

    public function test_an_expired_token_is_refreshed_and_the_new_one_stored(): void
    {
        $this->fakeIssuer($this->issuer->discovery(), $this->issuer->tokenResponse(['id_token' => $this->issuer->idToken(['nonce' => null, 'email' => 'new@example.ca'])]), $this->issuer->jwks());

        $this->withSession($this->signedIn(60))->get('/private')->assertOk()->assertSeeText('ok');

        $stored = session(HonkioSession::SESSION_KEY);
        $this->assertSame('hka_rt_new', $stored['tokens']['refresh_token']);
        $this->assertGreaterThan(time(), $stored['tokens']['expires_at']);
        $this->assertSame('new@example.ca', $stored['claims']['email']);
        parse_str((string) $this->issuerHistory[1]['request']->getBody(), $form);
        $this->assertSame('hka_rt_old', $form['refresh_token']);
    }

    public function test_an_outage_keeps_the_user_signed_in(): void
    {
        $this->fakeIssuer($this->down());
        $this->withSession($this->signedIn(3600))->get('/private')->assertOk()->assertSeeText('offline');
        $this->assertNotNull(session(HonkioSession::SESSION_KEY));
    }

    public function test_a_5xx_is_an_outage_too(): void
    {
        $this->fakeIssuer($this->issuer->discovery(), new Response(503));
        $this->withSession($this->signedIn(60))->get('/private')->assertOk()->assertSeeText('offline');
    }

    public function test_an_outage_past_24_hours_signs_the_user_out(): void
    {
        $this->fakeIssuer($this->down());
        $this->withSession($this->signedIn(86400 + 60))->get('/private')->assertRedirect('/login');
        $this->assertNull(session(HonkioSession::SESSION_KEY));
    }

    public function test_a_refused_refresh_signs_the_user_out_at_once(): void
    {
        $this->fakeIssuer($this->issuer->discovery(), AuthFixture::json(['error' => 'invalid_grant'], 400));
        $this->withSession($this->signedIn(5))->get('/private')->assertRedirect('/login');
        $this->assertNull(session(HonkioSession::SESSION_KEY));
    }

    public function test_a_refused_refresh_also_logs_out_the_logout_guard(): void
    {
        $this->app['config']->set('honkio.auth.logout_guard', 'web');
        $this->app['config']->set('auth.providers.users.model', EloquentUser::class);
        $this->fakeIssuer($this->issuer->discovery(), AuthFixture::json(['error' => 'invalid_grant'], 400));

        $local = new EloquentUser();
        $local->forceFill(['id' => 7]);
        $this->actingAs($local, 'web');
        $this->withSession($this->signedIn(5))->get('/private')->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_the_honkio_guard_returns_the_claims_user(): void
    {
        $this->fakeIssuer();
        $this->withSession($this->signedIn(-300))->get('/guarded')->assertOk()->assertSeeText('usr_1');

        $user = Auth::guard('honkio')->user();
        $this->assertInstanceOf(HonkioUser::class, $user);
        $this->assertSame('ada@example.ca', $user->email());
        $this->assertSame('org_1', $user->organizationId());
        $this->assertSame('ada@example.ca', $user->email);
    }

    public function test_the_honkio_guard_has_no_user_after_a_refusal(): void
    {
        $this->fakeIssuer($this->issuer->discovery(), AuthFixture::json(['error' => 'invalid_grant'], 400));
        $this->withSession($this->signedIn(5))->getJson('/guarded')->assertStatus(401);
    }

    public function test_the_guard_is_registered_unless_the_app_defines_one(): void
    {
        $this->assertSame(['driver' => 'honkio'], $this->app['config']->get('auth.guards.honkio'));
        $this->assertFalse(Auth::guard('honkio')->validate(['email' => 'a@b.ca']));
    }

    public function test_logout_url_carries_the_id_token_hint(): void
    {
        $this->fakeIssuer($this->issuer->discovery());
        $this->app['session.store']->put(HonkioSession::SESSION_KEY, $this->signedIn(-300)[HonkioSession::SESSION_KEY]);

        $url = $this->app->make(HonkioSession::class)->logoutUrl('https://app.example.ca/');
        $this->assertSame(
            'https://login.example.ca/logout?client_id=hka_client_1&id_token_hint=old.id.token&post_logout_redirect_uri=https%3A%2F%2Fapp.example.ca%2F',
            $url,
        );
    }

    public function test_tokens_never_reach_a_cookie(): void
    {
        $this->fakeIssuer();
        $response = $this->withSession($this->signedIn(-300))->get('/private');
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertStringNotContainsString('old.access.token', (string) $cookie->getValue());
        }
    }
}
