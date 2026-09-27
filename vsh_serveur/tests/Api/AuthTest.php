<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Core\Support\Clock;

final class AuthTest extends ApiTestCase
{
    public function testLoginReturnsTokensAndProfileWithPermissions(): void
    {
        $user = $this->createUser(['MEDECIN']);

        $response = $this->send('POST', '/auth/login', [
            'phone' => $user['phone'],
            'password' => $user['password'],
            'device' => $this->device(),
        ]);
        $data = $this->payload($response)['data'];

        $this->assertStatus(200, $response);
        $this->assertSame('Bearer', $data['tokens']['token_type']);
        $this->assertSame(3600, $data['tokens']['expires_in']);
        $this->assertNotEmpty($data['tokens']['refresh_token']);
        $this->assertSame($user['uuid'], $data['user']['id']);
        $this->assertSame(['MEDECIN'], $data['user']['roles']);
        $this->assertContains('consultations.create', $data['user']['permissions']);
        $this->assertArrayNotHasKey('password_hash', $data['user']);
    }

    public function testLocalNumberFormatIsAccepted(): void
    {
        $user = $this->createUser(['MEDECIN']);
        $local = substr($user['phone'], 4, 2) . ' ' . substr($user['phone'], 6, 2) . ' ' . substr($user['phone'], 8);

        $this->assertStatus(200, $this->send('POST', '/auth/login', ['phone' => $local, 'password' => $user['password']]));
    }

