<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

final class TeamsTest extends ApiTestCase
{
    public function testTeamAndDatedMembership(): void
    {
        $admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $nurseUser = $this->createUser(['INFIRMIER']);
        $nurse = $this->login($nurseUser)['access_token'];

        $created = $this->send('POST', '/teams', ['code' => 'EQ' . getmypid() . 'A', 'label' => 'Équipe mobile Plateau'], $admin);
        $this->assertStatus(201, $created);
        $team = $this->payload($created)['data'];
        $this->assertTrue($team['is_mobile']);
        $this->assertSame([], $team['members']);
        $this->assertArrayHasKey('code', $this->payload($this->send('POST', '/teams', ['code' => $team['code'], 'label' => 'Doublon'], $admin))['errors']);

        $base = '/teams/' . $team['id'] . '/members';
        $withMember = $this->payload($this->send('POST', $base, [
            'user_id' => $nurseUser['uuid'], 'team_role' => 'INFIRMIER', 'from_date' => gmdate('Y-m-d', strtotime('-30 days')),
        ], $admin))['data'];
        $this->assertCount(1, $withMember['members']);
        $this->assertTrue($withMember['members'][0]['is_current']);
        $this->assertSame('ALREADY_MEMBER', $this->payload($this->send('POST', $base, ['user_id' => $nurseUser['uuid'], 'team_role' => 'CHEF'], $admin))['code']);

        $this->assertSame([$team['id']], array_column($this->payload($this->send('GET', '/me/teams', null, $nurse))['data'], 'id'));

        // Fin d'appartenance : l'historique reste, la personne ne fait plus partie de l'équipe.
        $memberId = $withMember['members'][0]['id'];
        $this->assertArrayHasKey('to_date', $this->payload($this->send('PUT', $base . '/' . $memberId, ['to_date' => '2000-01-01'], $admin))['errors']);
        $ended = $this->payload($this->send('PUT', $base . '/' . $memberId, ['to_date' => gmdate('Y-m-d', strtotime('-1 day'))], $admin))['data'];
        $this->assertFalse($ended['members'][0]['is_current']);
        $this->assertSame([], $this->payload($this->send('GET', '/me/teams', null, $nurse))['data']);
    }

    public function testRightsAndStaffOnlyMembers(): void
    {
        $admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $nurse = $this->login($this->createUser(['INFIRMIER']))['access_token'];
        $patient = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);

        $this->assertStatus(403, $this->send('POST', '/teams', ['code' => 'EQ' . getmypid() . 'B', 'label' => 'X'], $nurse));
        $team = $this->payload($this->send('POST', '/teams', ['code' => 'EQ' . getmypid() . 'B', 'label' => 'Équipe B', 'is_mobile' => false], $admin))['data'];
        $this->assertFalse($team['is_mobile']);
        $this->assertArrayHasKey('user_id', $this->payload($this->send('POST', '/teams/' . $team['id'] . '/members', ['user_id' => $patient['uuid'], 'team_role' => 'AUTRE'], $admin))['errors']);
        $this->assertStatus(200, $this->send('GET', '/teams/' . $team['id'], null, $nurse));
        $this->assertFalse($this->payload($this->send('PUT', '/teams/' . $team['id'], ['active' => false], $admin))['data']['active']);
    }
}
