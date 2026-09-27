<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Core\Support\Uuid;
use Vsh\Modules\Notifications\NotificationService;

final class SyncTest extends ApiTestCase
{
    /** @var string */
    private $doctor;

    /** @var string */
    private $reception;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doctor = $this->login($this->createUser(['MEDECIN']), $this->device())['access_token'];
        $this->reception = $this->login($this->createUser(['ACCUEIL']), $this->device())['access_token'];
    }

    public function testPushRequiresARegisteredDevice(): void
    {
        $webSession = $this->login($this->createUser(['MEDECIN']))['access_token'];

        $response = $this->send('POST', '/sync/push', ['operations' => [$this->op('patient', 'CREATE', Uuid::v4(), $this->identity())]], $webSession);

        $this->assertStatus(400, $response);
        $this->assertSame('DEVICE_REQUIRED', $this->payload($response)['code']);
    }

    public function testOfflineCreationIsAppliedOnlyOnceEvenIfSentTwice(): void
    {
        $patientId = Uuid::v4();
        $contactId = Uuid::v4();
        $createPatient = $this->op('patient', 'CREATE', $patientId, $this->identity());
        $createContact = $this->op('patient_contact', 'CREATE', $contactId, [
            'patient_id' => $patientId, 'full_name' => 'Awa Moussa', 'phone' => '96 12 12 12',
        ], ['depends_on' => $createPatient['op_id']]);

        $first = $this->push([$createPatient, $createContact], $this->doctor);

        $this->assertSame(['APPLIED', 'APPLIED'], array_column($first, 'status'));
        $this->assertMatchesRegularExpression('/^VSH-\d{4}-\d{6}$/', $first[0]['data']['file_number']);
        $this->assertSame($patientId, $first[1]['data']['patient_id']);

        // Réponse perdue : l'appareil renvoie exactement le même lot.
        $second = $this->push([$createPatient, $createContact], $this->doctor);

        $this->assertSame([true, true], array_column($second, 'duplicate'));
        $this->assertSame(['APPLIED', 'APPLIED'], array_column($second, 'status'));
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM patients WHERE uuid = ?', [$patientId]));
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM patient_contacts WHERE uuid = ?', [$contactId]));
    }

    public function testOperationDependingOnARejectedOneIsDeferred(): void
    {
        $patientId = Uuid::v4();
        $invalid = $this->op('patient', 'CREATE', $patientId, ['first_name' => 'Sans', 'last_name' => 'Sexe']);
        $child = $this->op('allergy', 'CREATE', Uuid::v4(), ['patient_id' => $patientId, 'allergen' => 'Latex'], ['depends_on' => $invalid['op_id']]);

        $results = $this->push([$invalid, $child], $this->doctor);

        $this->assertSame('REJECTED', $results[0]['status']);
        $this->assertSame('VALIDATION_ERROR', $results[0]['error']['code']);
        $this->assertSame('DEFERRED', $results[1]['status']);

        $replay = $this->push([$invalid], $this->doctor)[0];
        $this->assertTrue($replay['duplicate']);
        $this->assertSame('REJECTED', $replay['status']);
    }

    public function testUpdateBasedOnTheCurrentVersionIsApplied(): void
    {
        $patient = $this->createPatient();

        $result = $this->push([$this->op('patient', 'UPDATE', $patient['id'], ['first_name' => 'Hadiza'], [
            'base_version' => $patient['version'],
        ])], $this->doctor)[0];

        $this->assertSame('APPLIED', $result['status']);
        $this->assertSame('Hadiza', $result['data']['first_name']);
        $this->assertSame($patient['version'] + 1, $result['version']);
    }

    public function testConcurrentChangesOnDifferentFieldsAreMerged(): void
    {
        $patient = $this->createPatient();
        $this->send('PUT', '/patients/' . $patient['id'], ['phone' => '90 44 44 44'], $this->reception);

        $result = $this->push([$this->op('patient', 'UPDATE', $patient['id'], ['first_name' => 'Rakia'], [
            'base_version' => $patient['version'],
            'base' => ['first_name' => $patient['first_name']],
        ])], $this->doctor)[0];

        $this->assertSame('APPLIED', $result['status']);
        $this->assertSame('Rakia', $result['data']['first_name']);
        $this->assertSame('+22790444444', $result['data']['phone']);
    }

    public function testConflictIsRecordedWithoutLosingDataAndCanBeResolved(): void
    {
        $patient = $this->createPatient();
        $this->send('PUT', '/patients/' . $patient['id'], ['first_name' => 'Serveur'], $this->reception);

        $result = $this->push([$this->op('patient', 'UPDATE', $patient['id'], ['first_name' => 'Appareil'], [
            'base_version' => $patient['version'],
            'base' => ['first_name' => $patient['first_name']],
        ])], $this->doctor)[0];

        $this->assertSame('CONFLICT', $result['status']);
        $this->assertSame(['first_name'], $result['conflict']['fields']);
        $this->assertSame('Serveur', $result['data']['first_name'], 'La valeur du serveur est conservée en attendant l\'arbitrage');

        $conflicts = $this->payload($this->send('GET', '/sync/conflicts', null, $this->doctor))['data'];
        $this->assertSame(['client' => 'Appareil', 'server' => 'Serveur'], $conflicts[0]['details']['first_name']);

        $resolved = $this->send('POST', '/sync/conflicts/' . $result['conflict']['id'] . '/resolve', ['resolution' => 'CLIENT'], $this->doctor);
        $this->assertStatus(200, $resolved);
        $this->assertSame('RESOLU_CLIENT', $this->payload($resolved)['data']['status']);
        $this->assertSame('Appareil', $this->payload($this->send('GET', '/patients/' . $patient['id'], null, $this->doctor))['data']['first_name']);
        $this->assertStatus(409, $this->send('POST', '/sync/conflicts/' . $result['conflict']['id'] . '/resolve', ['resolution' => 'SERVER'], $this->doctor));
    }

    public function testServerPermissionsApplyToSynchronisedOperations(): void
    {
        $patient = $this->createPatient();
        $operation = $this->op('allergy', 'CREATE', Uuid::v4(), ['patient_id' => $patient['id'], 'allergen' => 'Arachide']);

        $result = $this->push([$operation], $this->reception)[0];

        $this->assertSame('REJECTED', $result['status']);
        $this->assertSame('FORBIDDEN', $result['error']['code']);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM allergies WHERE uuid = ?', [$operation['entity_id']]));
    }

    public function testDeletingTwiceIsHarmless(): void
    {
        $patient = $this->createPatient();
        $contactId = Uuid::v4();
        $this->push([$this->op('patient_contact', 'CREATE', $contactId, ['patient_id' => $patient['id'], 'full_name' => 'Ali', 'phone' => '96 30 30 30'])], $this->reception);

        $first = $this->push([$this->op('patient_contact', 'DELETE', $contactId)], $this->reception)[0];
        $second = $this->push([$this->op('patient_contact', 'DELETE', $contactId)], $this->reception)[0];

        $this->assertSame(['APPLIED', 'APPLIED'], [$first['status'], $second['status']]);
        $this->assertSame([], $this->payload($this->send('GET', '/patients/' . $patient['id'] . '/contacts', null, $this->reception))['data']);
    }

    public function testUnknownEntityOrOperationIsRejected(): void
    {
        $results = $this->push([
            $this->op('inconnue', 'CREATE', Uuid::v4()),
            $this->op('patient', 'DELETE', Uuid::v4()),
        ], $this->doctor);

        $this->assertSame(['UNSUPPORTED_OPERATION', 'UNSUPPORTED_OPERATION'], array_column(array_column($results, 'error'), 'code'));
    }

    public function testPullOnlyReturnsTheUserScopeAndRespectsMedicalRights(): void
    {
        $patientId = Uuid::v4();
        $allergyId = Uuid::v4();
        $this->push([
            $this->op('patient', 'CREATE', $patientId, $this->identity()),
            $this->op('allergy', 'CREATE', $allergyId, ['patient_id' => $patientId, 'allergen' => 'Pénicilline']),
        ], $this->doctor);

        $creator = $this->pullAll($this->doctor);
        $this->assertContains($patientId, $this->ids($creator, 'patient'));
        $this->assertContains($allergyId, $this->ids($creator, 'allergy'));

        $otherDoctor = $this->login($this->createUser(['MEDECIN']), $this->device())['access_token'];
        $this->assertNotContains($patientId, $this->ids($this->pullAll($otherDoctor), 'patient'), 'Hors périmètre tant qu\'il n\'est pas épinglé');

        $this->assertStatus(200, $this->send('POST', '/sync/patients/' . $patientId . '/pin', null, $otherDoctor));
        $afterPin = $this->pullAll($otherDoctor);
        $this->assertContains($patientId, $this->ids($afterPin, 'patient'));
        $this->assertContains($allergyId, $this->ids($afterPin, 'allergy'));

        $this->send('POST', '/sync/patients/' . $patientId . '/pin', null, $this->reception);
        $receptionPull = $this->pullAll($this->reception);
        $this->assertContains($patientId, $this->ids($receptionPull, 'patient'));
        $this->assertNotContains($allergyId, $this->ids($receptionPull, 'allergy'), 'Pas de données médicales pour l\'accueil');
    }

    public function testPullIsIncremental(): void
    {
        $patientId = Uuid::v4();
        $this->push([$this->op('patient', 'CREATE', $patientId, $this->identity())], $this->doctor);
        $initial = $this->pull($this->doctor, 0, 500);
        while ($initial['has_more']) {
            $initial = $this->pull($this->doctor, $initial['next_cursor'], 500);
        }
        $cursor = $initial['next_cursor'];

        $this->assertSame([], $this->pull($this->doctor, $cursor)['changes']);

        $patient = $this->payload($this->send('GET', '/patients/' . $patientId, null, $this->doctor))['data'];
        $this->push([$this->op('patient', 'UPDATE', $patientId, ['first_name' => 'Nouveau'], ['base_version' => $patient['version']])], $this->doctor);

        $delta = $this->pull($this->doctor, $cursor);
        $this->assertSame([$patientId], array_column($delta['changes'], 'id'));
        $this->assertSame('Nouveau', $delta['changes'][0]['data']['first_name']);
        $this->assertGreaterThan($cursor, $delta['next_cursor']);
    }

    public function testPatientAccountReceivesOnlyItsOwnRecordsAndNotifications(): void
    {
        $account = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $own = $this->createPatient();
        $other = $this->createPatient();
        $this->db()->execute('UPDATE patients SET user_id = ? WHERE uuid = ?', [$account['id'], $own['id']]);
        $this->container->get(NotificationService::class)->notify($account['id'], 'TEST', 'Bienvenue', null, 'patient', $own['id']);
        $token = $this->login($account, $this->device())['access_token'];

        $changes = $this->pullAll($token);

        $this->assertSame([$own['id']], $this->ids($changes, 'patient'));
        $this->assertNotContains($other['id'], $this->ids($changes, 'patient'));
        $notificationId = $this->ids($changes, 'notification')[0];

        $read = $this->push([$this->op('notification', 'UPDATE', $notificationId, ['read_at' => '2026-09-25T10:00:00Z'], ['base' => ['read_at' => null]])], $token)[0];
        $this->assertSame('APPLIED', $read['status']);
        $this->assertNotNull($read['data']['read_at']);
    }

    public function testSupervisionAndDeviceRevocation(): void
    {
        $admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->push([$this->op('inconnue', 'CREATE', Uuid::v4())], $this->doctor);

        $devices = $this->payload($this->send('GET', '/sync/devices?per_page=100', null, $admin))['data'];
        $this->assertNotEmpty($devices);
        $rejected = $this->payload($this->send('GET', '/sync/operations?status=REJECTED', null, $admin));
        $this->assertGreaterThanOrEqual(1, $rejected['meta']['total']);
        $this->assertStatus(403, $this->send('GET', '/sync/devices', null, $this->doctor));

        $status = $this->payload($this->send('GET', '/sync/status', null, $this->doctor))['data'];
        $this->assertNotNull($status['device']);
        $this->assertContains('patient', $status['entities']);

        $this->assertStatus(200, $this->send('POST', '/sync/devices/' . $status['device']['id'] . '/revoke', null, $admin));
        $this->assertStatus(401, $this->send('GET', '/sync/status', null, $this->doctor));
    }

    public function testConsultationIsRecordedOfflineAndClosedByAction(): void
    {
        $patient = $this->createPatient();
        $consultationId = Uuid::v4();
        $open = $this->op('consultation', 'CREATE', $consultationId, [
            'patient_id' => $patient['id'], 'consultation_type' => 'CLINIQUE', 'chief_complaint' => 'Toux',
        ]);
        $operations = [
            $open,
            $this->op('vital_sign', 'CREATE', Uuid::v4(), ['consultation_id' => $consultationId, 'temperature_c' => 38.2], ['depends_on' => $open['op_id']]),
            $this->op('consultation_diagnosis', 'CREATE', Uuid::v4(), ['consultation_id' => $consultationId, 'label' => 'Bronchite aiguë'], ['depends_on' => $open['op_id']]),
            $this->op('consultation', 'ACTION', $consultationId, [], ['action' => 'close', 'depends_on' => $open['op_id']]),
        ];

        $results = $this->push($operations, $this->doctor);

        $this->assertSame(['APPLIED', 'APPLIED', 'APPLIED', 'APPLIED'], array_column($results, 'status'));
        $this->assertSame('CLOTUREE', $results[3]['data']['status']);
        $this->assertSame([true, true, true, true], array_column($this->push($operations, $this->doctor), 'duplicate'));

        $late = $this->push([$this->op('vital_sign', 'CREATE', Uuid::v4(), ['consultation_id' => $consultationId, 'pulse_bpm' => 90])], $this->doctor)[0];
        $this->assertSame('REJECTED', $late['status']);
        $this->assertSame('CONSULTATION_CLOSED', $late['error']['code']);
    }

    public function testPinningBringsTheWholeRecordIncludingConsultations(): void
    {
        $patient = $this->createPatient();
        $consultationId = Uuid::v4();
        $vitalId = Uuid::v4();
        $this->push([
            $this->op('consultation', 'CREATE', $consultationId, ['patient_id' => $patient['id'], 'consultation_type' => 'CLINIQUE']),
            $this->op('vital_sign', 'CREATE', $vitalId, ['consultation_id' => $consultationId, 'spo2_percent' => 98]),
        ], $this->doctor);
        $colleague = $this->login($this->createUser(['MEDECIN']), $this->device())['access_token'];
        $this->assertNotContains($consultationId, $this->ids($this->pullAll($colleague), 'consultation'));

        $this->send('POST', '/sync/patients/' . $patient['id'] . '/pin', null, $colleague);
        $changes = $this->pullAll($colleague);

        $this->assertContains($consultationId, $this->ids($changes, 'consultation'));
        $this->assertContains($vitalId, $this->ids($changes, 'vital_sign'));
    }

    public function testUnknownActionIsRejected(): void
    {
        $result = $this->push([$this->op('consultation', 'ACTION', Uuid::v4(), [], ['action' => 'delete_everything'])], $this->doctor)[0];

        $this->assertSame('REJECTED', $result['status']);
        $this->assertSame('UNSUPPORTED_OPERATION', $result['error']['code']);
    }

    private function op(string $entity, string $operation, string $entityId, array $payload = [], array $extra = []): array
    {
        return [
            'op_id' => Uuid::v4(),
            'entity' => $entity,
            'entity_id' => $entityId,
            'operation' => $operation,
            'payload' => $payload,
            'client_created_at' => '2026-09-25T08:00:00Z',
        ] + $extra;
    }

    private function push(array $operations, string $token): array
    {
        $response = $this->send('POST', '/sync/push', ['operations' => $operations], $token);
        $this->assertStatus(200, $response);
        return $this->payload($response)['data']['results'];
    }

    private function pull(string $token, int $cursor = 0, ?int $limit = null): array
    {
        $path = '/sync/pull?cursor=' . $cursor . ($limit !== null ? '&limit=' . $limit : '');
        $response = $this->send('GET', $path, null, $token);
        $this->assertStatus(200, $response);
        return $this->payload($response)['data'];
    }

    private function pullAll(string $token): array
    {
        $changes = [];
        $cursor = 0;
        do {
            $page = $this->pull($token, $cursor, 500);
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

    private function identity(): array
    {
        $n = ++self::$sequence;
        return [
            'first_name' => 'Mariama',
            'last_name' => 'Sync' . getmypid() . 'N' . $n,
            'sex' => 'F',
            'birth_date' => sprintf('19%02d-0%d-1%d', 50 + $n % 49, 1 + $n % 9, $n % 10),
        ];
    }

    private function createPatient(): array
    {
        $response = $this->send('POST', '/patients', $this->identity(), $this->reception);
        $this->assertStatus(201, $response);
        return $this->payload($response)['data'];
    }
}
