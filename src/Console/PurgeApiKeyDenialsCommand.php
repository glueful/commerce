<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seller-API-key `auth_denied` retention cleanup (design spec §2.10, MV5c-1
 * Task 7): deletes every `commerce_seller_api_key_events` row whose
 * `action = 'auth_denied'` AND `created_at` is older than
 * `commerce.marketplace.api_keys.auth_denied_retention_days` (default 90,
 * design spec §2.10/§3-config, `config/commerce.php`). Host-cron-invoked --
 * Commerce owns no scheduler of its own, mirroring every other MV4/MV5a
 * sweep command ({@see ReservesReleaseSweepCommand}, {@see PayoutsReconcileSweepCommand}).
 *
 * **Tenant-safe by construction, not by filtering:** this is a SINGLE
 * cross-tenant sweep on `action = 'auth_denied' AND created_at < threshold`
 * (the `(action, created_at)` index migration 018 adds specifically for this
 * sweep -- see {@see \Glueful\Extensions\Commerce\Database\Migrations\CreateSellerApiKeysTables}'s
 * own docblock) -- there is no per-tenant retention override to honor, so no
 * per-tenant discovery loop is needed (unlike {@see ReservesReleaseSweepCommand},
 * whose POLICY is genuinely per-seller). The `action = 'auth_denied'` predicate
 * is what makes this safe for every tenant simultaneously: it can NEVER touch a
 * `created`/`rotated`/`revoked` row (permanent, design spec §2.10 -- "Mutation
 * events are permanent and atomic with their mutation"), regardless of tenant.
 *
 * **Expiry honored before cleanup (design spec §2.10):** reads
 * ({@see \Glueful\Extensions\Commerce\Marketplace\SellerApiKeyRepository}'s
 * own list/audit surfaces) never special-case a stale `auth_denied` row --
 * this command is the ONLY place retention is enforced; a row past retention
 * that this command has not yet swept is still ordinary, readable audit
 * history until the day this command actually deletes it. There is no
 * separate "ignore expired rows on read" branch to keep in sync.
 */
#[AsCommand(
    name: 'commerce:marketplace:api-keys:purge-denials',
    description: 'Delete commerce_seller_api_key_events auth_denied rows past the configured retention window'
)]
final class PurgeApiKeyDenialsCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();

        $retentionDays = max(
            0,
            (int) config($context, 'commerce.marketplace.api_keys.auth_denied_retention_days', 90)
        );
        $threshold = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));

        $deleted = db($context)->table('commerce_seller_api_key_events')
            ->where('action', '=', 'auth_denied')
            ->where('created_at', '<', $threshold)
            ->delete();

        $this->info(sprintf(
            'Purged %d auth_denied seller API key event(s) older than %d day(s) (before %s UTC).',
            $deleted,
            $retentionDays,
            $threshold
        ));

        $this->success('Seller API key auth_denied retention sweep complete.');

        return self::SUCCESS;
    }
}
