<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Auth;

use GraphQL\Type\Schema;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\TokenAuthenticator;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\GraphQL\Middleware\QueryMiddleware;
use SilverStripe\GraphQL\QueryHandler\UserContextProvider;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Query middleware that authenticates a GraphQL request from an `Authorization: Bearer <token>` header, reusing the
 * REST base's {@link TokenAuthenticator}. It is the GraphQL equivalent of the base's
 * {@see \SilverShop\Api\Auth\BearerTokenMiddleware}:
 *
 *  1. resolve the bearer token to an {@link ApiContext} (member + scopes), rejecting a present-but-invalid token;
 *  2. publish it via {@link ApiContext::set()} so the resolvers' {@link ResolverScope} checks enforce scopes;
 *  3. log the token's member in for the request and inject it into the GraphQL `currentUser` context, so both
 *     core cart/order code (which reads {@link Security::getCurrentUser()}) and any scaffolded model `canView`
 *     checks see the authenticated member;
 *  4. restore the previous state once the query has executed.
 *
 * Tokens are read from the Authorization header only (never the query string), delegated to the base authenticator.
 * Requests without a bearer token pass through as anonymous — the catalog is still readable when `public_read` is on.
 */
class ApiTokenQueryMiddleware implements QueryMiddleware
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $vars
     * @return \GraphQL\Executor\ExecutionResult|array<string, mixed>
     */
    public function process(Schema $schema, string $query, array $context, array $vars, callable $next)
    {
        $request = $this->currentRequest();
        $authenticator = Injector::inst()->create(TokenAuthenticator::class);

        $apiContext = ApiContext::anonymous();
        if ($request instanceof HTTPRequest && $authenticator->extractBearerToken($request) !== null) {
            $apiContext = $authenticator->authenticate($request);
            if (!$apiContext->isAuthenticated()) {
                // A bearer token was presented but did not resolve to a valid, unexpired token.
                ResolverScope::fail('InvalidToken', 'Invalid or expired API token.');
            }
        }

        ApiContext::set($apiContext);

        $member = $apiContext->isAuthenticated() ? $apiContext->getMember() : null;
        $restoreUser = false;
        $previousUser = Security::getCurrentUser();

        if ($member instanceof Member && $member->exists()) {
            Security::setCurrentUser($member);
            $context[UserContextProvider::KEY] = $member;
            $restoreUser = true;
        }

        try {
            return $next($schema, $query, $context, $vars);
        } finally {
            if ($restoreUser) {
                Security::setCurrentUser($previousUser);
            }
            ApiContext::set(null);
        }
    }

    /**
     * The request being handled. The GraphQL controller is the current controller while a query executes, so its
     * request carries the Authorization header. Isolated as a seam so tests can supply a crafted request.
     */
    protected function currentRequest(): ?HTTPRequest
    {
        return Controller::curr()?->getRequest();
    }
}
