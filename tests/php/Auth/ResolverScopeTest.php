<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Tests\Auth;

use GraphQL\Error\Error;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Control\ApiController;
use SilverShop\Api\GraphQL\Auth\ResolverScope;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The scope/auth matrix — the heart of "GraphQL enforces the same model as REST". Exercises
 * {@see ResolverScope} directly: read allowed under public_read, write denied without `write`, admin denied without
 * `admin`, and the privilege implication admin ⊇ write ⊇ read.
 */
class ResolverScopeTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function tearDown(): void
    {
        ApiContext::set(null);
        parent::tearDown();
    }

    private function context(bool $authenticated, array $scopes): void
    {
        ApiContext::set(new ApiContext($authenticated, null, $scopes, null));
    }

    private function denies(callable $fn): bool
    {
        try {
            $fn();
            return false;
        } catch (Error) {
            return true;
        }
    }

    public function testAnonymousHoldsNoScope(): void
    {
        $this->context(false, []);

        $this->assertTrue($this->denies(fn () => ResolverScope::requireScope(ApiScope::READ)));
        $this->assertTrue($this->denies(fn () => ResolverScope::requireScope(ApiScope::WRITE)));
        $this->assertTrue($this->denies(fn () => ResolverScope::requireScope(ApiScope::ADMIN)));
    }

    public function testReadScope(): void
    {
        $this->context(true, [ApiScope::READ]);

        $this->assertFalse($this->denies(fn () => ResolverScope::requireScope(ApiScope::READ)));
        $this->assertTrue($this->denies(fn () => ResolverScope::requireScope(ApiScope::WRITE)));
        $this->assertTrue($this->denies(fn () => ResolverScope::requireScope(ApiScope::ADMIN)));
    }

    public function testWriteScopeImpliesRead(): void
    {
        $this->context(true, [ApiScope::WRITE]);

        $this->assertFalse($this->denies(fn () => ResolverScope::requireScope(ApiScope::READ)));
        $this->assertFalse($this->denies(fn () => ResolverScope::requireScope(ApiScope::WRITE)));
        $this->assertTrue($this->denies(fn () => ResolverScope::requireScope(ApiScope::ADMIN)));
    }

    public function testAdminScopeImpliesWriteAndRead(): void
    {
        $this->context(true, [ApiScope::ADMIN]);

        $this->assertFalse($this->denies(fn () => ResolverScope::requireScope(ApiScope::READ)));
        $this->assertFalse($this->denies(fn () => ResolverScope::requireScope(ApiScope::WRITE)));
        $this->assertFalse($this->denies(fn () => ResolverScope::requireScope(ApiScope::ADMIN)));
    }

    public function testPublicReadOpenByDefault(): void
    {
        Config::modify()->set(ApiController::class, 'public_read', true);
        $this->context(false, []);

        $this->assertFalse($this->denies(fn () => ResolverScope::requireReadAccess()));
    }

    public function testPublicReadCanBeTokenGated(): void
    {
        Config::modify()->set(ApiController::class, 'public_read', false);

        // Anonymous is rejected when public read is off...
        $this->context(false, []);
        $this->assertTrue($this->denies(fn () => ResolverScope::requireReadAccess()));

        // ...but a read-scope token is accepted.
        $this->context(true, [ApiScope::READ]);
        $this->assertFalse($this->denies(fn () => ResolverScope::requireReadAccess()));
    }

    public function testScopeRequiredMessageNamesTheScope(): void
    {
        $this->context(true, [ApiScope::READ]);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('write');
        ResolverScope::requireScope(ApiScope::WRITE);
    }
}
