<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\EncryptionService;

/**
 * ALL seller-webhook secret cryptography (design spec §2.2/§2.5, MV5c-2
 * Task 3): minting a new secret for storage (register/rotate) and
 * decrypting the CURRENT secret for signing (a later task's delivery
 * client). Deliberately the ONLY class in this subsystem that ever touches
 * plaintext secret material.
 *
 * **Encrypted at rest, endpoint-bound AAD (design spec §2.2/§3):** every
 * secret is stored via the framework's {@see EncryptionService} with
 * Additional Authenticated Data `tenant_uuid:endpoint_uuid:secret_uuid`
 * (see {@see self::aad()}) -- binding the ciphertext to its exact tenant +
 * endpoint + secret identity so a ciphertext copied to a different
 * endpoint/secret row (or a different tenant) fails to decrypt even with
 * the correct encryption key, exactly the same "context binding" AAD
 * technique the framework's own `EncryptionService` docblock recommends for
 * field-swap-attack prevention.
 *
 * **Returned once, never re-derivable (design spec §2.2):** {@see self::mint()}
 * hands back the raw plaintext to the caller ({@see SellerWebhookEndpointService::register()}/
 * `rotateSecret()}) exactly ONCE, at generation time -- this class stores
 * only the ciphertext + a non-secret SHA-256 fingerprint (support
 * correlation ONLY, design spec §3: "never sufficient to sign or verify a
 * payload"). There is no "read the secret back out" API on this class other
 * than {@see self::currentSecretPlain()}, which is reserved for the
 * delivery-signing path (a later task) and MUST NEVER be exposed through
 * any read/list surface.
 */
final class SellerWebhookSecretService
{
    private const SECRET_BYTES = 32;

    /** @var callable(): string */
    private $secretGenerator;

    /**
     * @param (callable(): string)|null $secretGenerator Injectable seam for
     *     tests; defaults to a cryptographically random 32-byte,
     *     base64url-encoded secret.
     */
    public function __construct(
        private SellerWebhookEndpointRepository $endpoints,
        private EncryptionService $encryption,
        ?callable $secretGenerator = null,
    ) {
        $this->secretGenerator = $secretGenerator ?? static fn (): string => self::randomSecret();
    }

    /**
     * Mints a brand-new secret for storage under the given identity triple
     * -- pure crypto/generation, no database access. The caller
     * ({@see SellerWebhookEndpointService}) owns persisting the returned
     * ciphertext/fingerprint alongside the given `$secretUuid`.
     *
     * @return array{plain: string, ciphertext: string, fingerprint: string}
     */
    public function mint(string $tenant, string $endpointUuid, string $secretUuid): array
    {
        $plain = ($this->secretGenerator)();
        $ciphertext = $this->encryption->encrypt($plain, self::aad($tenant, $endpointUuid, $secretUuid));
        $fingerprint = hash('sha256', $plain);

        return ['plain' => $plain, 'ciphertext' => $ciphertext, 'fingerprint' => $fingerprint];
    }

    /**
     * Decrypts the endpoint's CURRENT (non-revoked) secret via its own AAD
     * (design spec §2.5, for delivery signing -- a later task). NEVER
     * called from any read/list surface; there is deliberately no
     * `secretsForVerification()`-style API returning a previous secret.
     *
     * @param array<string,mixed> $endpoint
     */
    public function currentSecretPlain(ApplicationContext $context, string $tenant, array $endpoint): string
    {
        $endpointUuid = (string) $endpoint['uuid'];

        $secret = $this->endpoints->findCurrentSecret($context, $tenant, $endpointUuid);
        if ($secret === null) {
            throw SellerWebhookException::noCurrentSecret();
        }

        return $this->encryption->decrypt(
            (string) $secret['secret_ciphertext'],
            self::aad($tenant, $endpointUuid, (string) $secret['uuid'])
        );
    }

    /**
     * The AAD binding (design spec §2.2/§3): `tenant_uuid:endpoint_uuid:secret_uuid`,
     * verbatim -- the exact same triple both {@see self::mint()} (encrypt)
     * and {@see self::currentSecretPlain()} (decrypt) derive independently,
     * so a ciphertext only ever decrypts under the identity it was minted
     * for.
     */
    private static function aad(string $tenant, string $endpointUuid, string $secretUuid): string
    {
        return "{$tenant}:{$endpointUuid}:{$secretUuid}";
    }

    /** A cryptographically random, URL-safe 32-byte secret. */
    private static function randomSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::SECRET_BYTES)), '+/', '-_'), '=');
    }
}
