<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Symfony\Component\HttpFoundation\Request;

trait ReadsAdminInput
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
}
