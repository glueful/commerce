<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Closed payment-presentation view model (Slice-2 Task 4): classifies
 * {@see CheckoutService::placeOrder()}'s `payment` array -- itself a plain-
 * array projection of {@see \Glueful\Extensions\Contracts\Payments\PaymentInitiation}
 * (`status`/`provider`/`payload`) built by `CheckoutService::initiatePayment()`
 * -- into a CLOSED, storefront-safe shape: `['action' => 'manual'|'redirect'
 * |'reference'|'unavailable', ...allowlisted fields]`.
 *
 * The contract's own docblock defines the only two recognized statuses:
 * `'ok'` (a live gateway flow) and `'manual'` (the payload carries
 * `instructions`). `initiatePayment()`'s own failure shape --
 * `['status' => 'init_failed', 'retryable' => true]`, with no `provider`/
 * `payload` keys at all -- and any other/unrecognized status both fall
 * through to `unavailable`.
 *
 * Every branch allowlists a FIXED set of output keys, copied individually
 * from the collector's payload -- the raw `payload` array is NEVER passed
 * through, so a future or misbehaving collector cannot leak an internal
 * field (an API secret, PSP-internal metadata, PII, etc.) into a storefront
 * response merely by adding it to its payload. An unrecognized/malformed
 * shape is logged (status/provider only, never the raw payload) so an
 * operator can see a collector regression without the storefront ever
 * rendering anything unsafe.
 *
 * - `manual` -- exactly {@see \Glueful\Extensions\Commerce\Payments\ManualPaymentCollector}'s
 *   real display field: `instructions`. Missing/non-string `instructions`
 *   is treated as malformed, not a degraded manual VM.
 * - `redirect` -- the payload's hosted-checkout URL (checked, in order, as
 *   `checkout_url` / `redirect_url` / `url` -- `checkout_url` is what the
 *   real `payvia` gateway collector emits today; the other two are accepted
 *   for forward compatibility with a differently-named future collector).
 *   The URL MUST be absolute `https` (parsed via `parse_url()`; scheme and
 *   host both required) or the whole result is `unavailable` -- there is no
 *   partial-credit fallback to `reference` for an untrusted/malformed URL.
 * - `reference` -- only reachable when no URL key is present at all: an
 *   opaque `reference` string plus an optional `gateway` display hint (both
 *   real fields on `payvia`'s reference-only payload shape).
 * - `unavailable` -- carries no extra fields; never echoes the input.
 */
final class CheckoutPresentation
{
    private const CANDIDATE_URL_KEYS = ['checkout_url', 'redirect_url', 'url'];

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * @param array<string,mixed> $paymentResult
     * @return array<string,mixed>
     */
    public function present(array $paymentResult): array
    {
        $status = $paymentResult['status'] ?? null;
        $payloadRaw = $paymentResult['payload'] ?? [];
        $payload = is_array($payloadRaw) ? $payloadRaw : null;

        if (!is_string($status) || $payload === null) {
            return $this->unavailable($paymentResult);
        }

        if ($status === 'manual') {
            return $this->presentManual($payload, $paymentResult);
        }

        if ($status === 'ok') {
            return $this->presentOk($payload, $paymentResult);
        }

        return $this->unavailable($paymentResult);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function presentManual(array $payload, array $raw): array
    {
        $instructions = $payload['instructions'] ?? null;
        if (!is_string($instructions) || $instructions === '') {
            return $this->unavailable($raw);
        }

        return ['action' => 'manual', 'instructions' => $instructions];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function presentOk(array $payload, array $raw): array
    {
        $url = $this->firstNonEmptyString($payload, self::CANDIDATE_URL_KEYS);
        if ($url !== null) {
            $validated = $this->validatedAbsoluteHttpsUrl($url);
            if ($validated === null) {
                return $this->unavailable($raw);
            }

            return ['action' => 'redirect', 'redirect_url' => $validated];
        }

        $reference = $this->firstNonEmptyString($payload, ['reference']);
        if ($reference !== null) {
            $vm = ['action' => 'reference', 'reference' => $reference];
            $gateway = $this->firstNonEmptyString($payload, ['gateway']);
            if ($gateway !== null) {
                $vm['gateway'] = $gateway;
            }

            return $vm;
        }

        return $this->unavailable($raw);
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function unavailable(array $raw): array
    {
        $status = $raw['status'] ?? null;
        $provider = $raw['provider'] ?? null;

        $this->logger->warning('commerce.checkout.payment_presentation_unavailable', [
            'status' => is_string($status) ? $status : null,
            'provider' => is_string($provider) ? $provider : null,
        ]);

        return ['action' => 'unavailable'];
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $keys
     */
    private function firstNonEmptyString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function validatedAbsoluteHttpsUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (strtolower($parts['scheme']) !== 'https' || $parts['host'] === '') {
            return null;
        }

        return $url;
    }
}
