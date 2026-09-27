<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Core\Support\Uuid;

final class PrescriptionsTest extends ApiTestCase
{
    /** @var array */
    private $doctorUser;

    /** @var string */
    private $doctor;

    /** @var string */
    private $admin;

    /** @var array */
    private $patient;

    /** @var array */
    private $medication;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $n = ++self::$sequence;
        $this->doctorUser = $this->createUser(['MEDECIN']);
        $this->doctor = $this->login($this->doctorUser)['access_token'];
        $this->admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Zeinabou', 'last_name' => 'Ordo' . getmypid() . 'N' . $n, 'sex' => 'F',
            'birth_date' => sprintf('19%02d-0%d-1%d', 50 + $n % 49, 1 + $n % 9, $n % 10),
        ], $this->admin))['data'];
        // Données de test : le référentiel réel est saisi par la clinique.
        $this->medication = $this->payload($this->send('POST', '/medications', [
            'dci' => 'Amoxicilline T' . getmypid() . 'N' . $n, 'strength' => '500 mg', 'form' => 'Gélule', 'route' => 'Orale',
        ], $this->admin))['data'];
    }

    public function testTemplateLifecycleAndUseInPrescription(): void
    {
        $template = $this->payload($this->send('POST', '/prescription-templates', [
            'name' => 'Protocole test', 'pathology' => 'Pathologie test', 'population' => 'ADULTE',
            'items' => [['medication_id' => $this->medication['id'], 'posology' => '1 gélule 3 fois par jour', 'duration' => '7 jours']],
        ], $this->admin))['data'];
        $this->assertSame('BROUILLON', $template['status']);
        $this->assertSame('500 mg', $template['items'][0]['dosage']);

        // Un brouillon ne peut pas servir à prescrire.
        $this->assertArrayHasKey('template_id', $this->payload($this->send('POST', '/prescriptions', [
            'patient_id' => $this->patient['id'], 'template_id' => $template['id'],
        ], $this->doctor))['errors']);

        $this->assertStatus(403, $this->send('POST', '/prescription-templates/' . $template['id'] . '/approve', null, $this->admin));
        $approved = $this->payload($this->send('POST', '/prescription-templates/' . $template['id'] . '/approve', null, $this->doctor))['data'];
        $this->assertSame('ACTIF', $approved['status']);
        $this->assertSame($this->doctorUser['uuid'], $approved['approved_by']['id']);

        $prescription = $this->send('POST', '/prescriptions', ['patient_id' => $this->patient['id'], 'template_id' => $template['id']], $this->doctor);
        $this->assertStatus(201, $prescription);
        $prescription = $this->payload($prescription)['data'];
        $this->assertSame('BROUILLON', $prescription['status']);
        $this->assertSame(1, $prescription['template']['template_version']);
        $this->assertSame('1 gélule 3 fois par jour', $prescription['items'][0]['posology']);
        $this->assertSame($this->medication['id'], $prescription['items'][0]['medication_id']);

        // Modifier un modèle actif le repasse en brouillon (nouvelle version clinique à approuver).
        $edited = $this->payload($this->send('PUT', '/prescription-templates/' . $template['id'], ['usage_notes' => 'Revoir à J3'], $this->admin))['data'];
        $this->assertSame('BROUILLON', $edited['status']);
        $this->assertSame(2, $edited['template_version']);
        $this->assertNull($edited['approved_by']);

        $bundle = $this->payload($this->send('GET', '/reference/bundle', null, $this->doctor))['data'];
        $this->assertNotContains($template['id'], array_column($bundle['prescription_templates'], 'id'));

        $this->assertSame('ARCHIVE', $this->payload($this->send('POST', '/prescription-templates/' . $template['id'] . '/archive', null, $this->admin))['data']['status']);
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('PUT', '/prescription-templates/' . $template['id'], ['name' => 'X'], $this->admin))['code']);
    }

    public function testSuggestionAppliesOnlyConfiguredCriteria(): void
    {
        $child = $this->activeTemplate(['name' => 'Enfant', 'population' => 'ENFANT', 'age_max_months' => 180, 'weight_max_kg' => 40]);
        $adult = $this->activeTemplate(['name' => 'Adulte', 'age_min_months' => 216]);
        $heavy = $this->activeTemplate(['name' => 'Poids', 'weight_min_kg' => 50]);

        $before = $this->payload($this->send('GET', '/prescription-templates/suggest?patient_id=' . $this->patient['id'], null, $this->doctor))['data'];
        $ids = array_column($before['templates'], 'id');
        $this->assertNotContains($child['id'], $ids);
        $this->assertContains($adult['id'], $ids);
        $this->assertContains($heavy['id'], $ids);
        $this->assertSame(['weight'], $before['templates'][array_search($heavy['id'], $ids, true)]['criteria_to_check']);

        $this->assertStatus(201, $this->send('POST', '/patients/' . $this->patient['id'] . '/vitals', ['weight_kg' => 45], $this->doctor));
        $after = $this->payload($this->send('GET', '/prescription-templates/suggest?patient_id=' . $this->patient['id'], null, $this->doctor))['data'];
        $this->assertSame(45.0, $after['patient']['last_weight_kg']);
        $this->assertNotContains($heavy['id'], array_column($after['templates'], 'id'));

        $reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $this->assertStatus(403, $this->send('GET', '/prescription-templates/suggest?patient_id=' . $this->patient['id'], null, $reception));
    }

    public function testDraftSignatureAndPatientAccess(): void
    {
        $account = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->db()->execute('UPDATE patients SET user_id = ? WHERE uuid = ?', [$account['id'], $this->patient['id']]);
        $patientToken = $this->login($account)['access_token'];
        $this->send('POST', '/patients/' . $this->patient['id'] . '/allergies', ['allergen' => 'Amoxicilline', 'severity' => 'SEVERE'], $this->doctor);

        $draft = $this->payload($this->send('POST', '/prescriptions', [
            'patient_id' => $this->patient['id'],
            'notes' => 'Contrôle dans 7 jours',
            'items' => [
                ['medication_id' => $this->medication['id'], 'posology' => '1 x 3 / jour'],
                ['medication_label' => 'Paracétamol 500 mg', 'posology' => 'Si fièvre'],
            ],
        ], $this->doctor))['data'];
        $this->assertCount(1, $draft['alerts']);
        $this->assertSame(0, $draft['alerts'][0]['item_index']);
        $this->assertSame('SEVERE', $draft['alerts'][0]['severity']);

        $this->assertSame([], $this->payload($this->send('GET', '/me/patients/' . $this->patient['id'] . '/prescriptions', null, $patientToken))['data']);

        $other = $this->login($this->createUser(['MEDECIN']))['access_token'];
        $this->assertStatus(403, $this->send('POST', '/prescriptions/' . $draft['id'] . '/sign', null, $other));
        $this->assertStatus(403, $this->send('PUT', '/prescriptions/' . $draft['id'], ['notes' => 'x'], $other));

        $updated = $this->payload($this->send('PUT', '/prescriptions/' . $draft['id'], [
            'items' => [['medication_label' => 'Paracétamol 500 mg', 'posology' => '1 cp si fièvre, 4 par jour au plus']],
            'version' => $draft['version'],
        ], $this->doctor))['data'];
        $this->assertCount(1, $updated['items']);
        $this->assertSame([], $updated['alerts']);
        $this->assertSame('VERSION_CONFLICT', $this->payload($this->send('PUT', '/prescriptions/' . $draft['id'], ['notes' => 'x', 'version' => $draft['version']], $this->doctor))['code']);

        $signed = $this->payload($this->send('POST', '/prescriptions/' . $draft['id'] . '/sign', null, $this->doctor))['data'];
        $this->assertSame('SIGNEE', $signed['status']);
        $this->assertNotNull($signed['signed_at']);
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('PUT', '/prescriptions/' . $draft['id'], ['notes' => 'x'], $this->doctor))['code']);

        $mine = $this->payload($this->send('GET', '/me/patients/' . $this->patient['id'] . '/prescriptions', null, $patientToken))['data'];
        $this->assertSame([$draft['id']], array_column($mine, 'id'));
        $this->assertSame('Paracétamol 500 mg', $mine[0]['items'][0]['medication_label']);
        $notification = $this->db()->fetchOne("SELECT title FROM notifications WHERE user_id = ? AND notif_type = 'PRESCRIPTION_SIGNED'", [$account['id']]);
        $this->assertSame('Nouvelle ordonnance', $notification['title']);

        $cancelled = $this->payload($this->send('POST', '/prescriptions/' . $draft['id'] . '/cancel', ['reason' => 'Erreur de patient'], $this->doctor))['data'];
        $this->assertSame('ANNULEE', $cancelled['status']);
    }

    public function testRightsAndValidation(): void
    {
        $nurse = $this->login($this->createUser(['INFIRMIER']))['access_token'];
        $this->assertStatus(403, $this->send('POST', '/prescriptions', ['patient_id' => $this->patient['id'], 'items' => [['medication_label' => 'X']]], $nurse));
        $this->assertStatus(403, $this->send('GET', '/prescriptions', null, $this->admin));

        $errors = $this->payload($this->send('POST', '/prescriptions', [
            'patient_id' => $this->patient['id'],
            'items' => [['posology' => 'sans médicament'], ['medication_id' => Uuid::v4()]],
        ], $this->doctor))['errors'];
        $this->assertArrayHasKey('items.0.medication_label', $errors);
        $this->assertArrayHasKey('items.1.medication_id', $errors);
        $this->assertArrayHasKey('items', $this->payload($this->send('POST', '/prescriptions', ['patient_id' => $this->patient['id']], $this->doctor))['errors']);

        $id = $this->payload($this->send('POST', '/prescriptions', ['patient_id' => $this->patient['id'], 'items' => [['medication_label' => 'Soluté de réhydratation']]], $this->doctor))['data']['id'];
        $asNurse = $this->payload($this->send('GET', '/prescriptions/' . $id, null, $nurse))['data'];
        $this->assertSame('Soluté de réhydratation', $asNurse['items'][0]['medication_label']);
        $this->assertArrayHasKey('age_max_months', $this->payload($this->send('POST', '/prescription-templates', [
            'name' => 'X', 'pathology' => 'Y', 'population' => 'ENFANT', 'age_min_months' => 24, 'age_max_months' => 12,
        ], $this->admin))['errors']);
    }

    public function testOfflinePrescriptionThroughSync(): void
    {
        $token = $this->login($this->doctorUser, $this->device())['access_token'];
        $id = Uuid::v4();
        $results = $this->push([
            $this->op('prescription', 'CREATE', $id, ['patient_id' => $this->patient['id'], 'items' => [['medication_id' => $this->medication['id'], 'posology' => '1 x 2']]]),
            $this->op('prescription', 'ACTION', $id, [], ['action' => 'sign']),
            $this->op('prescription', 'UPDATE', $id, ['notes' => 'Trop tard'], ['base_version' => 1, 'base' => ['notes' => null]]),
        ], $token);

        $this->assertSame('APPLIED', $results[0]['status']);
        $this->assertSame('APPLIED', $results[1]['status']);
        $this->assertSame('SIGNEE', $results[1]['data']['status']);
        $this->assertSame('REJECTED', $results[2]['status']);

        $again = $this->push([$this->op('prescription', 'CREATE', $id, ['patient_id' => $this->patient['id'], 'items' => [['medication_label' => 'Autre']]])], $token)[0];
        $this->assertSame('APPLIED', $again['status']);
        $this->assertSame('SIGNEE', $again['data']['status']);
    }

    private function activeTemplate(array $fields): array
    {
        $template = $this->payload($this->send('POST', '/prescription-templates', $fields + [
            'pathology' => 'Suggestion ' . getmypid(), 'population' => 'ADULTE',
            'items' => [['medication_id' => $this->medication['id']]],
        ], $this->admin))['data'];
        return $this->payload($this->send('POST', '/prescription-templates/' . $template['id'] . '/approve', null, $this->doctor))['data'];
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
}
