<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderDraftController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\DraftOrderProjection;
use Glueful\Extensions\Commerce\Http\Admin\OrderProjection;
use Glueful\Extensions\Commerce\Http\DTOs\AdminProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\DraftOrderListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\OrderListQuery;
use Glueful\Extensions\Commerce\Http\Storefront\StorefrontOrderProjection;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Orders\DraftCleanupService;
use Glueful\Extensions\Commerce\Orders\DraftLineEligibility;
use Glueful\Extensions\Commerce\Orders\DraftOrderService;
use Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PurchasableLineResolver;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Admin-order-creation cycle 2, Task 9 (design spec §2.3): the engine's draft
 * order API and the ONE closed eligibility authority its product-search
 * projection shares with its own line-mutation rejection.
 *
 * Everything asserted here is a contract, not an implementation detail:
 *  - a fully ANONYMOUS `in_store` draft is valid (no placeholder email ever);
 *  - the phone contract is exactly the spec's (trim / cap 32 / leading `+` /
 *    strip only ASCII space, `-`, `(`, `)` / strict E.164), stored split across
 *    `phone_normalized` + `phone_display`, cleared atomically, never a lookup;
 *  - user attachment resolves an ACTIVE user through the framework user
 *    provider -- unknown and inactive are ONE neutral 422, and a supplied email
 *    that disagrees with the resolved user's is a typed 409 that links nothing;
 *  - every customer/line/shipping/mode mutation CAS-increments `draft_revision`
 *    and a stale expected revision is a typed 409;
 *  - `digital` and marketplace-partitioned lines are typed rejections at
 *    mutation time, carrying the SAME closed reason the admin product search
 *    already published;
 *  - drafts stay off every finalized surface and the storefront wire stays
 *    closed to the walk-in PII columns.
 */
