<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Core\Push\PushGatewayInterface;
use Vsh\Core\Support\Clock;
use Vsh\Modules\Notifications\NotificationDispatcher;
use Vsh\Modules\Notifications\NotificationService;
use Vsh\Modules\Settings\SettingsService;
use Vsh\Tests\Support\FakePushGateway;

/**
 * Étape 13 (D-011) : jetons push, file d'envoi push et SMS, SMS en repli, nouvelles tentatives.
 */
final class NotificationDeliveryTest extends ApiTestCase
{
    /** @var FakePushGateway */
    private $push;

    /** @var array<string,string|null> Valeurs des paramètres avant le test */
    private $savedSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->push = new FakePushGateway();
        $this->container->instance(PushGatewayInterface::class, $this->push);
        foreach (['notifications.push_enabled', 'notifications.sms_mode', 'notifications.sms_types'] as $key) {
            $this->savedSettings[$key] = $this->db()->fetchValue('SELECT value FROM settings WHERE setting_key = ?', [$key]);
        }
        $this->setting('notifications.push_enabled', 'true');
        $this->setting('notifications.sms_mode', 'FALLBACK');
        $this->setting('notifications.sms_types', '["APPOINTMENT_STATUS","HOMECARE_STATUS"]');
        // Envois laissés en file par les autres tests : hors du périmètre de celui-ci.
        $this->db()->execute("UPDATE notification_deliveries SET status = 'SKIPPED' WHERE status = 'PENDING'");
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $value) {
            $this->setting($key, $value);
        }
        parent::tearDown();
    }

    public function testPushTokenRequiresAMobileDeviceSession(): void
    {
        $token = $this->login($this->createUser(['INFIRMIER']))['access_token'];
        $response = $this->send('POST', '/push-tokens', ['token' => $this->fcmToken()], $token);
        $this->assertStatus(409, $response);
        $this->assertSame('DEVICE_REQUIRED', $this->payload($response)['code']);
    }

    public function testPushTokenIsRejectedWhenMalformed(): void
    {
        $token = $this->login($this->createUser(['INFIRMIER']), $this->device())['access_token'];
        $this->assertStatus(422, $this->send('POST', '/push-tokens', ['token' => 'court'], $token));
        $this->assertStatus(422, $this->send('POST', '/push-tokens', ['token' => str_repeat('a', 30) . ' <script>'], $token));
    }

    public function testPushTokenFollowsTheDeviceAndIsRemovedAtLogout(): void
    {
        $first = $this->createUser(['INFIRMIER']);
        $second = $this->createUser(['INFIRMIER']);
        $fcm = $this->fcmToken();

        $firstSession = $this->login($first, $this->device())['access_token'];
        $response = $this->send('POST', '/push-tokens', ['token' => $fcm], $firstSession);
        $this->assertStatus(200, $response);
        $this->assertTrue($this->payload($response)['data']['push_enabled']);
        // Renouvellement du même jeton : pas de doublon.
        $this->assertStatus(200, $this->send('POST', '/push-tokens', ['token' => $fcm], $firstSession));
        $this->assertSame(1, $this->tokenCount($first['id']));

        // Le téléphone passe à un autre utilisateur : le jeton ne sert plus au premier.
        $secondSession = $this->login($second, $this->device())['access_token'];
        $this->assertStatus(200, $this->send('POST', '/push-tokens', ['token' => $fcm], $secondSession));
        $this->assertSame(0, $this->tokenCount($first['id']));
        $this->assertSame(1, $this->tokenCount($second['id']));

        $this->assertStatus(200, $this->send('POST', '/auth/logout', null, $secondSession));
        $this->assertSame(0, $this->tokenCount($second['id']));
    }

    public function testUnregisterDisablesPushForTheDevice(): void
    {
        $user = $this->createUser(['INFIRMIER']);
        $session = $this->login($user, $this->device())['access_token'];
        $this->send('POST', '/push-tokens', ['token' => $this->fcmToken()], $session);
        $this->assertStatus(200, $this->send('DELETE', '/push-tokens', null, $session));
        $this->assertSame(0, $this->tokenCount($user['id']));
    }

    public function testStaffNotificationIsPushedAndNeverSentBySms(): void
    {
        $nurse = $this->createUser(['INFIRMIER']);
        $fcm = $this->registerDevice($nurse);

        $this->notifications()->notify($nurse['id'], 'HOMECARE_ASSIGNED', 'Nouvelle visite à domicile', 'Une visite à domicile a été affectée à votre équipe.', 'homecare_request', '11111111-1111-4111-8111-111111111111');
        $this->assertSame(['PUSH'], $this->channels($nurse['id']));

        $counts = $this->dispatcher()->dispatch();
        $this->assertSame(1, $counts['sent']);
        $this->assertCount(1, $this->push->sent);
        $sent = $this->push->sent[0];
        $this->assertSame($fcm, $sent['token']);
        $this->assertSame('Nouvelle visite à domicile', $sent['message']->title());
        $this->assertSame('HOMECARE_ASSIGNED', $sent['message']->data()['type']);
        $this->assertSame('homecare_request', $sent['message']->data()['entity_type']);
        $this->assertArrayHasKey('notification_id', $sent['message']->data());
        $this->assertSame([], $this->sms->sent);
        $this->assertNotNull($this->db()->fetchValue('SELECT pushed_at FROM notifications WHERE user_id = ?', [$nurse['id']]));

        // Déjà envoyé : rien au passage suivant.
        $this->assertSame(0, array_sum($this->dispatcher()->dispatch()));
    }

    public function testPatientWithoutAppReceivesAnSms(): void
    {
        $patient = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->notifications()->notify($patient['id'], 'APPOINTMENT_STATUS', 'Rendez-vous confirmé', 'Votre rendez-vous du 12/10 à 09:00 est confirmé.', 'appointment', null);
        $this->assertSame(['SMS'], $this->channels($patient['id']));

        $this->assertSame(1, $this->dispatcher()->dispatch()['sent']);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame($patient['phone'], $this->sms->sent[0]['to']);
        $this->assertStringEndsWith(' : Votre rendez-vous du 12/10 à 09:00 est confirmé.', $this->sms->sent[0]['message']);
    }

    public function testSmsIsOnlyAFallbackWhenThePushArrives(): void
    {
        $patient = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->registerDevice($patient);
        $this->notifications()->notify($patient['id'], 'HOMECARE_STATUS', 'Visite à domicile', 'L\'équipe de soins est en route.', 'homecare_request', null);
        $this->assertSame(['PUSH', 'SMS'], $this->channels($patient['id']));

        $counts = $this->dispatcher()->dispatch();
        $this->assertSame(['sent' => 1, 'skipped' => 1, 'retry' => 0, 'failed' => 0], $counts);
        $this->assertCount(1, $this->push->sent);
        $this->assertSame([], $this->sms->sent);
        $this->assertSame('Reçue par push.', $this->deliveryError($patient['id'], 'SMS'));
    }

    public function testAlwaysModeSendsBothPushAndSms(): void
    {
        $this->setting('notifications.sms_mode', 'ALWAYS');
        $patient = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->registerDevice($patient);
        $this->notifications()->notify($patient['id'], 'HOMECARE_STATUS', 'Visite à domicile', 'L\'équipe de soins est en route.');

        $this->assertSame(2, $this->dispatcher()->dispatch()['sent']);
        $this->assertCount(1, $this->push->sent);
        $this->assertCount(1, $this->sms->sent);
    }

    public function testSmsWaitsForPushRetriesThenTakesOverWhenPushFails(): void
    {
        $patient = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $fcm = $this->registerDevice($patient);
        $this->push->responses[$fcm] = 'FAIL';
        $this->notifications()->notify($patient['id'], 'APPOINTMENT_STATUS', 'Rendez-vous déplacé', 'Votre rendez-vous est déplacé.');

        $start = Clock::now();
        $counts = $this->dispatcher()->dispatch();
        $this->assertSame(2, $counts['retry'], 'Push à retenter, SMS en attente de son issue');
        $this->assertSame([], $this->sms->sent);
        $this->assertSame(0, (int) $this->db()->fetchValue(
            "SELECT d.attempts FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id WHERE n.user_id = ? AND d.channel = 'SMS'",
            [$patient['id']]
        ), 'Attendre le push ne consomme pas de tentative SMS');

        // Quatre nouveaux échecs (délais 1, 5, 15, 60 min) : le push est abandonné, le SMS part.
        foreach ([2, 8, 25, 90] as $minutes) {
            Clock::setTestNow($start->modify('+' . $minutes . ' minutes'));
            $this->dispatcher()->dispatch();
        }
        $this->assertSame('FAILED', $this->deliveryStatus($patient['id'], 'PUSH'));
        $this->assertSame('SENT', $this->deliveryStatus($patient['id'], 'SMS'));
        $this->assertCount(1, $this->sms->sent);
    }

    public function testInvalidTokenIsRemovedAndTheSmsTakesOver(): void
    {
        $patient = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $fcm = $this->registerDevice($patient);
        $this->push->responses[$fcm] = PushGatewayInterface::INVALID_TOKEN;
        $this->notifications()->notify($patient['id'], 'APPOINTMENT_STATUS', 'Rendez-vous annulé', 'Votre rendez-vous a été annulé par la clinique.');

        $this->dispatcher()->dispatch();
        $this->assertSame(0, $this->tokenCount($patient['id']));
        $this->assertSame('SKIPPED', $this->deliveryStatus($patient['id'], 'PUSH'));
        $this->assertCount(1, $this->sms->sent);
    }

    public function testReadOrExpiredNotificationsAreNotSent(): void
    {
        $reader = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->notifications()->notify($reader['id'], 'APPOINTMENT_STATUS', 'Rendez-vous confirmé', 'Votre rendez-vous est confirmé.');
        $this->notifications()->markAllRead($reader['id']);

        $late = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        Clock::setTestNow(Clock::now()->modify('-13 hours'));
        $this->notifications()->notify($late['id'], 'HOMECARE_STATUS', 'Visite à domicile', 'L\'équipe de soins est en route.');
        Clock::setTestNow(null);

        $this->assertSame(2, $this->dispatcher()->dispatch()['skipped']);
        $this->assertSame([], $this->sms->sent);
        $this->assertStringStartsWith('Déjà lue', (string) $this->deliveryError($reader['id'], 'SMS'));
        $this->assertStringStartsWith('Expirée', (string) $this->deliveryError($late['id'], 'SMS'));
    }

    public function testNoSmsForUnselectedTypesOrWhenDisabled(): void
    {
        $patient = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->notifications()->notify($patient['id'], 'INVOICE_ISSUED', 'Nouvelle facture', 'Une facture est disponible dans votre dossier.');
        $this->assertSame([], $this->channels($patient['id']));

        $this->setting('notifications.sms_mode', 'OFF');
        $other = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->notifications()->notify($other['id'], 'APPOINTMENT_STATUS', 'Rendez-vous confirmé', 'Votre rendez-vous est confirmé.');
        $this->assertSame([], $this->channels($other['id']));
    }

    public function testNothingIsQueuedWhenTheBusinessActionIsRolledBack(): void
    {
        $patient = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        try {
            $this->db()->transaction(function () use ($patient): void {
                $this->notifications()->notify($patient['id'], 'APPOINTMENT_STATUS', 'Rendez-vous confirmé', 'Votre rendez-vous est confirmé.');
                throw new \RuntimeException('Échec de l\'action métier');
            });
        } catch (\RuntimeException $exception) {
        }
        $this->assertSame([], $this->channels($patient['id']));
    }

    public function testSmsSettingsAreValidated(): void
    {
        $admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->assertStatus(422, $this->send('PUT', '/settings', ['values' => ['notifications.sms_mode' => 'SOMETIMES']], $admin));
        $this->assertStatus(422, $this->send('PUT', '/settings', ['values' => ['notifications.sms_types' => ['HOMECARE_ASSIGNED']]], $admin));
        $this->assertStatus(200, $this->send('PUT', '/settings', ['values' => ['notifications.sms_types' => ['EXAM_RESULT'], 'notifications.sms_mode' => 'ALWAYS']], $admin));
    }

    public function testSupervisionShowsVolumesAndProblemsWithoutContent(): void
    {
        $patient = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->sms->failing = true;
        $this->notifications()->notify($patient['id'], 'APPOINTMENT_STATUS', 'Rendez-vous confirmé', 'Votre rendez-vous est confirmé.');
        $this->assertSame(1, $this->dispatcher()->dispatch()['retry']);

        $nurse = $this->login($this->createUser(['INFIRMIER']))['access_token'];
        $this->assertStatus(403, $this->send('GET', '/notification-deliveries', null, $nurse));

        $admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $response = $this->send('GET', '/notification-deliveries', null, $admin);
        $this->assertStatus(200, $response);
        $data = $this->payload($response)['data'];
        $this->assertSame('FALLBACK', $data['sms']['mode']);
        $this->assertGreaterThanOrEqual(1, $data['pending']);
        $retried = array_filter($data['problems'], function (array $problem): bool {
            return $problem['channel'] === 'SMS' && $problem['status'] === 'PENDING' && $problem['error'] === 'Passerelle indisponible (test).';
        });
        $this->assertNotEmpty($retried);
        $this->assertStringNotContainsString('Votre rendez-vous', $response->body());
        $this->assertStringNotContainsString($patient['phone'], $response->body());
    }

    private function notifications(): NotificationService
    {
        return $this->container->get(NotificationService::class);
    }

    private function dispatcher(): NotificationDispatcher
    {
        return $this->container->get(NotificationDispatcher::class);
    }

    private function setting(string $key, ?string $value): void
    {
        $this->db()->execute('UPDATE settings SET value = ? WHERE setting_key = ?', [$value, $key]);
        $this->container->get(SettingsService::class)->refresh();
    }

    private function fcmToken(): string
    {
        return 'fcm-' . bin2hex(random_bytes(16)) . ':APA91b' . bin2hex(random_bytes(20));
    }

    private function registerDevice(array $user): string
    {
        $fcm = $this->fcmToken();
        $session = $this->login($user, $this->device())['access_token'];
        $this->assertStatus(200, $this->send('POST', '/push-tokens', ['token' => $fcm], $session));
        return $fcm;
    }

    private function tokenCount(int $userId): int
    {
        return (int) $this->db()->fetchValue('SELECT COUNT(*) FROM push_tokens WHERE user_id = ?', [$userId]);
    }

    /**
     * @return string[]
     */
    private function channels(int $userId): array
    {
        return array_column($this->db()->fetchAll(
            'SELECT d.channel FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id WHERE n.user_id = ? ORDER BY d.channel',
            [$userId]
        ), 'channel');
    }

    private function deliveryStatus(int $userId, string $channel): ?string
    {
        $value = $this->db()->fetchValue(
            'SELECT d.status FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id WHERE n.user_id = ? AND d.channel = ?',
            [$userId, $channel]
        );
        return $value === null ? null : (string) $value;
    }

    private function deliveryError(int $userId, string $channel): ?string
    {
        $value = $this->db()->fetchValue(
            'SELECT d.last_error FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id WHERE n.user_id = ? AND d.channel = ?',
            [$userId, $channel]
        );
        return $value === null ? null : (string) $value;
    }
}
