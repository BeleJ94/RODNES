<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Domain\Invoicing\Receivables;
use App\Repositories\ClientRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentRepository;
use App\Security\SecurityHeaders;
use App\Services\Settings;

/** Relevés de compte client : soldes, balance âgée, mouvements avec solde progressif sur une période. */
final class StatementController extends Controller
{
    public function __construct(
        private InvoiceRepository $invoices,
        private PaymentRepository $payments,
        private ClientRepository $clients,
        private Settings $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        $today = date('Y-m-d');
        $rows = [];
        $totals = array_fill_keys(Receivables::BUCKETS, 0);
        foreach ($this->invoices->clientBalances() as $client) {
            $open = $this->invoices->openForClient((int) $client['id']);
            $aging = Receivables::aging(array_map(static fn (array $i): array => ['due_date' => (string) $i['due_date'], 'balance' => (int) $i['balance']], $open), $today);
            foreach ($aging as $bucket => $amount) {
                $totals[$bucket] += $amount;
            }
            $rows[] = $client + ['aging' => $aging, 'balance' => (int) $client['invoiced'] - (int) $client['received'] - (int) $client['credited'], 'overdue' => array_sum($aging) - $aging['not_due']];
        }
        if ($request->str('format') === 'xlsx') {
            $aging = array_map(static fn (string $b): array => ['label' => t('aging.' . $b), 'type' => Xlsx::MONEY], Receivables::BUCKETS);
            return $this->xlsx(t('nav.statements'), [
                ['label' => t('export.col.code')], ['label' => t('export.col.client'), 'width' => 28],
                ['label' => t('export.col.invoiced'), 'type' => Xlsx::MONEY], ['label' => t('export.col.received'), 'type' => Xlsx::MONEY],
                ['label' => t('export.col.credited'), 'type' => Xlsx::MONEY], ['label' => t('export.col.balance'), 'type' => Xlsx::MONEY],
                ...$aging,
                ['label' => t('export.col.overdue'), 'type' => Xlsx::MONEY], ['label' => t('export.col.last_payment'), 'type' => Xlsx::DATE],
            ], array_map(static fn (array $r): array => [
                $r['code'], $r['name'], $r['invoiced'], $r['received'], $r['credited'], $r['balance'], ...array_values($r['aging']), $r['overdue'], $r['last_payment_on'],
            ], $rows));
        }
        return $this->view('statements/index', ['title' => t('statements.title'), 'rows' => $rows, 'totals' => $totals]);
    }

    public function show(Request $request, string $clientId): Response
    {
        $data = $this->statement($request, (int) $clientId);
        if ($request->str('format') === 'xlsx') {
            return $this->xlsx(t('statements.show_title', ['client' => $data['client']['name']]), self::ledgerColumns(),
                self::ledgerRows($data['from'], $data['opening'], $data['lines']), t('nav.statements'));
        }
        return $this->view('statements/show', $data);
    }

    public function print(Request $request, string $clientId): Response
    {
        $data = $this->statement($request, (int) $clientId);
        return SecurityHeaders::framableBySelf($this->view('statements/print', $data + [
            's' => $this->settings->all(),
            'back' => url('/statements/' . $clientId, array_filter(['from' => $data['from'], 'to' => $data['to']])),
            'embed' => $request->str('embed') === '1',
        ], 'print'));
    }

    /** @return array<string, mixed> */
    private function statement(Request $request, int $clientId): array
    {
        $client = $this->clients->get($clientId);
        $from = self::date($request->str('from')) ?? date('Y-01-01');
        $to = self::date($request->str('to')) ?? date('Y-m-d');
        $opening = 0;
        $lines = [];
        $running = 0;
        foreach ($this->invoices->statementEntries($clientId) as $entry) {
            if ($entry['date'] < $from) {
                $opening += $entry['debit'] - $entry['credit'];
                continue;
            }
            if ($entry['date'] > $to) {
                continue;
            }
            $lines[] = $entry;
        }
        $running = $opening;
        foreach ($lines as $i => $line) {
            $running += $line['debit'] - $line['credit'];
            $lines[$i]['balance'] = $running;
        }
        $open = $this->invoices->openForClient($clientId);
        return [
            'title' => t('statements.show_title', ['client' => $client['name']]),
            'client' => $client,
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'lines' => $lines,
            'closing' => $running,
            'debits' => array_sum(array_column($lines, 'debit')),
            'credits' => array_sum(array_column($lines, 'credit')),
            'open' => $open,
            'aging' => Receivables::aging(array_map(static fn (array $i): array => ['due_date' => (string) $i['due_date'], 'balance' => (int) $i['balance']], $open), date('Y-m-d')),
            'advances' => array_sum(array_map(static fn (array $p): int => (int) $p['amount_minor'] - (int) $p['allocated_minor'], $this->payments->unallocatedForClient($clientId))),
        ];
    }

    /** @return list<array{label: string, type?: string, width?: int}> */
    public static function ledgerColumns(): array
    {
        return [
            ['label' => t('statements.col.date'), 'type' => Xlsx::DATE], ['label' => t('statements.col.document')], ['label' => t('statements.col.detail'), 'width' => 30],
            ['label' => t('export.col.note'), 'width' => 24], ['label' => t('statements.col.debit'), 'type' => Xlsx::MONEY],
            ['label' => t('statements.col.credit'), 'type' => Xlsx::MONEY], ['label' => t('statements.col.balance'), 'type' => Xlsx::MONEY, 'total' => false],
        ];
    }

    /**
     * Écritures d'un relevé, précédées du solde d'ouverture.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<list<mixed>>
     */
    public static function ledgerRows(string $from, int $opening, array $lines): array
    {
        $rows = [[$from, null, t('statements.opening'), null, null, null, $opening]];
        foreach ($lines as $l) {
            $rows[] = [$l['date'], $l['reference'], t('statements.type.' . $l['type']), $l['note'], $l['debit'] ?: null, $l['credit'] ?: null, $l['balance']];
        }
        return $rows;
    }

    private static function date(string $value): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }
}
