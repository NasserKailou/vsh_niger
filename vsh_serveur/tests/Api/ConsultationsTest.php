<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

final class ConsultationsTest extends ApiTestCase
{
    /** @var string */
    private $doctor;

    /** @var string */
    private $nurse;

    /** @var string */
    private $reception;

    /** @var string */
    private $admin;

    /** @var array */
    private $patient;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doctor = $this->login($this->createUser(['MEDECIN']))['access_token'];
        $this->nurse = $this->login($this->createUser(['INFIRMIER']))['access_token'];
        $this->reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $this->admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $n = ++self::$sequence;
        $response = $this->send('POST', '/patients', [
            'first_name' => 'Halima',
            'last_name' => 'Consult' . getmypid() . 'N' . $n,
            'sex' => 'F',
            'birth_date' => sprintf('19%02d-0%d-1%d', 50 + $n % 49, 1 + $n % 9, $n % 10),
        ], $this->reception);
        $this->assertStatus(201, $response);
        $this->patient = $this->payload($response)['data'];
    }

    public function testCompleteClinicalWorkflow(): void
    {
        $opened = $this->send('POST', '/consultations', [
            'patient_id' => $this->patient['id'],
            'consultation_type' => 'CLINIQUE',
            'chief_complaint' => 'Fièvre depuis deux jours',
        ], $this->reception);
        $this->assertStatus(201, $opened);
        $consultationId = $this->payload($opened)['data']['id'];
        $this->assertSame('OUVERTE', $this->payload($opened)['data']['status']);

        $vitals = $this->send('POST', '/consultations/' . $consultationId . '/vitals', [
            'temperature_c' => 38.6, 'systolic_mmhg' => 120, 'diastolic_mmhg' => 80,
            'pulse_bpm' => 96, 'spo2_percent' => 97, 'weight_kg' => 70, 'height_cm' => 175,
        ], $this->nurse);
        $this->assertStatus(201, $vitals);
        $this->assertSame(22.9, $this->payload($vitals)['data']['bmi']);

        $updated = $this->send('PUT', '/consultations/' . $consultationId, [
            'symptoms' => 'Céphalées, frissons',
            'clinical_exam' => 'Pas de raideur de nuque',
        ], $this->doctor);
        $this->assertStatus(200, $updated);
        $this->assertSame('EN_COURS', $this->payload($updated)['data']['status']);

        $this->assertStatus(201, $this->send('POST', '/consultations/' . $consultationId . '/diagnoses', [
            'label' => 'Paludisme simple', 'icd10_code' => 'B54', 'certainty' => 'PROBABLE',
        ], $this->doctor));
        $this->assertStatus(201, $this->send('POST', '/consultations/' . $consultationId . '/notes', ['content' => 'TDR demandé.'], $this->doctor));

        $type = $this->payload($this->send('POST', '/treatment-types', ['code' => 'INJ' . getmypid() . 'C' . self::$sequence, 'label' => 'Injection IM'], $this->admin))['data'];
        $this->assertStatus(201, $this->send('POST', '/treatments', [
            'patient_id' => $this->patient['id'], 'consultation_id' => $consultationId, 'treatment_type_id' => $type['id'],
            'observations' => 'Bien toléré',
        ], $this->nurse));

        $detail = $this->payload($this->send('GET', '/consultations/' . $consultationId, null, $this->doctor))['data'];
        $this->assertSame('Fièvre depuis deux jours', $detail['chief_complaint']);
        $this->assertCount(1, $detail['vitals']);
        $this->assertSame('Paludisme simple', $detail['diagnoses'][0]['label']);
        $this->assertSame('NOTE', $detail['notes'][0]['note_kind']);
        $this->assertSame('Injection IM', $detail['treatments'][0]['type']['label']);

        $closed = $this->send('POST', '/consultations/' . $consultationId . '/close', null, $this->doctor);
        $this->assertStatus(200, $closed);
        $this->assertSame('CLOTUREE', $this->payload($closed)['data']['status']);

        // Après clôture : plus de modification, seulement des addendums.
        $this->assertSame('CONSULTATION_CLOSED', $this->payload($this->send('PUT', '/consultations/' . $consultationId, ['conclusion' => 'x'], $this->doctor))['code']);
        $this->assertStatus(409, $this->send('POST', '/consultations/' . $consultationId . '/vitals', ['pulse_bpm' => 80], $this->nurse));
        $addendum = $this->send('POST', '/consultations/' . $consultationId . '/notes', ['content' => 'Résultat TDR positif.'], $this->doctor);
        $this->assertStatus(201, $addendum);
        $this->assertSame('ADDENDUM', $this->payload($addendum)['data']['note_kind']);
    }

    public function testReceptionOnlySeesMetadata(): void
    {
        $consultationId = $this->open('Douleur abdominale');

        $asReception = $this->payload($this->send('GET', '/consultations/' . $consultationId, null, $this->reception))['data'];
        $asDoctor = $this->payload($this->send('GET', '/consultations/' . $consultationId, null, $this->doctor))['data'];

        $this->assertArrayNotHasKey('chief_complaint', $asReception);
        $this->assertArrayNotHasKey('diagnoses', $asReception);
        $this->assertSame('Douleur abdominale', $asDoctor['chief_complaint']);
        $this->assertGreaterThanOrEqual(1, (int) $this->db()->fetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'MEDICAL_RECORD_VIEWED' AND entity_uuid = ?",
            [$consultationId]
        ));
    }

    public function testRolePermissions(): void
    {
        $consultationId = $this->open(null);
        $technician = $this->login($this->createUser(['TECHNICIEN']))['access_token'];

        $this->assertStatus(403, $this->send('POST', '/consultations/' . $consultationId . '/diagnoses', ['label' => 'X'], $this->nurse));
        $this->assertStatus(403, $this->send('PUT', '/consultations/' . $consultationId, ['symptoms' => 'X'], $this->reception));
        $this->assertStatus(403, $this->send('POST', '/consultations', ['patient_id' => $this->patient['id'], 'consultation_type' => 'CLINIQUE'], $technician));
    }

    public function testInputIsValidated(): void
    {
        $consultationId = $this->open(null);

        $this->assertArrayHasKey('measures', $this->payload($this->send('POST', '/consultations/' . $consultationId . '/vitals', [], $this->nurse))['errors']);
        $this->assertArrayHasKey('spo2_percent', $this->payload($this->send('POST', '/consultations/' . $consultationId . '/vitals', ['spo2_percent' => 120], $this->nurse))['errors']);
        $this->assertArrayHasKey('icd10_code', $this->payload($this->send('POST', '/consultations/' . $consultationId . '/diagnoses', ['label' => 'X', 'icd10_code' => 'paludisme'], $this->doctor))['errors']);
        $this->assertArrayHasKey('consultation_type', $this->payload($this->send('POST', '/consultations', ['patient_id' => $this->patient['id'], 'consultation_type' => 'DOMICILE'], $this->doctor))['errors']);
        $this->assertArrayHasKey('parent_consultation_id', $this->payload($this->send('POST', '/consultations', ['patient_id' => $this->patient['id'], 'consultation_type' => 'SUIVI'], $this->doctor))['errors']);
        $this->assertArrayHasKey('started_at', $this->payload($this->send('POST', '/consultations', [
            'patient_id' => $this->patient['id'], 'consultation_type' => 'CLINIQUE', 'started_at' => '2099-01-01T08:00:00Z',
        ], $this->doctor))['errors']);

        $followUp = $this->send('POST', '/consultations', [
            'patient_id' => $this->patient['id'], 'consultation_type' => 'SUIVI', 'parent_consultation_id' => $consultationId,
        ], $this->doctor);
        $this->assertStatus(201, $followUp);
        $this->assertSame($consultationId, $this->payload($followUp)['data']['parent_consultation_id']);
    }

    public function testCancellationRequiresAReasonAndClosesTheRecord(): void
    {
        $consultationId = $this->open(null);

        $this->assertStatus(422, $this->send('POST', '/consultations/' . $consultationId . '/cancel', [], $this->doctor));
        $this->assertStatus(200, $this->send('POST', '/consultations/' . $consultationId . '/cancel', ['reason' => 'Patient reparti avant la consultation'], $this->doctor));
        $this->assertStatus(409, $this->send('POST', '/consultations/' . $consultationId . '/notes', ['content' => 'x'], $this->doctor));
    }

    public function testVitalsOutsideAConsultationAndHistory(): void
    {
        $this->assertStatus(201, $this->send('POST', '/patients/' . $this->patient['id'] . '/vitals', ['glycemia_g_l' => 1.2], $this->nurse));

        $history = $this->payload($this->send('GET', '/patients/' . $this->patient['id'] . '/vitals', null, $this->doctor))['data'];
        $this->assertSame(1.2, $history[0]['glycemia_g_l']);
        $this->assertNull($history[0]['consultation_id']);
        $this->assertStatus(403, $this->send('GET', '/patients/' . $this->patient['id'] . '/vitals', null, $this->reception));
    }

    public function testStaleVersionIsRejectedAndAuditHasNoClinicalText(): void
    {
        $consultationId = $this->open(null);
        $version = $this->payload($this->send('GET', '/consultations/' . $consultationId, null, $this->doctor))['data']['version'];

        $this->assertStatus(200, $this->send('PUT', '/consultations/' . $consultationId, ['symptoms' => 'Toux sèche nocturne', 'version' => $version], $this->doctor));
        $stale = $this->send('PUT', '/consultations/' . $consultationId, ['symptoms' => 'Autre', 'version' => $version], $this->doctor);

        $this->assertSame('VERSION_CONFLICT', $this->payload($stale)['code']);
        $audit = (string) $this->db()->fetchValue(
            "SELECT GROUP_CONCAT(new_values) FROM audit_logs WHERE entity_uuid = ? AND action = 'CONSULTATION_UPDATED'",
            [$consultationId]
        );
        $this->assertStringContainsString('symptoms', $audit);
        $this->assertStringNotContainsString('Toux sèche', $audit);
    }

    public function testListFiltersByPatientAndPractitioner(): void
    {
        $consultationId = $this->open(null);

        $byPatient = $this->payload($this->send('GET', '/patients/' . $this->patient['id'] . '/consultations', null, $this->doctor))['data'];
        $mine = $this->payload($this->send('GET', '/consultations?mine=1&per_page=100', null, $this->doctor))['data'];

        $this->assertSame([$consultationId], array_column($byPatient, 'id'));
        $this->assertContains($consultationId, array_column($mine, 'id'));
        $this->assertArrayNotHasKey('chief_complaint', $byPatient[0], 'Les listes ne contiennent pas de contenu clinique');
    }

    private function open(?string $complaint): string
    {
        $response = $this->send('POST', '/consultations', array_filter([
            'patient_id' => $this->patient['id'],
            'consultation_type' => 'CLINIQUE',
            'chief_complaint' => $complaint,
        ]), $this->doctor);
        $this->assertStatus(201, $response);
        return $this->payload($response)['data']['id'];
    }
}
