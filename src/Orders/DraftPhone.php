<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Validation\ValidationException;

/**
 * The walk-in phone contract (admin-order-creation cycle 2, Task 9), transcribed
 * from design spec §2.3 verbatim and implemented in exactly the order the spec
 * states it:
 *
 *  1. `phone` is a NULLABLE SCALAR string in international form.
 *  2. Trim it.
 *  3. Cap the DISPLAY input at 32 characters (the `phone_display` column's own
 *     width) -- a longer input is rejected, never silently truncated.
 *  4. Require a leading `+`.
 *  5. Remove ONLY ASCII space, `-`, `(` and `)` for canonicalization. Nothing
 *     else is stripped: a `.`-separated or non-breaking-space-separated number
 *     stays as it was and therefore fails step 6, which is the intent -- this
 *     normalizer is not a permissive parser.
 *  6. Require `\A\+[1-9][0-9]{7,14}\z` of the canonical form (strict E.164: no
 *     leading zero after the `+`, 8-15 digits inclusive).
 *  7. Store the canonical value in `phone_normalized` and the TRIMMED OPERATOR
 *     INPUT in `phone_display` -- the operator's own formatting survives.
 *
 * `null` or an empty trimmed string clears BOTH columns atomically (they are
 * written in the same UPDATE, so there is no state where one is set and the
 * other is not). Every other invalid form is a 422 on the `phone` field.
 *
 * PHONE IS NEVER AN IDENTITY LOOKUP (design Ruling 4). Nothing in this class
 * -- or in {@see DraftOrderService}, its only caller -- reads any table with a
 * phone value: it establishes no ownership, no account link, and no access.
 * It is contact information the operator typed, and nothing else.
 */
final class DraftPhone
{
    /** Matches `commerce_orders.phone_display`'s own column width (migration 022). */
    public const MAX_DISPLAY_LENGTH = 32;

    /** The ONLY characters removed during canonicalization -- ASCII, deliberately. */
    private const STRIPPED = [' ', '-', '(', ')'];

    private const E164 = '/\A\+[1-9][0-9]{7,14}\z/';

    private const INVALID_MESSAGE =
        'phone must be a phone number in international format, e.g. +15550109999.';

    /**
     * @return array{0: string|null, 1: string|null} `[phone_normalized, phone_display]`
     *     -- `[null, null]` for a cleared value.
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null) {
            return [null, null];
        }

        if (!is_scalar($raw)) {
            throw ValidationException::forField('phone', self::INVALID_MESSAGE);
        }

        $display = trim((string) $raw);
        if ($display === '') {
            return [null, null];
        }

        if (mb_strlen($display) > self::MAX_DISPLAY_LENGTH) {
            throw ValidationException::forField(
                'phone',
                'phone must be at most ' . self::MAX_DISPLAY_LENGTH . ' characters.'
            );
        }

        if (!str_starts_with($display, '+')) {
            throw ValidationException::forField('phone', self::INVALID_MESSAGE);
        }

        $canonical = str_replace(self::STRIPPED, '', $display);
        if (preg_match(self::E164, $canonical) !== 1) {
            throw ValidationException::forField('phone', self::INVALID_MESSAGE);
        }

        return [$canonical, $display];
    }
}
