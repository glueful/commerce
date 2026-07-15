<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadAccessService;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Email deep-link access path (design spec §4.2): a public, rate-limited,
 * single-credential route. Hashes the raw bearer token, resolves the grant via the
 * correlation-style GLOBAL lookup ({@see DownloadGrantRepository::findByTokenHashGlobal()}),
 * then runs the SAME atomic mint primitive the order-authenticated path uses
 * ({@see DownloadAccessService::mint()}) -- no repair: an absent grant has no token
 * to reach this controller with in the first place, so this path can never heal a
 * partially- or entirely-missing grant set.
 */
final class DownloadLinkController
{
    public function __construct(
        private ApplicationContext $context,
        private ?DownloadGrantRepository $grants = null,
        private ?DownloadAccessService $access = null,
    ) {
        $this->grants ??= app($context, DownloadGrantRepository::class);
        $this->access ??= app($context, DownloadAccessService::class);
    }

    #[ApiOperation(summary: 'Redeem a digital-download email link', tags: ['Commerce Storefront'])]
    #[ApiResponse(302, description: 'Redirects to a freshly minted signed blob URL')]
    #[ApiResponse(404, description: 'Unknown or invalid token')]
    #[ApiResponse(410, description: 'Download link exhausted, expired, revoked, or refund-blocked')]
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $grant = $this->grants->findByTokenHashGlobal($this->context, TokenHasher::hash($token));
        if ($grant === null) {
            return Response::notFound('Resource not found.');
        }

        try {
            $result = $this->access->mint(
                $this->context,
                (string) $grant['tenant_uuid'],
                (string) $grant['order_uuid'],
                (string) $grant['uuid'],
                $request->getSchemeAndHttpHost()
            );
        } catch (NotFoundException) {
            return Response::notFound('Resource not found.');
        }

        if (!$result['ok']) {
            return Response::error('Download link unavailable', 410, ['code' => $result['code']]);
        }

        return new RedirectResponse($result['url'], 302);
    }
}
