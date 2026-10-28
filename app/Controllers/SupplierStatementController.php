<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Repositories\SupplierInvoiceRepository;
use App\Repositories\SupplierPaymentRepository;
use App\Repositories\SupplierRepository;

/**
 * Dettes fournisseurs : solde par fournisseur et par devise (on règle dans la devise facturée),
 * relevé par devise. Côté fournisseur : facture = crédit (on doit), paiement = débit.
 */
final class SupplierStatementController extends Controller
{
    public function __construct(
        private SupplierInvoiceRepository $invoices,
        private SupplierPaymentRepository $payments,
        private SupplierRepository $suppliers,
    ) {
    }

    public function index(Request $request): Response
    {
        $today = date('Y-m-d');
        $rows = [];
        $row = static function (array $source, string $currency) use (&$rows): string {
            $key = $source['supplier_id'] . '|' . $currency;
            $rows[$key] ??= [
                'supplier_id' => (int) $source['supplier_id'], 'supplier_name' => $source['supplier_name'], 'supplier_code' => $source['supplier_code'],
                'currency' => $currency, 'invoiced' => 0, 'paid' => 0, 'overdue' => 0, 'on_hold' => 0, 'balance_usd' => 0, 'next_due' => null,
            ];
            return $key;
        };
        foreach ($this->invoices->list() as $i) {
            if ($i['status'] === 'cancelled') {
                continue;
            }
            $k = $row($i, (string) $i['currency_code']);
            $rows[$k]['invoiced'] += (int) $i['total_minor'];
            $rows[$k]['balance_usd'] += (int) $i['balance_usd'];
            $rows[$k]['on_hold'] += $i['status'] === 'on_hold' ? 1 : 0;
            if ($i['balance'] > 0) {
                if ($i['due_date'] < $today) {
                    $rows[$k]['overdue'] += (int) $i['balance'];
                } elseif ($rows[$k]['next_due'] === null || $i['due_date'] < $rows[$k]['next_due']) {
                    $rows[$k]['next_due'] = $i['due_date'];
                }
            }
        }
        foreach ($this->payments->list() as $p) {
            $rows[$row($p, (string) $p['currency_code'])]['paid'] += (int) $p['amount_minor'];
        }
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => [$a['supplier_name'], $a['currency']] <=> [$b['supplier_name'], $b['currency']]);
        if ($request->str('format') === 'xlsx') {
            return $this->xlsx(t('nav.supplier_statements'), [
                ['label' => t('export.col.code')], ['label' => t('export.col.supplier'), 'width' => 28], ['label' => t('export.col.currency')],
                ['label' => t('export.col.invoiced'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.paid'), 'type' => Xlsx::MONEY, 'total' => false],
                ['label' => t('export.col.balance'), 'type' => Xlsx::MONEY, 'total' => false], ['label' => t('export.col.overdue'), 'type' => Xlsx::MONEY, 'total' => false],
                ['label' => t('export.col.balance_usd'), 'type' => Xlsx::MONEY], ['label' => t('export.col.next_due'), 'type' => Xlsx::DATE],
                ['label' => t('export.col.on_hold'), 'type' => Xlsx::INT],
            ], array_map(static fn (array $r): array => [
                $r['supplier_code'], $r['supplier_name'], $r['currency'], $r['invoiced'], $r['paid'], $r['invoiced'] - $r['paid'], $r['overdue'],
                $r['balance_usd'], $r['next_due'], $r['on_hold'],
            ], $rows));
        }
        return $this->view('supplier_statements/index', ['title' => t('supplier_statements.title'), 'rows' => $rows]);
    }

    public function show(Request $request, string $id): Response
    {
        $supplier = $this->suppliers->get((int) $id);
        $entries = [];
        foreach ($this->invoices->list(['supplier_id' => (int) $id]) as $i) {
            $cur = (string) $i['currency_code'];
            $entries[$cur][] = ['date' => (string) $i['invoice_date'], 'type' => 'supplier_invoice', 'reference' => $i['reference'] . ' (' . $i['supplier_invoice_number'] . ')',
                'id' => (int) $i['id'], 'debit' => 0, 'credit' => (int) $i['total_minor'], 'note' => (string) $i['due_date']];
            if ($i['status'] === 'cancelled') {
                $entries[$cur][] = ['date' => substr((string) $i['cancelled_at'], 0, 10), 'type' => 'supplier_cancellation', 'reference' => (string) $i['reference'],
                    'id' => (int) $i['id'], 'debit' => (int) $i['total_minor'], 'credit' => 0, 'note' => null];
            }
        }
        foreach ($this->payments->list(['supplier_id' => (int) $id]) as $p) {
            $entries[(string) $p['currency_code']][] = ['date' => (string) $p['paid_on'], 'type' => 'supplier_payment', 'reference' => (string) $p['reference'],
                'id' => (int) $p['id'], 'debit' => (int) $p['amount_minor'], 'credit' => 0, 'note' => $p['bank_reference']];
        }
        ksort($entries);
        $ledgers = [];
        foreach ($entries as $cur => $lines) {
            usort($lines, static fn (array $a, array $b): int => [$a['date'], $a['type'] === 'supplier_invoice' ? 0 : 1, $a['id']] <=> [$b['date'], $b['type'] === 'supplier_invoice' ? 0 : 1, $b['id']]);
            $running = 0;
            foreach ($lines as $n => $line) {
                $running += $line['credit'] - $line['debit'];
                $lines[$n]['balance'] = $running;
            }
            $ledgers[$cur] = [
                'lines' => $lines, 'from' => $lines[0]['date'], 'opening' => 0, 'closing' => $running,
                'debits' => array_sum(array_column($lines, 'debit')), 'credits' => array_sum(array_column($lines, 'credit')),
            ];
        }
        if ($request->str('format') === 'xlsx') {
            $title = t('statements.show_title', ['client' => $supplier['name']]);
            $book = new Xlsx();
            foreach ($ledgers as $cur => $ledger) {
                $book->sheet($cur, StatementController::ledgerColumns(), StatementController::ledgerRows($ledger['from'], 0, $ledger['lines']), self::exportMeta($title . ' — ' . $cur));
            }
            return self::workbook($title, $book);
        }
        return $this->view('supplier_statements/show', [
            'title' => t('statements.show_title', ['client' => $supplier['name']]),
            'supplier' => $supplier,
            'ledgers' => $ledgers,
            'open' => $this->invoices->list(['status' => 'open', 'supplier_id' => (int) $id]),
        ]);
    }
}
