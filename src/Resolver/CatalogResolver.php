<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use SilverShop\Api\GraphQL\Auth\ResolverScope;
use SilverShop\Api\Serializer\CategorySerializer;
use SilverShop\Api\Serializer\ProductSerializer;
use SilverShop\Api\Serializer\VariationSerializer;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\ORM\DataList;
use SilverStripe\Versioned\Versioned;

/**
 * Read-only catalog resolvers (products, categories, variations). Each delegates field shaping to the REST base's
 * serializers — so the GraphQL payload matches `api/v1` key-for-key — and enforces the same `public_read` gate and
 * published-catalog (`Versioned::LIVE` + `canView()`) rules as {@see \SilverShop\Api\Resource\ProductResource} and
 * its siblings. Resolvers are static, per the `silverstripe/graphql` contract.
 */
class CatalogResolver
{
    use HandlesResolverListParams;

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public static function resolveProducts(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::requireReadAccess();

        return Versioned::withVersionedMode(static function () use ($args): array {
            Versioned::set_stage(Versioned::LIVE);

            $list = Product::get();
            $list = self::applyProductFilters($list, $args);
            $list = self::applySort($list, $args['sort'] ?? null, [
                'Title',
                'BasePrice',
                'Created',
                'LastEdited',
                'Popularity',
                'Featured',
            ]);
            $list = self::applyLimit($list, $args);

            $serializer = ProductSerializer::create();
            $payload = [];
            foreach ($list as $product) {
                if ($product->canView()) {
                    $payload[] = $serializer->serialise($product);
                }
            }

            return $payload;
        });
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveProduct(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::requireReadAccess();

        return Versioned::withVersionedMode(static function () use ($args): array {
            Versioned::set_stage(Versioned::LIVE);

            $product = self::findProduct($args);
            if (!$product || !$product->canView()) {
                ResolverScope::fail('ProductNotFound', 'Product not found.');
            }

            return ProductSerializer::create()->serialise($product);
        });
    }

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public static function resolveCategories(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::requireReadAccess();

        return Versioned::withVersionedMode(static function () use ($args): array {
            Versioned::set_stage(Versioned::LIVE);

            $list = ProductCategory::get();
            if (isset($args['parentID']) && (int) $args['parentID'] > 0) {
                $list = $list->filter('ParentID', (int) $args['parentID']);
            }
            $list = self::applySort($list, $args['sort'] ?? null, ['Title', 'Sort', 'Created', 'LastEdited']);
            $list = self::applyLimit($list, $args);

            $serializer = CategorySerializer::create();
            $payload = [];
            foreach ($list as $category) {
                if ($category->canView()) {
                    $payload[] = $serializer->serialise($category);
                }
            }

            return $payload;
        });
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveCategory(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::requireReadAccess();

        return Versioned::withVersionedMode(static function () use ($args): array {
            Versioned::set_stage(Versioned::LIVE);

            /** @var DataList<ProductCategory> $categories */
            $categories = ProductCategory::get();
            $id = (int) ($args['id'] ?? 0);
            $urlSegment = (string) ($args['urlSegment'] ?? '');

            $category = $id > 0
                ? $categories->byID($id)
                : ($urlSegment !== '' ? $categories->filter('URLSegment', $urlSegment)->first() : null);

            if (!$category || !$category->canView()) {
                ResolverScope::fail('CategoryNotFound', 'Category not found.');
            }

            return CategorySerializer::create()->serialise($category);
        });
    }

    /**
     * The category tree, nested. Mirrors {@see \SilverShop\Api\Resource\CategoryResource} `tree`.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function resolveCategoryTree(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::requireReadAccess();

        return Versioned::withVersionedMode(static function (): array {
            Versioned::set_stage(Versioned::LIVE);

            $serializer = CategorySerializer::create();

            /** @var array<int, array<string, mixed>> $nodes */
            $nodes = [];
            /** @var array<int, array<int, int>> $childrenOf */
            $childrenOf = [];
            foreach (ProductCategory::get() as $category) {
                if (!$category->canView()) {
                    continue;
                }
                $id = (int) $category->ID;
                $nodes[$id] = $serializer->serialise($category);
                $childrenOf[(int) $category->ParentID][] = $id;
            }

            $build = static function (int $id) use (&$build, &$nodes, $childrenOf): array {
                $node = $nodes[$id];
                $node['children'] = [];
                foreach ($childrenOf[$id] ?? [] as $childId) {
                    if (isset($nodes[$childId])) {
                        $node['children'][] = $build($childId);
                    }
                }
                return $node;
            };

            $roots = [];
            foreach ($nodes as $node) {
                $parentId = (int) $node['parentID'];
                if (!isset($nodes[$parentId])) {
                    $roots[] = $build((int) $node['id']);
                }
            }

            return $roots;
        });
    }

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public static function resolveVariations(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::requireReadAccess();

        return Versioned::withVersionedMode(static function () use ($args): array {
            Versioned::set_stage(Versioned::LIVE);

            $list = Variation::get();
            if (isset($args['productID']) && (int) $args['productID'] > 0) {
                $list = $list->filter('ProductID', (int) $args['productID']);
            }
            $list = self::applySort($list, $args['sort'] ?? null, ['InternalItemID', 'Price', 'Sort']);
            $list = self::applyLimit($list, $args);

            $serializer = VariationSerializer::create();
            $payload = [];
            foreach ($list as $variation) {
                if ($variation->canView()) {
                    $payload[] = $serializer->serialise($variation);
                }
            }

            return $payload;
        });
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveVariation(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        ResolverScope::requireReadAccess();

        return Versioned::withVersionedMode(static function () use ($args): array {
            Versioned::set_stage(Versioned::LIVE);

            $variation = Variation::get()->byID((int) ($args['id'] ?? 0));
            if (!$variation || !$variation->canView()) {
                ResolverScope::fail('VariationNotFound', 'Variation not found.');
            }

            return VariationSerializer::create()->serialise($variation);
        });
    }

    /**
     * Filter products by category (recursive, via the category index when present), featured flag and title,
     * mirroring {@see \SilverShop\Api\Resource\ProductResource}.
     *
     * @template T of \SilverStripe\ORM\DataObject
     * @param DataList<T> $list
     * @param array<string, mixed> $args
     * @return DataList<T>
     */
    private static function applyProductFilters(DataList $list, array $args): DataList
    {
        if (isset($args['category']) && (int) $args['category'] > 0) {
            $categoryId = (int) $args['category'];
            if (Product::singleton()->hasMethod('EffectiveCategories')) {
                $list = $list->filter('EffectiveCategories.ID', $categoryId);
            } else {
                $category = ProductCategory::get()->byID($categoryId);
                $ids = [$categoryId];
                if ($category && $category->hasMethod('AllChildCategoryIDs')) {
                    $ids = array_merge($ids, array_map('intval', $category->AllChildCategoryIDs()));
                }
                $list = $list->filterAny([
                    'ParentID' => $ids,
                    'ProductCategories.ID' => $ids,
                ]);
            }
        }

        if (isset($args['featured'])) {
            $list = $list->filter('Featured', (bool) $args['featured'] ? 1 : 0);
        }

        if (isset($args['title']) && (string) $args['title'] !== '') {
            $list = $list->filter('Title:PartialMatch', (string) $args['title']);
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function findProduct(array $args): ?Product
    {
        /** @var DataList<Product> $products */
        $products = Versioned::get_by_stage(Product::class, Versioned::LIVE);

        $id = (int) ($args['id'] ?? 0);
        if ($id > 0) {
            return $products->byID($id);
        }

        $urlSegment = (string) ($args['urlSegment'] ?? '');
        if ($urlSegment !== '') {
            return $products->filter('URLSegment', $urlSegment)->first();
        }

        return null;
    }
}
