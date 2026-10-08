<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Database\QueryBuilder;

/**
 * The orders a product list can take (1.14.0). Price is the product's lowest ACTIVE variant price;
 * a product with no active variant sorts last both ways. Every order ends on uuid, so pages are
 * stable. Portable SQL: a correlated MIN() and a CASE, no driver functions.
 */
final class ProductSort
{
    public const NEWEST = 'newest';
    public const PRICE_ASC = 'price_asc';
    public const PRICE_DESC = 'price_desc';
    public const NAME = 'name';
    public const ALL = [self::NEWEST, self::PRICE_ASC, self::PRICE_DESC, self::NAME];

    private const MIN_PRICE = '(SELECT MIN(commerce_variants.price) FROM commerce_variants'
        . ' WHERE commerce_variants.product_uuid = commerce_products.uuid'
        . " AND commerce_variants.status = 'active')";

    public static function apply(QueryBuilder $query, string $sort): QueryBuilder
    {
        switch ($sort) {
            case self::PRICE_ASC:
            case self::PRICE_DESC:
                $direction = $sort === self::PRICE_ASC ? 'ASC' : 'DESC';
                $query->orderByRaw('CASE WHEN ' . self::MIN_PRICE . ' IS NULL THEN 1 ELSE 0 END ASC')
                    ->orderByRaw(self::MIN_PRICE . ' ' . $direction)
                    ->orderByRaw('LOWER(commerce_products.name) ASC');
                break;
            case self::NAME:
                $query->orderByRaw('LOWER(commerce_products.name) ASC');
                break;
            default:
                $query->orderBy('created_at', 'DESC');
        }
        return $query->orderBy('uuid', 'ASC');
    }
}
