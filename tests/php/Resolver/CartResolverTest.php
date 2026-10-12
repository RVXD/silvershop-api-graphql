<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Tests\Resolver;

use GraphQL\Error\Error;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\GraphQL\Resolver\CartResolver;
use SilverShop\Api\GraphQL\Tests\CatalogFixtures;
use SilverShop\Api\Serializer\OrderSerializer;
use SilverShop\Cart\ShoppingCart;
use SilverShop\Page\Product;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Cart + checkout resolvers: add/get/update/remove/clear through core's {@link ShoppingCart}, write-scope
 * enforcement, the checkout hand-off, and field-for-field parity with the REST {@link OrderSerializer}.
 */
class CartResolverTest extends SapphireTest
{
    use CatalogFixtures;

    protected $usesDatabase = true;

    protected Controller $controller;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Product::class, 'global_allow_purchase', true);

        // ShoppingCart needs a current controller + session to resolve/persist the active cart.
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        $this->controller = new Controller();
        $this->controller->setRequest($request);
        $this->controller->pushCurrent();

        ShoppingCart::singleton()->clear();
        $this->product = $this->makeProduct('Mug', 12.50, 0, ['InternalItemID' => 'MUG-1']);
    }

    protected function tearDown(): void
    {
        ShoppingCart::singleton()->clear();
        $this->clearAuthContext();
        $this->controller->popCurrent();
        parent::tearDown();
    }

    public function testWriteTokenAddsAndReadsCart(): void
    {
        $member = $this->makeMember('cart@example.com');
        $this->asAuthenticated($member, [ApiScope::WRITE]);

        $add = CartResolver::resolveAdd(null, ['productID' => $this->product->ID, 'quantity' => 2]);
        $this->assertTrue($add['success']);
        $this->assertSame(1, $add['cart']['itemCount']);

        $cart = CartResolver::resolveCart();
        $this->assertSame(1, $cart['itemCount']);
        $this->assertSame(2, $cart['items'][0]['quantity']);

        // Parity: the cart payload is exactly the REST OrderSerializer of the live cart order.
        $order = ShoppingCart::singleton()->current();
        $this->assertNotNull($order);
        $this->assertSame(OrderSerializer::create()->serialise($order), $cart);
    }

    public function testReadScopeCannotMutateCart(): void
    {
        $member = $this->makeMember('readonly@example.com');
        $this->asAuthenticated($member, [ApiScope::READ]);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('write');
        CartResolver::resolveAdd(null, ['productID' => $this->product->ID]);
    }

    public function testUpdateAndRemoveAndClear(): void
    {
        $member = $this->makeMember('mutate@example.com');
        $this->asAuthenticated($member, [ApiScope::WRITE]);

        CartResolver::resolveAdd(null, ['productID' => $this->product->ID, 'quantity' => 1]);

        $updated = CartResolver::resolveUpdate(null, ['productID' => $this->product->ID, 'quantity' => 5]);
        $this->assertTrue($updated['success']);
        $this->assertSame(5, $updated['cart']['items'][0]['quantity']);

        $removed = CartResolver::resolveRemove(null, ['productID' => $this->product->ID]);
        $this->assertTrue($removed['success']);
        $this->assertSame(0, $removed['cart']['itemCount']);

        CartResolver::resolveAdd(null, ['productID' => $this->product->ID, 'quantity' => 1]);
        $cleared = CartResolver::resolveClear();
        $this->assertTrue($cleared['success']);
        $this->assertSame(0, $cleared['cart']['itemCount']);
    }

    public function testCheckoutHandoffRequiresItems(): void
    {
        $member = $this->makeMember('checkout@example.com');
        $this->asAuthenticated($member, [ApiScope::WRITE]);

        // Empty cart → error.
        $threw = false;
        try {
            CartResolver::resolveCheckout();
        } catch (Error) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Checkout of an empty cart is rejected');

        CartResolver::resolveAdd(null, ['productID' => $this->product->ID]);
        $handoff = CartResolver::resolveCheckout();
        $this->assertArrayHasKey('checkoutUrl', $handoff);
        $this->assertArrayHasKey('cartUrl', $handoff);
        $this->assertNotSame('', $handoff['reference']);
    }

    public function testAnonymousSessionCartIsAllowed(): void
    {
        // No bearer token: the resolver permits the mutation (CSRF is the middleware's concern), operating on the
        // same-origin session cart — exactly as core / the REST base.
        $this->asAnonymous();

        $add = CartResolver::resolveAdd(null, ['productID' => $this->product->ID, 'quantity' => 1]);
        $this->assertTrue($add['success']);
        $this->assertSame(1, $add['cart']['itemCount']);
    }

    public function testUnknownBuyableIsRejected(): void
    {
        $member = $this->makeMember('nobuyable@example.com');
        $this->asAuthenticated($member, [ApiScope::WRITE]);

        $this->expectException(Error::class);
        CartResolver::resolveAdd(null, ['productID' => 999999]);
    }
}
