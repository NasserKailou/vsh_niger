<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

final class DashboardTest extends ApiTestCase
{
    public function testBlocksFollowPermissions(): void
    {
        $admin = $this->payload($this->send('GET', '/dashboard', null, $this->login($this->createUser(['ADMIN']))['access_token']))['data'];
        foreach (['activity', 'registrations_pending', 'appointments_today', 'homecare', 'billing_month'] as $block) {
            $this->assertArrayHasKey($block, $admin);
        }
        $this->assertArrayNotHasKey('exam_queue', $admin);
        $this->assertArrayNotHasKey('my_agenda', $admin);
        $this->assertSame('XOF', $admin['currency']);

        $nurse = $this->payload($this->send('GET', '/dashboard', null, $this->login($this->createUser(['INFIRMIER']))['access_token']))['data'];
        $this->assertArrayHasKey('my_team_visits', $nurse);
        $this->assertArrayHasKey('my_agenda', $nurse);
        $this->assertArrayNotHasKey('billing_month', $nurse);
        $this->assertArrayNotHasKey('activity', $nurse);

        $technician = $this->payload($this->send('GET', '/dashboard', null, $this->login($this->createUser(['TECHNICIEN']))['access_token']))['data'];
        $this->assertArrayHasKey('exam_queue', $technician);
        $this->assertArrayNotHasKey('homecare', $technician);

        $patient = $this->login($this->createUser(['PATIENT'], ['account_type' => 'PATIENT']))['access_token'];
        $this->assertStatus(403, $this->send('GET', '/dashboard', null, $patient));
        $this->assertStatus(401, $this->send('GET', '/dashboard'));
    }

    public function testCountersReflectActivityWithoutMedicalContent(): void
    {
        $adminToken = $this->login($this->createUser(['ADMIN']))['access_token'];
        $before = $this->payload($this->send('GET', '/dashboard', null, $adminToken))['data'];

        $patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Kadi', 'last_name' => 'Tableau' . getmypid(), 'sex' => 'F', 'birth_date' => '1988-04-12',
        ], $adminToken))['data'];
        $reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $this->assertStatus(201, $this->send('POST', '/homecare', [
            'patient_id' => $patient['id'], 'reason' => 'Motif confidentiel', 'urgency' => 'URGENTE',
            'landmark' => 'Près de l’école', 'contact_phone' => '+22790004455',
        ], $reception));

        $response = $this->send('GET', '/dashboard', null, $adminToken);
        $after = $this->payload($response)['data'];
        $this->assertSame($before['activity']['patients_active'] + 1, $after['activity']['patients_active']);
        $this->assertSame($before['homecare']['urgent_waiting'] + 1, $after['homecare']['urgent_waiting']);
        $this->assertStringNotContainsString('Motif confidentiel', $response->body());
    }
}
