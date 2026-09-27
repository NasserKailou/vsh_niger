<?php

declare(strict_types=1);

namespace Vsh\Modules\Reference;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

/**
 * Contrôleur commun à tous les référentiels ; le nom de la ressource est fourni par la route.
 */
final class ReferenceController
{
    /** @var ReferenceService */
    private $service;

    public function __construct(ReferenceService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request, string $slug): Response
    {
        $pagination = Pagination::fromRequest($request, 50, 200);
        list($items, $total) = $this->service->list($slug, (array) $request->query(), $pagination);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function show(Request $request, string $slug): Response
    {
        return ApiResponse::success($this->service->get($slug, (string) $request->param('id')));
    }

    public function store(Request $request, string $slug): Response
    {
        return ApiResponse::created($this->service->create($slug, $request->json(), $request));
    }

    public function update(Request $request, string $slug): Response
    {
        return ApiResponse::success(
            $this->service->update($slug, (string) $request->param('id'), $request->json(), $request),
            'Modification enregistrée.'
        );
    }

    public function childIndex(Request $request, string $slug, string $child): Response
    {
        return ApiResponse::success($this->service->listChildren($slug, $child, (string) $request->param('id')));
    }

    public function childStore(Request $request, string $slug, string $child): Response
    {
        return ApiResponse::created(
            $this->service->createChild($slug, $child, (string) $request->param('id'), $request->json(), $request)
        );
    }

    public function childUpdate(Request $request, string $slug, string $child): Response
    {
        return ApiResponse::success(
            $this->service->updateChild(
                $slug,
                $child,
                (string) $request->param('id'),
                (string) $request->param('childId'),
                $request->json(),
                $request
            ),
            'Modification enregistrée.'
        );
    }
}
