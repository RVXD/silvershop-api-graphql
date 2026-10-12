<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Tests\Resolver;

use GraphQL\Error\Error;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\GraphQL\Resolver\OrderResolver;
use SilverShop\Api\GraphQL\Tests\CatalogFixtures;
use SilverShop\Api\Serializer\OrderSerializer;
use SilverShop\Model\Order;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * Order resolvers: own-orders isolation (a member can only read their own; a foreign/missing order is reported as
 * "not found"), auth requirement, and serializer parity.
 */
class OrderResolverTest extends SapphireTest
{
    use CatalogFixtures;

    protected $usesDatabase = true;

    protected function tearDown(): void
    {
        $this->clearAuthContext();
        parent::tearDown();
    }

    private function makeOrder(Member $member, string $status = 'Unpaid'): Order
    {
        $order = Order::create(['Status' => $status, 'MemberID' => $member->ID]);
        $order->write();

        return $order;
    }

    public function testMemberReadsOwnOrdersOnly(): void
    {
        $alice = $this->makeMember('alice@example.com');
        $bob = $this->makeMember('bob@example.com');
        $aliceOrder = $this->makeOrder($alice);

        $this->asAuthenticated($alice, [ApiScope::READ]);
        $list = OrderResolver::resolveMyOrders();
        $this->assertCount(1, $list);
        $this->assertSame((int) $aliceOrder->ID, $list[0]['id']);

        // Parity with the REST serializer.
        $detail = OrderResolver::resolveOrder(null, ['id' => $aliceOrder->ID]);
        $this->assertSame(OrderSerializer::create()->serialise($aliceOrder), $detail);

        // Bob cannot see Alice's order and has none of his own.
        $this->asAuthenticated($bob, [ApiScope::READ]);
        $this->assertCount(0, OrderResolver::resolveMyOrders());

        $this->expectException(Error::class);
        OrderResolver::resolveOrder(null, ['id' => $aliceOrder->ID]);
    }

    public function testOrdersRequireAuthentication(): void
    {
        $this->asAnonymous();

        $this->expectException(Error::class);
        OrderResolver::resolveMyOrders();
    }
}
