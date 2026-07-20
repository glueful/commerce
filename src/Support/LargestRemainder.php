<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * Generic integer largest-remainder distribution (design spec §5): floor
 * each key's exact share of `$total` proportional to its weight, then hand
 * the leftover units (`$total - sum(floors)`) one each to the keys with the
 * largest remainder, ties broken by ASCENDING key for determinism. When
 * every weight is zero (including an empty `$weights` array), `$total` is
 * instead split evenly by ascending key -- the same tie-break rule applied
 * to a set of equal (zero) remainders. Every input key is present in the
 * result, in the SAME order as `$weights`, even when its allocation is 0.
 * `$total` may be 0, in which case every key gets 0.
 *
 * Shared by {@see \Glueful\Extensions\Commerce\Tax\DiscountAllocation::allocate()}
 * (keyed by line UUID) and `SellerAllocationCalculator` (keyed by seller
 * UUID) so both use the identical deterministic core.
 */
final class LargestRemainder
{
    /**
     * @param array<string,int> $weights non-negative weights keyed by a
     *   stable identifier (line UUID, seller UUID, ...)
     * @return array<string,int> same keys as $weights (same order), values
     *   summing EXACTLY to $total
     */
    public static function distribute(array $weights, int $total): array
    {
        if ($total < 0) {
            throw new \InvalidArgumentException('LargestRemainder::distribute total must be non-negative.');
        }
        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new \InvalidArgumentException('LargestRemainder::distribute weights must be non-negative.');
            }
        }

        if ($weights === []) {
            return [];
        }

        $sumWeights = array_sum($weights);

        $floors = [];
        $remainders = [];
        if ($sumWeights === 0) {
            // No weight information to proportion by: split $total evenly:
            // every key floors to the same share, and the leftover units
            // (all remainders tied at 0) go to the ascending keys first.
            $evenShare = intdiv($total, count($weights));
            foreach (array_keys($weights) as $key) {
                $floors[$key] = $evenShare;
                $remainders[$key] = 0;
            }
            $flooredTotal = $evenShare * count($weights);
        } else {
            $flooredTotal = 0;
            foreach ($weights as $key => $weight) {
                $numerator = $total * $weight;
                $floor = intdiv($numerator, $sumWeights);
                $floors[$key] = $floor;
                $remainders[$key] = $numerator % $sumWeights;
                $flooredTotal += $floor;
            }
        }

        $remainderUnits = $total - $flooredTotal;

        $order = array_keys($weights);
        usort($order, static function (string $a, string $b) use ($remainders): int {
            return $remainders[$a] !== $remainders[$b]
                ? $remainders[$b] <=> $remainders[$a]
                : $a <=> $b;
        });

        $bonusKeys = array_fill_keys(array_slice($order, 0, $remainderUnits), true);

        $result = [];
        foreach ($weights as $key => $weight) {
            $result[$key] = $floors[$key] + (isset($bonusKeys[$key]) ? 1 : 0);
        }

        return $result;
    }
}
