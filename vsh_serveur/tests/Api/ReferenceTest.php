<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Core\Support\Clock;
use Vsh\Modules\Reference\TariffService;

final class ReferenceTest extends ApiTestCase
{
    /** @var string */
    private $adminToken;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminToken = $this->login($this->createUser(['ADMIN']))['access_token'];
    }

    public function testServiceWithSchedules(): void
    {
        $code = $this->code('CONS');
        $created = $this->send('POST', '/services', ['code' => $code, 'label' => 'Consultation générale', 'accepts_appointments' => true], $this->adminToken);
        $this->assertStatus(201, $created);
        $serviceId = $this->payload($created)['data']['id'];

        $schedule = $this->send('POST', '/services/' . $serviceId . '/schedules', [
            'weekday' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'slot_minutes' => 20,
        ], $this->adminToken);
        $this->assertStatus(201, $schedule);
        $this->assertSame('08:00', $this->payload($schedule)['data']['start_time']);
        $this->assertSame(1, $this->payload($schedule)['data']['weekday']);

        $invalid = $this->send('POST', '/services/' . $serviceId . '/schedules', [
            'weekday' => 2, 'start_time' => '14:00', 'end_time' => '10:00', 'slot_minutes' => 20,
        ], $this->adminToken);
        $this->assertStatus(422, $invalid);
        $this->assertArrayHasKey('end_time', $this->payload($invalid)['errors']);

        $service = $this->payload($this->send('GET', '/services/' . $serviceId, null, $this->adminToken))['data'];
        $this->assertCount(1, $service['schedules']);
        $this->assertTrue($service['accepts_appointments']);

        $duplicate = $this->send('POST', '/services', ['code' => $code, 'label' => 'Autre'], $this->adminToken);
        $this->assertStatus(422, $duplicate);
        $this->assertArrayHasKey('code', $this->payload($duplicate)['errors']);
    }

    public function testReadIsAllowedButModificationRequiresPermission(): void
    {
        $technician = $this->login($this->createUser(['TECHNICIEN']))['access_token'];

        $this->assertStatus(200, $this->send('GET', '/medications', null, $technician));
        $this->assertStatus(403, $this->send('POST', '/medications', ['dci' => 'Paracétamol'], $technician));
    }

    public function testExaminationParametersAreValidated(): void
    {
        $exam = $this->payload($this->send('POST', '/examination-types', [
            'code' => $this->code('TDR'), 'label' => 'Test de diagnostic rapide du paludisme', 'category' => 'TEST_RAPIDE',
        ], $this->adminToken))['data'];
        $base = '/examination-types/' . $exam['id'] . '/parameters';

        $noChoices = $this->send('POST', $base, ['code' => 'RESULTAT', 'label' => 'Résultat', 'value_type' => 'CHOICE'], $this->adminToken);
        $this->assertStatus(422, $noChoices);
        $this->assertArrayHasKey('choices', $this->payload($noChoices)['errors']);

        $choice = $this->send('POST', $base, [
            'code' => 'RESULTAT', 'label' => 'Résultat', 'value_type' => 'CHOICE', 'choices' => ['Positif', 'Négatif'],
        ], $this->adminToken);
        $this->assertStatus(201, $choice);
        $this->assertSame(['Positif', 'Négatif'], $this->payload($choice)['data']['choices']);

        $range = $this->send('POST', $base, [
            'code' => 'TAUX', 'label' => 'Taux', 'value_type' => 'NUMERIC', 'ref_min' => 10, 'ref_max' => 5,
        ], $this->adminToken);
        $this->assertStatus(422, $range);
    }

    public function testMedicationSearchAndDeactivation(): void
    {
        $name = 'Amoxicilline' . $this->code('');
        $created = $this->payload($this->send('POST', '/medications', ['dci' => $name, 'form' => 'Gélule', 'strength' => '500 mg'], $this->adminToken))['data'];

        $found = $this->payload($this->send('GET', '/medications?search=' . urlencode($name), null, $this->adminToken));
        $this->assertSame(1, $found['meta']['total']);

        $this->assertStatus(200, $this->send('PUT', '/medications/' . $created['id'], ['active' => false], $this->adminToken));
        $active = $this->payload($this->send('GET', '/medications?active=1&search=' . urlencode($name), null, $this->adminToken));
        $this->assertSame(0, $active['meta']['total']);
    }

    public function testTariffLifecycle(): void
    {
        $act = $this->payload($this->send('POST', '/medical-acts', ['code' => $this->code('ACT'), 'label' => 'Consultation'], $this->adminToken))['data'];
        $today = $this->container->get(TariffService::class)->today();
        $in10 = (new \DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d');

        $first = $this->send('POST', '/tariffs', ['billable_type' => 'MEDICAL_ACT', 'billable_id' => $act['id'], 'amount' => 5000, 'valid_from' => $today], $this->adminToken);
        $this->assertStatus(201, $first);
        $firstTariff = $this->payload($first)['data'];
        $this->assertSame('CURRENT', $firstTariff['status']);
        $this->assertSame('Consultation', $firstTariff['billable_label']);

        $current = $this->payload($this->send('GET', '/tariffs/current?billable_type=MEDICAL_ACT&billable_id=' . $act['id'], null, $this->adminToken))['data'];
        $this->assertSame(5000, $current['amount']);

        // Un nouveau tarif clôture automatiquement le tarif sans date de fin la veille de son entrée en vigueur.
        $future = $this->send('POST', '/tariffs', ['billable_type' => 'MEDICAL_ACT', 'billable_id' => $act['id'], 'amount' => 6000, 'valid_from' => $in10], $this->adminToken);
        $this->assertStatus(201, $future);
        $this->assertSame('FUTURE', $this->payload($future)['data']['status']);
        $list = $this->payload($this->send('GET', '/tariffs?billable_type=MEDICAL_ACT&billable_id=' . $act['id'], null, $this->adminToken))['data'];
        $closed = array_values(array_filter($list, function (array $tariff) use ($firstTariff): bool {
            return $tariff['id'] === $firstTariff['id'];
        }))[0];
        $this->assertSame((new \DateTimeImmutable($in10))->modify('-1 day')->format('Y-m-d'), $closed['valid_to']);

        $inEffect = $this->send('PUT', '/tariffs/' . $firstTariff['id'], ['amount' => 1], $this->adminToken);
        $this->assertStatus(409, $inEffect);
        $this->assertSame('TARIFF_IN_EFFECT', $this->payload($inEffect)['code']);

        $overlap = $this->send('POST', '/tariffs', [
            'billable_type' => 'MEDICAL_ACT', 'billable_id' => $act['id'], 'amount' => 7000,
            'valid_from' => (new \DateTimeImmutable($today))->modify('+5 days')->format('Y-m-d'),
            'valid_to' => (new \DateTimeImmutable($today))->modify('+20 days')->format('Y-m-d'),
        ], $this->adminToken);
        $this->assertStatus(409, $overlap);
        $this->assertSame('TARIFF_OVERLAP', $this->payload($overlap)['code']);

        $later = $this->payload($this->send('GET', '/tariffs/current?billable_type=MEDICAL_ACT&billable_id=' . $act['id'] . '&date=' . (new \DateTimeImmutable($today))->modify('+15 days')->format('Y-m-d'), null, $this->adminToken))['data'];
        $this->assertSame(6000, $later['amount']);

        $past = $this->send('POST', '/tariffs', [
            'billable_type' => 'MEDICAL_ACT', 'billable_id' => $act['id'], 'amount' => 1,
            'valid_from' => (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'),
        ], $this->adminToken);
        $this->assertStatus(422, $past);
    }

    public function testTariffManagementRequiresPermission(): void
    {
        $reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $act = $this->payload($this->send('POST', '/medical-acts', ['code' => $this->code('ACT'), 'label' => 'Pansement'], $this->adminToken))['data'];

        $response = $this->send('POST', '/tariffs', [
            'billable_type' => 'MEDICAL_ACT', 'billable_id' => $act['id'], 'amount' => 1000,
            'valid_from' => $this->container->get(TariffService::class)->today(),
        ], $reception);

        $this->assertStatus(403, $response);
    }

    public function testBundleIsIncrementalAndRestrictedForPatients(): void
    {
        $full = $this->payload($this->send('GET', '/reference/bundle', null, $this->adminToken))['data'];
        $this->assertTrue($full['full']);
        $this->assertArrayHasKey('tariffs', $full);
        $this->assertArrayHasKey('homecare.dispatch_mode', $full['settings']);
        $this->assertArrayNotHasKey('patients.file_number_prefix', $full['settings'], 'Paramètre non public');

        Clock::setTestNow(Clock::now()->modify('+2 minutes'));
        $since = Clock::now()->modify('-30 seconds')->format('Y-m-d\TH:i:s\Z');
        $name = 'Artésunate' . $this->code('');
        $this->send('POST', '/medications', ['dci' => $name], $this->adminToken);

        $delta = $this->payload($this->send('GET', '/reference/bundle?since=' . urlencode($since), null, $this->adminToken))['data'];
        $this->assertFalse($delta['full']);
        $this->assertSame([$name], array_column($delta['medications'], 'dci'));
        $this->assertSame([], $delta['services']);

        $patient = $this->login($this->createUser(['PATIENT'], ['account_type' => 'PATIENT']))['access_token'];
        $patientBundle = $this->payload($this->send('GET', '/reference/bundle', null, $patient))['data'];
        $this->assertSame(['generated_at', 'full', 'settings', 'services'], array_keys($patientBundle));
    }

    public function testSettingsAreTypedValidatedAndRestricted(): void
    {
        $settings = $this->payload($this->send('GET', '/settings', null, $this->adminToken))['data'];
        $this->assertContains('homecare.dispatch_mode', array_column($settings, 'key'));

        $invalid = $this->send('PUT', '/settings', ['values' => ['homecare.dispatch_mode' => 'N_IMPORTE_QUOI', 'appointments.max_days_ahead' => 'trente']], $this->adminToken);
        $this->assertStatus(422, $invalid);
        $this->assertArrayHasKey('homecare.dispatch_mode', $this->payload($invalid)['errors']);
        $this->assertArrayHasKey('appointments.max_days_ahead', $this->payload($invalid)['errors']);

        try {
            $updated = $this->send('PUT', '/settings', ['values' => ['homecare.dispatch_mode' => 'SELF_ASSIGN']], $this->adminToken);
            $this->assertStatus(200, $updated);
            $bundle = $this->payload($this->send('GET', '/reference/bundle', null, $this->adminToken))['data'];
            $this->assertSame('SELF_ASSIGN', $bundle['settings']['homecare.dispatch_mode']);
        } finally {
            $this->send('PUT', '/settings', ['values' => ['homecare.dispatch_mode' => 'BOTH']], $this->adminToken);
        }

        $doctor = $this->login($this->createUser(['MEDECIN']))['access_token'];
        $this->assertStatus(403, $this->send('GET', '/settings', null, $doctor));
    }

    private function code(string $prefix): string
    {
        return $prefix . 'T' . getmypid() % 1000 . 'X' . (++self::$sequence);
    }
}
