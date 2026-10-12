<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Tests\Auth;

use SilverShop\Api\GraphQL\Auth\ApiTokenQueryMiddleware;
use SilverStripe\Control\HTTPRequest;

/**
 * Test double that feeds {@see ApiTokenQueryMiddleware} a crafted request instead of reading the current
 * controller's request, so the auth middleware can be exercised in isolation.
 */
class FixedRequestMiddleware extends ApiTokenQueryMiddleware
{
    public function __construct(private readonly ?HTTPRequest $request)
    {
    }

    protected function currentRequest(): ?HTTPRequest
    {
        return $this->request;
    }
}
