<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\CreateTaxRateData;
use Glueful\Extensions\Commerce\Http\DTOs\TaxRateListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateTaxRateData;
use Glueful\Extensions\Commerce\Tax\TaxRateService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminTaxRateController
{
    use ReadsAdminInput;

    public function __construct(
        private ApplicationContext $context,
        private ?TaxRateService $rates = null,
    ) {
        $this->rates ??= app($context, TaxRateService::class);
    }

    #[ApiOperation(summary: 'List tax rates', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Tax rates retrieved')]
    public function index(TaxRateListQuery $query): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $result = $this->rates->list($this->context, $query->country, $query->class, $page, $perPage);

        return Response::paginated($result['items'], $result['total'], $page, $perPage, null, 'Tax rates retrieved');
    }

    #[ApiOperation(summary: 'Get a tax rate', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Tax rate retrieved')]
    #[ApiResponse(404, description: 'Tax rate not found')]
    public function show(Request $request, string $uuid): Response
    {
        return Response::success($this->rates->show($this->context, $uuid), 'Tax rate retrieved');
    }

    #[ApiOperation(summary: 'Create a tax rate', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Tax rate created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateTaxRateData $input, Request $request): Response
    {
        try {
            $rate = $this->rates->create($this->context, [
                'country' => $input->country,
                'state' => $input->state,
                'postcode_pattern' => $input->postcode_pattern,
                'rate_bps' => $input->rate_bps,
                'label' => $input->label,
                'priority' => $input->priority,
                'shipping_taxable' => $input->shipping_taxable,
                'class' => $input->class,
            ]);

            return Response::created($rate, 'Tax rate created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a tax rate', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateTaxRateData::class)]
    #[ApiResponse(200, description: 'Tax rate updated')]
    #[ApiResponse(404, description: 'Tax rate not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            $rate = $this->rates->update($this->context, $uuid, $this->input($request));

            return Response::success($rate, 'Tax rate updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Delete a tax rate', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Tax rate deleted')]
    #[ApiResponse(404, description: 'Tax rate not found')]
    public function destroy(Request $request, string $uuid): Response
    {
        $this->rates->delete($this->context, $uuid);

        return Response::noContent();
    }
}
