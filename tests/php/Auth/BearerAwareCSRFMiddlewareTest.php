<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Tests\Auth;

use Exception;
use GraphQL\Type\Schema;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\GraphQL\Auth\BearerAwareCSRFMiddleware;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * The bearer-aware CSRF middleware: a token-authenticated request bypasses the CSRF token check (the token is the
 * credential), while an unauthenticated mutation still falls through to the stock CSRF enforcement.
 */
class BearerAwareCSRFMiddlewareTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function tearDown(): void
    {
        ApiContext::set(null);
        parent::tearDown();
    }

    public function testAuthenticatedBearerRequestSkipsCsrf(): void
    {
        ApiContext::set(new ApiContext(true, new Member(), [ApiScope::WRITE], null));

        $middleware = new BearerAwareCSRFMiddleware();
        $called = false;
        $next = function () use (&$called) {
            $called = true;
            return ['data' => 'ok'];
        };

        // A mutation that would normally require an X-CSRF-TOKEN passes straight through.
        $result = $middleware->process(new Schema([]), 'mutation { cartClear { success } }', [], [], $next);

        $this->assertTrue($called);
        $this->assertSame(['data' => 'ok'], $result);
    }

    public function testUnauthenticatedMutationStillRequiresCsrf(): void
    {
        ApiContext::set(ApiContext::anonymous());

        $middleware = new BearerAwareCSRFMiddleware();
        $next = fn () => ['data' => 'ok'];

        // No bearer token and no CSRF token in context → stock CSRF middleware rejects the mutation.
        $this->expectException(Exception::class);
        $middleware->process(new Schema([]), 'mutation { cartClear { success } }', [], [], $next);
    }
}
