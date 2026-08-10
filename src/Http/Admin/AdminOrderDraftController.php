<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\CreateDraftLineData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateDraftOrderData;
use Glueful\Extensions\Commerce\Http\DTOs\DraftOrderListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateDraftLineData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateDraftOrderData;
use Glueful\Extensions\Commerce\Orders\DraftConflictException;
use Glueful\Extensions\Commerce\Orders\DraftFinalizationService;
use Glueful\Extensions\Commerce\Orders\DraftLineRejectedException;
use Glueful\Extensions\Commerce\Orders\DraftOrderService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin DRAFT order surface (admin-order-creation cycle 2, Task 9, design
 * spec §2.3). Deliberately THIN: every mechanic -- the phone contract, user
 * attachment, mode-switch clearing, live shipping quoting, discount validation,
 * eligibility, revision custody -- lives in {@see DraftOrderService}. This class
 * reads input, maps three typed exceptions onto their HTTP shapes, and projects.
 *
 * Bodies are read as RAW input rather than through a hydrated DTO because every
 * draft field is PRESENCE-sensitive: an explicit `null` (clear the phone, detach
 * the user, drop the discount code) and an absent key are genuinely different
 * requests, and a constructor-hydrated DTO cannot tell them apart. The
 * `#[ApiRequestBody]` schemas below still document the accepted shape, exactly
 * as {@see AdminProductController::update()} does for the same reason.
 *
 * The three typed outcomes:
 *  - {@see DraftLineRejectedException} -> 422 carrying `error.details.reason`,
 *    one of {@see \Glueful\Extensions\Commerce\Orders\DraftLineEligibility::REASONS}
 *    -- the SAME closed strings the admin product search publishes per row, so a
 *    client branches on one vocabulary.
 *  - {@see DraftConflictException} -> 409 carrying `error.details.conflict`
 *    (the closed vocabulary on that class) plus whatever machine-readable
 *    `details` the conflict carries -- currently the finalize per-line list.
 *  - {@see ValidationException} -> the ordinary 422 field-error envelope.
 * An unknown/cross-tenant/non-draft uuid raises the framework's own
 * `NotFoundException` from the service and is left to bubble, exactly like
 * every other admin order endpoint.
 */
final class AdminOrderDraftController
{
    use ReadsAdminInput;
    use ResolvesActor;

    public function __construct(
        private ApplicationContext $context,
        private ?DraftOrderService $drafts = null,
        // APPENDED OPTIONAL collaborator (the codebase's standing convention for
        // widening a constructor): every pre-Task-10 direct-construction call
        // site, tests included, stays source-compatible. Deliberately NOT resolved
        // eagerly beside `$drafts` above -- Task 9's own suite constructs this
        // controller with exactly two positional args against a lightweight
        // container that binds no finalization service at all, and an eager
        // `??= app(...)` there would throw at construction time even for a request
        // that never finalizes anything. Same lazy-accessor pattern
        // `CheckoutController` uses for its address book, and for the same reason.
        private ?DraftFinalizationService $finalization = null,
    ) {
        $this->drafts ??= app($context, DraftOrderService::class);
    }

    private function finalization(): DraftFinalizationService
    {
        return $this->finalization ??= app($this->context, DraftFinalizationService::class);
    }

    #[ApiOperation(summary: 'Create a draft order', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: CreateDraftOrderData::class)]
    #[ApiResponse(201, description: 'Draft order created')]
    #[ApiResponse(409, description: 'Draft conflict')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(Request $request): Response
    {
        return $this->guard(function () use ($request): Response {
            $result = $this->drafts->create($this->context, $this->input($request), $this->actorUuid($request));

            return Response::created($this->project($result), 'Draft order created');
        });
    }

    #[ApiOperation(summary: 'List draft orders', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Draft orders retrieved')]
    public function index(DraftOrderListQuery $query, Request $request): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $result = $this->drafts->paginate($this->context, $page, $perPage);

