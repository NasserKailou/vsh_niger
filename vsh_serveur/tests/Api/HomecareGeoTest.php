<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Core\Support\Geo;
use Vsh\Modules\Homecare\HomecareService;

/**
 * Géolocalisation des visites : contrôles des positions, trajet, contrôle d'arrivée, relevé du domicile,
 * équipes les plus proches, positions anciennes et purge des trajets.
 */
final class HomecareGeoTest extends ApiTestCase
{
    private const HOME = ['latitude' => 13.5137, 'longitude' => 2.1098];

    /** @var string */
    private $admin;

    /** @var string */
    private $reception;

    /** @var string */
    private $nurseA;

    /** @var string */
    private $nurseB;

    /** @var array */
    private $teamA;

    /** @var array */
    private $teamB;

    /** @var int */
    private static $sequence = 0;

    /** @var int */
    private static $patients = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $n = ++self::$sequence;
        $this->admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $nurseAUser = $this->createUser(['INFIRMIER']);
        $nurseBUser = $this->createUser(['INFIRMIER']);
        $this->nurseA = $this->login($nurseAUser)['access_token'];
        $this->nurseB = $this->login($nurseBUser)['access_token'];
        $this->teamA = $this->team('GA' . getmypid() . 'N' . $n, $nurseAUser);
        $this->teamB = $this->team('GB' . getmypid() . 'N' . $n, $nurseBUser);
    }

    public function testDistanceIsComputedOnTheSphere(): void
    {
        // Un millième de degré de latitude ≈ 111 m, partout sur le globe.
        $this->assertEqualsWithDelta(111.2, Geo::distance(13.5, 2.1, 13.501, 2.1), 0.5);
        $this->assertSame(0.0, Geo::distance(13.5, 2.1, 13.5, 2.1));
    }

    public function testNullIslandPositionIsRefusedEverywhere(): void
    {
        $patient = $this->patient();
        $zero = ['latitude' => 0, 'longitude' => 0];
        $this->assertArrayHasKey('latitude', $this->payload($this->send('POST', '/homecare', $this->body($patient, $zero), $this->reception))['errors']);
        $this->assertArrayHasKey('latitude', $this->payload($this->send('POST', '/patients/' . $patient['id'] . '/addresses', $zero + ['city' => 'Niamey'], $this->admin))['errors']);

        $id = $this->visit($patient);
        $this->assertSame('PRISE_EN_CHARGE', $this->payload($this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA))['data']['status']);
        $this->assertArrayHasKey('latitude', $this->payload($this->send('POST', '/homecare/' . $id . '/depart', $zero, $this->nurseA))['errors']);
        $this->send('POST', '/homecare/' . $id . '/depart', [], $this->nurseA);
        $errors = $this->payload($this->send('POST', '/homecare/' . $id . '/track', ['points' => [
            $zero + ['captured_at' => gmdate('Y-m-d\TH:i:s\Z')],
        ]], $this->nurseA))['errors'];
        $this->assertArrayHasKey('points.0.latitude', $errors);
    }

    public function testTrackIsIdempotentAndReservedToDispatchAndTeam(): void
    {
        $id = $this->visit($this->patient());
        $this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA);
        $this->send('POST', '/homecare/' . $id . '/depart', [
            'latitude' => 13.5000, 'longitude' => 2.1000, 'accuracy_m' => 10, 'at' => gmdate('Y-m-d\TH:i:s\Z', time() - 120),
        ], $this->nurseA);
        $batch = ['points' => [
            ['latitude' => 13.5050, 'longitude' => 2.1000, 'accuracy_m' => 8, 'captured_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)],
            ['latitude' => 13.5100, 'longitude' => 2.1000, 'accuracy_m' => 500, 'captured_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 30)],
        ]];
        $this->assertSame(['recorded' => 2, 'duplicates' => 0], $this->payload($this->send('POST', '/homecare/' . $id . '/track', $batch, $this->nurseA))['data']);
        // Lot renvoyé après une coupure : aucun doublon.
        $this->assertSame(['recorded' => 0, 'duplicates' => 2], $this->payload($this->send('POST', '/homecare/' . $id . '/track', $batch, $this->nurseA))['data']);

        $track = $this->payload($this->send('GET', '/homecare/' . $id . '/track', null, $this->nurseA))['data'];
        $this->assertCount(1, $track['segments']);
        $segment = $track['segments'][0];
        $this->assertTrue($segment['active']);
        $this->assertSame($this->teamA['id'], $segment['team']['id']);
        $this->assertSame(['DEPART', 'TRACE', 'TRACE'], array_column($segment['points'], 'kind'));
        // Le point imprécis (500 m) est exclu du cumul : seuls ~556 m sont comptés.
        $this->assertEqualsWithDelta(556, $segment['distance_m'], 3);
        $this->assertSame(self::HOME, $track['home']);
        $this->assertSame(100, $track['settings']['low_accuracy_m']);

        $this->assertStatus(200, $this->send('GET', '/homecare/' . $id . '/track', null, $this->admin));
        $this->assertStatus(403, $this->send('GET', '/homecare/' . $id . '/track', null, $this->nurseB));
    }

    public function testArrivalFarFromHomeIsFlaggedWithoutBlocking(): void
    {
        $far = $this->visit($this->patient());
        $this->send('POST', '/homecare/' . $far . '/accept', [], $this->nurseA);
        $this->send('POST', '/homecare/' . $far . '/depart', [], $this->nurseA);
        // ≈ 2,9 km au nord du domicile : l'arrivée est acceptée mais signalée.
        $arrived = $this->send('POST', '/homecare/' . $far . '/arrive', ['latitude' => 13.5400, 'longitude' => 2.1098, 'accuracy_m' => 20], $this->nurseA);
        $this->assertStatus(200, $arrived);
        $geo = $this->payload($arrived)['data']['geo'];
        $this->assertTrue($geo['arrival']['far']);
        $this->assertEqualsWithDelta(2925, $geo['arrival']['distance_m'], 10);
        $this->assertFalse($geo['team_position']['stale']);
        $this->assertSame(300, $geo['settings']['arrival_radius_m']);

        $near = $this->visit($this->patient());
        $this->send('POST', '/homecare/' . $near . '/accept', [], $this->nurseB);
        $this->send('POST', '/homecare/' . $near . '/depart', [], $this->nurseB);
        $geo = $this->payload($this->send('POST', '/homecare/' . $near . '/arrive', ['latitude' => 13.5139, 'longitude' => 2.1099, 'accuracy_m' => 15], $this->nurseB))['data']['geo'];
        $this->assertFalse($geo['arrival']['far']);
        $this->assertLessThan(50, $geo['arrival']['distance_m']);
    }

    public function testStaleTeamPositionOnTheMap(): void
    {
        $id = $this->visit($this->patient());
        $this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA);
        $this->send('POST', '/homecare/' . $id . '/depart', [
            'latitude' => 13.52, 'longitude' => 2.12, 'accuracy_m' => 250, 'at' => gmdate('Y-m-d\TH:i:s\Z', time() - 40 * 60),
        ], $this->nurseA);
        $map = $this->payload($this->send('GET', '/map/homecare', null, $this->admin))['data'];
        $entry = array_values(array_filter($map, function (array $item) use ($id): bool {
            return $item['id'] === $id;
        }))[0];
        $this->assertTrue($entry['team_position']['stale']);
        $this->assertTrue($entry['team_position']['imprecise']);
        $this->assertGreaterThan(1000, $entry['team_position']['distance_m']);
        $this->assertFalse($entry['imprecise']);
        $this->assertSame(8.0, $entry['accuracy_m']);
    }

    public function testTeamRecordsHomePositionOnSiteAndUpdatesThePatientFile(): void
    {
        $patient = $this->patient();
        $id = $this->visit($patient);
        $this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA);
        $position = ['latitude' => 13.5141, 'longitude' => 2.1102, 'accuracy_m' => 6, 'update_patient_address' => true];
        // Avant l'arrivée, l'équipe ne peut pas relever le domicile.
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/homecare/' . $id . '/home-location', $position, $this->nurseA))['code']);
        $this->send('POST', '/homecare/' . $id . '/depart', [], $this->nurseA);
        $this->send('POST', '/homecare/' . $id . '/arrive', [], $this->nurseA);
        $this->assertSame('NOT_TEAM_MEMBER', $this->payload($this->send('POST', '/homecare/' . $id . '/home-location', $position, $this->nurseB))['code']);

        $saved = $this->payload($this->send('POST', '/homecare/' . $id . '/home-location', $position, $this->nurseA))['data'];
        $this->assertSame(13.5141, $saved['location']['latitude']);
        $this->assertSame(6.0, $saved['location']['accuracy_m']);
        $file = $this->payload($this->send('GET', '/patients/' . $patient['id'], null, $this->admin))['data'];
        $this->assertCount(1, $file['addresses']);
        $this->assertSame(13.5141, (float) $file['addresses'][0]['latitude']);
        $this->assertTrue((bool) $file['addresses'][0]['is_primary']);

        // Second relevé : l'adresse existante est mise à jour, pas dupliquée.
        $this->send('POST', '/homecare/' . $id . '/home-location', ['latitude' => 13.5142, 'longitude' => 2.1103, 'update_patient_address' => true], $this->nurseA);
        $file = $this->payload($this->send('GET', '/patients/' . $patient['id'], null, $this->admin))['data'];
        $this->assertCount(1, $file['addresses']);
        $this->assertSame(13.5142, (float) $file['addresses'][0]['latitude']);
        $this->assertSame(2, (int) $this->db()->fetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'PATIENT_ADDRESS_GPS_UPDATED' AND entity_uuid = ?",
            [$file['addresses'][0]['id']]
        ));
    }

    public function testDispatchOptionsRankTheNearestTeamFirst(): void
    {
        $waiting = $this->visit($this->patient());
        $busy = $this->visit($this->patient(), ['latitude' => 13.60, 'longitude' => 2.20]);
        $this->send('POST', '/homecare/' . $busy . '/assign', ['team_id' => $this->teamB['id']], $this->reception);
        // L'équipe B est en route à ~100 m du domicile de la demande en attente.
        $this->send('POST', '/homecare/' . $busy . '/depart', ['latitude' => 13.5146, 'longitude' => 2.1098, 'accuracy_m' => 10], $this->nurseB);

        $options = $this->payload($this->send('GET', '/homecare/' . $waiting . '/dispatch-options', null, $this->reception))['data'];
        $this->assertTrue($options['home_located']);
        $ids = array_column($options['teams'], 'id');
        $this->assertLessThan(array_search($this->teamA['id'], $ids, true), array_search($this->teamB['id'], $ids, true));
        $teamB = $options['teams'][array_search($this->teamB['id'], $ids, true)];
        $this->assertSame(1, $teamB['active_visits']);
        $this->assertSame(1, $teamB['members']);
        $this->assertEqualsWithDelta(100, $teamB['position']['distance_m'], 5);
        $this->assertNull($options['teams'][array_search($this->teamA['id'], $ids, true)]['position']);

        $this->assertStatus(403, $this->send('GET', '/homecare/' . $waiting . '/dispatch-options', null, $this->nurseA));
    }

    public function testPurgeRemovesOldTracesOfClosedVisitsOnly(): void
    {
        $old = gmdate('Y-m-d\TH:i:s\Z', time() - 120 * 86400);
        $closed = $this->visit($this->patient());
        $open = $this->visit($this->patient());
        foreach ([[$closed, $this->nurseA], [$open, $this->nurseB]] as list($id, $token)) {
            $this->send('POST', '/homecare/' . $id . '/accept', [], $token);
            $this->send('POST', '/homecare/' . $id . '/depart', ['latitude' => 13.50, 'longitude' => 2.10], $token);
            $this->send('POST', '/homecare/' . $id . '/track', ['points' => [['latitude' => 13.505, 'longitude' => 2.105, 'captured_at' => $old]]], $token);
        }
        $this->send('POST', '/homecare/' . $closed . '/fail', ['reason' => 'Patient absent'], $this->nurseA);

        $this->container->get(HomecareService::class)->purgeTraces();

        $kinds = function (string $uuid): array {
            return array_column($this->db()->fetchAll(
                'SELECT l.kind FROM homecare_locations l JOIN homecare_interventions i ON i.id = l.intervention_id
                 JOIN homecare_requests r ON r.id = i.request_id WHERE r.uuid = ? ORDER BY l.id',
                [$uuid]
            ), 'kind');
        };
        $this->assertSame(['DEPART'], $kinds($closed));
        $this->assertSame(['DEPART', 'TRACE'], $kinds($open));
    }

    public function testPatientSeesTheTeamOnlyWhileItIsOnTheWay(): void
    {
        $patient = $this->patient();
        $account = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->db()->execute('UPDATE patients SET user_id = ? WHERE uuid = ?', [$account['id'], $patient['id']]);
        $patientToken = $this->login($account)['access_token'];
        $mine = function () use ($patient, $patientToken): array {
            return $this->payload($this->send('GET', '/me/patients/' . $patient['id'] . '/homecare', null, $patientToken))['data'][0];
        };

        $id = $this->visit($patient);
        $this->send('POST', '/homecare/' . $id . '/accept', [], $this->nurseA);
        $this->assertNull($mine()['team_approach'], 'Pas de position avant le départ');

        $this->send('POST', '/homecare/' . $id . '/depart', ['latitude' => 13.5000, 'longitude' => 2.1000, 'accuracy_m' => 10], $this->nurseA);
        $this->send('POST', '/homecare/' . $id . '/track', ['points' => [
            ['latitude' => 13.5100, 'longitude' => 2.1098, 'accuracy_m' => 12, 'captured_at' => gmdate('Y-m-d\TH:i:s\Z')],
        ]], $this->nurseA);
        $approach = $mine()['team_approach'];
        $this->assertSame(13.51, $approach['latitude']);
        $this->assertEqualsWithDelta(411, $approach['distance_m'], 5);
        $this->assertFalse($approach['stale']);
        $this->assertSame(self::HOME, $approach['home']);
        $this->assertArrayNotHasKey('segments', $approach, 'Jamais le trajet complet de l’équipe');

        $this->send('POST', '/homecare/' . $id . '/arrive', ['latitude' => 13.5137, 'longitude' => 2.1098, 'accuracy_m' => 10], $this->nurseA);
        $this->assertNull($mine()['team_approach'], 'Plus de position une fois arrivée');
    }

    private function visit(array $patient, array $overrides = []): string
    {
        $response = $this->send('POST', '/homecare', $this->body($patient, $overrides), $this->reception);
        $this->assertStatus(201, $response);
        return $this->payload($response)['data']['id'];
    }

    private function body(array $patient, array $overrides = []): array
    {
        return $overrides + self::HOME + [
            'patient_id' => $patient['id'],
            'reason' => 'Injection à domicile',
            'gps_accuracy_m' => 8,
            'landmark' => 'Portail vert, face à l\'école',
            'contact_phone' => '+22790001122',
        ];
    }

    private function patient(): array
    {
        $count = ++self::$patients;
        return $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Ramatou',
            'last_name' => 'Geo' . getmypid() . 'P' . $count,
            'sex' => 'F',
            'birth_date' => sprintf('19%02d-%02d-%02d', 40 + $count % 50, 1 + $count % 12, 1 + $count % 28),
        ], $this->admin))['data'];
    }

    private function team(string $code, array $member): array
    {
        $team = $this->payload($this->send('POST', '/teams', ['code' => $code, 'label' => 'Équipe ' . $code], $this->admin))['data'];
        $this->assertStatus(201, $this->send('POST', '/teams/' . $team['id'] . '/members', ['user_id' => $member['uuid'], 'team_role' => 'INFIRMIER'], $this->admin));
        return $team;
    }
}
