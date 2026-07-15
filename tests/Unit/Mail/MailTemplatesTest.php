<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Mail;

use Glueful\Extensions\Commerce\Mail\MailTemplates;
use PHPUnit\Framework\TestCase;

final class MailTemplatesTest extends TestCase
{
    public function testOrderPlacedIncludesOrderNumber(): void
    {
        $rendered = MailTemplates::render('order_placed', $this->order(), []);

        self::assertStringContainsString('ORD-000123', $rendered['subject']);
        self::assertStringContainsString('ORD-000123', $rendered['body']);
    }

    public function testOrderPaidIncludesOrderNumberAndTotal(): void
    {
        $rendered = MailTemplates::render('order_paid', $this->order(), []);

        self::assertStringContainsString('ORD-000123', $rendered['subject']);
        self::assertStringContainsString('ORD-000123', $rendered['body']);
        // grand_total = 2500 minor units, USD (2 decimals) => 25.00
        self::assertStringContainsString('25.00', $rendered['body']);
        self::assertStringContainsString('USD', $rendered['body']);
    }

    /**
     * Digital-delivery deep links (design spec §6): rendered ONLY when the payload
     * carries a non-empty `downloads` list, and every entry's name and url appear.
     */
    public function testOrderPaidRendersDownloadsSectionWhenLinksArePresent(): void
    {
        $payload = ['downloads' => [
            ['name' => 'Ebook.pdf', 'url' => 'http://localhost/commerce/downloads/abc123'],
            ['name' => 'Bonus.zip', 'url' => 'http://localhost/commerce/downloads/def456'],
        ]];

        $rendered = MailTemplates::render('order_paid', $this->order(), $payload);

        self::assertStringContainsString('Ebook.pdf', $rendered['body']);
        self::assertStringContainsString('http://localhost/commerce/downloads/abc123', $rendered['body']);
        self::assertStringContainsString('Bonus.zip', $rendered['body']);
        self::assertStringContainsString('http://localhost/commerce/downloads/def456', $rendered['body']);
    }

    /**
     * No `downloads` key (physical order, or a second/idempotent re-dispatch) => the
     * body is byte-identical to the pre-Layer-3 rendering; an empty list is treated
     * the same as absent.
     */
    public function testOrderPaidOmitsDownloadsSectionWhenPayloadHasNoDownloads(): void
    {
        $withoutKey = MailTemplates::render('order_paid', $this->order(), []);
        $withEmptyList = MailTemplates::render('order_paid', $this->order(), ['downloads' => []]);

        self::assertSame($withoutKey, $withEmptyList);
        self::assertStringNotContainsString('download', strtolower($withoutKey['body']));
    }

    public function testOrderFulfilledIncludesTrackingRef(): void
    {
        $order = array_merge($this->order(), ['tracking_ref' => 'TRACK-999']);

        $rendered = MailTemplates::render('order_fulfilled', $order, []);

        self::assertStringContainsString('ORD-000123', $rendered['subject']);
        self::assertStringContainsString('TRACK-999', $rendered['body']);
    }

    public function testOrderFulfilledWithoutTrackingRefOmitsIt(): void
    {
        $order = array_merge($this->order(), ['tracking_ref' => null]);

        $rendered = MailTemplates::render('order_fulfilled', $order, []);

        self::assertStringContainsString('ORD-000123', $rendered['body']);
        self::assertStringNotContainsString('Tracking reference', $rendered['body']);
    }

    public function testOrderRefundedFullRefundIncludesAmountAndFullMarker(): void
    {
        $order = $this->order();
        $refund = [
            'amount' => 2500,
            'currency' => 'USD',
            'reason' => 'SECRET-OPERATOR-REASON-must-never-leak',
        ];

        $rendered = MailTemplates::render('order_refunded', $order, $refund);

        self::assertStringContainsString('ORD-000123', $rendered['subject']);
        self::assertStringContainsString('25.00', $rendered['body']);
        self::assertStringContainsString('full', $rendered['body']);
    }

    public function testOrderRefundedPartialRefundIncludesAmountAndPartialMarker(): void
    {
        $order = $this->order();
        $refund = [
            'amount' => 500,
            'currency' => 'USD',
            'reason' => 'SECRET-OPERATOR-REASON-must-never-leak',
        ];

        $rendered = MailTemplates::render('order_refunded', $order, $refund);

        self::assertStringContainsString('5.00', $rendered['body']);
        self::assertStringContainsString('partial', $rendered['body']);
    }

    public function testOrderRefundedNeverLeaksTheOperatorReason(): void
    {
        $order = $this->order();
        $refund = [
            'amount' => 500,
            'currency' => 'USD',
            'reason' => 'SECRET-OPERATOR-REASON-must-never-leak',
            'failure_reason' => 'ANOTHER-SECRET-must-never-leak',
        ];

        $rendered = MailTemplates::render('order_refunded', $order, $refund);

        self::assertStringNotContainsString('SECRET-OPERATOR-REASON-must-never-leak', $rendered['subject']);
        self::assertStringNotContainsString('SECRET-OPERATOR-REASON-must-never-leak', $rendered['body']);
        self::assertStringNotContainsString('ANOTHER-SECRET-must-never-leak', $rendered['body']);
        self::assertStringNotContainsString('reason', strtolower($rendered['body']));
    }

    public function testOrderNoteIncludesTheNoteBody(): void
    {
        $order = $this->order();
        $note = ['body' => 'Your package is delayed by one day.', 'visibility' => 'customer', 'notify' => true];

        $rendered = MailTemplates::render('order_note', $order, $note);

        self::assertStringContainsString('ORD-000123', $rendered['subject']);
        self::assertStringContainsString('Your package is delayed by one day.', $rendered['body']);
    }

    public function testUnknownTemplateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MailTemplates::render('not_a_real_template', $this->order(), []);
    }

    /** @return array<string,mixed> */
    private function order(): array
    {
        return [
            'uuid' => 'ord000000001',
            'order_number' => 'ORD-000123',
            'email' => 'buyer@example.com',
            'currency' => 'USD',
            'grand_total' => 2500,
            'refunded_total' => 0,
            'tracking_ref' => null,
        ];
    }
}
