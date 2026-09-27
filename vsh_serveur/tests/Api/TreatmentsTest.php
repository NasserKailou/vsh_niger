<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

final class TreatmentsTest extends ApiTestCase
{
    /** @var string */
    private $nurse;

    /** @var string */
    private $admin;

    /** @var array */
    private $patient;

    /** @var array */
    private $type;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $n = ++self::$sequence;
        $this->nurse = $this->login($this->createUser(['INFIRMIER']))['access_token'];
        $this->admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Rabi', 'last_name' => 'Soin' . getmypid() . 'N' . $n, 'sex' => 'F',
            'birth_date' => sprintf('19%02d-0%d-1%d', 50 + $n % 49, 1 + $n % 9, $n % 10),
        ], $this->admin))['data'];
        $this->type = $this->payload($this->send('POST', '/treatment-types', ['code' => 'PANS' . getmypid() . 'T' . $n, 'label' => 'Pansement simple'], $this->admin))['data'];
    }

    public function testPlannedTreatmentIsPerformedOnce(): void
    {
        $planned = $this->send('POST', '/treatments', [
            'patient_id' => $this->patient['id'], 'treatment_type_id' => $this->type['id'],
            'status' => 'PLANIFIE', 'scheduled_for' => '2026-10-01T09:00:00Z',
        ], $this->nurse);
        $this->assertStatus(201, $planned);
        $id = $this->payload($planned)['data']['id'];

        $todo = $this->payload($this->send('GET', '/treatments?status=PLANIFIE&date=2026-10-01', null, $this->nurse))['data'];
        $this->assertContains($id, array_column($todo, 'id'));

        $performed = $this->send('POST', '/treatments/' . $id . '/perform', ['observations' => 'Plaie propre'], $this->nurse);
        $this->assertStatus(200, $performed);
        $this->assertSame('REALISE', $this->payload($performed)['data']['status']);
        $this->assertNotNull($this->payload($performed)['data']['performed_by']);

        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/treatments/' . $id . '/perform', [], $this->nurse))['code']);
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/treatments/' . $id . '/cancel', ['reason' => 'x'], $this->nurse))['code']);
    }

    public function testValidation(): void
    {
        $this->assertArrayHasKey('scheduled_for', $this->payload($this->send('POST', '/treatments', [
            'patient_id' => $this->patient['id'], 'treatment_type_id' => $this->type['id'], 'status' => 'PLANIFIE',
        ], $this->nurse))['errors']);

        $this->send('PUT', '/treatment-types/' . $this->type['id'], ['active' => false], $this->admin);
        $this->assertArrayHasKey('treatment_type_id', $this->payload($this->send('POST', '/treatments', [
            'patient_id' => $this->patient['id'], 'treatment_type_id' => $this->type['id'],
        ], $this->nurse))['errors']);
    }

    public function testObservationsRequireMedicalRights(): void
    {
        $created = $this->payload($this->send('POST', '/treatments', [
            'patient_id' => $this->patient['id'], 'treatment_type_id' => $this->type['id'], 'observations' => 'Saignement léger',
        ], $this->nurse))['data'];

        $asAdmin = $this->payload($this->send('GET', '/treatments/' . $created['id'], null, $this->admin))['data'];

        $this->assertSame('Saignement léger', $created['observations']);
        $this->assertArrayNotHasKey('observations', $asAdmin);
        $reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $this->assertStatus(403, $this->send('GET', '/treatments', null, $reception));
    }

    public function testPlannedTreatmentCanBeCancelledWithAReason(): void
    {
        $id = $this->payload($this->send('POST', '/treatments', [
            'patient_id' => $this->patient['id'], 'treatment_type_id' => $this->type['id'],
            'status' => 'PLANIFIE', 'scheduled_for' => '2026-10-02T09:00:00Z',
        ], $this->nurse))['data']['id'];

        $this->assertStatus(422, $this->send('POST', '/treatments/' . $id . '/cancel', [], $this->nurse));
        $cancelled = $this->send('POST', '/treatments/' . $id . '/cancel', ['reason' => 'Patient hospitalisé'], $this->nurse);

        $this->assertSame('ANNULE', $this->payload($cancelled)['data']['status']);
    }
}