        return Response::paginated(
            array_map(static fn (array $row): array => DraftOrderProjection::forAdmin($row), $result['items']),
            $result['total'],
            $page,
            $perPage,
            null,
            'Draft orders retrieved'
        );
    }

    #[ApiOperation(summary: 'Get a draft order', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Draft order retrieved')]
    #[ApiResponse(404, description: 'Draft order not found')]
    public function show(Request $request, string $uuid): Response
    {
        return Response::success(
            $this->project($this->drafts->find($this->context, $uuid)),
            'Draft order retrieved'
        );
    }

    #[ApiOperation(summary: 'Update a draft order', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateDraftOrderData::class)]
    #[ApiResponse(200, description: 'Draft order updated')]
    #[ApiResponse(404, description: 'Draft order not found')]
    #[ApiResponse(409, description: 'Draft conflict')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        return $this->guard(function () use ($request, $uuid): Response {
            $result = $this->drafts->update($this->context, $uuid, $this->input($request));

            return Response::success($this->project($result), 'Draft order updated');
        });
    }

    #[ApiOperation(summary: 'Cancel a draft order', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Draft order canceled')]
    #[ApiResponse(404, description: 'Draft order not found')]
    public function cancel(Request $request, string $uuid): Response
    {
        return Response::success(
            $this->project($this->drafts->cancel($this->context, $uuid, $this->actorUuid($request))),
            'Draft order canceled'
        );
    }

    #[ApiOperation(summary: 'Recalculate a draft order', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Draft order recalculated')]
    #[ApiResponse(404, description: 'Draft order not found')]
    #[ApiResponse(409, description: 'Draft conflict')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function recalculate(Request $request, string $uuid): Response
    {
        return $this->guard(function () use ($request, $uuid): Response {
            $result = $this->drafts->recalculate($this->context, $uuid, $this->input($request));

            return Response::success($this->project($result), 'Draft order recalculated');
        });
    }

    /**
     * `POST /orders/drafts/{uuid}/finalize` -- the finalization authority
     * (admin-order-creation cycle 2, Task 10, design spec §2.5).
     *
     * The response is the FINALIZED ORDER on the ordinary admin wire
     * ({@see OrderProjection}), not the draft wire: the row is no longer a draft,
     * and `draft_revision` has no meaning on it. The SPA navigates straight to
     * order detail from here.
     *
     * Two deliberate deviations from this controller's other actions:
     *  - the idempotency key is a HEADER, so it is read here and validated by the
     *    service before any lookup (an absent header arrives as `null`, which the
     *    service rejects with the same 422 a malformed one gets);
     *  - a uuid that resolves to an already-finalized order is a typed 409, not
     *    the non-revealing 404 `show()`/`update()` return. Finalize must stay
     *    reachable after finalization so an idempotent RETRY of an ambiguous
     *    network failure can replay its own result.
     */
    #[ApiOperation(summary: 'Finalize a draft order', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Draft order finalized')]
    #[ApiResponse(404, description: 'Draft order not found')]
    #[ApiResponse(409, description: 'Finalize conflict')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function finalize(Request $request, string $uuid): Response
    {
        return $this->guard(function () use ($request, $uuid): Response {
            $input = $this->input($request);
            $result = $this->finalization()->finalize(
                $this->context,
                $uuid,
                $request->headers->get('X-Idempotency-Key'),
                $input['expected_revision'] ?? null
            );

            $projected = OrderProjection::forAdmin($result['order']);
            $projected['lines'] = array_map(
                [DraftOrderProjection::class, 'line'],
                $result['lines']
            );

            return Response::success($projected, 'Draft order finalized');
        });
    }

    #[ApiOperation(summary: 'Add a line to a draft order', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: CreateDraftLineData::class)]
    #[ApiResponse(201, description: 'Draft order line added')]
    #[ApiResponse(404, description: 'Draft order not found')]
    #[ApiResponse(409, description: 'Draft conflict')]
    #[ApiResponse(422, description: 'Line rejected or validation failed')]
    public function storeLine(Request $request, string $uuid): Response
    {
        return $this->guard(function () use ($request, $uuid): Response {
            $result = $this->drafts->addLine($this->context, $uuid, $this->input($request));

            return Response::created($this->project($result), 'Draft order line added');
        });
    }

    #[ApiOperation(summary: 'Update a draft order line', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateDraftLineData::class)]
    #[ApiResponse(200, description: 'Draft order line updated')]
    #[ApiResponse(404, description: 'Draft order or line not found')]
    #[ApiResponse(409, description: 'Draft conflict')]
    #[ApiResponse(422, description: 'Line rejected or validation failed')]
    public function updateLine(Request $request, string $uuid, string $lineUuid): Response
    {
        return $this->guard(function () use ($request, $uuid, $lineUuid): Response {
            $result = $this->drafts->updateLine($this->context, $uuid, $lineUuid, $this->input($request));

            return Response::success($this->project($result), 'Draft order line updated');
        });
    }

    #[ApiOperation(summary: 'Remove a draft order line', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Draft order line removed')]
    #[ApiResponse(404, description: 'Draft order or line not found')]
    #[ApiResponse(409, description: 'Draft conflict')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function destroyLine(Request $request, string $uuid, string $lineUuid): Response
    {
        return $this->guard(function () use ($request, $uuid, $lineUuid): Response {
            $result = $this->drafts->removeLine($this->context, $uuid, $lineUuid, $this->input($request));

            return Response::success($this->project($result), 'Draft order line removed');
        });
    }

    /**
     * @param array{order: array<string,mixed>, lines: list<array<string,mixed>>} $result
     * @return array<string,mixed>
     */
    private function project(array $result): array
    {
        return DraftOrderProjection::forAdmin($result['order'], $result['lines']);
    }

    /** @param callable(): Response $operation */
    private function guard(callable $operation): Response
    {
        try {
            return $operation();
        } catch (DraftLineRejectedException $e) {
            return Response::error($e->getMessage(), 422, ['reason' => $e->reason]);
        } catch (DraftConflictException $e) {
            return Response::error($e->getMessage(), 409, ['conflict' => $e->conflict] + $e->details);
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }
}
