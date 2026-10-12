<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Tests;

use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\ApiToken;
use SilverShop\Model\Variation\AttributeType;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Helpers to build a small published catalog + members/tokens, and to simulate the request-scoped auth context that
 * {@see \SilverShop\Api\GraphQL\Auth\ApiTokenQueryMiddleware} would publish — so resolver unit tests exercise the
 * real scope/auth rules without a built schema or a live server. Mirrors the REST base's test fixtures.
 */
trait CatalogFixtures
{
    protected function makeCategory(string $title, int $parentId = 0): ProductCategory
    {
        $category = ProductCategory::create([
            'Title' => $title,
            'ParentID' => $parentId,
            'CanViewType' => 'Anyone',
        ]);
        $category->write();
        $category->publishSingle();

        return $category;
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function makeProduct(string $title, float $price, int $parentId = 0, array $extra = []): Product
    {
        $product = Product::create(array_merge([
            'Title' => $title,
            'BasePrice' => $price,
            'ParentID' => $parentId,
            'CanViewType' => 'Anyone',
            'AllowPurchase' => true,
        ], $extra));
        $product->write();
        $product->publishSingle();

        return $product;
    }

    protected function makeVariation(Product $product, string $itemId, float $price, string $attributeLabel, string $attributeValue): Variation
    {
        $type = AttributeType::get()->filter('Label', $attributeLabel)->first();
        if (!$type) {
            $type = AttributeType::create(['Name' => $attributeLabel, 'Label' => $attributeLabel]);
            $type->write();
        }
        $type->addValues([$attributeValue]);
        $value = $type->Values()->filter('Value', $attributeValue)->first();

        $product->VariationAttributeTypes()->add($type);

        $variation = Variation::create([
            'InternalItemID' => $itemId,
            'Price' => $price,
            'ProductID' => $product->ID,
        ]);
        $variation->write();
        $variation->AttributeValues()->add($value);
        $variation->publishSingle();

        return $variation;
    }

    protected function makeMember(string $email): Member
    {
        $member = Member::create(['Email' => $email, 'FirstName' => 'Test', 'Surname' => 'Buyer']);
        $member->write();

        return $member;
    }

    /**
     * @param array<int, string> $scopes
     * @return array{0: ApiToken, 1: string} the token record and its plaintext value
     */
    protected function makeToken(Member $member, array $scopes, ?string $expiry = null): array
    {
        $token = ApiToken::create([
            'Title' => 'Test token',
            'Scopes' => implode(',', $scopes),
            'MemberID' => $member->ID,
        ]);
        if ($expiry !== null) {
            $token->Expiry = $expiry;
        }
        $token->write();

        return [$token, (string) $token->plainTextToken];
    }

    /**
     * Publish the given auth context (as the middleware would) and, for an authenticated member, log them in for
     * the duration of the test.
     *
     * @param array<int, string> $scopes
     */
    protected function asAuthenticated(Member $member, array $scopes): void
    {
        ApiContext::set(new ApiContext(true, $member, $scopes, null));
        Security::setCurrentUser($member);
    }

    /**
     * Publish an authenticated context that carries scopes but no member (e.g. a service token).
     *
     * @param array<int, string> $scopes
     */
    protected function asScopedToken(array $scopes): void
    {
        ApiContext::set(new ApiContext(true, null, $scopes, null));
    }

    protected function asAnonymous(): void
    {
        ApiContext::set(ApiContext::anonymous());
        Security::setCurrentUser(null);
    }

    protected function clearAuthContext(): void
    {
        ApiContext::set(null);
        Security::setCurrentUser(null);
    }
}
