<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\McpUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The MCP endpoint is the only way in, and it costs CPU on every call, so what may reach it
 * is worth testing as carefully as what it answers.
 */
class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();

        // Passport signs access tokens with a key pair, which is not in version control.
        if (! file_exists(Passport::keyPath('oauth-private.key'))) {
            $this->artisan('passport:keys')->assertSuccessful();
        }
    }

    /**
     * A JSON-RPC call to the MCP server, shaped the way an assistant sends one.
     *
     * @param  array<string, mixed>  $params
     * @return TestResponse<Response>
     */
    private function mcp(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ], ['Accept' => 'application/json, text/event-stream']);
    }

    #[Test]
    public function the_tools_are_not_reachable_without_a_token(): void
    {
        $this->mcp('tools/list')->assertUnauthorized();
    }

    /** A token is not enough: it has to have been granted the scope for this server. */
    #[Test]
    public function a_token_without_the_scope_is_refused(): void
    {
        $user = McpUser::query()->findOrFail(User::factory()->create()->id);

        Passport::actingAs($user, [], 'api');
        $this->mcp('tools/list')->assertForbidden();

        Passport::actingAs($user, ['mcp:use'], 'api');
        $this->mcp('tools/list')
            ->assertOk()
            ->assertSee('evaluate-position')
            ->assertSee('analyse-game');
    }

    #[Test]
    public function it_publishes_the_discovery_documents_a_client_needs(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertOk()
            ->assertJsonStructure(['resource', 'authorization_servers']);

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonStructure(['issuer', 'authorization_endpoint', 'token_endpoint']);
    }

    /** Only the authorization code flow is wanted; the device flow is turned off. */
    #[Test]
    public function the_flows_that_are_not_used_are_not_published(): void
    {
        $this->get('/oauth/device')->assertNotFound();
        $this->post('/oauth/device/code')->assertNotFound();
    }

    #[Test]
    public function a_client_may_only_be_sent_back_to_an_allowed_domain(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Somewhere else',
            'redirect_uris' => ['https://example.com/callback'],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');

        $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::CALLBACK],
        ])->assertCreated();
    }

    #[Test]
    public function consent_requires_signing_in_first(): void
    {
        $clientId = (string) $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::CALLBACK],
        ])->assertCreated()->json('client_id');

        // Clients registered here are public, so PKCE is required: without a challenge the
        // request is refused before anyone is asked to sign in.
        $verifier = Str::random(64);

        $authorize = route('passport.authorizations.authorize', [
            'client_id' => $clientId,
            'redirect_uri' => self::CALLBACK,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'claude-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        $this->get($authorize)->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get($authorize)
            ->assertOk()
            ->assertSee('Claude')
            ->assertSee('wants to use your account');
    }

    /**
     * The whole handshake, as an assistant performs it: register, consent, exchange the code
     * for a token with the PKCE verifier, and call a tool with it. Every piece above is
     * tested on its own; this is the one that proves they fit together.
     */
    #[Test]
    public function a_client_can_go_from_registration_to_calling_a_tool(): void
    {
        $clientId = (string) $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::CALLBACK],
        ])->assertCreated()->json('client_id');

        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->actingAs(User::factory()->create())
            ->get(route('passport.authorizations.authorize', [
                'client_id' => $clientId,
                'redirect_uri' => self::CALLBACK,
                'response_type' => 'code',
                'scope' => 'mcp:use',
                'state' => 'claude-state',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]))->assertOk();

        $callback = (string) $this->post(route('passport.authorizations.approve'), [
            'state' => 'claude-state',
            'client_id' => $clientId,
            'auth_token' => session('authToken'),
        ])->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::CALLBACK.'?', $callback);
        parse_str((string) parse_url($callback, PHP_URL_QUERY), $query);
        $this->assertSame('claude-state', $query['state']);

        // A public client exchanges the code with the verifier instead of a secret.
        $token = (string) $this->post(route('passport.token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::CALLBACK,
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ])->assertOk()->json('access_token');

        $this->withToken($token)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => (object) [],
            ], ['Accept' => 'application/json, text/event-stream'])
            ->assertOk()
            ->assertSee('evaluate-position');
    }

    #[Test]
    public function signing_in_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'someone@example.com']);

        foreach (range(1, 5) as $ignored) {
            $this->post('/login', ['email' => 'someone@example.com', 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => 'someone@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests();
    }
}
