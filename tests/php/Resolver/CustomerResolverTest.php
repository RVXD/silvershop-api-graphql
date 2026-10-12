<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Tests\Resolver;

use GraphQL\Error\Error;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\GraphQL\Resolver\CustomerResolver;
use SilverShop\Api\GraphQL\Tests\CatalogFixtures;
use SilverShop\Api\Serializer\CustomerSerializer;
use SilverShop\Model\Address;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\Member;

/**
 * Customer self-service resolvers: profile read/update, address create, address ownership isolation and
 * write-scope enforcement.
 */
class CustomerResolverTest extends SapphireTest
{
    use CatalogFixtures;

    protected $usesDatabase = true;

    protected function tearDown(): void
    {
        $this->clearAuthContext();
        parent::tearDown();
    }

    public function testProfileReadParityAndUpdate(): void
    {
        $member = $this->makeMember('profile@example.com');
        $this->asAuthenticated($member, [ApiScope::WRITE]);

        $me = CustomerResolver::resolveMe();
        $this->assertSame(CustomerSerializer::create()->serialise($member), $me);
        $this->assertSame('profile@example.com', $me['email']);

        $updated = CustomerResolver::resolveUpdateCustomer(null, ['firstName' => 'Updated']);
        $this->assertSame('Updated', $updated['firstName']);
        $this->assertSame('Updated', Member::get()->byID($member->ID)->FirstName);
    }

    public function testAddAddress(): void
    {
        $member = $this->makeMember('addr@example.com');
        $this->asAuthenticated($member, [ApiScope::WRITE]);

        $address = CustomerResolver::resolveAddAddress(null, [
            'address' => '1 Test Street',
            'city' => 'Amsterdam',
            'state' => 'Noord-Holland',
            'country' => 'NL',
        ]);

        $this->assertSame('1 Test Street', $address['address']);
        $this->assertSame('Amsterdam', $address['city']);
        $this->assertSame(1, $member->AddressBook()->count());
    }

    public function testUpdateAddressOwnershipEnforced(): void
    {
        $owner = $this->makeMember('owner@example.com');
        $address = Address::create([
            'Address' => 'Owner Street',
            'City' => 'Utrecht',
            'State' => 'Utrecht',
            'Country' => 'NL',
            'MemberID' => $owner->ID,
        ]);
        $address->write();

        $intruder = $this->makeMember('intruder@example.com');
        $this->asAuthenticated($intruder, [ApiScope::WRITE]);

        $this->expectException(Error::class);
        CustomerResolver::resolveUpdateAddress(null, ['id' => $address->ID, 'city' => 'Hacked']);
    }

    public function testWriteScopeRequiredForProfileUpdate(): void
    {
        $member = $this->makeMember('readonly-profile@example.com');
        $this->asAuthenticated($member, [ApiScope::READ]);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('write');
        CustomerResolver::resolveUpdateCustomer(null, ['firstName' => 'Nope']);
    }

    public function testProfileRequiresAuthentication(): void
    {
        $this->asAnonymous();

        $this->expectException(Error::class);
        CustomerResolver::resolveMe();
    }
}
