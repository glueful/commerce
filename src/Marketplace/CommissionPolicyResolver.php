<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Validates and resolves commission policy (design spec §2.2): three
 * inheritable database levels (product, seller, workspace-settings) plus a
 * total config fallback, walked **product -> seller -> workspace ->
 * config**. Pure -- no DB/context, no side effects.
 *
 * **Validation ({@see self::validate()}).** All three of `kind`/`bps`/
 * `fixed` null at a level means "inherit the next level"; a concrete policy
 * is either `percentage` (only `bps`, 0..10000 inclusive, `fixed` null) or
 * `fixed` (only `fixed`, >=0, `bps` null). Any other combination -- a
 * required companion missing, both companions set, an out-of-range `bps`,
 * a negative `fixed`, or an unrecognized `kind` -- is rejected with
 * {@see CommissionPolicyException}.
 *
 * **Resolution ({@see self::resolve()}).** The first level (in
 * product -> seller -> workspace -> config order) whose `kind` is non-null
 * wins. The config level is the total fallback -- it must never be
 * all-null (its documented default is `{kind: 'percentage', bps: 0,
 * fixed: null}`) -- so if resolution reaches an all-null config level, that
 * is a configuration error and also throws {@see CommissionPolicyException}:
 * policy resolution must always be total. `resolve()` assumes each level is
 * already `validate()`-clean but still independently guarantees totality.
 */
final class CommissionPolicyResolver
{
    /** Precedence order, index-aligned with the `$levels` argument to {@see self::resolve()}. */
    private const SOURCES = ['product', 'seller', 'workspace', 'config'];

    private const MIN_BPS = 0;
    private const MAX_BPS = 10000;

    /**
     * The three raw commission-policy column names a mutation request may
     * carry (design spec §2.2/§2.3/§3.1) -- the shared vocabulary
     * {@see self::extractFromChanges()}/{@see self::withoutFields()} and every
     * seller-write commission-field rejection (Task 4) key off, so the field
     * list itself never drifts between the resolver, the operator write
     * paths, and the seller-rejection guards.
     */
    public const FIELDS = ['commission_kind', 'commission_bps', 'commission_fixed'];

    /**
     * Pulls a whole-policy replacement out of a raw `$changes`/request-body
     * array (design spec §2.3, MV3 Task 4): commission policy is one atomic
     * `{kind,bps,fixed}` tuple, never a per-column PATCH, so touching ANY of
     * {@see self::FIELDS} triggers a full replace -- an omitted key within
     * the trio is treated as an explicit `null`, never "leave unchanged".
     * Returns null when $changes touches none of the three fields at all
     * (the ordinary "no commission change" case).
     *
     * @param array<string,mixed> $changes
     * @return array{kind:?string,bps:?int,fixed:?int}|null
     */
    public static function extractFromChanges(array $changes): ?array
    {
        if (array_intersect_key($changes, array_flip(self::FIELDS)) === []) {
            return null;
        }

        return [
            'kind' => array_key_exists('commission_kind', $changes) && $changes['commission_kind'] !== null
                ? (string) $changes['commission_kind']
                : null,
            'bps' => array_key_exists('commission_bps', $changes) && $changes['commission_bps'] !== null
                ? (int) $changes['commission_bps']
                : null,
            'fixed' => array_key_exists('commission_fixed', $changes) && $changes['commission_fixed'] !== null
                ? (int) $changes['commission_fixed']
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public static function withoutFields(array $changes): array
    {
        foreach (self::FIELDS as $field) {
            unset($changes[$field]);
        }

        return $changes;
    }

    public static function validate(?string $kind, ?int $bps, ?int $fixed): void
    {
        if ($kind === null && $bps === null && $fixed === null) {
            return;
        }

        if ($kind === 'percentage') {
            if ($fixed !== null) {
                throw new CommissionPolicyException(
                    'commission_fixed: must be null when commission_kind is percentage.'
                );
            }
            if ($bps === null) {
                throw new CommissionPolicyException(
                    'commission_bps: required when commission_kind is percentage.'
                );
            }
            if ($bps < self::MIN_BPS || $bps > self::MAX_BPS) {
                throw new CommissionPolicyException('commission_bps: must be between 0 and 10000 inclusive.');
            }
            return;
        }

        if ($kind === 'fixed') {
            if ($bps !== null) {
                throw new CommissionPolicyException('commission_bps: must be null when commission_kind is fixed.');
            }
            if ($fixed === null) {
                throw new CommissionPolicyException('commission_fixed: required when commission_kind is fixed.');
            }
            if ($fixed < 0) {
                throw new CommissionPolicyException('commission_fixed: must be non-negative.');
            }
            return;
        }

        if ($kind === null) {
            throw new CommissionPolicyException(
                'commission_kind: required when commission_bps or commission_fixed is set.'
            );
        }

        throw new CommissionPolicyException("commission_kind: unrecognized kind '{$kind}'.");
    }

    /**
     * @param list<array{kind:?string,bps:?int,fixed:?int}> $levels ordered
     *   `[product, seller, workspace, config]`
     * @return array{source:'product'|'seller'|'workspace'|'config',kind:string,bps:?int,fixed:?int}
     */
    public static function resolve(array $levels): array
    {
        foreach (self::SOURCES as $index => $source) {
            $level = $levels[$index];
            $kind = $level['kind'];

            if ($kind !== null) {
                /** @var 'product'|'seller'|'workspace'|'config' $source */
                return [
                    'source' => $source,
                    'kind' => $kind,
                    'bps' => $level['bps'],
                    'fixed' => $level['fixed'],
                ];
            }
        }

        throw new CommissionPolicyException(
            'Commission policy resolution is not total: the config level must have a non-null commission_kind.'
        );
    }
}
