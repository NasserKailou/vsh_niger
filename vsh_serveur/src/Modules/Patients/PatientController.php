<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients;

use Vsh\Core\Http\ApiResponse;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;

final class PatientController
{
    /** @var PatientService */
    private $patients;

    /** @var PatientChildService */
    private $children;

    public function __construct(PatientService $patients, PatientChildService $children)
    {
        $this->patients = $patients;
        $this->children = $children;
    }

    public function index(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->patients->list((array) $request->query(), $pagination);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function search(Request $request): Response
    {
        $pagination = Pagination::fromRequest($request);
        list($items, $total) = $this->patients->search($request->json(), $pagination);
        return ApiResponse::paginated($items, $pagination->page(), $pagination->perPage(), $total);
    }

    public function show(Request $request): Response
    {
        return ApiResponse::success($this->patients->get((string) $request->param('id'), $request));
    }

    public function store(Request $request): Response
    {
        return ApiResponse::created($this->patients->create($request->json(), $request), 'Dossier patient créé.');
    }

    public function update(Request $request): Response
    {
        return ApiResponse::success($this->patients->update((string) $request->param('id'), $request->json(), $request), 'Dossier mis à jour.');
    }

    public function attendingPhysician(Request $request): Response
    {
        return ApiResponse::success(
            $this->patients->assignAttendingPhysician((string) $request->param('id'), $request->json(), $request),
            'Médecin traitant mis à jour.'
        );
    }

    public function medicalProfile(Request $request): Response
    {
        return ApiResponse::success($this->patients->medicalProfile((string) $request->param('id'), $request));
    }

    public function updateMedicalProfile(Request $request): Response
    {
        return ApiResponse::success(
            $this->patients->updateMedicalProfile((string) $request->param('id'), $request->json(), $request),
            'Profil médical enregistré.'
        );
    }

    public function merge(Request $request): Response
    {
        return ApiResponse::success(
            $this->patients->merge((string) $request->param('id'), $request->json(), $request),
            'Dossiers fusionnés.'
        );
    }

    public function childIndex(Request $request, string $child): Response
    {
        $patient = $this->patients->findOrFail((string) $request->param('id'));
        return ApiResponse::success($this->children->list($child, $patient, $request));
    }

    public function childStore(Request $request, string $child): Response
    {
        $patient = $this->patients->findOrFail((string) $request->param('id'));
        return ApiResponse::created($this->children->create($child, $patient, $request->json(), $request));
    }

    public function childUpdate(Request $request, string $child): Response
    {
        $patient = $this->patients->findOrFail((string) $request->param('id'));
        return ApiResponse::success(
            $this->children->update($child, $patient, (string) $request->param('childId'), $request->json(), $request),
            'Modification enregistrée.'
        );
    }

    public function childDestroy(Request $request, string $child): Response
    {
        $patient = $this->patients->findOrFail((string) $request->param('id'));
        $this->children->delete($child, $patient, (string) $request->param('childId'), $request);
        return ApiResponse::success(null, 'Élément supprimé.');
    }
}
