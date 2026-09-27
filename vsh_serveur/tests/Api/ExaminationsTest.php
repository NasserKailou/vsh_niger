<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Core\Support\Uuid;

final class ExaminationsTest extends ApiTestCase
{
    /** @var array */
    private $doctorUser;

    /** @var string */
    private $doctor;

    /** @var string */
    private $technician;

    /** @var string */
    private $admin;

    /** @var array */
    private $patient;

    /** @var array */
    private $type;

    /** @var array */
    private $glucose;

    /** @var array */
    private $malaria;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $n = ++self::$sequence;
        $this->doctorUser = $this->createUser(['MEDECIN']);
        $this->doctor = $this->login($this->doctorUser)['access_token'];
        $this->technician = $this->login($this->createUser(['TECHNICIEN']))['access_token'];
        $this->admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Haoua', 'last_name' => 'Exam' . getmypid() . 'N' . $n, 'sex' => 'F',
            'birth_date' => sprintf('19%02d-0%d-1%d', 50 + $n % 49, 1 + $n % 9, $n % 10),
        ], $this->admin))['data'];
        $this->type = $this->payload($this->send('POST', '/examination-types', [
            'code' => 'BIO' . getmypid() . 'E' . $n, 'label' => 'Bilan test', 'category' => 'BIOLOGIE',
        ], $this->admin))['data'];
        $base = '/examination-types/' . $this->type['id'] . '/parameters';
        // Valeurs de référence saisies par la clinique (données de test, aucune norme codée).
        $this->glucose = $this->payload($this->send('POST', $base, [
            'code' => 'GLY', 'label' => 'Glycémie', 'value_type' => 'NUMERIC', 'unit' => 'g/L', 'ref_min' => 0.7, 'ref_max' => 1.1, 'sort_order' => 1,
        ], $this->admin))['data'];
        $this->malaria = $this->payload($this->send('POST', $base, [
            'code' => 'TDR', 'label' => 'TDR paludisme', 'value_type' => 'CHOICE', 'choices' => ['POSITIF', 'NEGATIF'], 'sort_order' => 2,
        ], $this->admin))['data'];
    }

    public function testFullWorkflowFromPrescriptionToPatientResult(): void
    {
        $account = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->db()->execute('UPDATE patients SET user_id = ? WHERE uuid = ?', [$account['id'], $this->patient['id']]);
        $patientToken = $this->login($account)['access_token'];

        $id = $this->prescribe(['priority' => 'URGENTE', 'clinical_info' => 'Fièvre depuis 3 jours']);

        $queue = $this->payload($this->send('GET', '/examinations?queue=1', null, $this->technician))['data'];
        $this->assertContains($id, array_column($queue, 'id'));

        $invalid = $this->send('POST', '/examinations/' . $id . '/results', ['results' => [
            ['parameter_id' => $this->malaria['id'], 'value_text' => 'PEUT-ETRE'],
            ['parameter_id' => Uuid::v4(), 'value_numeric' => 1],
        ]], $this->technician);
        $this->assertStatus(422, $invalid);
        $this->assertArrayHasKey('results.0.value_text', $this->payload($invalid)['errors']);
        $this->assertArrayHasKey('results.1.parameter_id', $this->payload($invalid)['errors']);

        $recorded = $this->payload($this->send('POST', '/examinations/' . $id . '/results', ['results' => [
            ['parameter_id' => $this->glucose['id'], 'value_numeric' => 1.45],
            ['parameter_id' => $this->malaria['id'], 'value_text' => 'POSITIF'],
            ['label' => 'Aspect du sérum', 'value_text' => 'Clair'],
        ]], $this->technician))['data'];
        $this->assertSame('EN_COURS', $recorded['status']);
        $this->assertSame('Fièvre depuis 3 jours', $recorded['clinical_info']);
        $this->assertCount(3, $recorded['results']);
        $this->assertTrue($recorded['results'][0]['is_abnormal']);
        $this->assertSame('0,7 – 1,1 g/L', $recorded['results'][0]['reference_text']);
        $this->assertNull($recorded['results'][1]['is_abnormal']);

        // Une nouvelle saisie remplace la précédente tant que l'examen n'est pas terminé.
        $corrected = $this->payload($this->send('POST', '/examinations/' . $id . '/results', ['results' => [
            ['parameter_id' => $this->glucose['id'], 'value_numeric' => 0.9],
        ], 'comment' => 'Contrôle refait'], $this->technician))['data'];
        $this->assertCount(1, $corrected['results']);
        $this->assertFalse($corrected['results'][0]['is_abnormal']);

        $this->assertSame([], $this->payload($this->send('GET', '/me/patients/' . $this->patient['id'] . '/examinations', null, $patientToken))['data']);

        $this->assertSame('TERMINE', $this->payload($this->send('POST', '/examinations/' . $id . '/complete', null, $this->technician))['data']['status']);
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/examinations/' . $id . '/results', ['results' => [
            ['parameter_id' => $this->glucose['id'], 'value_numeric' => 2],
        ]], $this->technician))['code']);
        $this->assertStatus(403, $this->send('POST', '/examinations/' . $id . '/validate', null, $this->technician));

        $validated = $this->send('POST', '/examinations/' . $id . '/validate', null, $this->doctor);
        $this->assertStatus(200, $validated);
        $this->assertSame('VALIDE', $this->payload($validated)['data']['status']);
        $this->assertSame($this->doctorUser['uuid'], $this->payload($validated)['data']['validated_by']['id']);

        $mine = $this->payload($this->send('GET', '/me/patients/' . $this->patient['id'] . '/examinations', null, $patientToken))['data'];
        $this->assertSame([$id], array_column($mine, 'id'));
        $this->assertSame(0.9, $mine[0]['results'][0]['value_numeric']);
        $this->assertArrayNotHasKey('clinical_info', $mine[0]);

        $notification = $this->db()->fetchOne("SELECT title, body FROM notifications WHERE user_id = ? AND notif_type = 'EXAM_RESULT'", [$account['id']]);
        $this->assertSame('Résultat disponible', $notification['title']);
        $this->assertStringNotContainsString('0,9', (string) $notification['body']);
    }

    public function testValidationRightsFollowD005(): void
    {
        $id = $this->finishedExamination();
        $otherDoctor = $this->createUser(['MEDECIN']);
        $otherToken = $this->login($otherDoctor)['access_token'];

        $this->assertStatus(403, $this->send('POST', '/examinations/' . $id . '/validate', null, $otherToken));

        $this->db()->execute('UPDATE patients SET attending_physician_id = ? WHERE uuid = ?', [$otherDoctor['id'], $this->patient['id']]);
        $this->assertStatus(200, $this->send('POST', '/examinations/' . $id . '/validate', null, $otherToken));
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/examinations/' . $id . '/validate', null, $this->doctor))['code']);
    }

    public function testTechnicianCannotValidateOwnResults(): void
    {
        $hybrid = $this->login($this->createUser(['MEDECIN', 'TECHNICIEN']))['access_token'];
        $id = $this->payload($this->send('POST', '/examinations', [
            'patient_id' => $this->patient['id'], 'examination_type_id' => $this->type['id'],
        ], $hybrid))['data']['id'];
        $this->send('POST', '/examinations/' . $id . '/results', ['results' => [['parameter_id' => $this->glucose['id'], 'value_numeric' => 1]]], $hybrid);
        $this->send('POST', '/examinations/' . $id . '/complete', null, $hybrid);

        $response = $this->send('POST', '/examinations/' . $id . '/validate', null, $hybrid);
        $this->assertStatus(403, $response);
        $this->assertSame('SELF_VALIDATION', $this->payload($response)['code']);
    }

    public function testClinicalContentIsHiddenFromAdministration(): void
    {
        $id = $this->finishedExamination();

        $asAdmin = $this->payload($this->send('GET', '/examinations/' . $id, null, $this->admin))['data'];
        $this->assertSame('TERMINE', $asAdmin['status']);
        $this->assertArrayNotHasKey('clinical_info', $asAdmin);
        $this->assertArrayNotHasKey('results', $asAdmin);

        $reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $this->assertStatus(403, $this->send('GET', '/examinations', null, $reception));
        $this->assertStatus(403, $this->send('POST', '/examinations', ['patient_id' => $this->patient['id'], 'examination_type_id' => $this->type['id']], $this->technician));
    }

    public function testCancellationAndCompletionRules(): void
    {
        $id = $this->prescribe();
        $this->assertArrayHasKey('results', $this->payload($this->send('POST', '/examinations/' . $id . '/complete', null, $this->technician))['errors'] ?? []);
        $this->send('POST', '/examinations/' . $id . '/start', null, $this->technician);
        $this->assertArrayHasKey('results', $this->payload($this->send('POST', '/examinations/' . $id . '/complete', null, $this->technician))['errors']);

        $this->assertArrayHasKey('reason', $this->payload($this->send('POST', '/examinations/' . $id . '/cancel', [], $this->doctor))['errors']);
        $cancelled = $this->payload($this->send('POST', '/examinations/' . $id . '/cancel', ['reason' => 'Patient transféré'], $this->doctor))['data'];
        $this->assertSame('ANNULE', $cancelled['status']);
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/examinations/' . $id . '/start', null, $this->technician))['code']);

        $this->send('PUT', '/examination-types/' . $this->type['id'], ['active' => false], $this->admin);
        $this->assertArrayHasKey('examination_type_id', $this->payload($this->send('POST', '/examinations', [
            'patient_id' => $this->patient['id'], 'examination_type_id' => $this->type['id'],
        ], $this->doctor))['errors']);
    }

    public function testOfflineResultsThroughSync(): void
    {
        $technicianUser = $this->createUser(['TECHNICIEN']);
        $token = $this->login($technicianUser, $this->device())['access_token'];
        $id = $this->prescribe();

        $changes = $this->pullAll($token);
        $this->assertContains($id, $this->ids($changes, 'examination'));
        $this->assertContains($this->patient['id'], $this->ids($changes, 'patient'));

        $results = $this->push([
            $this->op('examination', 'ACTION', $id, ['results' => [['parameter_id' => $this->malaria['id'], 'value_text' => 'NEGATIF']]], ['action' => 'record_results']),
            $this->op('examination', 'ACTION', $id, [], ['action' => 'complete']),
            $this->op('examination', 'ACTION', $id, [], ['action' => 'validate']),
        ], $token);

        $this->assertSame('APPLIED', $results[0]['status']);
        $this->assertSame('APPLIED', $results[1]['status']);
        $this->assertSame('TERMINE', $results[1]['data']['status']);
        $this->assertSame('REJECTED', $results[2]['status']);

        $offlineId = Uuid::v4();
        $doctorToken = $this->login($this->doctorUser, $this->device())['access_token'];
        $created = $this->push([$this->op('examination', 'CREATE', $offlineId, [
            'patient_id' => $this->patient['id'], 'examination_type_id' => $this->type['id'],
        ])], $doctorToken)[0];
        $this->assertSame('APPLIED', $created['status']);
        $this->assertSame('PRESCRIT', $created['data']['status']);
    }

    private function prescribe(array $extra = []): string
    {
        $response = $this->send('POST', '/examinations', [
            'patient_id' => $this->patient['id'], 'examination_type_id' => $this->type['id'],
        ] + $extra, $this->doctor);
        $this->assertStatus(201, $response);
        return $this->payload($response)['data']['id'];
    }

    private function finishedExamination(): string
    {
        $id = $this->prescribe(['clinical_info' => 'Bilan de contrôle']);
        $this->send('POST', '/examinations/' . $id . '/results', ['results' => [['parameter_id' => $this->glucose['id'], 'value_numeric' => 1]]], $this->technician);
        $this->assertStatus(200, $this->send('POST', '/examinations/' . $id . '/complete', null, $this->technician));
        return $id;
    }

    private function op(string $entity, string $operation, string $entityId, array $payload = [], array $extra = []): array
    {
        return [
            'op_id' => Uuid::v4(),
            'entity' => $entity,
            'entity_id' => $entityId,
            'operation' => $operation,
            'payload' => $payload,
            'client_created_at' => '2026-09-26T08:00:00Z',
        ] + $extra;
    }

    private function push(array $operations, string $token): array
    {
        $response = $this->send('POST', '/sync/push', ['operations' => $operations], $token);
        $this->assertStatus(200, $response);
        return $this->payload($response)['data']['results'];
    }

    private function pullAll(string $token): array
    {
        $changes = [];
        $cursor = 0;
        do {
            $response = $this->send('GET', '/sync/pull?cursor=' . $cursor . '&limit=500', null, $token);
            $this->assertStatus(200, $response);
            $page = $this->payload($response)['data'];
            $changes = array_merge($changes, $page['changes']);
            $cursor = $page['next_cursor'];
        } while ($page['has_more']);
        return $changes;
    }

    /**
     * @return string[]
     */
    private function ids(array $changes, string $entity): array
    {
        return array_values(array_unique(array_column(array_filter($changes, function (array $change) use ($entity): bool {
            return $change['entity'] === $entity;
        }), 'id')));
    }
}
