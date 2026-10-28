<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use InvalidArgumentException;

/** Règles des créances clients : ancienneté des échéances et répartition d'un encaissement. */
final class Receivables
{
    public const BUCKETS = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    /** Tranche d'ancienneté d'une échéance (jours de retard à la date donnée). */
    public static function bucket(string $dueDate, string $onDate): string
    {
        $late = (int) ((strtotime($onDate . ' 00:00:00') - strtotime($dueDate . ' 00:00:00')) / 86400);
        return match (true) {
            $late <= 0 => 'not_due',
            $late <= 30 => 'd1_30',
            $late <= 60 => 'd31_60',
            $late <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /**
     * Balance âgée : montants restant dus par tranche.
     *
     * @param list<array{due_date: string, balance: int}> $invoices
     * @return array<string, int>
     */
    public static function aging(array $invoices, string $onDate): array
    {
        $out = array_fill_keys(self::BUCKETS, 0);
        foreach ($invoices as $invoice) {
            $out[self::bucket($invoice['due_date'], $onDate)] += $invoice['balance'];
        }
        return $out;
    }

    /**
     * Proposition d'affectation d'un encaissement : échéances les plus anciennes d'abord.
     *
     * @param list<array{id: int, due_date: string, balance: int}> $invoices
     * @return array<int, int> facture → montant affecté (seuls les montants > 0)
     */
    public static function allocateOldestFirst(int $amount, array $invoices): array
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Montant négatif.');
        }
        usort($invoices, static fn (array $a, array $b): int => [$a['due_date'], $a['id']] <=> [$b['due_date'], $b['id']]);
        $plan = [];
        foreach ($invoices as $invoice) {
            if ($amount === 0) {
                break;
            }
            $part = min($amount, $invoice['balance']);
            if ($part > 0) {
                $plan[$invoice['id']] = $part;
                $amount -= $part;
            }
        }
        return $plan;
    }

    /**
     * Contrôle d'une affectation saisie : chaque part ≤ reste dû, total ≤ montant encaissé.
     *
     * @param array<int, int> $allocations facture → montant
     * @param array<int, int> $balances facture → reste dû
     * @return string|null clé d'erreur, null si valide
     */
    public static function checkAllocation(int $paymentAmount, array $allocations, array $balances): ?string
    {
        foreach ($allocations as $invoiceId => $part) {
            if ($part < 0 || !isset($balances[$invoiceId])) {
                return 'payments.error_invoice';
            }
            if ($part > $balances[$invoiceId]) {
                return 'payments.error_over_balance';
            }
        }
        return array_sum($allocations) > $paymentAmount ? 'payments.error_over_amount' : null;
    }
}
