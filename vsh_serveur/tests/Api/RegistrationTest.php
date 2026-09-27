<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

final class RegistrationTest extends ApiTestCase
{
    private const PATIENT_PASSWORD = 'Patient2026';

    /** @var int */
    private static $sequence = 0;

    /** @var string */
    private $reception;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
    }

    public function testPatientRegistersWithSmsCodeAndWaitsForValidation(): void
    {
        $phone = $this->newPhone();
        $code = $this->requestCode($phone);

        $response = $this->send('POST', '/auth/register', $this->registration($phone, $code) + [
            'device' => $this->device(),
            'address' => ['city' => 'Niamey', 'district' => 'Lazaret', 'landmark' => 'Près de la mosquée'],
            'emergency_contact' => ['full_name' => 'Ibrahim Issa', 'relationship' => 'Époux', 'phone' => '96 55 44 33'],
        ]);

        $this->assertStatus(201, $response);
        $data = $this->payload($response)['data'];
        $this->assertSame('PENDING', $data['patient']['status']);
        $this->assertNull($data['patient']['file_number']);
        $this->assertSame(['PATIENT'], $data['user']['roles']);
        $this->assertSame('PENDING', $this->db()->fetchValue('SELECT status FROM users WHERE phone = ?', [$phone]));

        $token = $data['tokens']['access_token'];
        $mine = $this->payload($this->send('GET', '/me/patients', null, $token))['data'];
        $this->assertSame([$data['patient']['id']], array_column($mine, 'id'));
        $this->assertStatus(403, $this->send('GET', '/patients', null, $token));
    }

    public function testWrongCodeCreatesNothing(): void
    {
        $phone = $this->newPhone();
        $code = $this->requestCode($phone);

        $response = $this->send('POST', '/auth/register', $this->registration($phone, $code === '000000' ? '111111' : '000000'));

        $this->assertStatus(422, $response);
        $this->assertSame('INVALID_OTP', $this->payload($response)['code']);
        $this->assertNull($this->db()->fetchValue('SELECT id FROM users WHERE phone = ?', [$phone]));
    }

    public function testExistingAccountIsOnlyRevealedAfterCodeVerification(): void
    {
        $phone = $this->newPhone();
        $this->register($phone);
        \Vsh\Core\Support\Clock::setTestNow(\Vsh\Core\Support\Clock::now()->modify('+2 minutes'));

        $response = $this->send('POST', '/auth/register', $this->registration($phone, $this->requestCode($phone)));

        $this->assertStatus(409, $response);
        $this->assertSame('PHONE_ALREADY_REGISTERED', $this->payload($response)['code']);
    }

    public function testApprovalAssignsFileNumberAndNotifiesThePatient(): void
    {
        $registered = $this->register($this->newPhone());
        $patientId = $registered['patient']['id'];

        $pending = $this->payload($this->send('GET', '/patient-registrations?per_page=100', null, $this->reception))['data'];
        $this->assertContains($patientId, array_column($pending, 'id'));

        $approved = $this->send('POST', '/patient-registrations/' . $patientId . '/approve', null, $this->reception);
        $this->assertStatus(200, $approved);
        $fileNumber = $this->payload($approved)['data']['file_number'];
        $this->assertMatchesRegularExpression('/^VSH-\d{4}-\d{6}$/', $fileNumber);
        $this->assertSame('ACTIVE', $this->db()->fetchValue('SELECT status FROM users WHERE uuid = ?', [$registered['user']['id']]));

        $token = $registered['tokens']['access_token'];
        $notifications = $this->payload($this->send('GET', '/notifications', null, $token));
        $this->assertSame(1, $notifications['meta']['unread']);
        $this->assertSame('PATIENT_APPROVED', $notifications['data'][0]['type']);
        $this->assertStringContainsString($fileNumber, (string) $notifications['data'][0]['body']);

        $this->assertStatus(200, $this->send('POST', '/notifications/' . $notifications['data'][0]['id'] . '/read', null, $token));
        $this->assertSame(0, $this->payload($this->send('GET', '/notifications', null, $token))['meta']['unread']);

        $again = $this->send('POST', '/patient-registrations/' . $patientId . '/approve', null, $this->reception);
        $this->assertSame('INVALID_STATUS', $this->payload($again)['code']);
    }

    public function testRejectionRequiresAReasonAndClosesTheAccount(): void
    {
        $registered = $this->register($this->newPhone());
        $url = '/patient-registrations/' . $registered['patient']['id'] . '/reject';

        $this->assertStatus(422, $this->send('POST', $url, [], $this->reception));
        $this->assertStatus(200, $this->send('POST', $url, ['reason' => 'Identité non vérifiable'], $this->reception));

        $this->assertStatus(401, $this->send('GET', '/me/patients', null, $registered['tokens']['access_token']));
        $this->assertSame('REJECTED', $this->db()->fetchValue('SELECT status FROM users WHERE uuid = ?', [$registered['user']['id']]));
    }

    public function testAccountCanManageFamilyRecords(): void
    {
        $registered = $this->register($this->newPhone());
        $token = $registered['tokens']['access_token'];

        $child = $this->send('POST', '/me/patients', ['first_name' => 'Moussa', 'last_name' => 'Enfant' . getmypid(), 'sex' => 'M', 'birth_date' => '2019-03-02'], $token);

        $this->assertStatus(201, $child);
        $this->assertSame('PENDING', $this->payload($child)['data']['status']);
        $this->assertCount(2, $this->payload($this->send('GET', '/me/patients', null, $token))['data']);
    }

    public function testPatientOnlySeesOwnRecords(): void
    {
        $first = $this->register($this->newPhone());
        $second = $this->register($this->newPhone());

        $response = $this->send('GET', '/me/patients/' . $second['patient']['id'], null, $first['tokens']['access_token']);

        $this->assertStatus(404, $response);
    }

    public function testValidatedPatientSeesOwnMedicalData(): void
    {
        $registered = $this->register($this->newPhone());
        $patientId = $registered['patient']['id'];
        $this->send('POST', '/patient-registrations/' . $patientId . '/approve', null, $this->reception);
        $doctor = $this->login($this->createUser(['MEDECIN']))['access_token'];
        $this->send('POST', '/patients/' . $patientId . '/allergies', ['allergen' => 'Aspirine'], $doctor);

        $record = $this->payload($this->send('GET', '/me/patients/' . $patientId, null, $registered['tokens']['access_token']))['data'];

        $this->assertSame(['Aspirine'], array_column($record['medical']['allergies'], 'allergen'));
    }

    public function testPortalLoginForARecordCreatedAtTheClinic(): void
    {
        $phone = $this->newPhone();
        $patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Zeinabou', 'last_name' => 'Portail' . getmypid() . 'N' . self::$sequence, 'sex' => 'F', 'phone' => $phone,
        ], $this->reception))['data'];
        $this->assertFalse($patient['has_account']);

        $wrongPhone = $this->send('POST', '/auth/patient-portal/code', ['file_number' => $patient['file_number'], 'phone' => $this->newPhone()]);
        $this->assertStatus(200, $wrongPhone);
        $this->assertSame([], $this->sms->sent);

        $this->assertStatus(200, $this->send('POST', '/auth/patient-portal/code', ['file_number' => $patient['file_number'], 'phone' => $phone]));
        $code = (string) $this->sms->lastCodeFor($phone);

        $wrongCode = $this->send('POST', '/auth/patient-portal/login', ['file_number' => $patient['file_number'], 'phone' => $phone, 'code' => $code === '000000' ? '111111' : '000000']);
        $this->assertSame('INVALID_PORTAL_CREDENTIALS', $this->payload($wrongCode)['code']);

        $login = $this->send('POST', '/auth/patient-portal/login', ['file_number' => strtolower($patient['file_number']), 'phone' => $phone, 'code' => $code]);
        $this->assertStatus(200, $login);
        $token = $this->payload($login)['data']['tokens']['access_token'];
        $this->assertSame([$patient['id']], array_column($this->payload($this->send('GET', '/me/patients', null, $token))['data'], 'id'));
        $this->assertTrue($this->payload($this->send('GET', '/patients/' . $patient['id'], null, $this->reception))['data']['has_account']);
    }

    public function testMobileAppPatientLoginIsBoundToTheDevice(): void
    {
        $phone = $this->newPhone();
        $patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Hadiza', 'last_name' => 'Mobile' . getmypid() . 'N' . self::$sequence, 'sex' => 'F', 'phone' => $phone,
        ], $this->reception))['data'];
        $this->send('POST', '/auth/patient-portal/code', ['file_number' => $patient['file_number'], 'phone' => $phone]);
        $code = (string) $this->sms->lastCodeFor($phone);

        $invalid = $this->send('POST', '/auth/patient-portal/login', ['file_number' => $patient['file_number'], 'phone' => $phone, 'code' => $code, 'device' => ['platform' => 'NOKIA']]);
        $this->assertStatus(422, $invalid);

        $device = $this->device();
        $login = $this->send('POST', '/auth/patient-portal/login', ['file_number' => $patient['file_number'], 'phone' => $phone, 'code' => $code, 'device' => $device]);
        $this->assertStatus(200, $login);
        $data = $this->payload($login)['data'];
        $this->assertSame('PATIENT', $data['user']['account_type']);
        $token = $data['tokens']['access_token'];

        // Appareil enregistré : visible par le patient, et apte à recevoir des push (D-011).
        $this->assertSame([$device['uuid']], array_column($this->payload($this->send('GET', '/me/devices', null, $token))['data'], 'id'));
        $this->assertStatus(200, $this->send('POST', '/push-tokens', ['token' => 'fcm-' . bin2hex(random_bytes(24))], $token));
    }

    public function testPortalDoesNotRevealUnknownFileNumbers(): void
    {
        $response = $this->send('POST', '/auth/patient-portal/code', ['file_number' => 'VSH-1999-999999', 'phone' => $this->newPhone()]);

        $this->assertStatus(200, $response);
        $this->assertSame([], $this->sms->sent);
    }

    public function testCodeIsNotRequiredWhenVerificationIsDisabled(): void
    {
        $admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->send('PUT', '/settings', ['values' => ['auth.registration_otp_required' => false]], $admin);
        try {
            $phone = $this->newPhone();
            $codeRequest = $this->payload($this->send('POST', '/auth/register/code', ['phone' => $phone]))['data'];
            $this->assertFalse($codeRequest['code_required']);
            $this->assertStatus(201, $this->send('POST', '/auth/register', $this->registration($phone, null)));
        } finally {
            $this->send('PUT', '/settings', ['values' => ['auth.registration_otp_required' => true]], $admin);
        }
    }

    private function register(string $phone): array
    {
        $response = $this->send('POST', '/auth/register', $this->registration($phone, $this->requestCode($phone)));
        $this->assertStatus(201, $response);
        return $this->payload($response)['data'];
    }

    private function requestCode(string $phone): string
    {
        $response = $this->send('POST', '/auth/register/code', ['phone' => $phone]);
        $this->assertStatus(200, $response);
        $code = $this->sms->lastCodeFor($phone);
        $this->assertNotNull($code);
        return (string) $code;
    }

    private function registration(string $phone, ?string $code): array
    {
        $body = [
            'first_name' => 'Fati',
            'last_name' => 'Inscrite' . getmypid() . 'N' . self::$sequence,
            'sex' => 'F',
            'birth_date' => '1995-06-15',
            'phone' => $phone,
            'password' => self::PATIENT_PASSWORD,
        ];
        if ($code !== null) {
            $body['code'] = $code;
        }
        return $body;
    }

    private function newPhone(): string
    {
        return sprintf('+2278%07d', ++self::$sequence + (getmypid() % 1000) * 1000);
    }
}
