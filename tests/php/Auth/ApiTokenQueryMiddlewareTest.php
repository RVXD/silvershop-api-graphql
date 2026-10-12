<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Tests\Auth;

use GraphQL\Error\Error;
use GraphQL\Type\Schema;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\GraphQL\Tests\CatalogFixtures;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;

/**
 * The bearer-token query middleware: a valid token publishes an authenticated {@link ApiContext} and logs the member
 * in for the duration of the query (then restores), an invalid/expired token is rejected, and an anonymous request
 * passes through untouched.
 */
class ApiTokenQueryMiddlewareTest extends SapphireTest
{
    use CatalogFixtures;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Start from a known, logged-out state so "restore previous user" assertions are deterministic.
        Security::setCurrentUser(null);
        ApiContext::set(null);
    }

    protected function tearDown(): void
    {
        ApiContext::set(null);
        Security::setCurrentUser(null);
        parent::tearDown();
    }

    private function request(?string $authorization): HTTPRequest
    {
        $request = new HTTPRequest('POST', 'graphql');
        if ($authorization !== null) {
            $request->addHeader('Authorization', $authorization);
        }

        return $request;
    }

    /**
     * Run the middleware and capture the auth state visible to the wrapped execution step.
     *
     * @return array<string, mixed>
     */
    private function runMiddleware(?string $authorization): array
    {
        $middleware = new FixedRequestMiddleware($this->request($authorization));

        $captured = [];
        $next = function ($schema, $query, $context, $vars) use (&$captured): array {
            $ctx = ApiContext::current();
            $captured = [
                'authenticated' => $ctx?->isAuthenticated() ?? false,
                'memberId' => $ctx?->getMember()?->ID,
                'hasWrite' => $ctx?->hasScope(ApiScope::WRITE) ?? false,
                'currentUserId' => Security::getCurrentUser()?->ID,
                'contextUser' => $context['currentUser'] ?? null,
            ];
            return ['data' => null];
        };

        $middleware->process(new Schema([]), 'query { cart { id } }', [], [], $next);

        return $captured;
    }

    public function testValidTokenPublishesContextAndLogsMemberIn(): void
    {
        $member = $this->makeMember('mw@example.com');
        [, $plain] = $this->makeToken($member, [ApiScope::WRITE]);

        $captured = $this->runMiddleware('Bearer ' . $plain);

        $this->assertTrue($captured['authenticated']);
        $this->assertSame((int) $member->ID, (int) $captured['memberId']);
        $this->assertTrue($captured['hasWrite']);
        $this->assertSame((int) $member->ID, (int) $captured['currentUserId'], 'member is logged in for the query');
        $this->assertNotNull($captured['contextUser'], 'member is injected into the GraphQL currentUser context');

        // Cleanup after the query: context cleared and the current user restored.
        $this->assertNull(ApiContext::current());
        $this->assertNull(Security::getCurrentUser());
    }

    public function testAnonymousWithoutHeaderPassesThrough(): void
    {
        $captured = $this->runMiddleware(null);

        $this->assertFalse($captured['authenticated']);
        $this->assertNull($captured['memberId']);
        $this->assertNull($captured['currentUserId']);
    }

    public function testNonBearerHeaderIsIgnored(): void
    {
        $captured = $this->runMiddleware('Basic dXNlcjpwYXNz');

        $this->assertFalse($captured['authenticated']);
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Invalid');

        $this->runMiddleware('Bearer sk_not_a_real_token');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $member = $this->makeMember('expired@example.com');
        [, $plain] = $this->makeToken($member, [ApiScope::READ], '2000-01-01 00:00:00');

        // Sanity: "now" is well after the expiry.
        $this->assertGreaterThan(
            strtotime('2000-01-01 00:00:00'),
            DBDatetime::now()->getTimestamp()
        );

        $this->expectException(Error::class);
        $this->runMiddleware('Bearer ' . $plain);
    }
}
