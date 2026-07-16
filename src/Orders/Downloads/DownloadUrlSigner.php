<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders\Downloads;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Repository\BlobRepository;
use Glueful\Support\SignedUrl;
use Glueful\Uploader\Contracts\BlobPublicUrlProvider;

/**
 * Mirrors the framework's verified `UploadController::signedUrl()` composition
 * exactly (design spec §4.1): load the snapshotted blob row, ask the optional
 * {@see BlobPublicUrlProvider} for its public base URL, fall back to the
 * caller-supplied request scheme/host ONLY when no provider answers, append
 * `/blobs/{uuid}`, then `SignedUrl::make($context)->generate($baseUrl, $ttl)`. In a
 * tenant-aware host this ensures a grant correlated to tenant A redirects to
 * tenant A's public host even when the request was served under another host.
 *
 * PURE / no side effects: every failure path here (unbound blob subsystem, missing
 * blob row, `SignedUrl`'s own "no signing secret configured" fail-closed guard)
 * throws {@see DownloadSigningException} BEFORE the caller's guarded grant UPDATE
 * ever runs, so a signing/configuration failure never consumes a mint.
 */
final class DownloadUrlSigner
{
    public function __construct(
        private ?BlobRepository $blobs = null,
        private ?BlobPublicUrlProvider $publicUrlProvider = null,
    ) {
    }

    /**
     * @return array{url: string, expires_in: int}
     * @throws DownloadSigningException
     */
    public function sign(ApplicationContext $context, string $blobUuid, string $requestBase): array
    {
        if ($this->blobs === null) {
            throw new DownloadSigningException('Blob storage subsystem is not available.');
        }

        $blob = $this->blobs->findByUuidWithDeleteFilter($blobUuid);
        if ($blob === null) {
            throw new DownloadSigningException("Blob {$blobUuid} could not be resolved for signing.");
        }

        $ttl = (int) config($context, 'commerce.downloads.url_ttl', 300);
        $base = $this->publicUrlProvider?->publicBaseUrl($blob) ?? $requestBase;
        $baseUrl = rtrim($base, '/') . '/blobs/' . $blobUuid;

        try {
            $url = SignedUrl::make($context)->generate($baseUrl, $ttl);
        } catch (\Throwable $e) {
            throw new DownloadSigningException('Failed to sign download URL: ' . $e->getMessage(), 0, $e);
        }

        return ['url' => $url, 'expires_in' => $ttl];
    }
}
