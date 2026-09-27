<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Core\Support\Uuid;

final class HomecareTest extends ApiTestCase
{
    /** @var string */
    private $admin;

    /** @var string */
    private $reception;

    /** @var array */
    private $nurseA1User;

    /** @var array */
    private $nurseBUser;

    /** @var string */
    private $nurseA1;

    /** @var string */
    private $nurseA2;

    /** @var string */
    private $nurseB;

    /** @var array */
    private $teamA;

    /** @var array */
    private $teamB;

    /** @var array */
    private $patient;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $n = ++self::$sequence;
        $this->admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $this->nurseA1User = $this->createUser(['INFIRMIER']);
        $nurseA2User = $this->createUser(['INFIRMIER']);
        $this->nurseBUser = $this->createUser(['INFIRMIER']);
        $this->nurseA1 = $this->login($this->nurseA1User)['access_token'];
        $this->nurseA2 = $this->login($nurseA2User)['access_token'];
        $this->nurseB = $this->login($this->nurseBUser)['access_token'];
        $this->teamA = $this->team('HA' . getmypid() . 'N' . $n, [$this->nurseA1User, $nurseA2User]);
        $this->teamB = $this->team('HB' . getmypid() . 'N' . $n, [$this->nurseBUser]);
        $this->patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Hadiza', 'last_name' => 'Domicile' . getmypid() . 'N' . $n, 'sex' => 'F',
            'birth_date' => sprintf('19%02d-0%d-1%d', 50 + $n % 49, 1 + $n % 9, $n % 10),
        ], $this->admin))['data'];
    }

    public function testSelfAssignedVisitFromRequestToCompletion(): void
    {
        $account = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->db()->execute('UPDATE patients SET user_id = ? WHERE uuid = ?', [$account['id'], $this->patient['id']]);
        $patientToken = $this->login($account)['access_token'];

        $id = $this->request();
        $queue = $this->payload($this->send('GET', '/homecare?queue=1', null, $this->nurseB))['data'];
        $this->assertContains($id, array_column($queue, 'id'));

        $accepted = $this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA1);
        $this->assertStatus(200, $accepted);
        $this->assertSame('PRISE_EN_CHARGE', $this->payload($accepted)['data']['status']);
        $this->assertSame($this->teamA['id'], $this->payload($accepted)['data']['team']['id']);

        // D-003 : la première équipe gagne ; l'autre équipe ne voit plus la demande.
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseB))['code']);
        $this->assertStatus(404, $this->send('GET', '/homecare/' . $id, null, $this->nurseB));
        $this->assertSame('NOT_TEAM_MEMBER', $this->payload($this->send('POST', '/homecare/' . $id . '/depart', [], $this->nurseB))['code']);

        $position = ['latitude' => 13.5116, 'longitude' => 2.1254, 'accuracy_m' => 12];
        $this->assertSame('EN_ROUTE', $this->payload($this->send('POST', '/homecare/' . $id . '/depart', $position, $this->nurseA2))['data']['status']);
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/homecare/' . $id . '/start', [], $this->nurseA2))['code']);
        $this->assertSame('SUR_PLACE', $this->payload($this->send('POST', '/homecare/' . $id . '/arrive', ['latitude' => 13.52, 'longitude' => 2.13], $this->nurseA2))['data']['status']);

        $started = $this->payload($this->send('POST', '/homecare/' . $id . '/start', [], $this->nurseA1))['data'];
        $this->assertSame('EN_COURS', $started['status']);
        $this->assertNotNull($started['consultation_id']);
        $consultation = $this->payload($this->send('GET', '/consultations/' . $started['consultation_id'], null, $this->nurseA1))['data'];
        $this->assertSame('DOMICILE', $consultation['consultation_type']);
        $this->assertStatus(201, $this->send('POST', '/consultations/' . $started['consultation_id'] . '/vitals', ['temperature_c' => 38.4], $this->nurseA1));

        $done = $this->payload($this->send('POST', '/homecare/' . $id . '/complete', ['comment' => 'Patient stable'], $this->nurseA1))['data'];
        $this->assertSame('TERMINEE', $done['status']);
        $this->assertSame(
            ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS', 'TERMINEE'],
            array_column($done['history'], 'to')
        );
        $this->assertSame(13.5116, $done['history'][3]['latitude']);

        $mine = $this->payload($this->send('GET', '/me/patients/' . $this->patient['id'] . '/homecare', null, $patientToken))['data'];
        $this->assertSame('TERMINEE', $mine[0]['status']);
        $this->assertArrayNotHasKey('history', $mine[0]);
        $this->assertArrayNotHasKey('contact_phone', $mine[0]);
        $this->assertSame(2, (int) $this->db()->fetchValue(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND notif_type = 'HOMECARE_STATUS'",
            [$account['id']]
        ));
    }

    public function testTeamInChargeCanValidateExaminationFromTheVisit(): void
    {
        $id = $this->request();
        $this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA1);
        $this->send('POST', '/homecare/' . $id . '/depart', [], $this->nurseA1);
        $this->send('POST', '/homecare/' . $id . '/arrive', [], $this->nurseA1);
        $consultationId = $this->payload($this->send('POST', '/homecare/' . $id . '/start', [], $this->nurseA1))['data']['consultation_id'];

        $doctor = $this->login($this->createUser(['MEDECIN']))['access_token'];
        $technician = $this->login($this->createUser(['TECHNICIEN']))['access_token'];
        $type = $this->payload($this->send('POST', '/examination-types', ['code' => 'TDRH' . getmypid() . 'N' . self::$sequence, 'label' => 'TDR à domicile'], $this->admin))['data'];
        $exam = $this->payload($this->send('POST', '/examinations', [
            'patient_id' => $this->patient['id'], 'consultation_id' => $consultationId, 'examination_type_id' => $type['id'],
        ], $doctor))['data'];
        $this->send('POST', '/examinations/' . $exam['id'] . '/results', ['results' => [['label' => 'TDR', 'value_text' => 'Négatif']]], $technician);
        $this->send('POST', '/examinations/' . $exam['id'] . '/complete', null, $technician);

        // D-005 : l'équipe en charge de la visite peut valider, une autre équipe non.
        $this->assertStatus(403, $this->send('POST', '/examinations/' . $exam['id'] . '/validate', null, $this->nurseB));
        $this->assertSame('VALIDE', $this->payload($this->send('POST', '/examinations/' . $exam['id'] . '/validate', null, $this->nurseA2))['data']['status']);
    }

    public function testDispatchReassignmentAndRelease(): void
    {
        $id = $this->request();
        $this->assertStatus(403, $this->send('POST', '/homecare/' . $id . '/assign', ['team_id' => $this->teamA['id']], $this->nurseA1));

        $assigned = $this->payload($this->send('POST', '/homecare/' . $id . '/assign', ['team_id' => $this->teamA['id']], $this->reception))['data'];
        $this->assertSame('PRISE_EN_CHARGE', $assigned['status']);
        $this->assertSame('ALREADY_ASSIGNED', $this->payload($this->send('POST', '/homecare/' . $id . '/assign', ['team_id' => $this->teamA['id']], $this->reception))['code']);

        $reassigned = $this->payload($this->send('POST', '/homecare/' . $id . '/assign', ['team_id' => $this->teamB['id']], $this->reception))['data'];
        $this->assertSame($this->teamB['id'], $reassigned['team']['id']);
        $this->assertSame('NOT_TEAM_MEMBER', $this->payload($this->send('POST', '/homecare/' . $id . '/depart', [], $this->nurseA1))['code']);
        $this->assertSame(1, (int) $this->db()->fetchValue(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND notif_type = 'HOMECARE_ASSIGNED' AND entity_uuid = ?",
            [$this->nurseBUser['id'], $id]
        ));

        $this->assertArrayHasKey('reason', $this->payload($this->send('POST', '/homecare/' . $id . '/release', [], $this->nurseB))['errors']);
        $released = $this->payload($this->send('POST', '/homecare/' . $id . '/release', ['reason' => 'Véhicule en panne'], $this->nurseB))['data'];
        $this->assertSame('EN_ATTENTE', $released['status']);
        $this->assertNull($released['team']);
        $this->assertSame('PRISE_EN_CHARGE', $this->payload($this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA1))['data']['status']);
    }

    public function testCancellationRules(): void
    {
        $account = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->db()->execute('UPDATE patients SET user_id = ? WHERE uuid = ?', [$account['id'], $this->patient['id']]);
        $patientToken = $this->login($account)['access_token'];

        $own = $this->send('POST', '/homecare', $this->requestBody(), $patientToken);
        $this->assertStatus(201, $own);
        $ownId = $this->payload($own)['data']['id'];
        $this->assertArrayNotHasKey('history', $this->payload($own)['data']);
        $this->assertSame('HOMECARE_ALREADY_OPEN', $this->payload($this->send('POST', '/homecare', $this->requestBody(), $this->reception))['code']);
        $this->assertSame('ANNULEE_PATIENT', $this->payload($this->send('POST', '/homecare/' . $ownId . '/cancel', [], $patientToken))['data']['status']);

        $id = $this->request();
        $this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA1);
        $this->send('POST', '/homecare/' . $id . '/depart', [], $this->nurseA1);
        $this->send('POST', '/homecare/' . $id . '/arrive', [], $this->nurseA1);
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/homecare/' . $id . '/cancel', [], $patientToken))['code']);
        $this->assertStatus(403, $this->send('POST', '/homecare/' . $id . '/cancel', ['reason' => 'x'], $this->nurseB));
        $this->assertArrayHasKey('reason', $this->payload($this->send('POST', '/homecare/' . $id . '/cancel', [], $this->reception))['errors']);
        $cancelled = $this->payload($this->send('POST', '/homecare/' . $id . '/cancel', ['reason' => 'Patient hospitalisé'], $this->reception))['data'];
        $this->assertSame('ANNULEE_CLINIQUE', $cancelled['status']);
        $this->assertSame('Patient hospitalisé', $cancelled['cancel_reason']);
    }

    public function testFailureMapAndValidation(): void
    {
        $this->assertArrayHasKey('latitude', $this->payload($this->send('POST', '/homecare', [
            'patient_id' => $this->patient['id'], 'reason' => 'Pansement', 'contact_phone' => '+22790001122',
        ], $this->reception))['errors']);
        $this->assertArrayHasKey('longitude', $this->payload($this->send('POST', '/homecare', $this->requestBody(['longitude' => null]), $this->reception))['errors']);

        $id = $this->request();
        $this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA1);
        $this->send('POST', '/homecare/' . $id . '/depart', ['latitude' => 13.50, 'longitude' => 2.10], $this->nurseA1);
        $this->assertSame(['recorded' => 2, 'duplicates' => 0], $this->payload($this->send('POST', '/homecare/' . $id . '/track', ['points' => [
            ['latitude' => 13.505, 'longitude' => 2.11, 'captured_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)],
            ['latitude' => 13.509, 'longitude' => 2.12, 'captured_at' => gmdate('Y-m-d\TH:i:s\Z')],
        ]], $this->nurseA1))['data']);

        $map = $this->payload($this->send('GET', '/map/homecare', null, $this->admin))['data'];
        $entry = array_values(array_filter($map, function (array $item) use ($id): bool {
            return $item['id'] === $id;
        }))[0];
        $this->assertSame('EN_ROUTE', $entry['status']);
        $this->assertSame(13.509, $entry['team_position']['latitude']);
        $this->assertArrayNotHasKey('reason', $entry);
        $this->assertArrayNotHasKey('name', $entry);
        $this->assertStatus(403, $this->send('GET', '/map/homecare', null, $this->nurseA1));

        $this->assertArrayHasKey('reason', $this->payload($this->send('POST', '/homecare/' . $id . '/fail', [], $this->nurseA1))['errors']);
        $failed = $this->payload($this->send('POST', '/homecare/' . $id . '/fail', ['reason' => 'Adresse introuvable'], $this->nurseA1))['data'];
        $this->assertSame('ECHEC', $failed['status']);
        $this->assertSame('Adresse introuvable', $failed['failure_reason']);
    }

    public function testDispatchOnlyModeDisablesSelfAssignment(): void
    {
        $id = $this->request();
        $this->assertStatus(200, $this->send('PUT', '/settings', ['values' => ['homecare.dispatch_mode' => 'DISPATCH_ONLY']], $this->admin));
        try {
            $this->assertSame('SELF_ASSIGN_DISABLED', $this->payload($this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA1))['code']);
            $this->assertNotContains($id, array_column($this->payload($this->send('GET', '/homecare?queue=1', null, $this->nurseA1))['data'], 'id'));
            $this->assertSame('PRISE_EN_CHARGE', $this->payload($this->send('POST', '/homecare/' . $id . '/assign', ['team_id' => $this->teamA['id']], $this->reception))['data']['status']);
        } finally {
            $this->send('PUT', '/settings', ['values' => ['homecare.dispatch_mode' => 'BOTH']], $this->admin);
        }
    }

    public function testOfflineFieldWorkThroughSync(): void
    {
        $id = $this->request();
        $token = $this->login($this->nurseA1User, $this->device())['access_token'];
        $otherToken = $this->login($this->createUser(['INFIRMIER']), $this->device())['access_token'];

        $queued = $this->find($this->pullAll($token), 'homecare_request', $id);
        $this->assertSame('EN_ATTENTE', $queued['data']['status']);
        $this->assertSame('Pansement du pied', $queued['data']['reason']);

        $consultationId = Uuid::v4();
        $start = $this->op('homecare_request', 'ACTION', $id, ['consultation_id' => $consultationId, 'at' => gmdate('Y-m-d\TH:i:s\Z', time() - 600)], ['action' => 'start']);
        $results = $this->push([
            $this->op('homecare_request', 'ACTION', $id, [], ['action' => 'accept']),
            $this->op('homecare_request', 'ACTION', $id, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 1800), 'latitude' => 13.5, 'longitude' => 2.1], ['action' => 'depart']),
            $this->op('homecare_request', 'ACTION', $id, ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 900)], ['action' => 'arrive']),
            $start,
            $this->op('vital_sign', 'CREATE', Uuid::v4(), ['consultation_id' => $consultationId, 'temperature_c' => 37.9], ['depends_on' => $start['op_id']]),
            $this->op('homecare_request', 'ACTION', $id, [], ['action' => 'complete']),
            $this->op('homecare_request', 'ACTION', $id, [], ['action' => 'arrive']),
        ], $token);

        $this->assertSame(['APPLIED', 'APPLIED', 'APPLIED', 'APPLIED', 'APPLIED', 'APPLIED', 'REJECTED'], array_column($results, 'status'));
        $this->assertSame($consultationId, $results[3]['data']['consultation_id']);
        $history = $this->payload($this->send('GET', '/homecare/' . $id, null, $this->nurseA1))['data']['history'];
        // L'heure réelle de l'action (appareil) est conservée à côté de l'heure de réception.
        $this->assertLessThan(strtotime($history[3]['received_at']), strtotime($history[3]['at']));

        // Une autre infirmière ne reçoit que le statut, pour retirer la demande de sa file.
        $seen = $this->find($this->pullAll($otherToken), 'homecare_request', $id);
        $this->assertSame('TERMINEE', $seen['data']['status']);
        $this->assertFalse($seen['data']['available']);
        $this->assertArrayNotHasKey('reason', $seen['data']);
    }

    private function request(): string
    {
        $response = $this->send('POST', '/homecare', $this->requestBody(), $this->reception);
        $this->assertStatus(201, $response);
        $data = $this->payload($response)['data'];
        $this->assertSame('EN_ATTENTE', $data['status']);
        return $data['id'];
    }

    private function requestBody(array $overrides = []): array
    {
        return array_filter($overrides + [
            'patient_id' => $this->patient['id'],
            'reason' => 'Pansement du pied',
            'urgency' => 'NORMALE',
            'latitude' => 13.5137,
            'longitude' => 2.1098,
            'gps_accuracy_m' => 8,
            'landmark' => 'Derrière la mosquée du quartier',
            'contact_phone' => '+22790001122',
        ], function ($value): bool {
            return $value !== null;
        });
    }

    private function team(string $code, array $members): array
    {
        $team = $this->payload($this->send('POST', '/teams', ['code' => $code, 'label' => 'Équipe ' . $code], $this->admin))['data'];
        foreach ($members as $member) {
            $this->assertStatus(201, $this->send('POST', '/teams/' . $team['id'] . '/members', ['user_id' => $member['uuid'], 'team_role' => 'INFIRMIER'], $this->admin));
        }
        return $team;
    }

    private function op(string $entity, string $operation, string $entityId, array $payload = [], array $extra = []): array
    {
        return [
            'op_id' => Uuid::v4(),
            'entity' => $entity,
            'entity_id' => $entityId,
            'operation' => $operation,
            'payload' => $payload,
            'client_created_at' => gmdate('Y-m-d\TH:i:s\Z'),
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

    private function find(array $changes, string $entity, string $id): array
    {
        foreach (array_reverse($changes) as $change) {
            if ($change['entity'] === $entity && $change['id'] === $id) {
                return $change;
            }
        }
        $this->fail('Élément ' . $entity . ' ' . $id . ' absent du pull.');
    }
}
