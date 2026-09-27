<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

final class BillingTest extends ApiTestCase
{
    /** @var string */
    private $admin;

    /** @var string */
    private $reception;

    /** @var string */
    private $doctor;

    /** @var string */
    private $nurse;

    /** @var array */
    private $nurseUser;

    /** @var array */
    private $patient;

    /** @var array */
    private $treatmentType;

    /** @var array */
    private $examType;

    /** @var array */
    private $act;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $n = ++self::$sequence;
        $suffix = getmypid() . 'B' . $n;
        $this->admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $this->doctor = $this->login($this->createUser(['MEDECIN']))['access_token'];
        $this->nurseUser = $this->createUser(['INFIRMIER']);
        $this->nurse = $this->login($this->nurseUser)['access_token'];
        $this->patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Aminatou', 'last_name' => 'Facture' . $suffix, 'sex' => 'F',
            'birth_date' => sprintf('19%02d-0%d-1%d', 50 + $n % 49, 1 + $n % 9, $n % 10),
        ], $this->admin))['data'];
        // Référentiel et tarifs de test : les vrais sont saisis par la clinique.
        $this->treatmentType = $this->payload($this->send('POST', '/treatment-types', ['code' => 'INJ' . $suffix, 'label' => 'Injection'], $this->admin))['data'];
        $this->examType = $this->payload($this->send('POST', '/examination-types', ['code' => 'GE' . $suffix, 'label' => 'Goutte épaisse'], $this->admin))['data'];
        $this->act = $this->payload($this->send('POST', '/medical-acts', ['code' => 'CS' . $suffix, 'label' => 'Consultation générale'], $this->admin))['data'];
        $this->tariff('TREATMENT_TYPE', $this->treatmentType['id'], 2500);
        $this->tariff('EXAMINATION_TYPE', $this->examType['id'], 5000);
        $this->tariff('MEDICAL_ACT', $this->act['id'], 10000);
    }

    public function testDraftFromConsultationIssueAndDeclaredSettlement(): void
    {
        $account = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->db()->execute('UPDATE patients SET user_id = ? WHERE uuid = ?', [$account['id'], $this->patient['id']]);
        $patientToken = $this->login($account)['access_token'];

        $consultationId = $this->payload($this->send('POST', '/consultations', ['patient_id' => $this->patient['id'], 'consultation_type' => 'CLINIQUE'], $this->doctor))['data']['id'];
        $this->assertStatus(201, $this->send('POST', '/treatments', ['patient_id' => $this->patient['id'], 'treatment_type_id' => $this->treatmentType['id'], 'consultation_id' => $consultationId], $this->nurse));
        $untariffed = $this->payload($this->send('POST', '/treatment-types', ['code' => 'SANS' . getmypid() . 'T' . self::$sequence, 'label' => 'Soin sans tarif'], $this->admin))['data'];
        $this->send('POST', '/treatments', ['patient_id' => $this->patient['id'], 'treatment_type_id' => $untariffed['id'], 'consultation_id' => $consultationId], $this->nurse);
        $this->performedExamination($consultationId);

        $created = $this->send('POST', '/invoices', [
            'patient_id' => $this->patient['id'], 'consultation_id' => $consultationId,
            'items' => [['item_type' => 'MEDICAL_ACT', 'reference_id' => $this->act['id']]],
        ], $this->reception);
        $this->assertStatus(201, $created);
        $invoice = $this->payload($created)['data'];
        $this->assertSame('BROUILLON', $invoice['status']);
        $this->assertNull($invoice['number']);
        $this->assertSame(['TREATMENT', 'EXAMINATION', 'MEDICAL_ACT'], array_column($invoice['items'], 'item_type'));
        $this->assertSame(17500, $invoice['total_amount']);
        $this->assertSame(['Soin sans tarif'], array_column($invoice['missing_tariffs'], 'description'));

        // Un soin ou un examen n'est facturé qu'une fois.
        $second = $this->payload($this->send('POST', '/invoices', ['patient_id' => $this->patient['id'], 'consultation_id' => $consultationId], $this->reception))['data'];
        $this->assertSame([], $second['items']);

        $base = '/invoices/' . $invoice['id'];
        $this->assertArrayHasKey('discount_amount', $this->payload($this->send('PUT', $base, ['discount_amount' => 20000], $this->reception))['errors']);
        $this->assertSame(16000, $this->payload($this->send('PUT', $base, ['discount_amount' => 1500], $this->reception))['data']['net_amount']);
        $this->assertArrayHasKey('items.0.unit_price', $this->payload($this->send('POST', $base . '/items', ['item_type' => 'MEDICAL_ACT', 'reference_id' => $this->act['id'], 'unit_price' => 1], $this->reception))['errors']);
        $withOther = $this->payload($this->send('POST', $base . '/items', ['item_type' => 'OTHER', 'description' => 'Carnet de santé', 'unit_price' => 500, 'quantity' => 2], $this->reception))['data'];
        $this->assertSame(18500, $withOther['total_amount']);
        $otherId = $withOther['items'][3]['id'];
        $this->assertSame(16000, $this->payload($this->send('DELETE', $base . '/items/' . $otherId, null, $this->reception))['data']['net_amount']);

        $this->assertSame([], $this->payload($this->send('GET', '/me/patients/' . $this->patient['id'] . '/invoices', null, $patientToken))['data']);
        $issued = $this->payload($this->send('POST', $base . '/issue', null, $this->reception))['data'];
        $this->assertSame('EMISE', $issued['status']);
        $this->assertMatchesRegularExpression('/^FAC-\d{4}-\d{6}$/', (string) $issued['number']);
        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('PUT', $base, ['notes' => 'x'], $this->reception))['code']);
        $notification = $this->db()->fetchOne("SELECT title, body FROM notifications WHERE user_id = ? AND notif_type = 'INVOICE_ISSUED'", [$account['id']]);
        $this->assertSame('Nouvelle facture', $notification['title']);
        $this->assertStringNotContainsString('16', (string) $notification['body']);

        // État de règlement déclaratif (D-004).
        $this->assertArrayHasKey('declared_paid_amount', $this->payload($this->send('POST', $base . '/settlement', ['settlement_status' => 'PARTIELLEMENT_REGLEE', 'declared_paid_amount' => 16000], $this->reception))['errors']);
        $partial = $this->payload($this->send('POST', $base . '/settlement', ['settlement_status' => 'PARTIELLEMENT_REGLEE', 'declared_paid_amount' => 6000, 'comment' => 'Versement à la caisse'], $this->reception))['data'];
        $this->assertSame(10000, $partial['outstanding_amount']);
        $paid = $this->payload($this->send('POST', $base . '/settlement', ['settlement_status' => 'REGLEE'], $this->reception))['data'];
        $this->assertSame(16000, $paid['declared_paid_amount']);
        $this->assertSame(0, $paid['outstanding_amount']);

        $mine = $this->payload($this->send('GET', '/me/patients/' . $this->patient['id'] . '/invoices', null, $patientToken))['data'];
        $this->assertSame([$issued['number']], array_column($mine, 'number'));
        $this->assertCount(3, $mine[0]['items']);
        $this->assertArrayNotHasKey('history', $mine[0]);

        $this->assertSame('SETTLEMENT_DECLARED', $this->payload($this->send('POST', $base . '/cancel', ['reason' => 'Erreur'], $this->reception))['code']);
        $this->send('POST', $base . '/settlement', ['settlement_status' => 'NON_REGLEE'], $this->reception);
        $cancelled = $this->payload($this->send('POST', $base . '/cancel', ['reason' => 'Erreur de patient'], $this->reception))['data'];
        $this->assertSame('ANNULEE', $cancelled['status']);
        $this->assertSame(
            [['STATUS', 'EMISE'], ['SETTLEMENT', 'PARTIELLEMENT_REGLEE'], ['SETTLEMENT', 'REGLEE'], ['SETTLEMENT', 'NON_REGLEE'], ['STATUS', 'ANNULEE']],
            array_map(function (array $entry): array {
                return [$entry['field'], $entry['to']];
            }, $cancelled['history'])
        );

        // Après annulation, les éléments redeviennent facturables.
        $again = $this->payload($this->send('POST', '/invoices', ['patient_id' => $this->patient['id'], 'consultation_id' => $consultationId], $this->reception))['data'];
        $this->assertCount(2, $again['items']);
    }

    public function testHomecareVisitBecomesInvoiced(): void
    {
        $team = $this->payload($this->send('POST', '/teams', ['code' => 'FB' . getmypid() . 'N' . self::$sequence, 'label' => 'Équipe facturation'], $this->admin))['data'];
        $this->send('POST', '/teams/' . $team['id'] . '/members', ['user_id' => $this->nurseUser['uuid'], 'team_role' => 'INFIRMIER'], $this->admin);
        $visit = $this->payload($this->send('POST', '/homecare', [
            'patient_id' => $this->patient['id'], 'reason' => 'Injection à domicile', 'landmark' => 'Près du marché', 'contact_phone' => '+22790001133',
        ], $this->reception))['data']['id'];
        foreach (['accept', 'depart', 'arrive'] as $action) {
            $this->send('POST', '/homecare/' . $visit . '/' . $action, [], $this->nurse);
        }
        $consultationId = $this->payload($this->send('POST', '/homecare/' . $visit . '/start', [], $this->nurse))['data']['consultation_id'];
        $this->send('POST', '/treatments', ['patient_id' => $this->patient['id'], 'treatment_type_id' => $this->treatmentType['id'], 'consultation_id' => $consultationId], $this->nurse);

        $this->assertSame('INVALID_TRANSITION', $this->payload($this->send('POST', '/invoices', ['patient_id' => $this->patient['id'], 'homecare_request_id' => $visit], $this->reception))['code']);
        $this->send('POST', '/homecare/' . $visit . '/complete', [], $this->nurse);

        $invoice = $this->payload($this->send('POST', '/invoices', [
            'patient_id' => $this->patient['id'], 'homecare_request_id' => $visit,
            'items' => [['item_type' => 'MEDICAL_ACT', 'reference_id' => $this->act['id']]],
        ], $this->reception))['data'];
        $this->assertSame(12500, $invoice['total_amount']);
        $this->send('POST', '/invoices/' . $invoice['id'] . '/issue', null, $this->reception);
        $this->assertSame('FACTUREE', $this->payload($this->send('GET', '/homecare/' . $visit, null, $this->reception))['data']['status']);

        $this->send('POST', '/invoices/' . $invoice['id'] . '/cancel', ['reason' => 'Refaire avec remise'], $this->reception);
        $this->assertSame('TERMINEE', $this->payload($this->send('GET', '/homecare/' . $visit, null, $this->reception))['data']['status']);
    }

    public function testRightsAndSummary(): void
    {
        $this->assertStatus(403, $this->send('GET', '/invoices', null, $this->nurse));
        $this->assertStatus(403, $this->send('POST', '/invoices', ['patient_id' => $this->patient['id']], $this->doctor));
        $invoice = $this->payload($this->send('POST', '/invoices', [
            'patient_id' => $this->patient['id'], 'items' => [['item_type' => 'OTHER', 'description' => 'Certificat médical', 'unit_price' => 3000]],
        ], $this->reception))['data'];
        $empty = $this->payload($this->send('POST', '/invoices', ['patient_id' => $this->patient['id']], $this->reception))['data'];
        $this->assertArrayHasKey('items', $this->payload($this->send('POST', '/invoices/' . $empty['id'] . '/issue', null, $this->reception))['errors']);

        $before = $this->payload($this->send('GET', '/invoices/summary', null, $this->admin))['data'];
        $this->send('POST', '/invoices/' . $invoice['id'] . '/issue', null, $this->reception);
        $after = $this->payload($this->send('GET', '/invoices/summary', null, $this->admin))['data'];
        $this->assertSame($before['issued_count'] + 1, $after['issued_count']);
        $this->assertSame($before['outstanding_amount'] + 3000, $after['outstanding_amount']);
        $this->assertSame('XOF', $after['currency']);

        $other = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->assertStatus(404, $this->send('GET', '/me/patients/' . $this->patient['id'] . '/invoices', null, $this->login($other)['access_token']));
    }

    private function performedExamination(string $consultationId): void
    {
        $technician = $this->login($this->createUser(['TECHNICIEN']))['access_token'];
        $exam = $this->payload($this->send('POST', '/examinations', [
            'patient_id' => $this->patient['id'], 'consultation_id' => $consultationId, 'examination_type_id' => $this->examType['id'],
        ], $this->doctor))['data'];
        $this->send('POST', '/examinations/' . $exam['id'] . '/results', ['results' => [['label' => 'Plasmodium', 'value_text' => 'Absent']]], $technician);
        $this->assertStatus(200, $this->send('POST', '/examinations/' . $exam['id'] . '/complete', null, $technician));
    }

    private function tariff(string $type, string $id, int $amount): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Niamey')))->format('Y-m-d');
        $this->assertStatus(201, $this->send('POST', '/tariffs', [
            'billable_type' => $type, 'billable_id' => $id, 'amount' => $amount, 'valid_from' => $today,
        ], $this->admin));
    }
}
