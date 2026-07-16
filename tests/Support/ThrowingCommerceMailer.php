<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Mail\CommerceMailer;

/** Fake {@see CommerceMailer} that always throws, to prove listener exception safety. */
final class ThrowingCommerceMailer implements CommerceMailer
{
    public function send(ApplicationContext $context, string $template, array $order, array $payload = []): void
    {
        throw new \RuntimeException('Simulated mailer failure for template: ' . $template);
    }
}
