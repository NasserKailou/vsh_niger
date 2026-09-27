<?php

declare(strict_types=1);

namespace Vsh\Modules\Billing;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class InvoiceController
{
    /** @var InvoiceService */
    private $service;

    public function __construct(InvoiceService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->service->list((array) $request->query(), $pagination);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function summary(Request $request): Response
    {
        return ApiResponse::success($this->service->summary((array) $request->query()));
    }

    public function show(Request $request): Response
    {
        return ApiResponse::success($this->service->get((string) $request->param('id')));
    }

    public function store(Request $request): Response
    {
        return ApiResponse::created($this->service->create($request->json(), $request), 'Facture brouillon créée.');
    }

    public function update(Request $request): Response
    {
        return ApiResponse::success($this->service->update((string) $request->param('id'), $request->json(), $request), 'Facture mise à jour.');
    }

    public function addItem(Request $request): Response
    {
        return ApiResponse::success($this->service->addItem((string) $request->param('id'), $request->json(), $request), 'Ligne ajoutée.');
    }

    public function removeItem(Request $request): Response
    {
        return ApiResponse::success($this->service->removeItem((string) $request->param('id'), (string) $request->param('itemId'), $request), 'Ligne retirée.');
    }

    public function issue(Request $request): Response
    {
        return ApiResponse::success($this->service->issue((string) $request->param('id'), $request), 'Facture émise.');
    }

    public function cancel(Request $request): Response
    {
        return ApiResponse::success($this->service->cancel((string) $request->param('id'), $request->json(), $request), 'Facture annulée.');
    }

    public function settlement(Request $request): Response
    {
        return ApiResponse::success($this->service->declareSettlement((string) $request->param('id'), $request->json(), $request), 'État de règlement enregistré.');
    }

    public function forOwnPatient(Request $request): Response
    {
        return ApiResponse::success($this->service->forOwnPatient((string) $request->param('id'), $request));
    }

    public function pdf(Request $request): Response
    {
        $document = $this->service->pdf((string) $request->param('id'), $request);
        return ApiResponse::file($document['content'], $document['filename']);
    }

    public function pdfForOwnPatient(Request $request): Response
    {
        $document = $this->service->pdfForOwnPatient((string) $request->param('id'), (string) $request->param('invoiceId'), $request);
        return ApiResponse::file($document['content'], $document['filename']);
    }
}
