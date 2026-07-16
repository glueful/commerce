<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Mail;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\NotificationService;

/**
 * Default {@see CommerceMailer} binding: soft posture copied from the Users extension's
 * proven pattern. `NotificationDispatcher`/`NotificationService` are a core binding that is
 * always present (see `Glueful\Container\Providers\NotificationsProvider`); their presence
 * alone does not mean email is deliverable, since core also registers the `database`
 * channel unconditionally. Availability is therefore judged by whether `email` is one of
 * `NotificationDispatcher::getChannelManager()->getActiveChannelNames()` — the same signal
 * `DiagnosticsReport` uses.
 *
 * The master `commerce.email.enabled` switch and per-template config gate are checked
 * BEFORE any container resolution, so a disabled install never touches the notification
 * subsystem at all (no side effects, no log noise) even when it's fully wired.
 */
final class NotificationCommerceMailer implements CommerceMailer
{
    private static bool $inactiveLogged = false;

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload
     */
    public function send(ApplicationContext $context, string $template, array $order, array $payload = []): void
    {
        if (!(bool) config($context, 'commerce.email.enabled', false)) {
            return;
        }
        if (!(bool) config($context, 'commerce.email.templates.' . $template, true)) {
            return;
        }

        $container = container($context);
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = $container->get(NotificationDispatcher::class); // core binding is always present
        $channels = $dispatcher->getChannelManager();
        if (!in_array('email', $channels->getActiveChannelNames(), true)) {
            self::markInactiveOnce();
            return;
        }

        try {
            $rendered = MailTemplates::render($template, $order, $payload);
            $container->get(NotificationService::class)->send(
                'commerce.' . $template,
                new OrderNotifiable($order),
                $rendered['subject'],
                ['body' => $rendered['body'], 'order_uuid' => $order['uuid'] ?? null],
                ['channels' => ['email']]
            );
        } catch (\Throwable $e) {
            // Log-only: mail must never fail a persisted order operation.
            error_log('[commerce] mail send failed: ' . $e->getMessage());
        }
    }

    private static function markInactiveOnce(): void
    {
        if (self::$inactiveLogged) {
            return;
        }

        self::$inactiveLogged = true;
        error_log('[commerce] email channel inactive; commerce transactional mail is a no-op.');
    }
}
