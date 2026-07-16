<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;

trait ReadsStorefrontInput
{
    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $content = $request->getContent();
        $json = is_string($content) && $content !== '' ? json_decode($content, true) : [];
        if (!is_array($json)) {
            $json = [];
        }

        return array_merge($request->request->all(), $json);
    }

    private function cartToken(Request $request): string
    {
        $token = trim((string) $request->headers->get('X-Cart-Token', ''));
        if ($token === '') {
            throw new NotFoundException('Resource not found.');
        }

        return $token;
    }
}
