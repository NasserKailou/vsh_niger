<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

final class UsersTest extends ApiTestCase
{
    /** @var array */
    private $admin;

    /** @var string */
    private $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser(['ADMIN']);
        $this->adminToken = $this->login($this->admin)['access_token'];
    }

    public function testPermissionIsCheckedOnTheServer(): void
    {
        $technician = $this->login($this->createUser(['TECHNICIEN']));

        $response = $this->send('GET', '/users', null, $technician['access_token']);

        $this->assertStatus(403, $response);
        $this->assertSame('FORBIDDEN', $this->payload($response)['code']);
    }

    public function testAdminCreatesStaffAccountWithTemporaryPassword(): void
    {
        $response = $this->send('POST', '/users', [
            'first_name' => 'Aïcha',
            'last_name' => 'Moussa',
            'phone' => '96 11 22 33',
            'roles' => ['INFIRMIER'],
            'profession' => 'INFIRMIER',
            'is_admin' => true,
        ], $this->adminToken);

        $this->assertStatus(201, $response);
        $data = $this->payload($response)['data'];
        $this->assertSame('+22796112233', $data['user']['phone']);
        $this->assertSame(['INFIRMIER'], $data['user']['roles']);
        $this->assertSame('INFIRMIER', $data['user']['staff_profile']['profession']);
        $this->assertTrue($data['user']['must_change_password']);

        $login = $this->send('POST', '/auth/login', ['phone' => '+22796112233', 'password' => $data['temporary_password']]);
        $this->assertStatus(200, $login);
        $this->assertTrue($this->payload($login)['data']['user']['must_change_password']);

        $audit = $this->db()->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'USER_CREATED' AND entity_uuid = ?", [$data['user']['id']]);
        $this->assertSame(1, (int) $audit);
    }

    public function testCreationValidatesInputAndUniqueness(): void
    {
        $existing = $this->createUser(['MEDECIN']);

        $response = $this->send('POST', '/users', [
            'first_name' => 'Ali',
            'last_name' => 'Issa',
            'phone' => $existing['phone'],
            'roles' => ['INEXISTANT'],
            'profession' => 'MEDECIN',
        ], $this->adminToken);

        $this->assertStatus(422, $response);
        $this->assertArrayHasKey('roles', $this->payload($response)['errors']);

        $duplicate = $this->send('POST', '/users', [
            'first_name' => 'Ali',
            'last_name' => 'Issa',
            'phone' => $existing['phone'],
            'roles' => ['MEDECIN'],
            'profession' => 'MEDECIN',
        ], $this->adminToken);
        $this->assertArrayHasKey('phone', $this->payload($duplicate)['errors']);
    }

    public function testPatientRoleCannotBeGivenToStaff(): void
    {
        $response = $this->send('POST', '/users', [
            'first_name' => 'Ali',
            'last_name' => 'Issa',
            'phone' => '96 44 55 66',
            'roles' => ['PATIENT'],
            'profession' => 'AUTRE',
        ], $this->adminToken);

        $this->assertStatus(422, $response);
    }

    public function testUpdateChangesRolesAndIsAudited(): void
    {
        $user = $this->createUser(['INFIRMIER']);

        $response = $this->send('PUT', '/users/' . $user['uuid'], ['roles' => ['INFIRMIER', 'ACCUEIL'], 'last_name' => 'Garba'], $this->adminToken);

        $this->assertStatus(200, $response);
        $this->assertSame(['ACCUEIL', 'INFIRMIER'], $this->payload($response)['data']['roles']);
        $row = $this->db()->fetchOne("SELECT old_values, new_values FROM audit_logs WHERE action = 'USER_UPDATED' AND entity_uuid = ?", [$user['uuid']]);
        $this->assertNotNull($row);
        $this->assertStringContainsString('Garba', (string) $row['new_values']);
    }

    public function testSuspendingAUserClosesTheirSessions(): void
    {
        $user = $this->createUser(['MEDECIN']);
        $session = $this->login($user, $this->device());

        $response = $this->send('POST', '/users/' . $user['uuid'] . '/suspend', null, $this->adminToken);

        $this->assertStatus(200, $response);
        $this->assertSame('SUSPENDED', $this->payload($response)['data']['status']);
        $this->assertStatus(401, $this->send('GET', '/me', null, $session['access_token']));
        $this->assertStatus(401, $this->send('POST', '/auth/refresh', ['refresh_token' => $session['refresh_token']]));

        $this->assertStatus(200, $this->send('POST', '/users/' . $user['uuid'] . '/activate', null, $this->adminToken));
        $this->assertStatus(200, $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => $user['password']]));
    }

    public function testAdministratorCannotSuspendThemselves(): void
    {
        $response = $this->send('POST', '/users/' . $this->admin['uuid'] . '/suspend', null, $this->adminToken);

        $this->assertStatus(409, $response);
        $this->assertSame('SELF_ACTION', $this->payload($response)['code']);
    }

    public function testAdminResetsPasswordOfAUser(): void
    {
        $user = $this->createUser(['MEDECIN']);
        $session = $this->login($user);

        $response = $this->send('POST', '/users/' . $user['uuid'] . '/reset-password', null, $this->adminToken);

        $this->assertStatus(200, $response);
        $temporary = $this->payload($response)['data']['temporary_password'];
        $this->assertStatus(401, $this->send('GET', '/me', null, $session['access_token']));
        $this->assertStatus(200, $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => $temporary]));
    }

    public function testListIsPaginatedAndSearchable(): void
    {
        $this->send('POST', '/users', [
            'first_name' => 'Zeinabou',
            'last_name' => 'Recherchable',
            'phone' => '96 77 88 99',
            'roles' => ['ACCUEIL'],
            'profession' => 'ADMINISTRATIF',
        ], $this->adminToken);

        $response = $this->send('GET', '/users?search=Recherch&per_page=5', null, $this->adminToken);
        $payload = $this->payload($response);

        $this->assertStatus(200, $response);
        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame(5, $payload['meta']['per_page']);
        $this->assertSame('Recherchable', $payload['data'][0]['last_name']);
    }

    public function testRolesCanBeCreatedAndDeletedOnlyWhenUnused(): void
    {
        $created = $this->send('POST', '/roles', [
            'code' => 'SECRETAIRE',
            'label' => 'Secrétaire médicale',
            'permissions' => ['patients.read', 'appointments.read', 'appointments.manage'],
        ], $this->adminToken);
        $this->assertStatus(201, $created);
        $this->assertSame(['appointments.manage', 'appointments.read', 'patients.read'], $this->payload($created)['data']['permissions']);

        $holder = $this->createUser(['SECRETAIRE']);
        $holderToken = $this->login($holder)['access_token'];
        $this->assertContains('appointments.manage', $this->payload($this->send('GET', '/me', null, $holderToken))['data']['permissions']);

        $inUse = $this->send('DELETE', '/roles/SECRETAIRE', null, $this->adminToken);
        $this->assertStatus(409, $inUse);
        $this->assertSame('ROLE_IN_USE', $this->payload($inUse)['code']);

        $this->assertStatus(409, $this->send('DELETE', '/roles/MEDECIN', null, $this->adminToken));
    }

    public function testRolePermissionChangesApplyImmediately(): void
    {
        $this->send('POST', '/roles', ['code' => 'STAGIAIRE', 'label' => 'Stagiaire', 'permissions' => []], $this->adminToken);
        $intern = $this->login($this->createUser(['STAGIAIRE']))['access_token'];
        $this->assertStatus(403, $this->send('GET', '/users', null, $intern));

        $this->send('PUT', '/roles/STAGIAIRE', ['permissions' => ['users.read']], $this->adminToken);

        $this->assertStatus(200, $this->send('GET', '/users', null, $intern));
    }

    public function testPatientRoleCannotReceiveStaffPermissions(): void
    {
        $response = $this->send('PUT', '/roles/PATIENT', ['permissions' => ['self.record.read', 'patients.read']], $this->adminToken);

        $this->assertStatus(422, $response);
    }

    public function testLastAdministrationPermissionCannotBeRemoved(): void
    {
        $response = $this->send('PUT', '/roles/ADMIN', ['permissions' => ['users.read']], $this->adminToken);

        $this->assertStatus(409, $response);
        $this->assertSame('LAST_ADMINISTRATOR', $this->payload($response)['code']);
        $this->assertStatus(200, $this->send('GET', '/permissions', null, $this->adminToken));
    }
}
