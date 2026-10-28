<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Domain\Logistics\ShipmentStatus;
use App\Domain\Orders\ClientOrderStatus;
use App\Domain\Orders\SupplierOrderStatus;
use App\Services\SupplierOrderService;
use App\Domain\Sales\QuotationStatus;
use App\Domain\Sales\RfqStatus;
use App\Domain\Sourcing\SupplierQuoteStatus;
use App\Security\Role;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Toute clé de traduction utilisée existe en FR et en EN ; les deux catalogues ont les mêmes clés. */
final class I18nKeysTest extends TestCase
{
    /** @var array<string, string> */
    private static array $fr;

    /** @var array<string, string> */
    private static array $en;

    public static function setUpBeforeClass(): void
    {
        self::$fr = require BASE_PATH . '/lang/fr.php';
        self::$en = require BASE_PATH . '/lang/en.php';
    }

    public function testCataloguesHaveTheSameKeys(): void
    {
        self::assertSame([], array_values(array_diff(array_keys(self::$fr), array_keys(self::$en))), 'Clés absentes de en.php');
        self::assertSame([], array_values(array_diff(array_keys(self::$en), array_keys(self::$fr))), 'Clés absentes de fr.php');
    }

    public function testLiteralKeysUsedInCodeExist(): void
    {
        // Clés littérales seulement ; les préfixes concaténés (t('unit.' . $u)) sont vérifiés par testDynamicKeysExist.
        $literal = "/\\bt\\('([a-z0-9_.]+)'(?!\\s*[.+])/";
        $keys = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app'));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $source = (string) file_get_contents($file->getPathname());
                preg_match_all($literal, $source, $m);
                array_push($keys, ...$m[1]);
                // Clés transmises comme valeur : RuleViolation('…'), flash('error', '…'), redirect(…, '…'), messages de transition.
                preg_match_all("/(?:RuleViolation\\(|flash\\('\\w+', |redirect\\([^;]*?, )'([a-z_]+\\.[a-z0-9_.]+)'(?!\\s*[.+])/", $source, $m);
                array_push($keys, ...$m[1]);
                preg_match_all("/'((?:quotations|rfqs|sourcing|common|settings|auth|client_orders|supplier_orders|shipments|files)\\.(?:flash|error)_[a-z_]+)'/", $source, $m);
                array_push($keys, ...$m[1]);
            }
        }
        preg_match_all("/'(?:label|section)' => '([a-z0-9_.]+)'/", (string) file_get_contents(BASE_PATH . '/config/menu.php'), $m);
        array_push($keys, ...$m[1]);
        preg_match_all($literal, (string) file_get_contents(BASE_PATH . '/public/assets/js/app.js'), $m);
        array_push($keys, ...$m[1]);

        $this->assertKeysExist(array_values(array_unique($keys)));
    }

    public function testDynamicKeysExist(): void
    {
        $keys = [];
        foreach ([...RfqStatus::cases(), ...QuotationStatus::cases(), ...SupplierQuoteStatus::cases(), ...Role::cases(),
            ...ClientOrderStatus::cases(), ...SupplierOrderStatus::cases(), ...ShipmentStatus::cases()] as $case) {
            $keys[] = $case->labelKey();
        }
        foreach (SupplierOrderService::ACTIONS as $action) {
            array_push($keys, "supplier_orders.flash_{$action}", "history.action.{$action}");
        }
        foreach (['closed', 'cancelled'] as $finish) {
            $keys[] = "client_orders.flash_{$finish}";
        }
        foreach (['created', 'milestone', 'pod', 'cancelled'] as $event) {
            $keys[] = "shipments.event.{$event}";
        }
        foreach (['forwarder', 'supplier'] as $carrier) {
            $keys[] = "shipments.carrier_type.{$carrier}";
        }
        $keys[] = 'history.action.pod';
        foreach (['image', 'pdf', 'document'] as $kind) {
            $keys[] = "preview.kind.{$kind}";
        }
        foreach (['client_orders.to_order', 'supplier_orders.drafts', 'supplier_orders.unconfirmed', 'shipments.late', 'shipments.pickup_overdue'] as $alert) {
            $keys[] = "alerts.{$alert}";
        }
        // Unités : libellés dans le référentiel (table units), pas dans les fichiers de langue.
        foreach (['clients', 'suppliers', 'items', 'units'] as $section) {
            array_push($keys, "{$section}.title", "{$section}.new", "{$section}.edit");
        }
        foreach (['rfqs.late', 'rfqs.due_soon', 'sourcing.pending', 'quotations.pending_approval', 'quotations.expiring', 'currencies.stale'] as $alert) {
            $keys[] = "alerts.{$alert}";
        }
        foreach (['created', 'updated', 'status', 'requested', 'offer_recorded', 'declined', 'send', 'approve', 'reject_approval', 'reopen', 'accept', 'decline', 'superseded'] as $action) {
            $keys[] = "history.action.{$action}";
        }
        foreach ([401, 403, 404, 405, 419, 500] as $status) {
            array_push($keys, "errors.{$status}.title", "errors.{$status}.text");
        }
        foreach (['search', 'lengthMenu', 'info', 'infoEmpty', 'infoFiltered', 'zeroRecords', 'emptyTable', 'processing', 'loadingRecords'] as $dt) {
            $keys[] = "dt.{$dt}";
        }
        $this->assertKeysExist($keys);
    }

    /** @param list<string> $keys */
    private function assertKeysExist(array $keys): void
    {
        self::assertNotEmpty($keys);
        $missing = array_values(array_filter($keys, static fn (string $k): bool => !isset(self::$fr[$k])));
        self::assertSame([], $missing, 'Clés de traduction manquantes');
    }
}
