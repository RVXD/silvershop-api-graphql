<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Auth;

use GraphQL\Error\Error;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Control\ApiController;
use SilverStripe\Core\Config\Config;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Scope/authorisation helpers shared by the GraphQL resolvers — the GraphQL counterpart of the REST base's
 * {@see \SilverShop\Api\Resource\AbstractResource}. Each method reads the request-scoped {@link ApiContext} that
 * {@link ApiTokenQueryMiddleware} published (bearer token → member + scopes), so GraphQL enforces exactly the same
 * model as REST:
 *
 *  - catalog reads are open when `public_read` is on, otherwise need a `read`-scope token;
 *  - `me`/cart/own-orders need an authenticated member;
 *  - cart/customer writes need the `write` scope (bearer) or a valid same-origin session (CSRF enforced upstream).
 *
 * Failures are raised as {@link Error} so the message reaches the GraphQL `errors` array (the handler surfaces the
 * message), and every message is translatable under this class's i18n entity.
 */
class ResolverScope
{
    /**
     * The resolved auth context for the current request, or an anonymous one before the middleware has run
     * (e.g. in a unit test that exercises a resolver directly without setting a context).
     */
    public static function context(): ApiContext
    {
        return ApiContext::current() ?? ApiContext::anonymous();
    }

    /**
     * Gate a catalog read: allowed to anyone when `public_read` is on, otherwise needs a token with the read scope.
     * Mirrors {@see \SilverShop\Api\Resource\AbstractResource::requireReadAccess()}.
     */
    public static function requireReadAccess(): void
    {
        if ((bool) Config::forClass(ApiController::class)->get('public_read')) {
            return;
        }

        if (!self::context()->hasScope(ApiScope::READ)) {
            self::fail('ReadScopeRequired', 'A token with the "read" scope is required to read the catalog.');
        }
    }

    /**
     * Require that the current context carries the given scope.
     */
    public static function requireScope(string $scope): void
    {
        $context = self::context();
        if (!$context->isAuthenticated()) {
            self::fail('AuthRequired', 'Authentication required.');
        }
        if (!$context->hasScope($scope)) {
            self::fail('ScopeRequired', 'This action requires the "{scope}" scope.', ['scope' => $scope]);
        }
    }

    /**
     * Require an authenticated member and return it. A bearer token's member is preferred; failing that the
     * same-origin session user (so storefront JS works without a token).
     */
    public static function requireMember(): Member
    {
        $member = self::context()->getMember() ?? Security::getCurrentUser();
        if (!$member || !$member->exists()) {
            self::fail('AuthRequired', 'Authentication required.');
        }

        return $member;
    }

    /**
     * Authorise a cart mutation: a bearer token needs the given scope; a same-origin session relies on the CSRF
     * check already performed by {@see BearerAwareCSRFMiddleware}, so an anonymous same-origin cart is allowed
     * (exactly as core / {@see \SilverShop\Api\Resource\CartResource::authoriseMutation()}).
     */
    public static function authoriseCartMutation(string $scope = ApiScope::WRITE): void
    {
        if (self::context()->isAuthenticated()) {
            self::requireScope($scope);
        }
    }

    /**
     * Authorise a member-scoped write (profile/address) and return the member: a bearer token needs the given scope
     * and a member; a same-origin session needs a logged-in member (CSRF enforced upstream). Mirrors
     * {@see \SilverShop\Api\Resource\AbstractResource::authoriseMemberWrite()}.
     */
    public static function authoriseMemberWrite(string $scope = ApiScope::WRITE): Member
    {
        $context = self::context();
        if ($context->isAuthenticated()) {
            self::requireScope($scope);
            $member = $context->getMember();
            if ($member && $member->exists()) {
                return $member;
            }
        }

        $sessionMember = Security::getCurrentUser();
        if (!$sessionMember || !$sessionMember->exists()) {
            self::fail('AuthRequired', 'Authentication required.');
        }

        return $sessionMember;
    }

    /**
     * Throw a translatable GraphQL error.
     *
     * @param array<string, mixed> $injection
     */
    public static function fail(string $key, string $default, array $injection = []): never
    {
        throw new Error(_t(self::class . '.' . $key, $default, $injection));
    }
}
