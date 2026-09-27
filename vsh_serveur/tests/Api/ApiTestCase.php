<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use PHPUnit\Framework\TestCase;
use Vsh\Core\Container;
use Vsh\Core\Database;
use Vsh\Core\ErrorHandler;
use Vsh\Core\Http\Kernel;
use Vsh\Core\Http\Request;
use Vsh\Core\Http\Response;
use Vsh\Core\Logger;
use Vsh\Core\Security\PasswordPolicy;
use Vsh\Core\Sms\SmsGatewayInterface;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Modules\Users\RoleRepository;
use Vsh\Modules\Users\UserRepository;
use Vsh\Tests\Support\FakeSmsGateway;

/**
 * Base des tests d'API : application complète, base vsh_homecare_test, SMS simulés.
 */
abstract class ApiTestCase extends TestCase
{
    protected const PASSWORD = 'Motdepasse1';

    /** @var Container */
    protected $container;

    /** @var FakeSmsGateway */
    protected $sms;

    /** @var int */
    private static $phoneSequence = 0;

    protected function setUp(): void
    {
        $this->container = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $this->sms = new FakeSmsGateway();
        $this->container->instance(SmsGatewayInterface::class, $this->sms);
        $this->container->set(Logger::class, function (): Logger {
            return new Logger(sys_get_temp_dir() . '/vsh-api-tests', 'debug');
        });
        $this->container->set(ErrorHandler::class, function (Container $c): ErrorHandler {
            return new ErrorHandler($c->get(Logger::class), true);
        });
    }

    protected function tearDown(): void
    {
        Clock::setTestNow(null);
        restore_error_handler();
        // PHPUnit garde chaque test jusqu'à la fin de la suite : sans cela, chaque conteneur garderait
        // sa connexion ouverte et la suite dépasserait max_connections (151 par défaut).
        $this->container = null;
        gc_collect_cycles();
    }

    protected function send(string $method, string $path, ?array $json = null, ?string $token = null, array $headers = []): Response
    {
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $body = $json === null ? '' : (string) json_encode($json);
        $query = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $path = (string) parse_url($path, PHP_URL_PATH);
        $request = new Request($method, '/api/v1' . $path, $query, $headers, $body, ['REMOTE_ADDR' => '127.0.0.1']);
        return $this->container->get(Kernel::class)->handle($request);
    }

    protected function payload(Response $response): array
    {
        $payload = json_decode($response->body(), true);
        $this->assertIsArray($payload, 'Réponse JSON attendue : ' . $response->body());
        return $payload;
    }

    protected function assertStatus(int $expected, Response $response): void
    {
        $this->assertSame($expected, $response->status(), $response->body());
    }

    /**
     * Crée directement un compte en base.
     *
     * @param string[] $roles
     * @return array{id: int, uuid: string, phone: string, password: string}
     */
    protected function createUser(array $roles = ['ADMIN'], array $overrides = []): array
    {
        $phone = sprintf('+2279%07d', ++self::$phoneSequence + (getmypid() % 1000) * 10000);
        $uuid = Uuid::v4();
        $users = $this->container->get(UserRepository::class);
        $id = $users->create($overrides + [
            'uuid' => $uuid,
            'account_type' => 'STAFF',
            'phone' => $phone,
            'password_hash' => PasswordPolicy::hash(self::PASSWORD),
            'first_name' => 'Test',
            'last_name' => 'Utilisateur ' . self::$phoneSequence,
            'status' => 'ACTIVE',
        ]);
        $roleIds = $this->container->get(RoleRepository::class)->roleIds($roles);
        $users->replaceRoles($id, array_values($roleIds), null);
        $row = (array) $users->findById($id);
        return ['id' => $id, 'uuid' => (string) $row['uuid'], 'phone' => (string) $row['phone'], 'password' => self::PASSWORD];
    }

    /**
     * @return array Jetons (access_token, refresh_token…)
     */
    protected function login(array $user, ?array $device = null): array
    {
        $body = ['phone' => $user['phone'], 'password' => $user['password']];
        if ($device !== null) {
            $body['device'] = $device;
        }
        $response = $this->send('POST', '/auth/login', $body);
        $this->assertStatus(200, $response);
        return $this->payload($response)['data']['tokens'];
    }

    protected function device(): array
    {
        return ['uuid' => Uuid::v4(), 'platform' => 'ANDROID', 'name' => 'Téléphone de test', 'app_version' => '1.0.0'];
    }

    protected function db(): Database
    {
        return $this->container->get(Database::class);
    }
}
