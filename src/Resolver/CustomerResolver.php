<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use SilverShop\Api\GraphQL\Auth\ResolverScope;
use SilverShop\Api\Serializer\AddressSerializer;
use SilverShop\Api\Serializer\CustomerSerializer;
use SilverShop\Model\Address;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Customer self-service resolvers — the authenticated customer's own profile and address book, mirroring
 * {@see \SilverShop\Api\Resource\CustomerResource} and reusing its serializers. Writes require the `write` scope
 * (bearer) or a logged-in same-origin session. GraphQL arguments are lowerCamelCase and mapped to the PascalCase
 * DataObject fields through an allow-list, so no unexpected field can be written.
 */
class CustomerResolver
{
    /**
     * lowerCamelCase GraphQL argument => writable DataObject field, for the profile.
     *
     * @var array<string, string>
     */
    private const PROFILE_WRITABLE = [
        'firstName' => 'FirstName',
        'surname' => 'Surname',
    ];

    /**
     * lowerCamelCase GraphQL argument => writable DataObject field, for an address.
     *
     * @var array<string, string>
     */
    private const ADDRESS_WRITABLE = [
        'firstName' => 'FirstName',
        'surname' => 'Surname',
        'company' => 'Company',
        'address' => 'Address',
        'addressLine2' => 'AddressLine2',
        'city' => 'City',
        'state' => 'State',
        'postalCode' => 'PostalCode',
        'country' => 'Country',
        'phone' => 'Phone',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function resolveMe(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        $member = ResolverScope::requireMember();

        return CustomerSerializer::create()->serialise($member);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveUpdateCustomer(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        $member = ResolverScope::authoriseMemberWrite();

        self::applyWritable($member, self::PROFILE_WRITABLE, $args);
        self::persist($member);

        return CustomerSerializer::create()->serialise($member);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveAddAddress(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        $member = ResolverScope::authoriseMemberWrite();

        $address = Address::create();
        $address->MemberID = $member->ID;
        self::applyWritable($address, self::ADDRESS_WRITABLE, $args);
        self::persist($address);

        return AddressSerializer::create()->serialise($address);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveUpdateAddress(mixed $value = null, array $args = [], array $context = [], ?ResolveInfo $info = null): array
    {
        $member = ResolverScope::authoriseMemberWrite();

        $address = self::ownedAddress($member, (int) ($args['id'] ?? 0));
        self::applyWritable($address, self::ADDRESS_WRITABLE, $args);
        self::persist($address);

        return AddressSerializer::create()->serialise($address);
    }

    private static function ownedAddress(Member $member, int $id): Address
    {
        $address = Address::get()->byID($id);
        if (!$address || !$address->exists() || (int) $address->MemberID !== (int) $member->ID) {
            ResolverScope::fail('AddressNotFound', 'Address not found.');
        }

        return $address;
    }

    /**
     * Apply the allow-listed, present (non-null) arguments onto the record. Throws if nothing writable was supplied.
     *
     * @param array<string, string> $allowed lowerCamelCase arg => DataObject field
     * @param array<string, mixed> $args
     */
    private static function applyWritable(DataObject $record, array $allowed, array $args): void
    {
        $applied = false;
        foreach ($allowed as $arg => $field) {
            if (!array_key_exists($arg, $args) || $args[$arg] === null) {
                continue;
            }
            $record->setField($field, (string) $args[$arg]);
            $applied = true;
        }

        if (!$applied) {
            ResolverScope::fail('NoWritableFields', 'No writable fields supplied.');
        }
    }

    /**
     * Write a record, converting a model validation failure into a GraphQL error (mirrors AbstractResource::persist).
     */
    private static function persist(DataObject $record): void
    {
        try {
            $record->write();
        } catch (ValidationException $e) {
            $messages = array_map(
                static fn (array $m): string => (string) ($m['message'] ?? ''),
                $e->getResult()->getMessages()
            );
            $messages = array_values(array_filter($messages));
            ResolverScope::fail(
                'ValidationFailed',
                'Validation failed: {messages}',
                ['messages' => implode('; ', $messages ?: ['invalid data'])]
            );
        }
    }
}
