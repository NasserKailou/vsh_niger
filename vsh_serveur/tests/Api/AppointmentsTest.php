<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Core\Support\Uuid;

final class AppointmentsTest extends ApiTestCase
{
    /** @var string */
    private $admin;

    /** @var string */
    private $reception;

    /** @var array */
    private $doctorUser;

    /** @var string */
    private $doctor;

    /** @var array */
    private $service;

    /** @var string Date locale du jour de test (J+3, dans l'horizon de réservation) */
    private $day;

    /** @var array */
    private $patient;

    /** @var array */
    private $account;

    /** @var string */
    private $patientToken;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $n = ++self::$sequence;
        $this->admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $this->doctorUser = $this->createUser(['MEDECIN']);
        $this->doctor = $this->login($this->doctorUser)['access_token'];

        $local = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Niamey'));
        $this->day = $local->modify('+3 days')->format('Y-m-d');
        $this->service = $this->payload($this->send('POST', '/services', ['code' => 'RDV' . getmypid() . 'N' . $n, 'label' => 'Médecine générale'], $this->admin))['data'];
        $this->assertStatus(201, $this->send('POST', '/services/' . $this->service['id'] . '/schedules', [
            'weekday' => (int) $local->modify('+3 days')->format('N'), 'start_time' => '08:00', 'end_time' => '10:00', 'slot_minutes' => 30, 'capacity_per_slot' => 2,
        ], $this->admin));

