<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public category browse (Layer 6 Global Constraints, §2 decision 6): ALL
 * tenant categories, independent of product activity (categories carry no
 * active/status column of their own), assembled into the same parent/child
 * tree shape the admin surface's data models -- but projected through a strict
 * recursive allowlist EXACTLY `slug,name,description,position,image_url,children`
 * at EVERY level. `uuid`, `tenant_uuid`, `parent_uuid`, the raw `blob_uuid`, and
 * `revision` never leave this surface -- `image_url` is the only blob echo, and
 * it is always either `null` or a path-only `/blobs/{blob_uuid}` (no host, same
 * convention as every other storefront blob echo in this codebase).
 *
 * The tree is built from ONE unbounded `CategoryRepository::all()` call
 * (position-then-name ordered) rather than N per-level queries -- category
 * trees are not paginated (Layer 6 Global Constraints' pagination retrofit
 * explicitly excludes them), so grouping the flat row list by `parent_uuid` in
 * PHP is both simpler and cheaper than a recursive query.
 */
final class CategoryController
{
    public function __construct(
        private ApplicationContext $context,
        private ?CategoryRepository $categories = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->categories ??= app($context, CategoryRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: 'List categories as a public tree', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Categories retrieved')]
    public function index(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $rows = $this->categories->all($this->context, $tenant);

        return Response::success($this->tree($rows, null), 'Categories retrieved');
    }

    /**
     * @param list<array<string,mixed>> $rows every tenant category, flat
     * @return list<array{
     *     slug:string,name:string,description:?string,position:int,
     *     image_url:?string,children:list<array<string,mixed>>
     * }>
     */
    private function tree(array $rows, ?string $parentUuid): array
    {
        $children = array_values(array_filter(
            $rows,
            static function (array $row) use ($parentUuid): bool {
                $rowParent = $row['parent_uuid'] ?? null;
                $rowParent = $rowParent === null ? null : (string) $rowParent;

                return $rowParent === $parentUuid;
            }
        ));

        return array_map(fn (array $row): array => $this->project($row, $rows), $children);
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $rows every tenant category, flat (for recursing into children)
     * @return array{
     *     slug:string,name:string,description:?string,position:int,
     *     image_url:?string,children:list<array<string,mixed>>
     * }
     */
    private function project(array $row, array $rows): array
    {
        $blobUuid = $row['blob_uuid'] ?? null;

        return [
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'description' => $row['description'] ?? null,
            'position' => (int) $row['position'],
            'image_url' => $blobUuid === null ? null : '/blobs/' . $blobUuid,
            'children' => $this->tree($rows, (string) $row['uuid']),
        ];
    }
}
