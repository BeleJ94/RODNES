<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testSplitIgnoresCommentsAndKeepsMultiLineStatements(): void
    {
        $sql = "-- en-tête ; ignoré\nCREATE TABLE a (\n  id INT -- commentaire\n);\n\nALTER TABLE a ADD b INT;\n";
        self::assertSame(["CREATE TABLE a (\n  id INT -- commentaire\n)", 'ALTER TABLE a ADD b INT'], Migrator::split($sql));
    }

    public function testEveryMigrationFileFollowsTheConventions(): void
    {
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertMatchesRegularExpression('/^\d{4}_[a-z0-9_]+\.sql$/', basename($file));
            foreach (Migrator::split((string) file_get_contents($file)) as $statement) {
                self::assertMatchesRegularExpression('/^(CREATE|ALTER|INSERT|UPDATE|DROP)\s/', $statement, basename($file));
                self::assertStringNotContainsString(';', preg_replace("/'[^']*'/", '', $statement) ?? '', basename($file) . ' : un « ; » hors fin de ligne casserait le découpage');
            }
        }
    }

    /** Montants, quantités et taux : toujours des entiers 64 bits, jamais de flottant ni de DECIMAL arrondi. */
    public function testMoneyQuantityAndRateColumnsAreIntegers(): void
    {
        $sql = implode("\n", array_map('file_get_contents', glob(BASE_PATH . '/database/migrations/*.sql') ?: []));
        preg_match_all('/^\s+(\w+_(?:minor|milli|micro))\s+(\w+)/m', $sql, $m, PREG_SET_ORDER);
        self::assertNotEmpty($m);
        foreach ($m as [, $column, $type]) {
            self::assertSame('BIGINT', $type, "{$column} doit être un BIGINT");
        }
        self::assertDoesNotMatchRegularExpression('/\b(FLOAT|DOUBLE)\b/i', $sql);
    }

    /** Chaque valeur d'énumération PHP tient dans la colonne qui la stocke (dernière définition, MODIFY compris). */
    public function testEnumValuesFitTheirColumns(): void
    {
        $columns = [
            'rfqs.status' => \App\Domain\Sales\RfqStatus::class,
            'quotations.status' => \App\Domain\Sales\QuotationStatus::class,
            'supplier_quotes.status' => \App\Domain\Sourcing\SupplierQuoteStatus::class,
            'client_orders.status' => \App\Domain\Orders\ClientOrderStatus::class,
            'supplier_orders.status' => \App\Domain\Orders\SupplierOrderStatus::class,
            'shipments.status' => \App\Domain\Logistics\ShipmentStatus::class,
            'client_invoices.status' => \App\Domain\Invoicing\InvoiceStatus::class,
            'supplier_invoices.status' => \App\Domain\Purchasing\SupplierInvoiceStatus::class,
            'users.role' => \App\Security\Role::class,
        ];
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        sort($files);
        $widths = [];
        foreach ($files as $file) {
            foreach (Migrator::split((string) file_get_contents($file)) as $statement) {
                if (preg_match('/^CREATE TABLE (\w+) \((.*)\) ENGINE/s', $statement, $t)) {
                    preg_match_all('/^\s+(\w+) VARCHAR\((\d+)\)/m', $t[2], $cols, PREG_SET_ORDER);
                    foreach ($cols as [, $col, $width]) {
                        $widths[$t[1] . '.' . $col] = (int) $width;
                    }
                } elseif (preg_match('/^ALTER TABLE (\w+) MODIFY (\w+) VARCHAR\((\d+)\)/', $statement, $m)) {
                    $widths[$m[1] . '.' . $m[2]] = (int) $m[3];
                }
            }
        }
        foreach ($columns as $column => $enum) {
            self::assertArrayHasKey($column, $widths);
            foreach ($enum::cases() as $case) {
                self::assertLessThanOrEqual($widths[$column], strlen($case->value), "{$column} : « {$case->value} » dépasse VARCHAR({$widths[$column]})");
            }
        }
    }

    public function testEveryTableIsInnoDbUtf8mb4(): void
    {
        $sql = implode("\n", array_map('file_get_contents', glob(BASE_PATH . '/database/migrations/*.sql') ?: []));
        $created = preg_match_all('/CREATE TABLE \w+/', $sql);
        $conforming = preg_match_all('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/', $sql);
        self::assertSame($created, $conforming);
    }
}
