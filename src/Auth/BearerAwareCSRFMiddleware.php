<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Auth;

use GraphQL\Type\Schema;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\TokenAuthenticator;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\GraphQL\Middleware\CSRFMiddleware;

/**
 * A CSRF middleware that skips the token check for bearer-authenticated requests. A bearer token is itself the
 * credential (headless clients can't carry a session CSRF token), so — exactly as the REST base does
 * ({@see \SilverShop\Api\Resource\CartResource::authoriseMutation()}) — token clients bypass CSRF while same-origin
 * session mutations still require a valid `X-CSRF-TOKEN`, enforced by the stock {@link CSRFMiddleware} behaviour.
 *
 * The bearer token is resolved independently from the request, so this does not depend on middleware ordering
 * relative to {@link ApiTokenQueryMiddleware}.
 */
class BearerAwareCSRFMiddleware extends CSRFMiddleware
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $vars
     * @return \GraphQL\Executor\ExecutionResult|array<string, mixed>
     */
    public function process(Schema $schema, string $query, array $context, array $vars, callable $next)
    {
        if ($this->hasValidBearerToken()) {
            return $next($schema, $query, $context, $vars);
        }

        return parent::process($schema, $query, $context, $vars, $next);
    }

    private function hasValidBearerToken(): bool
    {
        // Fast path: the auth middleware may already have resolved and published the context.
        if (ApiContext::current()?->isAuthenticated()) {
            return true;
        }

        $controller = Controller::curr();
        if (!$controller) {
            return false;
        }

        $request = $controller->getRequest();
        $authenticator = Injector::inst()->create(TokenAuthenticator::class);
        if ($authenticator->extractBearerToken($request) === null) {
            return false;
        }

        return $authenticator->authenticate($request)->isAuthenticated();
    }
}
