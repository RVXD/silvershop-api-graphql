<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use SilverShop\Api\GraphQL\Auth\ResolverScope;
use SilverShop\Api\Serializer\OrderSerializer;
use SilverShop\Cart\ShoppingCart;
use SilverShop\Model\Buyable;
use SilverShop\Model\Order;
use SilverShop\Model\OrderItem;
use SilverShop\Page\CartPage;
use SilverShop\Page\CheckoutPage;
use SilverShop\Page\Product;
use SilverShop\ShopTools;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Cart + checkout resolvers, reusing core's {@link ShoppingCart} exactly as {@see \SilverShop\Api\Resource\CartResource}
 * does, and the base {@link OrderSerializer} for output — so a cart over GraphQL and over `api/v1/cart` are the same
 * object, field-for-field. Writes require the `write` scope (bearer) or a valid same-origin session (CSRF handled by
 * {@see \SilverShop\Api\GraphQL\Auth\BearerAwareCSRFMiddleware}). `checkout` is a hand-off: it returns the hosted
 * checkout/cart URLs and never captures payment.
 */
class CartResolver
{
    /**
     * The current cart (a cart is an Order with status "Cart"). No auth required — an anonymous same-origin cart
     * is returned just like core.
     *
     * @return array<string, mixed>
     */
    public static function resolveCart(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        return self::cartPayload(self::resolveShoppingCart());
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveAdd(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::authoriseCartMutation();

        $cart = self::resolveShoppingCart();
        $buyable = self::buyableFromArgs($args, $cart);
        if (!$buyable instanceof Buyable) {
            ResolverScope::fail('BuyableNotFound', 'Buyable not found.');
        }

        $quantity = isset($args['quantity']) ? (int) $args['quantity'] : 1;
        if ($quantity < 0) {
            $quantity = 0;
        }

        // Decimal/fractional quantity (silvershop-suite/decimal-quantity): carry it in the filter and set the
        // integer quantity to its ceil mirror. Harmless when that module isn't installed (the var is ignored).
        $filter = [];
        $decimal = isset($args['quantityDecimal']) ? (float) $args['quantityDecimal'] : 0.0;
        if ($decimal > 0) {
            $quantity = (int) ceil($decimal);
            $filter['quantitydecimal'] = $decimal;
        }

        $result = $quantity === 0
            ? $cart->remove($buyable, null, [])
            : $cart->add($buyable, $quantity, $filter);

        if ($result === null) {
            return self::cartOperationResult($cart, false);
        }

        self::bindCartToMember($cart);
        $extra = ($result instanceof OrderItem) ? ['itemId' => (int) $result->ID] : [];

        return self::cartOperationResult($cart, true, $extra);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveUpdate(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::authoriseCartMutation();

        $cart = self::resolveShoppingCart();
        $buyable = self::buyableFromArgs($args, $cart);
        if (!$buyable instanceof Buyable) {
            ResolverScope::fail('BuyableNotFound', 'Buyable not found.');
        }

        $quantity = (int) ($args['quantity'] ?? -1);
        $decimal = isset($args['quantityDecimal']) ? (float) $args['quantityDecimal'] : 0.0;
        if ($decimal > 0) {
            $quantity = (int) ceil($decimal);
        }
        if ($quantity < 0) {
            ResolverScope::fail('QuantityRequired', 'A quantity of zero or greater is required.');
        }

        $filter = $decimal > 0 ? ['quantitydecimal' => $decimal] : [];
        $result = $cart->setQuantity($buyable, $quantity, $filter);
        if ($result === null) {
            return self::cartOperationResult($cart, false);
        }

        self::bindCartToMember($cart);

        return self::cartOperationResult($cart, true);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveRemove(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::authoriseCartMutation();

        $cart = self::resolveShoppingCart();
        $buyable = self::buyableFromArgs($args, $cart);
        if (!$buyable instanceof Buyable) {
            ResolverScope::fail('BuyableNotFound', 'Buyable not found.');
        }

        if (!$cart->findLineItem($buyable) instanceof OrderItem) {
            ResolverScope::fail('ItemNotInCart', 'Item not found in cart.');
        }

        // null quantity means "remove the whole line" (core semantics); a negative quantity is coerced to null.
        $quantity = null;
        if (isset($args['quantity'])) {
            $q = (int) $args['quantity'];
            $quantity = $q < 0 ? null : $q;
        }

        $result = $cart->remove($buyable, $quantity, []);
        if ($result === null || $result === false) {
            return self::cartOperationResult($cart, false);
        }

        return self::cartOperationResult($cart, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolveClear(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::authoriseCartMutation();

        $cart = self::resolveShoppingCart();
        $result = $cart->clear();

        return self::cartOperationResult($cart, $result !== null);
    }

    /**
     * Checkout hand-off: the hosted checkout/cart URLs and the order reference. No API payment capture.
     *
     * @return array<string, mixed>
     */
    public static function resolveCheckout(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        $cart = self::resolveShoppingCart();
        $current = $cart->current();
        if (!$current || !$current->Items()->exists()) {
            ResolverScope::fail('CartEmpty', 'The cart is empty.');
        }

        return [
            'checkoutUrl' => CheckoutPage::find_link(),
            'cartUrl' => CartPage::find_link(),
            'reference' => (string) $current->getReference(),
        ];
    }

    /**
     * Resolve the cart to operate on: for a token-authenticated member, prefer their existing cart order so the
     * cart persists across stateless requests; otherwise the session cart. Mirrors CartResource::resolveCart().
     */
    private static function resolveShoppingCart(): ShoppingCart
    {
        $cart = ShoppingCart::singleton();
        $apiContext = ResolverScope::context();
        $member = $apiContext->isAuthenticated() ? $apiContext->getMember() : null;

        if ($member && $member->exists()) {
            $existing = Order::get()
                ->filter(['MemberID' => $member->ID, 'Status' => 'Cart'])
                ->first();
            if ($existing && $existing->exists()) {
                $cart->setCurrent($existing);
            }
        }

        return $cart;
    }

    private static function bindCartToMember(ShoppingCart $cart): void
    {
        $apiContext = ResolverScope::context();
        $member = $apiContext->isAuthenticated() ? $apiContext->getMember() : null;
        $current = $cart->current();
        if ($member && $member->exists() && $current && !$current->MemberID) {
            $current->MemberID = $member->ID;
            $current->write();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function cartPayload(ShoppingCart $cart): array
    {
        $current = $cart->current();
        if (!$current || !$current->exists()) {
            return ['id' => null, 'itemCount' => 0, 'items' => [], 'total' => 0.0];
        }

        return OrderSerializer::create()->serialise($current);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function cartOperationResult(ShoppingCart $cart, bool $success, array $extra = []): array
    {
        $payload = array_merge([
            'success' => $success,
            'message' => (string) $cart->getMessage(),
            'messageType' => $cart->getMessageType(),
        ], $extra);

        $payload['cart'] = self::cartPayload($cart);

        return $payload;
    }

    /**
     * Resolve the buyable (Product by default, or an explicit `buyable` class such as a Variation) by id, from the
     * published stage. Mirrors CartResource::buyableFromRequest().
     *
     * @param array<string, mixed> $args
     */
    private static function buyableFromArgs(array $args, ShoppingCart $cart): ?Buyable
    {
        $buyableClass = (string) ($args['buyable'] ?? Product::class);
        if (!ClassInfo::exists($buyableClass)) {
            $buyableClass = ShopTools::unsanitiseClassName($buyableClass);
        }

        $buyableId = (int) ($args['productID'] ?? 0);

        if ($buyableId === 0 || !ClassInfo::exists($buyableClass) || !is_a($buyableClass, Buyable::class, true)) {
            return null;
        }

        // Every Buyable is a DataObject; narrow the type so the Versioned/DataObject calls type-check.
        /** @var class-string<Buyable&DataObject> $buyableClass */
        $buyable = DataObject::singleton($buyableClass)->hasExtension(Versioned::class)
            ? Versioned::get_by_stage($buyableClass, Versioned::LIVE)->byID($buyableId)
            : DataObject::get($buyableClass)->byID($buyableId);

        if (!$buyable instanceof Buyable) {
            return null;
        }

        return $cart->getCorrectBuyable($buyable);
    }
}
