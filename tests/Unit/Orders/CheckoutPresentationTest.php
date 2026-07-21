<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Commerce\Orders\CheckoutPresentation;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class CheckoutPresentationTest extends TestCase
{
    private const POISON = 'poison-marker-do-not-leak';

    public function testManualCollectorRealOutputProducesManualVmWithExactlyTheAllowlistedKeys(): void
    {
        $initiation = (new ManualPaymentCollector())->initiate(
            $this->context(),
            $this->payable()
        );

        // Mirror CheckoutService::initiatePayment()'s exact wrapping shape, but
        // smuggle a poison key into the collector's payload the way a future/
        // malicious collector implementation might.
        $paymentResult = [
            'status' => $initiation->status,
            'provider' => $initiation->provider,
            'payload' => $initiation->payload + ['secret_token' => self::POISON, 'api_key' => self::POISON],
        ];

        $vm = (new CheckoutPresentation())->present($paymentResult);

        self::assertSame(['action' => 'manual', 'instructions' => $initiation->payload['instructions']], $vm);
        self::assertArrayNotHasKey('secret_token', $vm);
        self::assertArrayNotHasKey('api_key', $vm);
        self::assertArrayNotHasKey('payload', $vm);
        $this->assertPoisonNeverLeaks($vm);
    }

    public function testHttpUrlIsUnavailable(): void
    {
        $vm = (new CheckoutPresentation())->present($this->okPayload(['checkout_url' => 'http://pay.example.com/x']));

        self::assertSame(['action' => 'unavailable'], $vm);
        $this->assertPoisonNeverLeaks($vm);
    }

    public function testRelativeUrlIsUnavailable(): void
    {
        $vm = (new CheckoutPresentation())->present($this->okPayload(['checkout_url' => '/checkout/pay']));

        self::assertSame(['action' => 'unavailable'], $vm);
    }

    public function testJavascriptSchemeUrlIsUnavailable(): void
    {
        $vm = (new CheckoutPresentation())->present(
            $this->okPayload(['checkout_url' => 'javascript:alert(document.cookie)'])
        );

        self::assertSame(['action' => 'unavailable'], $vm);
    }

    public function testProtocolRelativeUrlIsUnavailable(): void
    {
        $vm = (new CheckoutPresentation())->present($this->okPayload(['checkout_url' => '//evil.example.com/x']));

        self::assertSame(['action' => 'unavailable'], $vm);
    }

    public function testValidHttpsUrlProducesRedirectVm(): void
    {
        $vm = (new CheckoutPresentation())->present(
            $this->okPayload([
                'checkout_url' => 'https://pay.example.com/session/abc123',
                'reference' => 'ref-1',
                'gateway' => 'paystack',
            ])
        );

        self::assertSame(['action' => 'redirect', 'redirect_url' => 'https://pay.example.com/session/abc123'], $vm);
        $this->assertPoisonNeverLeaks($vm);
    }

    public function testRedirectPoisonPayloadKeysNeverLeak(): void
    {
        $vm = (new CheckoutPresentation())->present($this->okPayload([
            'checkout_url' => 'https://pay.example.com/session/abc123',
            'secret_token' => self::POISON,
        ]));

        self::assertSame(['action' => 'redirect', 'redirect_url' => 'https://pay.example.com/session/abc123'], $vm);
        $this->assertPoisonNeverLeaks($vm);
    }

    public function testOpaqueReferenceWithNoUrlProducesReferenceVm(): void
    {
        $vm = (new CheckoutPresentation())->present(
            $this->okPayload(['reference' => 'txn_ref_9', 'gateway' => 'bank_transfer'])
        );

        self::assertSame(['action' => 'reference', 'reference' => 'txn_ref_9', 'gateway' => 'bank_transfer'], $vm);
        $this->assertPoisonNeverLeaks($vm);
    }

    public function testReferenceWithoutGatewayOmitsTheOptionalKey(): void
    {
        $vm = (new CheckoutPresentation())->present($this->okPayload(['reference' => 'txn_ref_9']));

        self::assertSame(['action' => 'reference', 'reference' => 'txn_ref_9'], $vm);
    }

    public function testReferencePoisonPayloadKeysNeverLeak(): void
    {
        $vm = (new CheckoutPresentation())->present($this->okPayload([
            'reference' => 'txn_ref_9',
            'gateway' => 'bank_transfer',
            'internal_note' => self::POISON,
            'customer_pii' => self::POISON,
        ]));

        self::assertSame(['action' => 'reference', 'reference' => 'txn_ref_9', 'gateway' => 'bank_transfer'], $vm);
        $this->assertPoisonNeverLeaks($vm);
    }

    public function testUnknownProviderShapeIsUnavailableAndLogged(): void
    {
        $logger = new RecordingLogger();

        $vm = (new CheckoutPresentation($logger))->present([
            'status' => 'weird_new_status',
            'provider' => 'mystery',
            'payload' => ['whatever' => self::POISON],
        ]);

        self::assertSame(['action' => 'unavailable'], $vm);
        self::assertNotEmpty($logger->records, 'Expected an unrecognized payment shape to be logged.');
        $this->assertPoisonNeverLeaks($vm);
        foreach ($logger->records as $record) {
            self::assertStringNotContainsString(self::POISON, (string) $record['message']);
            self::assertStringNotContainsString(self::POISON, json_encode($record['context']) ?: '');
        }
    }

    public function testInitiationFailureShapeIsUnavailable(): void
    {
        // The exact catch-branch shape CheckoutService::initiatePayment() returns
        // when the collector throws -- no 'provider'/'payload' keys at all.
        $vm = (new CheckoutPresentation())->present(['status' => 'init_failed', 'retryable' => true]);

        self::assertSame(['action' => 'unavailable'], $vm);
    }

    public function testCompletelyMalformedInputIsUnavailableAndNeverEchoesPoison(): void
    {
        $logger = new RecordingLogger();

        $vm = (new CheckoutPresentation($logger))->present([
            'unexpected_key' => self::POISON,
            'nested' => ['deep' => self::POISON],
        ]);

        self::assertSame(['action' => 'unavailable'], $vm);
        $this->assertPoisonNeverLeaks($vm);
    }

    public function testManualStatusWithoutInstructionsIsUnavailableNotAFabricatedManualVm(): void
    {
        $vm = (new CheckoutPresentation())->present([
            'status' => 'manual',
            'provider' => 'manual',
            'payload' => ['not_instructions' => self::POISON],
        ]);

        self::assertSame(['action' => 'unavailable'], $vm);
    }

    public function testOkStatusWithNeitherUrlNorReferenceIsUnavailable(): void
    {
        $vm = (new CheckoutPresentation())->present($this->okPayload(['random_field' => self::POISON]));

        self::assertSame(['action' => 'unavailable'], $vm);
    }

    public function testDefaultConstructionRequiresNoLoggerAndStillWorks(): void
    {
        // Production wiring soft-resolves a bound logger; the class must still
        // be usable with zero collaborators (e.g. constructed directly in tests).
        $vm = (new CheckoutPresentation())->present(['status' => 'nonsense']);

        self::assertSame(['action' => 'unavailable'], $vm);
    }

    public function testRegisteredAsASharedServiceInTheProvider(): void
    {
        $services = CommerceServiceProvider::services();

        self::assertArrayHasKey(CheckoutPresentation::class, $services);
        self::assertTrue($services[CheckoutPresentation::class]['shared'] ?? false);
    }

    /** @param array<string,mixed> $vm */
    private function assertPoisonNeverLeaks(array $vm): void
    {
        self::assertStringNotContainsString(self::POISON, json_encode($vm) ?: '');
    }

    /** @param array<string,mixed> $payload */
    private function okPayload(array $payload): array
    {
        return ['status' => 'ok', 'provider' => 'fake', 'payload' => $payload];
    }

    private function context(): ApplicationContext
    {
        return new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
    }

    private function payable(): PayableReference
    {
        return new PayableReference('commerce_order', 'order0000001', 1000, 'USD');
    }
}

/** In-memory spy logger -- records every call without any external dependency. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level:mixed,message:string|Stringable,context:array<mixed,mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}
