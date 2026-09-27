<?php

declare(strict_types=1);

namespace Vsh\Modules\Consultations;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class ConsultationController
{
    /** @var ConsultationService */
    private $consultations;

    /** @var VitalSignService */
    private $vitals;

    public function __construct(ConsultationService $consultations, VitalSignService $vitals)
    {
        $this->consultations = $consultations;
        $this->vitals = $vitals;
    }

    public function index(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->consultations->list((array) $request->query(), $pagination, $request);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function forPatient(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        $query = ['patient_id' => (string) $request->param('id')] + (array) $request->query();
        list($items, $total) = $this->consultations->list($query, $pagination, $request);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function show(Request $request): Response
    {
        return ApiResponse::success($this->consultations->get((string) $request->param('id'), $request));
    }

    public function store(Request $request): Response
    {
        return ApiResponse::created($this->consultations->create($request->json(), $request), 'Consultation ouverte.');
    }

    public function update(Request $request): Response
    {
        return ApiResponse::success($this->consultations->update((string) $request->param('id'), $request->json(), $request), 'Consultation enregistrée.');
    }

    public function close(Request $request): Response
    {
        return ApiResponse::success($this->consultations->close((string) $request->param('id'), $request), 'Consultation clôturée.');
    }

    public function cancel(Request $request): Response
    {
        return ApiResponse::success($this->consultations->cancel((string) $request->param('id'), $request->json(), $request), 'Consultation annulée.');
    }

    public function addVitals(Request $request): Response
    {
        return ApiResponse::created($this->vitals->record((string) $request->param('id'), null, $request->json(), $request), 'Constantes enregistrées.');
    }

    public function addPatientVitals(Request $request): Response
    {
        return ApiResponse::created($this->vitals->record(null, (string) $request->param('id'), $request->json(), $request), 'Constantes enregistrées.');
    }

    public function patientVitals(Request $request): Response
    {
        return ApiResponse::success($this->vitals->history((string) $request->param('id'), $request));
    }

    public function addDiagnosis(Request $request): Response
    {
        return ApiResponse::created($this->consultations->addDiagnosis((string) $request->param('id'), $request->json(), $request), 'Diagnostic enregistré.');
    }

    public function deleteDiagnosis(Request $request): Response
    {
        $this->consultations->findOrFail((string) $request->param('id'));
        $this->consultations->deleteDiagnosis((string) $request->param('diagnosisId'), $request);
        return ApiResponse::success(null, 'Diagnostic retiré.');
    }

    public function addNote(Request $request): Response
    {
        return ApiResponse::created($this->consultations->addNote((string) $request->param('id'), $request->json(), $request), 'Note enregistrée.');
    }
}
