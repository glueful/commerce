<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Support;

use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;

/**
 * Recording fake `email` channel for exercising {@see \Glueful\Notifications\Services\ChannelManager}
 * availability without a real email-notification extension installed.
 */
final class RecordingNotificationChannel implements NotificationChannel
{
    /** @var list<array{notifiable:Notifiable,data:array<string,mixed>}> */
    public array $sent = [];

    public function __construct(private readonly bool $available = true)
    {
    }

    public function getChannelName(): string
    {
        return 'email';
    }

    /** @param array<string,mixed> $data */
    public function send(Notifiable $notifiable, array $data): bool
    {
        $this->sent[] = ['notifiable' => $notifiable, 'data' => $data];

        return true;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function format(array $data, Notifiable $notifiable): array
    {
        return $data;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /** @return array<string,mixed> */
    public function getConfig(): array
    {
        return [];
    }
}
