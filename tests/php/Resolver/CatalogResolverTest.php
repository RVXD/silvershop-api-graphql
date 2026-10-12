<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Tests\Resolver;

use GraphQL\Error\Error;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Control\ApiController;
use SilverShop\Api\GraphQL\Resolver\CatalogResolver;
use SilverShop\Api\GraphQL\Tests\CatalogFixtures;
use SilverShop\Api\Serializer\ProductSerializer;
use SilverShop\Api\Serializer\VariationSerializer;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Catalog read resolvers: field-for-field parity with the REST serializers, plus list/sort/filter/tree behaviour
 * and the `public_read` gate.
 */
class CatalogResolverTest extends SapphireTest
{
    use CatalogFixtures;

    protected $usesDatabase = true;

    protected ProductCategory $category;
    protected Product $tshirt;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        Config::modify()->set(ApiController::class, 'public_read', true);

        $this->category = $this->makeCategory('Apparel');
        $this->tshirt = $this->makeProduct('T-Shirt', 19.99, $this->category->ID, [
            'InternalItemID' => 'TS-001',
            'Featured' => true,
        ]);
        $this->makeProduct('Hoodie', 49.00, $this->category->ID, ['InternalItemID' => 'HD-001']);
        $this->makeProduct('Cap', 9.00, $this->category->ID, ['InternalItemID' => 'CP-001']);
    }

    protected function tearDown(): void
    {
        $this->clearAuthContext();
        parent::tearDown();
    }

    public function testListReturnsPublishedProducts(): void
    {
        $data = CatalogResolver::resolveProducts(null, ['limit' => 50]);
        $this->assertCount(3, $data);
        $this->assertContains('T-Shirt', array_column($data, 'title'));
    }

    public function testProductDetailParityWithRestSerializer(): void
    {
        // The GraphQL resolver returns the base serializer's array verbatim, so the two surfaces cannot drift.
        $expected = ProductSerializer::create()->serialise($this->tshirt);
        $actual = CatalogResolver::resolveProduct(null, ['id' => $this->tshirt->ID]);

        $this->assertSame($expected, $actual);
        $this->assertSame('T-Shirt', $actual['title']);
        $this->assertSame(19.99, $actual['price']);
        $this->assertArrayHasKey('currency', $actual);
        $this->assertArrayHasKey('categoryIDs', $actual);
    }

    public function testDetailByUrlSegmentAndNotFound(): void
    {
        $bySegment = CatalogResolver::resolveProduct(null, ['urlSegment' => $this->tshirt->URLSegment]);
        $this->assertSame((int) $this->tshirt->ID, $bySegment['id']);

        $this->expectException(Error::class);
        CatalogResolver::resolveProduct(null, ['urlSegment' => 'does-not-exist']);
    }

    public function testSortDescendingByPrice(): void
    {
        $data = CatalogResolver::resolveProducts(null, ['sort' => '-BasePrice']);
        $prices = array_map('floatval', array_column($data, 'price'));
        $this->assertSame([49.0, 19.99, 9.0], $prices);
    }

    public function testInvalidSortFieldRejected(): void
    {
        $this->expectException(Error::class);
        CatalogResolver::resolveProducts(null, ['sort' => 'Secret']);
    }

    public function testFeaturedFilter(): void
    {
        $data = CatalogResolver::resolveProducts(null, ['featured' => true]);
        $this->assertCount(1, $data);
        $this->assertSame('T-Shirt', $data[0]['title']);
    }

    public function testCategoryFilterExcludesOutsiders(): void
    {
        $this->makeProduct('Unrelated', 5.00, 0, ['InternalItemID' => 'UN-1']);

        $data = CatalogResolver::resolveProducts(null, ['category' => $this->category->ID, 'limit' => 50]);
        $titles = array_column($data, 'title');

        $this->assertContains('T-Shirt', $titles);
        $this->assertNotContains('Unrelated', $titles);
    }

    public function testCategoryTreeNesting(): void
    {
        $this->makeCategory('Shirts', $this->category->ID);

        $tree = CatalogResolver::resolveCategoryTree();
        $root = null;
        foreach ($tree as $node) {
            if ($node['id'] === (int) $this->category->ID) {
                $root = $node;
            }
        }

        $this->assertNotNull($root, 'Apparel is a tree root');
        $this->assertNotEmpty($root['children']);
        $this->assertSame('Shirts', $root['children'][0]['title']);
    }

    public function testVariationsParityAndFilter(): void
    {
        $variation = $this->makeVariation($this->tshirt, 'TS-001-R', 21.50, 'Colour', 'Red');

        $byProduct = CatalogResolver::resolveVariations(null, ['productID' => $this->tshirt->ID]);
        $this->assertCount(1, $byProduct);

        $expected = VariationSerializer::create()->serialise(Variation::get()->byID($variation->ID));
        $actual = CatalogResolver::resolveVariation(null, ['id' => $variation->ID]);
        $this->assertSame($expected, $actual);
        $this->assertSame('Red', $actual['attributes'][0]['value']);
    }

    public function testPublicReadGate(): void
    {
        Config::modify()->set(ApiController::class, 'public_read', false);

        // Anonymous is denied...
        $this->asAnonymous();
        $denied = false;
        try {
            CatalogResolver::resolveProducts();
        } catch (Error) {
            $denied = true;
        }
        $this->assertTrue($denied, 'Catalog read is denied to anonymous when public_read is off');

        // ...a read-scope token is allowed.
        $this->asScopedToken([ApiScope::READ]);
        $this->assertCount(3, CatalogResolver::resolveProducts(null, ['limit' => 50]));
    }
}
