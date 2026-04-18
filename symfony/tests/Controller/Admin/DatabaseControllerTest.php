<?php

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\DatabaseController;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du DatabaseController — couverture sécurité.
 *
 * On utilise la Reflection pour accéder aux méthodes et constantes privées
 * sans passer par le kernel Symfony (pas de BDD, pas de container).
 */
class DatabaseControllerTest extends TestCase
{
    private DatabaseController $controller;
    private Connection&MockObject $db;
    private EntityManagerInterface&MockObject $em;
    private \ReflectionClass $ref;

    protected function setUp(): void
    {
        $this->em  = $this->createMock(EntityManagerInterface::class);
        $this->db  = $this->createMock(Connection::class);
        $this->ref = new \ReflectionClass(DatabaseController::class);

        $this->controller = $this->ref->newInstanceWithoutConstructor();

        // Injecter les propriétés readonly via Reflection
        $this->setProp('em', $this->em);
        $this->setProp('db', $this->db);
    }

    // =========================================================================
    // DDL_PATTERN — blocage des instructions dangereuses
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\DataProvider('ddlBlockedProvider')]
    public function testDdlPatternBlocksDangerousKeywords(string $sql): void
    {
        $pattern = $this->ref->getConstant('DDL_PATTERN');
        $this->assertMatchesRegularExpression($pattern, $sql, "Devrait bloquer : {$sql}");
    }

    public static function ddlBlockedProvider(): array
    {
        return [
            'DROP TABLE'             => ['DROP TABLE infrastructure_metric'],
            'DROP minuscule'         => ['drop table user'],
            'TRUNCATE'               => ['TRUNCATE infrastructure_alert'],
            'ALTER TABLE'            => ['ALTER TABLE user ADD COLUMN foo INT'],
            'CREATE TABLE'           => ['CREATE TABLE evil (id INT)'],
            'CREATE DATABASE'        => ['CREATE DATABASE attaque'],
            'RENAME TABLE'           => ['RENAME TABLE user TO admins'],
            'GRANT'                  => ['GRANT ALL ON *.* TO hacker'],
            'REVOKE'                 => ['REVOKE SELECT ON ih_argos FROM user'],
            'LOAD DATA INFILE'       => ['LOAD DATA INFILE "/etc/passwd" INTO TABLE user'],
            'INTO OUTFILE'           => ["SELECT * FROM user INTO OUTFILE '/tmp/dump.csv'"],
            'DROP dans sous-requête' => ["SELECT * FROM (DROP TABLE user) t"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ddlAllowedProvider')]
    public function testDdlPatternAllowsLegitimateQueries(string $sql): void
    {
        $pattern = $this->ref->getConstant('DDL_PATTERN');
        $this->assertDoesNotMatchRegularExpression($pattern, $sql, "Ne devrait pas bloquer : {$sql}");
    }

    public static function ddlAllowedProvider(): array
    {
        return [
            'SELECT simple'       => ['SELECT * FROM user'],
            'SELECT avec WHERE'   => ['SELECT id, email FROM user WHERE id = 1'],
            'INSERT'              => ["INSERT INTO user (email) VALUES ('test@test.com')"],
            'UPDATE'              => ["UPDATE user SET email = 'new@test.com' WHERE id = 1"],
            'DELETE avec WHERE'   => ['DELETE FROM infrastructure_alert WHERE resolved_at IS NOT NULL'],
            'SELECT avec JOIN'    => ['SELECT a.*, d.hostname FROM infrastructure_alert a JOIN infrastructure_device d ON a.device_id = d.id'],
            'SELECT GROUP BY'     => ['SELECT name, COUNT(*) FROM infrastructure_metric GROUP BY name'],
        ];
    }

    // =========================================================================
    // maskSensitiveColumns — masquage des colonnes sensibles
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveColumnProvider')]
    public function testMaskSensitiveColumnsMasksKnownColumns(string $colName): void
    {
        $rows = [
            [$colName => 'valeur_secrete', 'id' => 1, 'email' => 'test@test.com'],
        ];

        $result = $this->callPrivate('maskSensitiveColumns', [$rows]);

        $this->assertSame('••••••••', $result[0][$colName], "La colonne '{$colName}' doit être masquée");
        $this->assertSame(1, $result[0]['id'], "L'id ne doit pas être masqué");
        $this->assertSame('test@test.com', $result[0]['email'], "L'email ne doit pas être masqué");
    }

    public static function sensitiveColumnProvider(): array
    {
        return [
            'password'    => ['password'],
            'passwd'      => ['passwd'],
            'secret'      => ['secret'],
            'token'       => ['token'],
            'api_key'     => ['api_key'],
            'private_key' => ['private_key'],
        ];
    }

    public function testMaskSensitiveColumnsPreservesNonSensitiveData(): void
    {
        $rows = [
            ['id' => 1, 'email' => 'user@test.com', 'roles' => '["ROLE_ADMIN"]', 'created_at' => '2025-01-01'],
            ['id' => 2, 'email' => 'other@test.com', 'roles' => '["ROLE_USER"]',  'created_at' => '2025-02-01'],
        ];

        $result = $this->callPrivate('maskSensitiveColumns', [$rows]);

        $this->assertSame($rows, $result, "Les colonnes non sensibles ne doivent pas être modifiées");
    }

    public function testMaskSensitiveColumnsHandlesNullValue(): void
    {
        $rows = [['password' => null, 'id' => 1]];

        $result = $this->callPrivate('maskSensitiveColumns', [$rows]);

        // null reste null (pas de données à masquer)
        $this->assertNull($result[0]['password']);
    }

    public function testMaskSensitiveColumnsHandlesEmptyArray(): void
    {
        $result = $this->callPrivate('maskSensitiveColumns', [[]]);
        $this->assertSame([], $result);
    }

    // =========================================================================
    // parseBack — prévention d'injection dans le redirect
    // =========================================================================

    public function testParseBackOnlyKeepsSafeParams(): void
    {
        $back = 'filter%5Bemail%5D=test&sort=id&dir=DESC&page=2&evil=hack&redirect=http%3A%2F%2Fmalicious.com';
        $result = $this->callPrivate('parseBack', [urldecode($back)]);

        $this->assertArrayHasKey('filter', $result);
        $this->assertArrayHasKey('sort',   $result);
        $this->assertArrayHasKey('dir',    $result);
        $this->assertArrayHasKey('page',   $result);
        $this->assertArrayNotHasKey('evil',     $result, "Param inconnu doit être filtré");
        $this->assertArrayNotHasKey('redirect', $result, "Param redirect doit être filtré (open redirect)");
    }

    public function testParseBackWithEmptyStringReturnsEmptyArray(): void
    {
        $result = $this->callPrivate('parseBack', ['']);
        $this->assertSame([], $result);
    }

    public function testParseBackPreservesFilterArray(): void
    {
        $back = http_build_query(['filter' => ['email' => 'test', 'id' => '5'], 'sort' => 'created_at', 'dir' => 'DESC']);
        $result = $this->callPrivate('parseBack', [$back]);

        $this->assertSame(['email' => 'test', 'id' => '5'], $result['filter']);
        $this->assertSame('created_at', $result['sort']);
        $this->assertSame('DESC', $result['dir']);
    }

    // =========================================================================
    // assertTableAllowed — validation des noms de tables
    // =========================================================================

    public function testAssertTableAllowedThrowsForUnknownTable(): void
    {
        $this->db->method('fetchFirstColumn')->willReturn(['user', 'infrastructure_metric']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->callPrivate('assertTableAllowed', ['evil_table']);
    }

    public function testAssertTableAllowedPassesForKnownTable(): void
    {
        $this->db->method('fetchFirstColumn')->willReturn(['user', 'infrastructure_metric', 'infrastructure_alert']);

        // Ne doit pas lever d'exception
        $this->callPrivate('assertTableAllowed', ['infrastructure_alert']);
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function callPrivate(string $method, array $args = []): mixed
    {
        return $this->ref->getMethod($method)->invokeArgs($this->controller, $args);
    }

    private function setProp(string $name, mixed $value): void
    {
        $this->ref->getProperty($name)->setValue($this->controller, $value);
    }
}
