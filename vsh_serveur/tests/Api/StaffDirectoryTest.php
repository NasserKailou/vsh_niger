<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

final class StaffDirectoryTest extends ApiTestCase
{
    public function testDirectoryListsActiveCliniciansWithMinimalData(): void
    {
        $doctor = $this->createUser(['MEDECIN']);
        $nurse = $this->createUser(['INFIRMIER']);
        $suspended = $this->createUser(['MEDECIN'], ['status' => 'SUSPENDED']);
        foreach ([[$doctor, 'MEDECIN'], [$nurse, 'INFIRMIER'], [$suspended, 'MEDECIN']] as [$user, $profession]) {
            $this->db()->insert('staff_profiles', ['user_id' => $user['id'], 'profession' => $profession]);
        }
        $reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];

        $doctors = $this->payload($this->send('GET', '/staff/directory?profession=MEDECIN', null, $reception))['data'];
        $ids = array_column($doctors, 'id');
        $this->assertContains($doctor['uuid'], $ids);
        $this->assertNotContains($nurse['uuid'], $ids);
        $this->assertNotContains($suspended['uuid'], $ids);
        $this->assertSame(['id', 'name', 'profession', 'speciality'], array_keys($doctors[0]));

        $all = array_column($this->payload($this->send('GET', '/staff/directory', null, $reception))['data'], 'id');
        $this->assertContains($nurse['uuid'], $all);

        $technician = $this->login($this->createUser(['TECHNICIEN']))['access_token'];
        $this->assertStatus(403, $this->send('GET', '/staff/directory', null, $technician));
        $this->assertStatus(422, $this->send('GET', '/staff/directory?profession=PIRATE', null, $reception));
    }
}
