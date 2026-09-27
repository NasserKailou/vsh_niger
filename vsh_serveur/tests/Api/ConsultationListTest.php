<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

final class ConsultationListTest extends ApiTestCase
{
    public function testListsCarryMinimalPatientIdentityWithoutClinicalContent(): void
    {
        $admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $doctor = $this->login($this->createUser(['MEDECIN']))['access_token'];
        $patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Salamatou', 'last_name' => 'Liste' . getmypid(), 'sex' => 'F', 'birth_date' => '1985-02-03',
        ], $admin))['data'];
        $this->assertStatus(201, $this->send('POST', '/consultations', [
            'patient_id' => $patient['id'], 'consultation_type' => 'CLINIQUE', 'chief_complaint' => 'Motif confidentiel',
        ], $doctor));

        $response = $this->send('GET', '/consultations?patient_id=' . $patient['id'], null, $admin);
        $row = $this->payload($response)['data'][0];
        $this->assertSame($patient['id'], $row['patient_id']);
        $this->assertSame(['id' => $patient['id'], 'file_number' => $patient['file_number'], 'name' => mb_strtoupper($patient['last_name']) . ' Salamatou'], $row['patient']);
        $this->assertArrayNotHasKey('chief_complaint', $row);
        $this->assertStringNotContainsString('Motif confidentiel', $response->body());
    }
}
