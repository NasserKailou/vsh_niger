<?php

declare(strict_types=1);

namespace Vsh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vsh\Core\Migrations\SqlSplitter;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsStatementsOnSemicolons(): void
    {
        $statements = SqlSplitter::split("SELECT 1;\nSELECT 2;\n");

        $this->assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    public function testIgnoresSemicolonsInsideQuotesAndEscapedQuotes(): void
    {
        $sql = "INSERT INTO t VALUES ('a;b', 'l''accueil; ok', \"x;y\", 'c\\';d'); SELECT `a;b` FROM t";

        $statements = SqlSplitter::split($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString("'l''accueil; ok'", $statements[0]);
        $this->assertSame('SELECT `a;b` FROM t', $statements[1]);
    }

    public function testRemovesComments(): void
    {
        $sql = "-- commentaire ; ignoré\nSELECT 1; /* bloc ; ignoré */ SELECT 2; # fin ;\n";

        $statements = SqlSplitter::split($sql);

        $this->assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    public function testDoubleDashWithoutSpaceIsNotAComment(): void
    {
        $this->assertSame(['SELECT 5--2'], SqlSplitter::split('SELECT 5--2;'));
    }

    public function testEveryMigrationFileSplitsIntoOneStatementPerTableDefinition(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            $statements = SqlSplitter::split($sql);
            $this->assertSame(
                substr_count($sql, 'CREATE TABLE') + substr_count($sql, 'ALTER TABLE'),
                count($statements),
                basename($file)
            );
            foreach ($statements as $statement) {
                $this->assertMatchesRegularExpression('/^(CREATE|ALTER) TABLE /', $statement, basename($file));
            }
        }
    }
}
