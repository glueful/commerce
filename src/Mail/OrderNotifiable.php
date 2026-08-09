<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Mail;

use Glueful\Extensions\Commerce\Payments\OrderPayable;
use Glueful\Notifications\Contracts\Notifiable;

/**
 * Lightweight {@see Notifiable} wrapper around a Commerce order row. Routes only the
 * `email` channel (to the order's own `email` column); Commerce has no other notification
 * channel opinion. Matches the framework's five-method contract exactly.
 */
final class OrderNotifiable implements Notifiable
{
    /** @param array<string,mixed> $order */
    public function __construct(private readonly array $order)
    {
    }

    public function routeNotificationFor(string $channel): mixed
    {
        if ($channel !== 'email') {
            return null;
        }

        $email = $this->order['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    public function getNotifiableId(): string
    {
        return (string) ($this->order['uuid'] ?? '');
    }

    public function getNotifiableType(): string
    {
        return OrderPayable::TYPE;
    }

    public function shouldReceiveNotification(string $notificationType, string $channel): bool
    {
        return $channel === 'email';
    }

    /** @return array<string, mixed> */
    public function getNotificationPreferences(): array
    {
        return ['email' => true];
    }
}
