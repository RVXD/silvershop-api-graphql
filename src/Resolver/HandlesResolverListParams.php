<?php

declare(strict_types=1);

namespace SilverShop\Api\GraphQL\Resolver;

use SilverShop\Api\Control\ApiController;
use SilverShop\Api\GraphQL\Auth\ResolverScope;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataList;

/**
 * Shared `sort` and `limit`/`start` handling for the list resolvers — the GraphQL counterpart of the REST base's
 * {@see \SilverShop\Api\Resource\HandlesListParams}, applying the same allow-listed sort fields and the same
 * configured page-size clamp so the two surfaces paginate and order identically.
 */
trait HandlesResolverListParams
{
    /**
     * Apply `sort=Field` / `sort=-Field` (leading `-` = descending), comma-separated, restricted to the allowed
     * fields.
     *
     * @template T of \SilverStripe\ORM\DataObject
     * @param DataList<T> $list
     * @param array<int, string> $allowed
     * @return DataList<T>
     */
    protected static function applySort(DataList $list, ?string $sort, array $allowed): DataList
    {
        $sort = trim((string) $sort);
        if ($sort === '') {
            return $list;
        }

        $order = [];
        foreach (explode(',', $sort) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $direction = 'ASC';
            if (str_starts_with($segment, '-')) {
                $direction = 'DESC';
                $segment = substr($segment, 1);
            }
            if (!in_array($segment, $allowed, true)) {
                ResolverScope::fail('CannotSort', 'Cannot sort by "{field}".', ['field' => $segment]);
            }
            $order[$segment] = $direction;
        }

        return $order === [] ? $list : $list->sort($order);
    }

    /**
     * Apply `limit`/`start` pagination, clamped to the base's configured maximum page size.
     *
     * @template T of \SilverStripe\ORM\DataObject
     * @param DataList<T> $list
     * @param array<string, mixed> $args
     * @return DataList<T>
     */
    protected static function applyLimit(DataList $list, array $args): DataList
    {
        $config = Config::forClass(ApiController::class);
        $default = (int) $config->get('default_page_size');
        $max = (int) $config->get('max_page_size');

        $limit = (int) ($args['limit'] ?? $default);
        if ($limit <= 0) {
            $limit = $default;
        }
        $limit = min($limit, $max);

        $start = max(0, (int) ($args['start'] ?? 0));

        return $list->limit($limit, $start);
    }
}
