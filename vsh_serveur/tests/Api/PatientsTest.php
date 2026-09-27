<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Modules\Users\UserRepository;

final class PatientsTest extends ApiTestCase
{
    /** @var string */
    private $reception;

    /** @var string */
    private $doctor;

    /** @var string */
    private $nurse;

    /** @var string */
    private $admin;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $this->doctor = $this->login($this->createUser(['MEDECIN']))['access_token'];
        $this->nurse = $this->login($this->createUser(['INFIRMIER']))['access_token'];
        $this->admin = $this->login($this->createUser(['ADMIN']))['access_token'];
    }

    public function testReceptionCreatesAValidatedAndNumberedRecord(): void
    {
        $response = $this->send('POST', '/patients', $this->identity() + [
            'phone' => '90 11 22 33',
            'contacts' => [['full_name' => 'Moussa Garba', 'relationship' => 'Frère', 'phone' => '96 00 00 01', 'is_emergency' => true]],
            'addresses' => [['city' => 'Niamey', 'district' => 'Plateau', 'landmark' => 'Près du marché', 'latitude' => 13.5116, 'longitude' => 2.1254, 'is_primary' => true]],
        ], $this->reception);

        $this->assertStatus(201, $response);
        $patient = $this->payload($response)['data'];
        $this->assertMatchesRegularExpression('/^VSH-\d{4}-\d{6}$/', $patient['file_number']);
        $this->assertSame('ACTIVE', $patient['status']);
        $this->assertSame('+22790112233', $patient['phone']);
        $this->assertCount(1, $patient['contacts']);
        $this->assertSame(13.5116, $patient['addresses'][0]['latitude']);
        $this->assertArrayNotHasKey('medical', $patient, 'L\'accueil n\'a pas accès aux données médicales');
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'PATIENT_CREATED' AND entity_uuid = ?", [$patient['id']]));
    }

    public function testFileNumbersAreUniqueAndSequential(): void
    {
        $first = $this->createPatient()['file_number'];
        $second = $this->createPatient()['file_number'];

        $this->assertSame((int) substr($first, -6) + 1, (int) substr($second, -6));
    }

    public function testPossibleDuplicateMustBeConfirmed(): void
    {
        $identity = $this->identity();
        $existing = $this->createPatient($identity);

        $duplicate = $this->send('POST', '/patients', $identity, $this->reception);
        $this->assertStatus(409, $duplicate);
        $this->assertSame('POSSIBLE_DUPLICATE', $this->payload($duplicate)['code']);
        $this->assertSame($existing['id'], $this->payload($duplicate)['errors']['duplicates'][0]['id']);

        $this->assertStatus(201, $this->send('POST', '/patients', $identity + ['confirm_not_duplicate' => true], $this->reception));
    }

    public function testSearchUsesPostAndRequiresACriterion(): void
    {
        $patient = $this->createPatient();

        $byName = $this->payload($this->send('POST', '/patients/search', ['q' => substr($patient['last_name'], 0, 6) . ' ' . $patient['first_name']], $this->reception));
        $this->assertContains($patient['id'], array_column($byName['data'], 'id'));

        $byNumber = $this->payload($this->send('POST', '/patients/search', ['file_number' => strtolower($patient['file_number'])], $this->reception));
        $this->assertSame([$patient['id']], array_column($byNumber['data'], 'id'));

        $this->assertStatus(422, $this->send('POST', '/patients/search', [], $this->reception));
    }

    public function testStaleVersionIsRejected(): void
    {
        $patient = $this->createPatient();

        $this->assertStatus(200, $this->send('PUT', '/patients/' . $patient['id'], ['first_name' => 'Hadiza', 'version' => $patient['version']], $this->reception));
        $stale = $this->send('PUT', '/patients/' . $patient['id'], ['first_name' => 'Rakia', 'version' => $patient['version']], $this->reception);

        $this->assertStatus(409, $stale);
        $this->assertSame('VERSION_CONFLICT', $this->payload($stale)['code']);
    }

    public function testMedicalDataIsRestrictedAndAudited(): void
    {
        $patient = $this->createPatient();
        $base = '/patients/' . $patient['id'] . '/allergies';

        $created = $this->send('POST', $base, ['allergen' => 'Pénicilline', 'severity' => 'SEVERE'], $this->doctor);
        $this->assertStatus(201, $created);

        $this->assertSame(['Pénicilline'], array_column($this->payload($this->send('GET', $base, null, $this->nurse))['data'], 'allergen'));
        $this->assertStatus(403, $this->send('GET', $base, null, $this->reception));
        $this->assertStatus(403, $this->send('POST', $base, ['allergen' => 'Arachide'], $this->reception));

        $asDoctor = $this->payload($this->send('GET', '/patients/' . $patient['id'], null, $this->doctor))['data'];
        $this->assertSame('SEVERE', $asDoctor['medical']['allergies'][0]['severity']);
        $asReception = $this->payload($this->send('GET', '/patients/' . $patient['id'], null, $this->reception))['data'];
        $this->assertArrayNotHasKey('medical', $asReception);

        $views = (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'MEDICAL_RECORD_VIEWED' AND entity_uuid = ?", [$patient['id']]);
        $this->assertGreaterThanOrEqual(2, $views);
    }

    public function testAllergyCanReferenceAMedication(): void
    {
        $patient = $this->createPatient();
        $medication = $this->payload($this->send('POST', '/medications', ['dci' => 'Amoxicilline'], $this->admin))['data'];

        $allergy = $this->payload($this->send('POST', '/patients/' . $patient['id'] . '/allergies', [
            'allergen' => 'Amoxicilline', 'medication_id' => $medication['id'],
        ], $this->doctor))['data'];
        $this->assertSame($medication['id'], $allergy['medication_id']);
        $this->assertSame('INCONNUE', $allergy['severity']);

        $unknown = $this->send('POST', '/patients/' . $patient['id'] . '/allergies', [
            'allergen' => 'X', 'medication_id' => '00000000-0000-4000-8000-000000000000',
        ], $this->doctor);
        $this->assertStatus(422, $unknown);
    }

    public function testMedicalProfile(): void
    {
        $patient = $this->createPatient();
        $base = '/patients/' . $patient['id'] . '/medical-profile';

        $this->assertStatus(200, $this->send('PUT', $base, ['blood_group' => 'O+'], $this->doctor));
        $this->assertSame('O+', $this->payload($this->send('GET', $base, null, $this->nurse))['data']['blood_group']);
        $this->assertStatus(422, $this->send('PUT', $base, ['blood_group' => 'Z'], $this->doctor));
        $this->assertStatus(403, $this->send('PUT', $base, ['blood_group' => 'A+'], $this->reception));
    }

    public function testOnlyOnePrimaryAddress(): void
    {
        $patient = $this->createPatient();
        $base = '/patients/' . $patient['id'] . '/addresses';

        $first = $this->payload($this->send('POST', $base, ['city' => 'Niamey', 'is_primary' => true], $this->reception))['data'];
        $this->send('POST', $base, ['city' => 'Dosso', 'is_primary' => true], $this->reception);

        $addresses = $this->payload($this->send('GET', $base, null, $this->reception))['data'];
        $primary = array_column(array_filter($addresses, function (array $address): bool {
            return $address['is_primary'];
        }), 'city');
        $this->assertSame(['Dosso'], $primary);
        $this->assertNotSame($first['id'], $addresses[0]['id']);

        $incomplete = $this->send('POST', $base, ['city' => 'Tahoua', 'latitude' => 14.9], $this->reception);
        $this->assertStatus(422, $incomplete);
    }

    public function testDeletionIsSoftAndPropagatedToDevices(): void
    {
        $patient = $this->createPatient();
        $contact = $this->payload($this->send('POST', '/patients/' . $patient['id'] . '/contacts', ['full_name' => 'Awa', 'phone' => '96 00 00 02'], $this->reception))['data'];

        $this->assertStatus(200, $this->send('DELETE', '/patients/' . $patient['id'] . '/contacts/' . $contact['id'], null, $this->reception));

        $this->assertSame([], $this->payload($this->send('GET', '/patients/' . $patient['id'] . '/contacts', null, $this->reception))['data']);
        $this->assertNotNull($this->db()->fetchValue('SELECT deleted_at FROM patient_contacts WHERE uuid = ?', [$contact['id']]));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM sync_changes WHERE entity_uuid = ? AND operation = 'DELETE'", [$contact['id']]));
    }

    public function testAttendingPhysicianMustBeADoctor(): void
    {
        $patient = $this->createPatient();
        $users = $this->container->get(UserRepository::class);
        $doctor = $this->createUser(['MEDECIN']);
        $users->saveStaffProfile($doctor['id'], ['profession' => 'MEDECIN']);
        $nurse = $this->createUser(['INFIRMIER']);
        $users->saveStaffProfile($nurse['id'], ['profession' => 'INFIRMIER']);
        $base = '/patients/' . $patient['id'] . '/attending-physician';

        $assigned = $this->send('PUT', $base, ['attending_physician_id' => $doctor['uuid']], $this->admin);
        $this->assertStatus(200, $assigned);
        $this->assertSame($doctor['uuid'], $this->payload($assigned)['data']['attending_physician']['id']);

        $this->assertStatus(422, $this->send('PUT', $base, ['attending_physician_id' => $nurse['uuid']], $this->admin));
        $this->assertStatus(403, $this->send('PUT', $base, ['attending_physician_id' => $doctor['uuid']], $this->nurse));
    }

    public function testMergeMovesDataAndClosesTheDuplicate(): void
    {
        $identity = $this->identity();
        $kept = $this->createPatient($identity);
        $duplicate = $this->payload($this->send('POST', '/patients', $identity + ['confirm_not_duplicate' => true], $this->reception))['data'];
        $this->send('POST', '/patients/' . $duplicate['id'] . '/allergies', ['allergen' => 'Sulfamides'], $this->doctor);

        $this->assertStatus(422, $this->send('POST', '/patients/' . $kept['id'] . '/merge', ['target_id' => $kept['id']], $this->admin));
        $merged = $this->send('POST', '/patients/' . $duplicate['id'] . '/merge', ['target_id' => $kept['id']], $this->admin);

        $this->assertStatus(200, $merged);
        $allergies = $this->payload($this->send('GET', '/patients/' . $kept['id'] . '/allergies', null, $this->doctor))['data'];
        $this->assertSame(['Sulfamides'], array_column($allergies, 'allergen'));
        $closed = $this->payload($this->send('GET', '/patients/' . $duplicate['id'], null, $this->admin))['data'];
        $this->assertSame('MERGED', $closed['status']);
        $this->assertSame($kept['id'], $closed['merged_into']);

        $again = $this->send('POST', '/patients/' . $duplicate['id'] . '/merge', ['target_id' => $kept['id']], $this->admin);
        $this->assertStatus(409, $again);
        $this->assertStatus(409, $this->send('POST', '/patients/' . $duplicate['id'] . '/contacts', ['full_name' => 'X', 'phone' => '96 00 00 03'], $this->reception));
    }

    public function testCreationRequiresPermission(): void
    {
        $technician = $this->login($this->createUser(['TECHNICIEN']))['access_token'];

        $this->assertStatus(403, $this->send('POST', '/patients', $this->identity(), $technician));
    }

    private function identity(): array
    {
        $n = ++self::$sequence;
        return [
            'first_name' => 'Aïcha',
            'last_name' => 'Patient' . getmypid() . 'N' . $n,
            'sex' => 'F',
            'birth_date' => sprintf('19%02d-0%d-1%d', 50 + $n % 49, 1 + $n % 9, $n % 10),
        ];
    }

    private function createPatient(?array $identity = null): array
    {
        $response = $this->send('POST', '/patients', $identity ?? $this->identity(), $this->reception);
        $this->assertStatus(201, $response);
        return $this->payload($response)['data'];
    }
}
