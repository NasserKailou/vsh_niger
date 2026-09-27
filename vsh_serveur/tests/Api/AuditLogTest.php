<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

/**
 * Journal d'audit en lecture (droit audit.read) et validation des paramètres JSON de l'administration.
 */
final class AuditLogTest extends ApiTestCase
{
    public function testAuditLogIsReadableWithFiltersAndReservedToAuditors(): void
    {
        $adminUser = $this->createUser(['ADMIN']);
        $admin = $this->login($adminUser)['access_token'];
        $doctor = $this->login($this->createUser(['MEDECIN']))['access_token'];
        $patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Rabi', 'last_name' => 'Audit' . getmypid(), 'sex' => 'F', 'birth_date' => '1979-04-12',
        ], $admin))['data'];
        $this->send('PUT', '/patients/' . $patient['id'], ['phone' => '+22790112233'], $admin);

        $response = $this->send('GET', '/audit-logs?entity_id=' . $patient['id'], null, $admin);
        $this->assertStatus(200, $response);
        $entries = $this->payload($response)['data'];
        $this->assertNotEmpty($entries);
        foreach ($entries as $entry) {
            $this->assertSame($patient['id'], $entry['entity_id']);
            $this->assertSame($adminUser['uuid'], $entry['user']['id']);
        }
        // Du plus récent au plus ancien.
        $this->assertGreaterThanOrEqual((int) $entries[count($entries) - 1]['id'], (int) $entries[0]['id']);

        $action = $entries[0]['action'];
        $type = $entries[0]['entity_type'];
        $filtered = $this->payload($this->send('GET', '/audit-logs?action=' . $action . '&entity_type=' . $type . '&user_id=' . $adminUser['uuid'], null, $admin))['data'];
        $this->assertNotEmpty($filtered);
        $this->assertSame([$action], array_values(array_unique(array_column($filtered, 'action'))));

        // Période en jours locaux : aujourd'hui inclus, demain vide.
        $zone = new \DateTimeZone('Africa/Niamey');
        $today = (new \DateTimeImmutable('now', $zone))->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('tomorrow', $zone))->format('Y-m-d');
        $this->assertNotEmpty($this->payload($this->send('GET', '/audit-logs?entity_id=' . $patient['id'] . '&from=' . $today . '&to=' . $today, null, $admin))['data']);
        $this->assertSame([], $this->payload($this->send('GET', '/audit-logs?entity_id=' . $patient['id'] . '&from=' . $tomorrow, null, $admin))['data']);

        $facets = $this->payload($this->send('GET', '/audit-logs/facets', null, $admin))['data'];
        $this->assertContains($action, $facets['actions']);
        $this->assertContains($type, $facets['entity_types']);

        $this->assertArrayHasKey('action', $this->payload($this->send('GET', '/audit-logs?action=drop%20table', null, $admin))['errors']);
        $this->assertStatus(403, $this->send('GET', '/audit-logs', null, $doctor));
        $this->assertContains($this->send('DELETE', '/audit-logs', null, $admin)->status(), [404, 405]);
    }

    public function testLetterheadSettingAcceptsOnlyKnownTextFields(): void
    {
        $admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $errors = $this->payload($this->send('PUT', '/settings', ['values' => ['documents.letterhead' => ['address' => 'Niamey', 'logo' => 'x']]], $admin))['errors'];
        $this->assertStringContainsString('Champ inconnu : logo', $errors['documents.letterhead'][0]);
        $errors = $this->payload($this->send('PUT', '/settings', ['values' => ['documents.letterhead' => ['address' => str_repeat('a', 256)]]], $admin))['errors'];
        $this->assertArrayHasKey('documents.letterhead', $errors);
        $errors = $this->payload($this->send('PUT', '/settings', ['values' => ['uploads.allowed_mime_types' => ['pdf']]], $admin))['errors'];
        $this->assertArrayHasKey('uploads.allowed_mime_types', $errors);
    }
}