final class AdminOrderDraftApiTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // create
    // -----------------------------------------------------------------

    public function testCreateDefaultsToAnAnonymousInStoreDraftWithNoPlaceholderIdentity(): void
    {
        $response = $this->controller()->store($this->request('POST', []));
        $body = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        $draft = $body['data'];

        self::assertSame('draft', $draft['status']);
        self::assertSame('in_store', $draft['fulfillment_mode']);
        self::assertSame('admin', $draft['origin']);
        self::assertSame(0, (int) $draft['draft_revision']);
        self::assertNull($draft['order_number']);
        self::assertNull($draft['email']);
        self::assertNull($draft['user_uuid']);
        self::assertNull($draft['customer_name']);
        self::assertNull($draft['phone_normalized']);
        self::assertNull($draft['phone_display']);
        self::assertSame(0, (int) $draft['grand_total']);
        self::assertSame([], $draft['lines']);

        // No guest credential is ever minted for an admin-created order.
        $row = $this->orderRow((string) $draft['uuid']);
        self::assertNull($row['guest_token_hash']);
        self::assertSame([DraftOrderEvents::CREATED], $this->eventTypesFor((string) $draft['uuid']));
    }

    /**
     * The Task 6 defeat-the-default convention: drop the standing DB defaults
     * for `origin`/`fulfillment_mode` (keeping NOT NULL) so a writer that
     * merely leaned on them would fail loudly, then prove the real create path
     * still succeeds -- only possible if it writes both columns explicitly.
     */
    public function testCreateWritesOriginAndFulfillmentModeExplicitlyNotViaTheColumnDefault(): void
    {
        $this->connection->getSchemaBuilder()->alterTable('commerce_orders', function ($table): void {
            $table->modifyColumn('origin')->string(16)->notNull();
            $table->modifyColumn('fulfillment_mode')->string(16)->notNull();
        });

        $draft = $this->json($this->controller()->store($this->request('POST', [
            'fulfillment_mode' => 'delivery',
        ])))['data'];

        self::assertSame('admin', $draft['origin']);
        self::assertSame('delivery', $draft['fulfillment_mode']);
    }

    public function testCreateRejectsAnUnknownFulfillmentMode(): void
    {
        $response = $this->controller()->store($this->request('POST', ['fulfillment_mode' => 'teleport']));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('fulfillment_mode', $this->json($response)['error']['details']);
    }

    public function testCreateAcceptsAnInitialCustomerBlock(): void
    {
        $draft = $this->json($this->controller()->store($this->request('POST', [
            'customer_name' => '  Ada Lovelace  ',
            'email' => ' ada@example.com ',
            'phone' => '+1 (555) 010-9999',
        ])))['data'];

        self::assertSame('Ada Lovelace', $draft['customer_name']);
        self::assertSame('ada@example.com', $draft['email']);
        self::assertSame('+15550109999', $draft['phone_normalized']);
        self::assertSame('+1 (555) 010-9999', $draft['phone_display']);
    }

    // -----------------------------------------------------------------
    // phone contract (spec §2.3, verbatim)
    // -----------------------------------------------------------------

    /** @return list<array{0:string,1:string,2:string}> */
    public static function validPhoneProvider(): array
    {
        return [
            'plain e164' => ['+15550109999', '+15550109999', '+15550109999'],
            'spaces' => ['+44 20 7946 0958', '+442079460958', '+44 20 7946 0958'],
            'dashes and parens' => ['+1 (555) 010-9999', '+15550109999', '+1 (555) 010-9999'],
            'outer whitespace trimmed' => ["  +233201234567\t", '+233201234567', '+233201234567'],
            'shortest legal' => ['+12345678', '+12345678', '+12345678'],
            'longest legal' => ['+123456789012345', '+123456789012345', '+123456789012345'],
        ];
    }

    /** @dataProvider validPhoneProvider */
    public function testPhoneCanonicalizationStoresBothForms(string $input, string $canonical, string $display): void
    {
        $uuid = $this->createDraft();

        $draft = $this->json($this->controller()->update($this->request('PATCH', ['phone' => $input]), $uuid))['data'];

        self::assertSame($canonical, $draft['phone_normalized']);
        self::assertSame($display, $draft['phone_display']);
    }

    /** @return list<array{0:mixed}> */
    public static function invalidPhoneProvider(): array
    {
        return [
            'no plus' => ['15550109999'],
            'leading zero after plus' => ['+05550109999'],
            'too short' => ['+1234567'],
            'too long' => ['+1234567890123456'],
            'letters' => ['+1555ABC9999'],
            'dot separators are not stripped' => ['+1.555.010.9999'],
            'over 32 display characters' => ['+1 (555) 010-9999 ext. 12345678901234'],
            'non scalar' => [['+15550109999']],
        ];
    }

    /** @dataProvider invalidPhoneProvider */
    public function testInvalidPhoneFormsAreRejected(mixed $input): void
    {
        $uuid = $this->createDraft();

        $response = $this->controller()->update($this->request('PATCH', ['phone' => $input]), $uuid);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('phone', $this->json($response)['error']['details']);
        // A rejected mutation leaves the draft (and its revision) untouched.
        self::assertSame(0, (int) $this->orderRow($uuid)['draft_revision']);
    }

    /** @return list<array{0:mixed}> */
    public static function clearingPhoneProvider(): array
    {
        return [[null], [''], ['   ']];
    }

    /** @dataProvider clearingPhoneProvider */
    public function testNullOrEmptyPhoneClearsBothColumnsAtomically(mixed $input): void
    {
        $uuid = $this->createDraft();
        $this->controller()->update($this->request('PATCH', ['phone' => '+15550109999']), $uuid);
        self::assertNotNull($this->orderRow($uuid)['phone_normalized']);

        $draft = $this->json($this->controller()->update($this->request('PATCH', ['phone' => $input]), $uuid))['data'];

        self::assertNull($draft['phone_normalized']);
        self::assertNull($draft['phone_display']);
    }

    public function testPhoneIsNeverAnIdentityLookup(): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();

        $draft = $this->json($this->controller()->update(
            $this->request('PATCH', ['phone' => '+15550109999']),
            $uuid
        ))['data'];

        self::assertNull($draft['user_uuid'], 'a phone number must never establish an account link');
        self::assertNull($draft['email']);
    }

    // -----------------------------------------------------------------
    // user attachment
    // -----------------------------------------------------------------

    public function testAttachingAnActiveUserLinksTheDraft(): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();

        $draft = $this->json($this->controller()->update(
            $this->request('PATCH', ['user_uuid' => 'usr000000001']),
            $uuid
        ))['data'];

        self::assertSame('usr000000001', $draft['user_uuid']);
        self::assertSame(1, (int) $draft['draft_revision']);
    }

    /** @return list<array{0:string}> */
    public static function unattachableUserProvider(): array
    {
        return [
            'unknown uuid' => ['usr999999999'],
            'inactive user' => ['usr000000002'],
        ];
    }

    /** @dataProvider unattachableUserProvider */
    public function testUnknownAndInactiveUsersBothReturnOneNeutralValidationError(string $userUuid): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
            'usr000000002' => new UserIdentity('usr000000002', [], [], [], 'sus@example.com', 'sus', 'suspended'),
        ]);
        $uuid = $this->createDraft();

        $response = $this->controller()->update($this->request('PATCH', ['user_uuid' => $userUuid]), $uuid);
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            DraftOrderService::UNATTACHABLE_USER_MESSAGE,
            $body['error']['details']['user_uuid'],
            'unknown and inactive must be indistinguishable to the caller'
        );
        self::assertNull($this->orderRow($uuid)['user_uuid']);
        self::assertSame(0, (int) $this->orderRow($uuid)['draft_revision']);
    }

    public function testASuppliedEmailThatMismatchesTheResolvedUserIsATypedConflictAndLinksNothing(): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();

        $response = $this->controller()->update($this->request('PATCH', [
            'user_uuid' => 'usr000000001',
            'email' => 'someone-else@example.com',
        ]), $uuid);
        $body = $this->json($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('user_email_mismatch', $body['error']['details']['conflict']);

        $row = $this->orderRow($uuid);
        self::assertNull($row['user_uuid'], 'the order must remain unlinked');
        self::assertNull($row['email']);
        self::assertSame(0, (int) $row['draft_revision']);
    }

    /**
     * The guard is EFFECTIVE-STATE, not per-request. Splitting the two fields
     * across two PATCHes -- in EITHER order -- must not be a bypass, or a draft
     * could sit linked to one account while carrying a foreign email, and
     * finalize would mail an address that account never gave.
     */
    public function testAttachingAUserThenSettingAForeignEmailIsStillATypedConflict(): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();
        $this->controller()->update($this->request('PATCH', ['user_uuid' => 'usr000000001']), $uuid);
        self::assertSame('usr000000001', $this->orderRow($uuid)['user_uuid']);

        $response = $this->controller()->update(
            $this->request('PATCH', ['email' => 'someone-else@example.com']),
            $uuid
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('user_email_mismatch', $this->json($response)['error']['details']['conflict']);

        $row = $this->orderRow($uuid);
        self::assertNull($row['email'], 'the foreign email must not be stored');
        self::assertSame('usr000000001', $row['user_uuid']);
        self::assertSame(1, (int) $row['draft_revision']);
    }

    /** The reverse ordering of the same bypass. */
    public function testSettingAnEmailThenAttachingADisagreeingUserIsStillATypedConflict(): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();
        $this->controller()->update($this->request('PATCH', ['email' => 'someone-else@example.com']), $uuid);

        $response = $this->controller()->update($this->request('PATCH', ['user_uuid' => 'usr000000001']), $uuid);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('user_email_mismatch', $this->json($response)['error']['details']['conflict']);

        $row = $this->orderRow($uuid);
        self::assertNull($row['user_uuid'], 'the order must remain unlinked');
        self::assertSame('someone-else@example.com', $row['email']);
        self::assertSame(1, (int) $row['draft_revision']);
    }

    public function testAttachingAUserThenSettingTheAccountsOwnEmailAcrossTwoRequestsIsAccepted(): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();
        $this->controller()->update($this->request('PATCH', ['user_uuid' => 'usr000000001']), $uuid);

        $response = $this->controller()->update($this->request('PATCH', ['email' => 'ADA@example.com']), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ADA@example.com', $this->orderRow($uuid)['email']);
    }

    /**
     * Clearing EITHER side is always legal: an unlinked draft with an email and
     * a linked draft with no email are both legitimate states, so the guard must
     * only ever fire on a genuine disagreement between two PRESENT values.
     */
    public function testDetachingTheUserFreesTheDraftToCarryAnyEmail(): void
    {
        $uuid = $this->linkedDraft();

        $detach = $this->controller()->update($this->request('PATCH', ['user_uuid' => null]), $uuid);
        self::assertSame(200, $detach->getStatusCode());
        self::assertNull($this->orderRow($uuid)['user_uuid']);

        // The very email that would have conflicted a moment ago is now fine.
        $response = $this->controller()->update(
            $this->request('PATCH', ['email' => 'someone-else@example.com']),
            $uuid
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('someone-else@example.com', $this->orderRow($uuid)['email']);
    }

    public function testClearingTheEmailIsAllowedOnALinkedDraftButAForeignEmailStillConflicts(): void
    {
        $uuid = $this->linkedDraft();

        $clear = $this->controller()->update($this->request('PATCH', ['email' => null]), $uuid);
        self::assertSame(200, $clear->getStatusCode());
        self::assertNull($this->orderRow($uuid)['email']);
        self::assertSame('usr000000001', $this->orderRow($uuid)['user_uuid']);

        // Still linked, so a foreign email is still refused.
        $response = $this->controller()->update(
            $this->request('PATCH', ['email' => 'someone-else@example.com']),
            $uuid
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertNull($this->orderRow($uuid)['email']);
    }

    /**
     * Fails CLOSED: a stored link whose account no longer resolves (deleted or
     * suspended since attachment) cannot confirm an incoming email, so the pair
     * is refused rather than persisted unverifiable.
     */
    public function testAnEmailAgainstAStoredUserThatNoLongerResolvesIsATypedConflict(): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();
        $this->controller()->update($this->request('PATCH', ['user_uuid' => 'usr000000001']), $uuid);

        // The account disappears from the provider between requests.
        $this->bindUserProvider([]);

        $response = $this->controller()->update($this->request('PATCH', ['email' => 'ada@example.com']), $uuid);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('user_email_mismatch', $this->json($response)['error']['details']['conflict']);
        self::assertNull($this->orderRow($uuid)['email']);
    }

    /**
     * A mutation that touches NEITHER identity field must never consult the user
     * provider -- otherwise every line add on a linked draft would pay for (and
     * could fail on) an unrelated identity lookup.
     */
    public function testANonIdentityMutationOnALinkedDraftNeverConsultsTheUserProvider(): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();
        $this->controller()->update($this->request('PATCH', ['user_uuid' => 'usr000000001']), $uuid);
        $this->userProviderLookups = 0;

        $response = $this->controller()->update($this->request('PATCH', ['customer_name' => 'Counter sale']), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->userProviderLookups);
    }

    public function testASuppliedEmailMatchingTheResolvedUserLinksAndStoresBoth(): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();

        $draft = $this->json($this->controller()->update($this->request('PATCH', [
            'user_uuid' => 'usr000000001',
            'email' => 'ADA@example.com',
        ]), $uuid))['data'];

        self::assertSame('usr000000001', $draft['user_uuid']);
        self::assertSame('ADA@example.com', $draft['email']);
    }

    public function testAttachmentFailsClosedWhenNoUserProviderIsBound(): void
    {
        $uuid = $this->createDraft();

        $response = $this->controller()->update($this->request('PATCH', ['user_uuid' => 'usr000000001']), $uuid);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            DraftOrderService::UNATTACHABLE_USER_MESSAGE,
            $this->json($response)['error']['details']['user_uuid']
        );
        self::assertNull($this->orderRow($uuid)['user_uuid']);
    }

    public function testANullUserUuidDetachesWithoutConsultingTheProvider(): void
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();
        $this->controller()->update($this->request('PATCH', ['user_uuid' => 'usr000000001']), $uuid);

        $draft = $this->json($this->controller()->update($this->request('PATCH', ['user_uuid' => null]), $uuid))['data'];

        self::assertNull($draft['user_uuid']);
    }

    // -----------------------------------------------------------------
    // fulfillment mode + shipping
    // -----------------------------------------------------------------

    public function testInStoreDraftsRejectAddressesAndShippingMethods(): void
    {
        $uuid = $this->createDraft();

        $addresses = $this->controller()->update(
            $this->request('PATCH', ['addresses' => ['shipping' => ['country' => 'US']]]),
            $uuid
        );
        self::assertSame(422, $addresses->getStatusCode());
        self::assertArrayHasKey('addresses', $this->json($addresses)['error']['details']);

        $method = $this->controller()->update($this->request('PATCH', ['shipping_method' => 'std']), $uuid);
        self::assertSame(422, $method->getStatusCode());
        self::assertArrayHasKey('shipping_method', $this->json($method)['error']['details']);
    }

    public function testDeliveryAcceptsOnlyALiveQuotedShippingMethodId(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-SHIP-1', 1500);
        $uuid = $this->createDraft(['fulfillment_mode' => 'delivery']);
        $this->controller()->storeLine($this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]), $uuid);
        $this->controller()->update(
            $this->request('PATCH', ['addresses' => ['shipping' => ['country' => 'US']]]),
            $uuid
        );

        $rejected = $this->controller()->update($this->request('PATCH', ['shipping_method' => 'hyperloop']), $uuid);
        self::assertSame(422, $rejected->getStatusCode());
        self::assertArrayHasKey('shipping_method', $this->json($rejected)['error']['details']);

        $accepted = $this->json($this->controller()->update(
            $this->request('PATCH', ['shipping_method' => 'std']),
            $uuid
        ))['data'];
        self::assertSame('std', $accepted['shipping_method']);
        self::assertSame(500, (int) $accepted['shipping_total'], 'the amount comes from the live quote, never input');
    }

    public function testShippingAmountsAreNeverAcceptedFromTheClient(): void
    {
        $uuid = $this->createDraft(['fulfillment_mode' => 'delivery']);

        $draft = $this->json($this->controller()->update(
            $this->request('PATCH', ['shipping_total' => 999999, 'grand_total' => 1]),
            $uuid
        ))['data'];

        self::assertSame(0, (int) $draft['shipping_total']);
        self::assertSame(0, (int) $draft['grand_total']);
    }

    public function testSwitchingDeliveryToInStoreClearsTheShippingSelectionAndRecalculates(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-SHIP-2', 1500);
        $uuid = $this->createDraft(['fulfillment_mode' => 'delivery']);
        $this->controller()->storeLine($this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 2]), $uuid);
        $this->controller()->update(
            $this->request('PATCH', ['addresses' => ['shipping' => ['country' => 'US']]]),
            $uuid
        );
        $withShipping = $this->json($this->controller()->update(
            $this->request('PATCH', ['shipping_method' => 'std']),
            $uuid
        ))['data'];
        self::assertSame(500, (int) $withShipping['shipping_total']);
        self::assertSame(3500, (int) $withShipping['grand_total']);

        $switched = $this->json($this->controller()->update(
            $this->request('PATCH', ['fulfillment_mode' => 'in_store']),
            $uuid
        ))['data'];

        self::assertSame('in_store', $switched['fulfillment_mode']);
        self::assertNull($switched['shipping_method']);
        self::assertSame(0, (int) $switched['shipping_total']);
        self::assertNull($switched['addresses']);
        self::assertSame(3000, (int) $switched['grand_total']);
    }

    // -----------------------------------------------------------------
    // line mutations
    // -----------------------------------------------------------------

    public function testLineMutationsKeepStableUuidsAndStoreAdvisorySnapshots(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-LINE-1', 2500);
        $uuid = $this->createDraft();

        $createdResponse = $this->controller()->storeLine(
            $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 2]),
            $uuid
        );
        self::assertSame(201, $createdResponse->getStatusCode());
        $created = $this->json($createdResponse);
        $line = $created['data']['lines'][0];
        $lineUuid = (string) $line['uuid'];

        self::assertSame($variantUuid, $line['variant_uuid']);
        self::assertSame('SKU-LINE-1', $line['sku']);
        self::assertSame(2500, (int) $line['unit_price']);
        self::assertSame(5000, (int) $line['line_total']);
        self::assertSame(5000, (int) $created['data']['subtotal']);
        self::assertSame(5000, (int) $created['data']['grand_total']);

        $updated = $this->json($this->controller()->updateLine(
            $this->request('PATCH', ['quantity' => 3]),
            $uuid,
            $lineUuid
        ))['data'];
        self::assertSame($lineUuid, $updated['lines'][0]['uuid'], 'line uuids are stable across mutations');
        self::assertSame(7500, (int) $updated['lines'][0]['line_total']);
        self::assertSame(7500, (int) $updated['subtotal']);

        $removed = $this->json($this->controller()->destroyLine($this->request('DELETE', []), $uuid, $lineUuid))['data'];
        self::assertSame([], $removed['lines']);
        self::assertSame(0, (int) $removed['subtotal']);
    }

    public function testLineResolutionGoesThroughTheSharedResolverIncludingAddonSnapshots(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-LINE-2', 1000);
        $productUuid = (string) $this->connection->table('commerce_variants')
            ->where('uuid', '=', $variantUuid)->first()['product_uuid'];
        $addonUuid = $this->seedCheckboxAddon($productUuid, 250);
        $uuid = $this->createDraft();

        $body = $this->json($this->controller()->storeLine($this->request('POST', [
            'variant_uuid' => $variantUuid,
            'quantity' => 1,
            'addons' => [['addon_uuid' => $addonUuid, 'value' => true]],
        ]), $uuid))['data'];

        self::assertSame(1250, (int) $body['lines'][0]['unit_price']);
        self::assertCount(1, $body['lines'][0]['addons']);
        // The sanitized addon echo -- never the definition internals.
        self::assertArrayNotHasKey('addon_uuid', $body['lines'][0]['addons'][0]);
    }

    public function testAnUnknownVariantIsRejectedAsUnavailable(): void
    {
        $uuid = $this->createDraft();

        $response = $this->controller()->storeLine(
            $this->request('POST', ['variant_uuid' => 'novariant001', 'quantity' => 1]),
            $uuid
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            DraftLineEligibility::UNAVAILABLE,
            $this->json($response)['error']['details']['reason']
        );
    }

    public function testDigitalProductsAreATypedRejectionAtMutationTime(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DIGI-1', 900, type: 'digital');
        $uuid = $this->createDraft();

        $response = $this->controller()->storeLine(
            $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]),
            $uuid
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(DraftLineEligibility::DIGITAL, $this->json($response)['error']['details']['reason']);
        self::assertSame([], $this->linesFor($uuid));
        self::assertSame(0, (int) $this->orderRow($uuid)['draft_revision']);
    }

    public function testMarketplacePartitionedProductsAreATypedRejectionAtMutationTime(): void
    {
        $this->activateMarketplace();
        $variantUuid = $this->seedPhysicalProduct('SKU-MKT-1', 900, sellerUuid: 'sel000000001');
        $uuid = $this->createDraft();

        $response = $this->controller()->storeLine(
            $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]),
            $uuid
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(DraftLineEligibility::MARKETPLACE, $this->json($response)['error']['details']['reason']);
    }

    public function testASellerProductIsEligibleWhenTheWorkspaceIsNotPartitioned(): void
    {
        // Marketplace INSTALLED but the workspace never activated: the order-level
        // composition is false, so a seller-owned line is an ordinary line.
        $this->context->overrideConfig('commerce.marketplace.enabled', true);
        $this->seedActiveSeller('sel000000001');
        $variantUuid = $this->seedPhysicalProduct('SKU-MKT-2', 900, sellerUuid: 'sel000000001');
        $uuid = $this->createDraft();

        $response = $this->controller()->storeLine(
            $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]),
            $uuid
        );

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * The order-level partitioning decision is composed PER CALL, never memoized
     * on the service: `DraftOrderService` is registered `shared`, and
     * `MarketplaceMode`'s own contract is that behavioral consumers re-check per
     * call so a live settings-screen toggle takes effect immediately. Proven on
     * ONE controller/service instance -- a memo would let the second add through.
     */
    public function testPartitioningIsRecomposedPerCallSoALiveMarketplaceToggleApplies(): void
    {
        $this->context->overrideConfig('commerce.marketplace.enabled', true);
        $variantUuid = $this->seedPhysicalProduct('SKU-MKT-3', 900, sellerUuid: 'sel000000001');
        $controller = $this->controller();
        $uuid = (string) $this->json($controller->store($this->request('POST', [])))['data']['uuid'];

        // Workspace not activated yet: an ordinary line.
        $before = $controller->storeLine(
            $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]),
            $uuid
        );
        self::assertSame(201, $before->getStatusCode());

        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettings1',
            'tenant_uuid' => self::TENANT,
            'status' => 'active',
        ]);

        $after = $controller->storeLine(
            $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]),
            $uuid
        );

        self::assertSame(422, $after->getStatusCode());
        self::assertSame(DraftLineEligibility::MARKETPLACE, $this->json($after)['error']['details']['reason']);
    }

    public function testExternalProductsAreRejectedAsUnavailable(): void
    {
        $variantUuid = $this->seedExternalProductWithDirectlyInsertedVariant('SKU-EXT-1');
        $uuid = $this->createDraft();

        $response = $this->controller()->storeLine(
            $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]),
            $uuid
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(DraftLineEligibility::UNAVAILABLE, $this->json($response)['error']['details']['reason']);
    }

    // -----------------------------------------------------------------
    // eligibility parity: product search <-> line endpoint
    // -----------------------------------------------------------------

    public function testAdminProductSearchPublishesTheSameClosedEligibilityTheLineEndpointEnforces(): void
    {
        $this->activateMarketplace();
        $this->seedPhysicalProduct('SKU-ELIG-OK', 100);
        $this->seedPhysicalProduct('SKU-ELIG-DIG', 100, type: 'digital');
        $this->seedPhysicalProduct('SKU-ELIG-MKT', 100, sellerUuid: 'sel000000001');
        $this->seedExternalProductWithDirectlyInsertedVariant('SKU-ELIG-EXT');

        $rows = $this->json($this->productController()->index(
            new AdminProductListQuery(),
            Request::create('/commerce/admin/products', 'GET')
        ))['data'];

        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) $row['name']] = $row;
        }

        self::assertTrue($byName['SKU-ELIG-OK']['admin_draft_eligible']);
        self::assertNull($byName['SKU-ELIG-OK']['admin_draft_ineligible_reason']);

        self::assertFalse($byName['SKU-ELIG-DIG']['admin_draft_eligible']);
        self::assertSame(DraftLineEligibility::DIGITAL, $byName['SKU-ELIG-DIG']['admin_draft_ineligible_reason']);

        self::assertFalse($byName['SKU-ELIG-MKT']['admin_draft_eligible']);
        self::assertSame(DraftLineEligibility::MARKETPLACE, $byName['SKU-ELIG-MKT']['admin_draft_ineligible_reason']);

        self::assertFalse($byName['SKU-ELIG-EXT']['admin_draft_eligible']);
        self::assertSame(DraftLineEligibility::UNAVAILABLE, $byName['SKU-ELIG-EXT']['admin_draft_ineligible_reason']);
    }

    public function testEligibilityReasonsAreAClosedVocabulary(): void
    {
        self::assertSame(['digital', 'marketplace', 'unavailable'], DraftLineEligibility::REASONS);
    }

    public function testASuspendedSellersProductIsReportedUnavailableNotMarketplace(): void
    {
        // Marketplace installed, workspace NOT partitioned, seller suspended: the
        // product is not buyer-available at all, which the resolver would reject.
        $this->context->overrideConfig('commerce.marketplace.enabled', true);
        $this->seedActiveSeller('sel000000009', 'suspended');
        $this->seedPhysicalProduct('SKU-ELIG-SUS', 100, sellerUuid: 'sel000000009');

        $rows = $this->json($this->productController()->index(
            new AdminProductListQuery(),
            Request::create('/commerce/admin/products', 'GET')
        ))['data'];

        self::assertFalse($rows[0]['admin_draft_eligible']);
        self::assertSame(DraftLineEligibility::UNAVAILABLE, $rows[0]['admin_draft_ineligible_reason']);
    }

    // -----------------------------------------------------------------
    // revision custody
    // -----------------------------------------------------------------

    public function testEveryMutationIncrementsTheDraftRevision(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-REV-1', 100);
        $uuid = $this->createDraft();
        self::assertSame(0, (int) $this->orderRow($uuid)['draft_revision']);

        $this->controller()->update($this->request('PATCH', ['customer_name' => 'Grace']), $uuid);
        self::assertSame(1, (int) $this->orderRow($uuid)['draft_revision']);

        $lineUuid = (string) $this->json($this->controller()->storeLine(
            $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]),
            $uuid
        ))['data']['lines'][0]['uuid'];
        self::assertSame(2, (int) $this->orderRow($uuid)['draft_revision']);

        $this->controller()->updateLine($this->request('PATCH', ['quantity' => 2]), $uuid, $lineUuid);
        self::assertSame(3, (int) $this->orderRow($uuid)['draft_revision']);

        $this->controller()->update($this->request('PATCH', ['fulfillment_mode' => 'delivery']), $uuid);
        self::assertSame(4, (int) $this->orderRow($uuid)['draft_revision']);

        $this->controller()->recalculate($this->request('POST', []), $uuid);
        self::assertSame(5, (int) $this->orderRow($uuid)['draft_revision']);

        $this->controller()->destroyLine($this->request('DELETE', []), $uuid, $lineUuid);
        self::assertSame(6, (int) $this->orderRow($uuid)['draft_revision']);
    }

    /** @return list<array{0:string}> */
    public static function staleRevisionOperationProvider(): array
    {
        return ['update' => ['update'], 'line' => ['line'], 'recalculate' => ['recalculate']];
    }

    /** @dataProvider staleRevisionOperationProvider */
    public function testAStaleExpectedRevisionIsATypedConflictThatChangesNothing(string $operation): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-REV-2', 100);
        $uuid = $this->createDraft();
        $this->controller()->update($this->request('PATCH', ['customer_name' => 'Grace']), $uuid);
        self::assertSame(1, (int) $this->orderRow($uuid)['draft_revision']);

        $response = match ($operation) {
            'update' => $this->controller()->update(
                $this->request('PATCH', ['customer_name' => 'Ada', 'expected_revision' => 0]),
                $uuid
            ),
            'line' => $this->controller()->storeLine(
                $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1, 'expected_revision' => 0]),
                $uuid
            ),
            'recalculate' => $this->controller()->recalculate(
                $this->request('POST', ['expected_revision' => 0]),
                $uuid
            ),
        };

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('stale_revision', $this->json($response)['error']['details']['conflict']);
        self::assertSame(1, (int) $this->orderRow($uuid)['draft_revision']);
        self::assertSame('Grace', $this->orderRow($uuid)['customer_name']);
        self::assertSame([], $this->linesFor($uuid));
    }

    public function testAMatchingExpectedRevisionSucceeds(): void
    {
        $uuid = $this->createDraft();

        $draft = $this->json($this->controller()->update(
            $this->request('PATCH', ['customer_name' => 'Ada', 'expected_revision' => 0]),
            $uuid
        ))['data'];

        self::assertSame('Ada', $draft['customer_name']);
        self::assertSame(1, (int) $draft['draft_revision']);
    }

    // -----------------------------------------------------------------
    // recalculate = explicit drift acceptance
    // -----------------------------------------------------------------

    public function testRecalculateRefreshesDriftedVariantAndAddonSnapshotsAndTotals(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DRIFT-1', 1000);
        $productUuid = (string) $this->connection->table('commerce_variants')
            ->where('uuid', '=', $variantUuid)->first()['product_uuid'];
        $addonUuid = $this->seedCheckboxAddon($productUuid, 250);
        $uuid = $this->createDraft();
        $this->controller()->storeLine($this->request('POST', [
            'variant_uuid' => $variantUuid,
            'quantity' => 2,
            'addons' => [['addon_uuid' => $addonUuid, 'value' => true]],
        ]), $uuid);
        self::assertSame(2500, (int) $this->orderRow($uuid)['subtotal']);

        // Catalog drifts underneath the draft.
        $this->connection->table('commerce_variants')->where('uuid', '=', $variantUuid)->update(['price' => 1500]);
        $this->connection->table('commerce_product_addons')
            ->where('uuid', '=', $addonUuid)->update(['price_delta' => 500]);
        $this->connection->table('commerce_products')
            ->where('uuid', '=', $productUuid)->update(['name' => 'Renamed product']);

        // Untouched until the operator explicitly accepts the drift.
        self::assertSame(2500, (int) $this->orderRow($uuid)['subtotal']);

        $body = $this->json($this->controller()->recalculate($this->request('POST', []), $uuid))['data'];

        self::assertSame(2000, (int) $body['lines'][0]['unit_price']);
        self::assertSame(4000, (int) $body['lines'][0]['line_total']);
        self::assertSame('Renamed product', $body['lines'][0]['product_name']);
        self::assertSame(4000, (int) $body['subtotal']);
        self::assertSame(4000, (int) $body['grand_total']);
    }

    public function testRecalculateRequotesTheShippingMethodAndDropsOneThatVanished(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DRIFT-2', 1000);
        $uuid = $this->createDraft(['fulfillment_mode' => 'delivery']);
        $this->controller()->storeLine($this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]), $uuid);
        $this->controller()->update(
            $this->request('PATCH', ['addresses' => ['shipping' => ['country' => 'US']]]),
            $uuid
        );
        $this->controller()->update($this->request('PATCH', ['shipping_method' => 'std']), $uuid);
        self::assertSame(500, (int) $this->orderRow($uuid)['shipping_total']);

        $this->shippingQuotes = [];
        $body = $this->json($this->controller()->recalculate($this->request('POST', []), $uuid))['data'];

        self::assertNull($body['shipping_method']);
        self::assertSame(0, (int) $body['shipping_total']);
    }

    // -----------------------------------------------------------------
    // discounts
    // -----------------------------------------------------------------

    public function testADiscountCodeIsValidatedButNotConsumed(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DISC-1', 1000);
        $this->seedDiscount('SAVE10', 'percentage', 1000);
        $uuid = $this->createDraft();
        $this->controller()->storeLine($this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]), $uuid);

        $response = $this->controller()->update($this->request('PATCH', ['discount_code' => 'SAVE10']), $uuid);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('SAVE10', $body['data']['discount_code']);
        self::assertSame(100, (int) $body['data']['discount_total']);
        self::assertSame(900, (int) $body['data']['grand_total']);
        self::assertSame(
            [],
            $this->connection->table('commerce_discount_redemptions')->get(),
            'a draft never consumes a discount'
        );
        self::assertSame(
            0,
            (int) $this->connection->table('commerce_discounts')->where('code', '=', 'SAVE10')->first()['usage_count']
        );
    }

    public function testAnUnknownDiscountCodeIsRejected(): void
    {
        $uuid = $this->createDraft();

        $response = $this->controller()->update($this->request('PATCH', ['discount_code' => 'NOPE']), $uuid);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('discount_code', $this->json($response)['error']['details']);
    }

    public function testOncePerBuyerOnAnAnonymousDraftIsRejectedAtApplication(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DISC-2', 1000);
        $this->seedDiscount('ONCE', 'percentage', 1000, oncePerBuyer: true);
        $uuid = $this->createDraft();
        $this->controller()->storeLine($this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]), $uuid);

        $response = $this->controller()->update($this->request('PATCH', ['discount_code' => 'ONCE']), $uuid);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('discount_code', $this->json($response)['error']['details']);
        self::assertNull($this->orderRow($uuid)['discount_code']);
    }

    public function testOncePerBuyerIsAcceptedOnceTheDraftCarriesAnIdentity(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-DISC-3', 1000);
        $this->seedDiscount('ONCE2', 'percentage', 1000, oncePerBuyer: true);
        $uuid = $this->createDraft(['email' => 'walkin@example.com']);
        $this->controller()->storeLine($this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]), $uuid);

        $response = $this->controller()->update($this->request('PATCH', ['discount_code' => 'ONCE2']), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ONCE2', $this->orderRow($uuid)['discount_code']);
    }

    // -----------------------------------------------------------------
    // cancel
    // -----------------------------------------------------------------

    public function testCancelUsesTheSharedDraftCancellationMechanicAndRecordsTheAuditRow(): void
    {
        $uuid = $this->createDraft();

        $response = $this->controller()->cancel($this->request('POST', []), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('canceled', $this->json($response)['data']['status']);
        self::assertSame('canceled', (string) $this->orderRow($uuid)['status']);
        self::assertSame(
            [DraftOrderEvents::CREATED, DraftOrderEvents::CANCELED],
            $this->eventTypesFor($uuid)
        );
    }

    public function testCancelingAnAlreadyCanceledDraftIsANonRevealing404(): void
    {
        $uuid = $this->createDraft();
        $this->controller()->cancel($this->request('POST', []), $uuid);

        $this->expectException(NotFoundException::class);
        $this->controller()->cancel($this->request('POST', []), $uuid);
    }

    public function testEveryDraftEndpointIsANonRevealing404ForAFinalizedOrder(): void
    {
        $this->seedFinalizedOrder('realorder001');

        foreach (['show', 'cancel', 'recalculate'] as $action) {
            try {
                match ($action) {
                    'show' => $this->controller()->show($this->request('GET', []), 'realorder001'),
                    'cancel' => $this->controller()->cancel($this->request('POST', []), 'realorder001'),
                    'recalculate' => $this->controller()->recalculate($this->request('POST', []), 'realorder001'),
                };
                self::fail("{$action} must 404 for a finalized order");
            } catch (NotFoundException $e) {
                self::assertSame('Resource not found.', $e->getMessage());
            }
        }
    }

    // -----------------------------------------------------------------
    // listing isolation + PII ratchet
    // -----------------------------------------------------------------

    public function testTheDraftsListingIsTheOnlyDraftInclusiveListing(): void
    {
        $draftUuid = $this->createDraft();
        $this->seedFinalizedOrder('realorder002');

        $drafts = $this->json($this->controller()->index(
            new DraftOrderListQuery(),
            Request::create('/commerce/admin/orders/drafts', 'GET')
        ));
        self::assertSame([$draftUuid], array_column($drafts['data'], 'uuid'));
        self::assertSame(1, (int) $drafts['total']);

        $orders = $this->json($this->orderController()->index(
            new OrderListQuery(),
            Request::create('/commerce/admin/orders', 'GET')
        ));
        self::assertSame(['realorder002'], array_column($orders['data'], 'uuid'));

        // The ordinary listing's status filter is not a back door.
        $filtered = $this->json($this->orderController()->index(
            new OrderListQuery(status: 'draft'),
            Request::create('/commerce/admin/orders', 'GET')
        ));
        self::assertSame([], $filtered['data']);
    }

    /**
     * Cleanup-train Task 4: the drafts LIST used to project every row through
     * `DraftOrderProjection::forAdmin($row)` with no lines at all, so an admin
     * client could only render a confidently wrong "0 items" per row. The list
     * now hydrates a REAL `line_count` per row -- one grouped count for the whole
     * page, never the full line payload and never a per-row query.
     */
    public function testTheDraftsListingHydratesEachRowsRealLineCount(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-COUNT-1', 1000);
        $otherVariantUuid = $this->seedPhysicalProduct('SKU-COUNT-2', 2000);

        $empty = $this->createDraft();
        $twoLines = $this->createDraft();
        $this->controller()->storeLine(
            $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 3]),
            $twoLines
        );
        $this->controller()->storeLine(
            $this->request('POST', ['variant_uuid' => $otherVariantUuid, 'quantity' => 1]),
            $twoLines
        );

        $listed = $this->json($this->controller()->index(
            new DraftOrderListQuery(),
            Request::create('/commerce/admin/orders/drafts', 'GET')
        ))['data'];

        $counts = [];
        foreach ($listed as $row) {
            self::assertArrayHasKey('line_count', $row, 'the drafts list must publish a line count');
            $counts[(string) $row['uuid']] = (int) $row['line_count'];
        }

        // The count is DISTINCT LINES, not summed quantities.
        self::assertSame(2, $counts[$twoLines] ?? null);
        self::assertSame(0, $counts[$empty] ?? null);
    }

    /**
     * The same derived field is published by every OTHER draft response too, so a
     * client reads one key everywhere rather than switching on the endpoint.
     */
    public function testEveryDraftResponsePublishesTheSameLineCountField(): void
    {
        $variantUuid = $this->seedPhysicalProduct('SKU-COUNT-3', 1000);
        $uuid = $this->createDraft();

        self::assertSame(0, (int) $this->json($this->controller()->show($this->request('GET', []), $uuid))['data']
            ['line_count']);

        $added = $this->json($this->controller()->storeLine(
            $this->request('POST', ['variant_uuid' => $variantUuid, 'quantity' => 1]),
            $uuid
        ))['data'];
        self::assertSame(1, (int) $added['line_count']);
        self::assertSame(1, (int) $this->json($this->controller()->show($this->request('GET', []), $uuid))['data']
            ['line_count']);
    }

    public function testTheDraftProjectionCarriesWalkInIdentityAndRevisionWhileStorefrontStaysClosed(): void
    {
        $uuid = $this->createDraft();
        $this->controller()->update($this->request('PATCH', [
            'customer_name' => 'Ada Lovelace',
            'phone' => '+15550109999',
        ]), $uuid);

        $draft = $this->json($this->controller()->show($this->request('GET', []), $uuid))['data'];

        foreach (['customer_name', 'phone_normalized', 'phone_display', 'fulfillment_mode', 'origin'] as $field) {
            self::assertArrayHasKey($field, $draft, "draft projection must publish {$field}");
        }
        self::assertSame(1, (int) $draft['draft_revision']);
        self::assertSame('Ada Lovelace', $draft['customer_name']);

        // Fail-closed whitelist: the draft wire is the admin wire plus exactly
        // `draft_revision`, and never an internal column.
        self::assertSame(
            array_merge(OrderProjection::FIELDS, ['draft_revision']),
            DraftOrderProjection::FIELDS
        );
        foreach (['id', 'tenant_uuid', 'guest_token_hash', 'marketplace_partitioned', 'refund_revision'] as $internal) {
            self::assertArrayNotHasKey($internal, $draft);
        }

        // The storefront ratchet stays shut on every walk-in column.
        foreach (
            [
                'customer_name', 'phone_normalized', 'phone_display',
                'fulfillment_mode', 'origin', 'draft_revision',
            ] as $field
        ) {
            self::assertNotContains($field, StorefrontOrderProjection::FIELDS, "storefront must not expose {$field}");
        }
    }

    // -----------------------------------------------------------------
    // fixtures
    // -----------------------------------------------------------------

    /** @var list<ShippingQuote> */
    private array $shippingQuotes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shippingQuotes = [new ShippingQuote('std', 'Standard', 500)];
    }

    /** A draft linked to `usr000000001` and carrying that account's own email. */
    private function linkedDraft(): string
    {
        $this->bindUserProvider([
            'usr000000001' => new UserIdentity('usr000000001', [], [], [], 'ada@example.com', 'ada', 'active'),
        ]);
        $uuid = $this->createDraft();
        $this->controller()->update($this->request('PATCH', ['user_uuid' => 'usr000000001']), $uuid);
        $this->controller()->update($this->request('PATCH', ['email' => 'ADA@example.com']), $uuid);

        return $uuid;
    }

    /** @param array<string,mixed> $body */
    private function createDraft(array $body = []): string
    {
        $response = $this->controller()->store($this->request('POST', $body));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return (string) $this->json($response)['data']['uuid'];
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): Request
    {
        return Request::create(
            '/commerce/admin/orders/drafts',
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    private function controller(): AdminOrderDraftController
    {
        return new AdminOrderDraftController($this->context, $this->service());
    }

    private function service(): DraftOrderService
    {
        $tenants = new SentinelTenantResolver();
        $discounts = new DiscountRepository();

        return new DraftOrderService(
            new OrderRepository(),
            new PurchasableLineResolver(
                new VariantRepository(),
                new ProductRepository(),
                new AddonRepository(),
                new ShippingClassRepository()
            ),
            new PricingEngine(),
            $this->shippingProvider(),
            $this->taxCalculator(),
            $discounts,
            new DiscountService($discounts, $tenants),
            new DraftCleanupService(new OrderRepository(), $tenants),
            $tenants,
            new MarketplaceMode(),
            $this->userProvider
        );
    }

    private function orderController(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            new OrderPaymentService(new OrderRepository()),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider()
        );
    }

    private function productController(): AdminProductController
    {
        return new AdminProductController(
            $this->context,
            $this->catalog(),
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new ShippingClassRepository(),
            new StockRepository(),
            new MarketplaceMode()
        );
    }

    private ?UserProviderInterface $userProvider = null;

    /** Counts `findByUuid()` calls so a test can pin that a lookup did NOT happen. */
    private int $userProviderLookups = 0;

    /** @param array<string,UserIdentity> $identities */
    private function bindUserProvider(array $identities): void
    {
        $this->userProvider = new class ($identities, $this) implements UserProviderInterface {
            /** @param array<string,UserIdentity> $identities */
            public function __construct(
                private array $identities,
                private AdminOrderDraftApiTest $test,
            ) {
            }

            public function findByUuid(string $uuid): ?UserIdentity
            {
                $this->test->recordUserProviderLookup();

                return $this->identities[$uuid] ?? null;
            }

            public function findByLogin(string $identifier): ?UserIdentity
            {
                return null;
            }

            public function verifyCredentials(string $identifier, string $password): ?UserIdentity
            {
                return null;
            }
        };
    }

    private function shippingProvider(): ShippingRateProvider
    {
        $test = $this;

        return new class ($test) implements ShippingRateProvider {
            public function __construct(private AdminOrderDraftApiTest $test)
            {
            }

            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return $this->test->currentShippingQuotes();
            }
        };
    }

    /** @return list<ShippingQuote> */
    public function currentShippingQuotes(): array
    {
        return $this->shippingQuotes;
    }

    public function recordUserProviderLookup(): void
    {
        $this->userProviderLookups++;
    }

    private function taxCalculator(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
            }
        };
    }

    private function catalog(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository(),
            null,
            new ShippingClassRepository()
        );
    }

    private function seedPhysicalProduct(
        string $sku,
        int $price,
        string $type = 'physical',
        ?string $sellerUuid = null
    ): string {
        $product = $this->catalog()->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => $type,
            'status' => 'active',
            'variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, self::TENANT, $variantUuid, 100);

        if ($sellerUuid !== null) {
            $this->seedActiveSeller($sellerUuid);
            $this->connection->table('commerce_products')
                ->where('uuid', '=', $product['uuid'])
                ->update(['seller_uuid' => $sellerUuid]);
        }

        return $variantUuid;
    }

    private function seedExternalProductWithDirectlyInsertedVariant(string $sku): string
    {
        $product = $this->catalog()->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => 'external',
            'status' => 'active',
            'metadata' => ['external_url' => 'https://example.com/' . strtolower($sku)],
        ]);
        $variantUuid = 'var' . substr(md5($sku), 0, 9);
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => self::TENANT,
            'product_uuid' => (string) $product['uuid'],
            'sku' => $sku,
            'option_values' => '[]',
            'price' => 100,
            'currency' => 'USD',
        ]);

        return $variantUuid;
    }

    private function seedActiveSeller(string $uuid, string $status = 'active'): void
    {
        $existing = $this->connection->table('commerce_sellers')->where('uuid', '=', $uuid)->first();
        if ($existing !== null) {
            return;
        }
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => 'seller-' . $uuid,
            'name' => 'Seller ' . $uuid,
            'status' => $status,
        ]);
    }

    private function activateMarketplace(): void
    {
        $this->context->overrideConfig('commerce.marketplace.enabled', true);
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettings1',
            'tenant_uuid' => self::TENANT,
            'status' => 'active',
        ]);
    }

    private function seedCheckboxAddon(string $productUuid, int $priceDelta): string
    {
        $addon = (new AddonService(new AddonRepository(), new ProductRepository(), new SentinelTenantResolver()))
            ->create($this->context, $productUuid, [
                'name' => 'Gift wrap',
                'field_type' => 'checkbox',
                'required' => false,
                'price_delta' => $priceDelta,
            ]);

        return (string) $addon['uuid'];
    }

    private function seedDiscount(string $code, string $type, int $value, bool $oncePerBuyer = false): void
    {
        $this->connection->table('commerce_discounts')->insert([
            'uuid' => 'dsc' . substr(md5($code), 0, 9),
            'tenant_uuid' => self::TENANT,
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'status' => 'active',
            'once_per_buyer' => $oncePerBuyer,
            'usage_count' => 0,
        ]);
    }

    private function seedFinalizedOrder(string $uuid): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'storefront',
            'fulfillment_mode' => 'delivery',
        ]);
    }

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function linesFor(string $uuid): array
    {
        return $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $uuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @return list<string> */
    private function eventTypesFor(string $uuid): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type'],
            $this->connection->table('commerce_order_events')
                ->where('order_uuid', '=', $uuid)
                ->orderBy('id', 'ASC')
                ->get()
        );
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