        $this->patient = $this->newPatient('P');
        $this->account = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->db()->execute('UPDATE patients SET user_id = ? WHERE uuid = ?', [$this->account['id'], $this->patient['id']]);
        $this->patientToken = $this->login($this->account)['access_token'];
    }

    public function testPatientRequestConfirmationAndCapacity(): void
    {
        $slots = $this->payload($this->send('GET', '/appointments/slots?service_id=' . $this->service['id'] . '&date=' . $this->day, null, $this->patientToken))['data'];
        $this->assertSame(['08:00', '08:30', '09:00', '09:30'], array_column($slots['slots'], 'local_time'));
        $this->assertSame([2, 2, 2, 2], array_column($slots['slots'], 'available'));
        $eight = $slots['slots'][0]['start'];

        $requested = $this->send('POST', '/appointments', [
            'patient_id' => $this->patient['id'], 'service_id' => $this->service['id'], 'scheduled_start' => $eight, 'reason' => 'Toux persistante',
        ], $this->patientToken);
        $this->assertStatus(201, $requested);
        $appointment = $this->payload($requested)['data'];
        $this->assertSame('DEMANDE', $appointment['status']);
        $this->assertArrayNotHasKey('patient', $appointment);

        $offGrid = (new \DateTimeImmutable($eight))->modify('+10 minutes')->format('Y-m-d\TH:i:s\Z');
        $this->assertArrayHasKey('scheduled_start', $this->payload($this->send('POST', '/appointments', [
            'patient_id' => $this->patient['id'], 'service_id' => $this->service['id'], 'scheduled_start' => $offGrid,
        ], $this->patientToken))['errors']);
        $this->assertSame('PATIENT_BUSY', $this->payload($this->send('POST', '/appointments', [
            'patient_id' => $this->patient['id'], 'service_id' => $this->service['id'], 'scheduled_start' => $eight,
        ], $this->patientToken))['code']);
        $this->assertStatus(422, $this->send('POST', '/appointments', [
            'patient_id' => $this->patient['id'], 'service_id' => $this->service['id'], 'scheduled_start' => $eight, 'practitioner_id' => $this->doctorUser['uuid'],
        ], $this->patientToken));

        $confirmed = $this->payload($this->send('POST', '/appointments/' . $appointment['id'] . '/confirm', ['practitioner_id' => $this->doctorUser['uuid']], $this->reception))['data'];
        $this->assertSame('CONFIRME', $confirmed['status']);
        $this->assertSame($this->doctorUser['uuid'], $confirmed['practitioner']['id']);
        $body = (string) $this->db()->fetchValue("SELECT body FROM notifications WHERE user_id = ? AND notif_type = 'APPOINTMENT_STATUS' ORDER BY id DESC LIMIT 1", [$this->account['id']]);
        $this->assertStringContainsString('08h00', $body);
        $this->assertStringNotContainsString('Médecine', $body);

        // Planning du praticien ; une infirmière non concernée ne voit pas le rendez-vous.
        $this->assertContains($appointment['id'], array_column($this->payload($this->send('GET', '/appointments?date=' . $this->day, null, $this->doctor))['data'], 'id'));
        $nurse = $this->login($this->createUser(['INFIRMIER']))['access_token'];
        $this->assertNotContains($appointment['id'], array_column($this->payload($this->send('GET', '/appointments?date=' . $this->day, null, $nurse))['data'], 'id'));
        $this->assertStatus(404, $this->send('GET', '/appointments/' . $appointment['id'], null, $nurse));

        // Capacité : 2 par créneau.
        $this->assertStatus(201, $this->book($this->newPatient('C1'), $eight));
        $this->assertSame('SLOT_FULL', $this->payload($this->book($this->newPatient('C2'), $eight))['code']);
        $this->assertSame(0, $this->slots()[0]['available']);
    }

    public function testCancellationRulesAndReschedule(): void
    {
        $slots = $this->slots();
        $appointment = $this->payload($this->send('POST', '/appointments', [
            'patient_id' => $this->patient['id'], 'service_id' => $this->service['id'], 'scheduled_start' => $slots[1]['start'],
        ], $this->patientToken))['data'];

        $this->assertStatus(200, $this->send('PUT', '/settings', ['values' => ['appointments.patient_cancel_min_hours' => 200]], $this->admin));
        try {
            $this->assertSame('CANCEL_TOO_LATE', $this->payload($this->send('POST', '/appointments/' . $appointment['id'] . '/cancel', [], $this->patientToken))['code']);
        } finally {
            $this->send('PUT', '/settings', ['values' => ['appointments.patient_cancel_min_hours' => 24]], $this->admin);
        }

        $moved = $this->payload($this->send('POST', '/appointments/' . $appointment['id'] . '/reschedule', ['scheduled_start' => $slots[3]['start']], $this->reception))['data'];
        $this->assertSame('DEPLACE', $moved['status']);
        $this->assertSame($slots[1]['start'], $moved['rescheduled_from']);
        $this->assertSame($slots[3]['start'], $moved['scheduled_start']);
        $this->assertSame('NOT_TODAY', $this->payload($this->send('POST', '/appointments/' . $appointment['id'] . '/check-in', null, $this->reception))['code']);
        $this->assertSame('NOT_YET', $this->payload($this->send('POST', '/appointments/' . $appointment['id'] . '/no-show', null, $this->reception))['code']);

        $this->assertArrayHasKey('reason', $this->payload($this->send('POST', '/appointments/' . $appointment['id'] . '/cancel', [], $this->reception))['errors']);
        $this->assertSame('ANNULE', $this->payload($this->send('POST', '/appointments/' . $appointment['id'] . '/cancel', [], $this->patientToken))['data']['status']);
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/appointments/' . $appointment['id'] . '/confirm', [], $this->reception))['code']);

        $other = $this->payload($this->book($this->newPatient('X'), $slots[0]['start']))['data'];
        $this->assertSame('CONFIRME', $other['status']);
        $this->assertStatus(403, $this->send('POST', '/appointments/' . $other['id'] . '/cancel', ['reason' => 'x'], $this->doctor));
        $this->assertStatus(404, $this->send('POST', '/appointments/' . $other['id'] . '/cancel', [], $this->patientToken));
        $this->assertSame('ANNULE', $this->payload($this->send('POST', '/appointments/' . $other['id'] . '/cancel', ['reason' => 'Médecin absent'], $this->reception))['data']['status']);
    }

    public function testDayOfAppointmentConsultationAndNoShow(): void
    {
        $slots = $this->slots();
        $honoured = $this->payload($this->book($this->patient, $slots[0]['start'], $this->doctorUser['uuid']))['data'];
        $missed = $this->payload($this->book($this->newPatient('M'), $slots[1]['start']))['data'];
        // Ramène les deux rendez-vous à « tout à l'heure », comme le jour J.
        $this->db()->execute(
            'UPDATE appointments SET scheduled_start = ?, scheduled_end = ? WHERE uuid IN (?, ?)',
            [gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() + 1740), $honoured['id'], $missed['id']]
        );

        $checked = $this->payload($this->send('POST', '/appointments/' . $honoured['id'] . '/check-in', null, $this->reception))['data'];
        $this->assertNotNull($checked['checked_in_at']);
        $this->assertSame('CHECKED_IN', $this->payload($this->send('POST', '/appointments/' . $honoured['id'] . '/no-show', null, $this->reception))['code']);

        $consultation = $this->send('POST', '/consultations', [
            'patient_id' => $this->patient['id'], 'consultation_type' => 'CLINIQUE', 'appointment_id' => $honoured['id'],
        ], $this->doctor);
        $this->assertStatus(201, $consultation);
        $afterConsultation = $this->payload($this->send('GET', '/appointments/' . $honoured['id'], null, $this->reception))['data'];
        $this->assertSame('HONORE', $afterConsultation['status']);
        $this->assertSame($this->payload($consultation)['data']['id'], $afterConsultation['consultation_id']);
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/consultations', [
            'patient_id' => $this->patient['id'], 'consultation_type' => 'CLINIQUE', 'appointment_id' => $honoured['id'],
        ], $this->doctor))['code']);

        $this->assertSame('ABSENT', $this->payload($this->send('POST', '/appointments/' . $missed['id'] . '/no-show', null, $this->reception))['data']['status']);
        $this->assertSame(['HONORE'], array_column($this->payload($this->send('GET', '/me/patients/' . $this->patient['id'] . '/appointments', null, $this->patientToken))['data'], 'status'));
    }

    public function testOfflineRequestAndPractitionerPlanningSync(): void
    {
        $slots = $this->slots();
        $token = $this->login($this->account, $this->device())['access_token'];
        $id = Uuid::v4();
        $response = $this->send('POST', '/sync/push', ['operations' => [[
            'op_id' => Uuid::v4(), 'entity' => 'appointment', 'entity_id' => $id, 'operation' => 'CREATE',
            'payload' => ['patient_id' => $this->patient['id'], 'service_id' => $this->service['id'], 'scheduled_start' => $slots[2]['start']],
            'client_created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]]], $token);
        $result = $this->payload($response)['data']['results'][0];
        $this->assertSame('APPLIED', $result['status']);
        $this->assertSame('DEMANDE', $result['data']['status']);

        $this->send('POST', '/appointments/' . $id . '/confirm', ['practitioner_id' => $this->doctorUser['uuid']], $this->reception);
        $doctorToken = $this->login($this->doctorUser, $this->device())['access_token'];
        $cursor = 0;
        $found = null;
        do {
            $page = $this->payload($this->send('GET', '/sync/pull?cursor=' . $cursor . '&limit=500', null, $doctorToken))['data'];
            foreach ($page['changes'] as $change) {
                if ($change['entity'] === 'appointment' && $change['id'] === $id) {
                    $found = $change;
                }
            }
            $cursor = $page['next_cursor'];
        } while ($page['has_more']);
        $this->assertNotNull($found);
        $this->assertSame('CONFIRME', $found['data']['status']);
    }

    private function slots(): array
    {
        return $this->payload($this->send('GET', '/appointments/slots?service_id=' . $this->service['id'] . '&date=' . $this->day, null, $this->reception))['data']['slots'];
    }

    private function book(array $patient, string $start, ?string $practitionerId = null)
    {
        return $this->send('POST', '/appointments', array_filter([
            'patient_id' => $patient['id'], 'service_id' => $this->service['id'], 'scheduled_start' => $start, 'practitioner_id' => $practitionerId,
        ]), $this->reception);
    }

    private function newPatient(string $tag): array
    {
        $n = self::$sequence;
        return $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Fati', 'last_name' => 'Rdv' . getmypid() . $tag . 'N' . $n, 'sex' => 'F',
            'birth_date' => sprintf('19%02d-0%d-1%d', 50 + (($n + strlen($tag)) % 49), 1 + $n % 9, strlen($tag) % 10),
        ], $this->admin))['data'];
    }
}