    public function testWrongPasswordAndUnknownPhoneGiveTheSameResponse(): void
    {
        $user = $this->createUser(['MEDECIN']);

        $wrongPassword = $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => 'Mauvais123']);
        $unknownPhone = $this->send('POST', '/auth/login', ['phone' => '+22799999999', 'password' => 'Mauvais123']);

        $this->assertStatus(401, $wrongPassword);
        $this->assertSame($wrongPassword->status(), $unknownPhone->status());
        $this->assertSame($wrongPassword->body(), $unknownPhone->body());
        $this->assertSame('INVALID_CREDENTIALS', $this->payload($wrongPassword)['code']);
    }

    public function testLoginIsBlockedAfterFiveFailuresEvenWithTheRightPassword(): void
    {
        $user = $this->createUser(['MEDECIN']);
        for ($i = 0; $i < 5; $i++) {
            $this->assertStatus(401, $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => 'Mauvais123']));
        }

        $response = $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => $user['password']]);

        $this->assertStatus(429, $response);
        $this->assertSame('RATE_LIMITED', $this->payload($response)['code']);
        $this->assertGreaterThan(0, (int) $response->header('Retry-After'));
        $this->assertNotNull($this->db()->fetchValue('SELECT locked_until FROM users WHERE id = ?', [$user['id']]));

        // Après la fenêtre de 15 minutes, la connexion redevient possible.
        Clock::setTestNow(Clock::now()->modify('+16 minutes'));
        $this->assertStatus(200, $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => $user['password']]));
    }

    public function testSuspendedAccountCannotLogIn(): void
    {
        $user = $this->createUser(['MEDECIN'], ['status' => 'SUSPENDED']);

        $response = $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => $user['password']]);

        $this->assertStatus(403, $response);
        $this->assertSame('ACCOUNT_DISABLED', $this->payload($response)['code']);
    }

    public function testProtectedRouteRequiresAValidToken(): void
    {
        $this->assertSame('UNAUTHENTICATED', $this->payload($this->send('GET', '/me'))['code']);
        $this->assertStatus(401, $this->send('GET', '/me', null, 'jeton-inventé'));
    }

    public function testExpiredAccessTokenReturnsTokenExpired(): void
    {
        $tokens = $this->login($this->createUser(['MEDECIN']));

        Clock::setTestNow(Clock::now()->modify('+2 hours'));
        $response = $this->send('GET', '/me', null, $tokens['access_token']);

        $this->assertStatus(401, $response);
        $this->assertSame('TOKEN_EXPIRED', $this->payload($response)['code']);
    }

    public function testRefreshRotatesAndReuseRevokesTheWholeSession(): void
    {
        $tokens = $this->login($this->createUser(['MEDECIN']), $this->device());

        $first = $this->send('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertStatus(200, $first);
        $rotated = $this->payload($first)['data']['tokens'];
        $this->assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
        $this->assertStatus(200, $this->send('GET', '/me', null, $rotated['access_token']));

        // Le nouveau jeton a servi : représenter l'ancien après le délai de grâce = vol présumé.
        $this->assertStatus(200, $this->send('POST', '/auth/refresh', ['refresh_token' => $rotated['refresh_token']]));
        Clock::setTestNow(Clock::now()->modify('+5 minutes'));
        $reuse = $this->send('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

        $this->assertStatus(401, $reuse);
        $this->assertSame('REFRESH_TOKEN_REUSED', $this->payload($reuse)['code']);
        $this->assertStatus(401, $this->send('GET', '/me', null, $rotated['access_token']));
    }

    public function testLostRefreshResponseCanBeReplayedWithinGracePeriod(): void
    {
        $tokens = $this->login($this->createUser(['MEDECIN']), $this->device());

        // Réponse perdue (coupure réseau) : l'application n'a jamais reçu ce second jeton.
        $lost = $this->payload($this->send('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']]))['data']['tokens'];

        Clock::setTestNow(Clock::now()->modify('+30 seconds'));
        $replay = $this->send('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);

        $this->assertStatus(200, $replay);
        $recovered = $this->payload($replay)['data']['tokens'];
        $this->assertStatus(200, $this->send('GET', '/me', null, $recovered['access_token']));
        $this->assertStatus(401, $this->send('POST', '/auth/refresh', ['refresh_token' => $lost['refresh_token']]));
    }

    public function testLogoutRevokesAccessAndRefreshTokens(): void
    {
        $tokens = $this->login($this->createUser(['MEDECIN']), $this->device());

        $this->assertStatus(200, $this->send('POST', '/auth/logout', null, $tokens['access_token']));

        $this->assertStatus(401, $this->send('GET', '/me', null, $tokens['access_token']));
        $refresh = $this->send('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        $this->assertSame('SESSION_REVOKED', $this->payload($refresh)['code']);
    }

    public function testTemporaryPasswordMustBeChangedBeforeAnythingElse(): void
    {
        $user = $this->createUser(['ADMIN'], ['must_change_password' => true]);
        $tokens = $this->login($user);

        $blocked = $this->send('GET', '/users', null, $tokens['access_token']);
        $this->assertStatus(403, $blocked);
        $this->assertSame('PASSWORD_CHANGE_REQUIRED', $this->payload($blocked)['code']);
        $this->assertTrue($this->payload($this->send('GET', '/me', null, $tokens['access_token']))['data']['must_change_password']);

        $weak = $this->send('PUT', '/auth/password', ['current_password' => $user['password'], 'new_password' => 'court'], $tokens['access_token']);
        $this->assertStatus(422, $weak);
        $this->assertArrayHasKey('new_password', $this->payload($weak)['errors']);

        $changed = $this->send('PUT', '/auth/password', ['current_password' => $user['password'], 'new_password' => 'Nouveau2026'], $tokens['access_token']);
        $this->assertStatus(200, $changed);
        $this->assertStatus(200, $this->send('GET', '/users', null, $tokens['access_token']));
    }

    public function testChangingPasswordDisconnectsOtherDevices(): void
    {
        $user = $this->createUser(['MEDECIN']);
        $phone = $this->login($user, $this->device());
        $tablet = $this->login($user, $this->device());

        $this->send('PUT', '/auth/password', ['current_password' => $user['password'], 'new_password' => 'Nouveau2026'], $phone['access_token']);

        $this->assertStatus(200, $this->send('GET', '/me', null, $phone['access_token']));
        $this->assertStatus(401, $this->send('GET', '/me', null, $tablet['access_token']));
    }

    public function testForgotPasswordDoesNotRevealWhetherTheNumberExists(): void
    {
        $unknown = $this->send('POST', '/auth/password/forgot', ['phone' => '+22798765432']);

        $this->assertStatus(200, $unknown);
        $this->assertSame([], $this->sms->sent);
    }

    public function testPasswordResetWithSmsCode(): void
    {
        $user = $this->createUser(['INFIRMIER']);
        $session = $this->login($user, $this->device());

        $this->assertStatus(200, $this->send('POST', '/auth/password/forgot', ['phone' => $user['phone']]));
        $code = $this->sms->lastCodeFor($user['phone']);
        $this->assertNotNull($code, 'Un SMS contenant le code doit être envoyé');
        $this->assertStringNotContainsString('Utilisateur', $this->sms->sent[0]['message']);

        $wrong = $this->send('POST', '/auth/password/reset', ['phone' => $user['phone'], 'code' => $code === '000000' ? '111111' : '000000', 'new_password' => 'Nouveau2026']);
        $this->assertStatus(422, $wrong);
        $this->assertSame('INVALID_OTP', $this->payload($wrong)['code']);

        $this->assertStatus(200, $this->send('POST', '/auth/password/reset', ['phone' => $user['phone'], 'code' => $code, 'new_password' => 'Nouveau2026']));

        // Code à usage unique, anciennes sessions fermées, nouveau mot de passe actif.
        $this->assertStatus(422, $this->send('POST', '/auth/password/reset', ['phone' => $user['phone'], 'code' => $code, 'new_password' => 'Autre2026x']));
        $this->assertStatus(401, $this->send('GET', '/me', null, $session['access_token']));
        $this->assertStatus(401, $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => $user['password']]));
        $this->assertStatus(200, $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => 'Nouveau2026']));
    }

    public function testOtpCodeExpires(): void
    {
        $user = $this->createUser(['INFIRMIER']);
        $this->send('POST', '/auth/password/forgot', ['phone' => $user['phone']]);
        $code = (string) $this->sms->lastCodeFor($user['phone']);

        Clock::setTestNow(Clock::now()->modify('+6 minutes'));

        $this->assertStatus(422, $this->send('POST', '/auth/password/reset', ['phone' => $user['phone'], 'code' => $code, 'new_password' => 'Nouveau2026']));
    }

    public function testOtpCannotBeResentImmediately(): void
    {
        $user = $this->createUser(['INFIRMIER']);
        $this->assertStatus(200, $this->send('POST', '/auth/password/forgot', ['phone' => $user['phone']]));

        $again = $this->send('POST', '/auth/password/forgot', ['phone' => $user['phone']]);

        $this->assertStatus(429, $again);
        $this->assertCount(1, $this->sms->sent);
    }

    public function testSmsFailureIsReportedAsServiceUnavailable(): void
    {
        $user = $this->createUser(['INFIRMIER']);
        $this->sms->failing = true;

        $response = $this->send('POST', '/auth/password/forgot', ['phone' => $user['phone']]);

        $this->assertStatus(503, $response);
        $this->assertSame('SMS_UNAVAILABLE', $this->payload($response)['code']);
    }

    public function testRevokedDeviceLosesAccessAndCannotLogInAgain(): void
    {
        $user = $this->createUser(['INFIRMIER']);
        $lostPhone = $this->device();
        $lostSession = $this->login($user, $lostPhone);
        $currentSession = $this->login($user, $this->device());

        $devices = $this->payload($this->send('GET', '/me/devices', null, $currentSession['access_token']))['data'];
        $this->assertCount(2, $devices);

        $this->assertStatus(200, $this->send('DELETE', '/me/devices/' . $lostPhone['uuid'], null, $currentSession['access_token']));

        $this->assertStatus(401, $this->send('GET', '/me', null, $lostSession['access_token']));
        $this->assertStatus(401, $this->send('POST', '/auth/refresh', ['refresh_token' => $lostSession['refresh_token']]));
        $relogin = $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => $user['password'], 'device' => $lostPhone]);
        $this->assertSame('DEVICE_REVOKED', $this->payload($relogin)['code']);
    }

    public function testSharedDeviceSwitchClosesThePreviousUserSession(): void
    {
        $device = $this->device();
        $first = $this->login($this->createUser(['INFIRMIER']), $device);

        $this->login($this->createUser(['INFIRMIER']), $device);

        $this->assertStatus(401, $this->send('GET', '/me', null, $first['access_token']));
    }

    public function testInvalidDevicePayloadIsRejected(): void
    {
        $user = $this->createUser(['INFIRMIER']);

        $response = $this->send('POST', '/auth/login', [
            'phone' => $user['phone'],
            'password' => $user['password'],
            'device' => ['uuid' => 'pas-un-uuid', 'platform' => 'WINDOWS'],
        ]);

        $this->assertStatus(422, $response);
        $this->assertArrayHasKey('device.uuid', $this->payload($response)['errors']);
        $this->assertArrayHasKey('device.platform', $this->payload($response)['errors']);
    }

    public function testLoginAndFailuresAreAudited(): void
    {
        $user = $this->createUser(['MEDECIN']);
        $this->send('POST', '/auth/login', ['phone' => $user['phone'], 'password' => 'Mauvais123']);
        $this->login($user);

        $actions = array_column($this->db()->fetchAll(
            'SELECT action FROM audit_logs WHERE entity_uuid = ? ORDER BY id',
            [$user['uuid']]
        ), 'action');

        $this->assertSame(['LOGIN_FAILED', 'LOGIN'], $actions);
        $stored = (string) $this->db()->fetchValue('SELECT GROUP_CONCAT(COALESCE(new_values, "")) FROM audit_logs WHERE entity_uuid = ?', [$user['uuid']]);
        $this->assertStringNotContainsString('Mauvais123', $stored);
    }
}
