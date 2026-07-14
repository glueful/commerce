<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Mail\CommerceMailer;

/** Recording fake for {@see CommerceMailer}; captures every `send()` call verbatim. */
final class RecordingCommerceMailer implements CommerceMailer
{
    /** @var list<array{template:string,order:array<string,mixed>,payload:array<string,mixed>}> */
    public array $calls = [];

    public function send(ApplicationContext $context, string $template, array $order, array $payload = []): void
    {
        $this->calls[] = ['template' => $template, 'order' => $order, 'payload' => $payload];
    }
}
