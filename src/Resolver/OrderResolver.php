<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use SilverShop\Api\GraphQL\Auth\ResolverScope;
use SilverShop\Api\Serializer\OrderSerializer;
use SilverShop\Model\Order;
use SilverStripe\Security\Permission;

/**
 * Order resolvers — read-only and scoped to the authenticated customer's own placed orders, mirroring
 * {@see \SilverShop\Api\Resource\OrderResource}. There is no order-create resolver: checkout is a hosted hand-off
 * (see {@see CartResolver::resolveCheckout()}), never an API payment.
 */
class OrderResolver
{
    use HandlesResolverListParams;

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public static function resolveMyOrders(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        $member = ResolverScope::requireMember();

        $list = Order::get()
            ->filter('MemberID', $member->ID)
            ->exclude('Status', Order::config()->get('hidden_status'));
        $list = self::applySort($list, $args['sort'] ?? null, ['Placed', 'Created', 'Total']);
        $list = self::applyLimit($list, $args);

        $serializer = OrderSerializer::create();
        $payload = [];
        foreach ($list as $order) {
            if ($order->canView($member)) {
                $payload[] = $serializer->serialise($order);
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveOrder(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        $member = ResolverScope::requireMember();

        $order = Order::get()->byID((int) ($args['id'] ?? 0));

        // Order::canView() is permissive by default, so ownership is enforced explicitly (a member may only read
        // their own orders; admins may read any). A missing/foreign order is reported as "not found" so its
        // existence is not revealed — identical to the REST OrderResource.
        $owned = $order && $order->exists() && (int) $order->MemberID === (int) $member->ID;
        if (!$owned && !Permission::check('ADMIN', 'any', $member)) {
            ResolverScope::fail('OrderNotFound', 'Order not found.');
        }

        return OrderSerializer::create()->serialise($order);
    }
}
