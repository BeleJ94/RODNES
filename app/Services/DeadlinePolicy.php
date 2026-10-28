<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Sales\Deadlines;

/**
 * Délais en vigueur : paramètres de la société, surchargés par client (délai de réponse RFQ, validité des devis).
 * La validité d'un devis reste en jours calendaires : c'est la date imprimée sur le devis.
 */
final class DeadlinePolicy
{
    public const SCHEDULES = [
        'quote' => 'quote_followup_days',
        'client_po' => 'client_po_followup_days',
        'supplier_confirm' => 'supplier_confirm_followup_days',
    ];

    /** @var array<int, array{rfq_response_days: ?int, quote_validity_days: ?int}> */
    private array $clients = [];

    public function __construct(private Settings $settings, private Database $db)
    {
    }

    public function mode(): string
    {
        return $this->settings->get('delay_mode') === Deadlines::CALENDAR ? Deadlines::CALENDAR : Deadlines::BUSINESS;
    }

    public function rfqResponseDays(int $clientId): int
    {
        return $this->client($clientId)['rfq_response_days'] ?? $this->settings->int('rfq_response_days');
    }

    public function quoteValidityDays(int $clientId): int
    {
        return $this->client($clientId)['quote_validity_days'] ?? $this->settings->int('quote_validity_days');
    }

    /** Date limite de réponse à une RFQ reçue ce jour-là (fin de journée). */
    public function rfqDueAt(int $clientId, string $receivedOn): string
    {
        return Deadlines::add($receivedOn, $this->rfqResponseDays($clientId), $this->mode()) . ' 17:00:00';
    }

    /** @return list<int> rythme de relance d'un type (quote, client_po, supplier_confirm) */
    public function schedule(string $kind): array
    {
        return Deadlines::parseSchedule($this->settings->get(self::SCHEDULES[$kind])) ?? [];
    }

    /** Vérification de la mise à disposition fournisseur : N jours avant la date prévue. */
    public function readyCheckOn(string $expectedReadyOn): string
    {
        $days = $this->settings->int('supplier_ready_check_days');
        $d = new \DateTimeImmutable($expectedReadyOn);
        if ($this->mode() === Deadlines::CALENDAR) {
            return $d->modify("-{$days} days")->format('Y-m-d');
        }
        while ($days > 0) {
            $d = $d->modify('-1 day');
            if ((int) $d->format('N') <= 5) {
                $days--;
            }
        }
        return $d->format('Y-m-d');
    }

    /** @return array{rfq_response_days: ?int, quote_validity_days: ?int} */
    private function client(int $clientId): array
    {
        if (!isset($this->clients[$clientId])) {
            $row = $this->db->fetchOne('SELECT rfq_response_days, quote_validity_days FROM clients WHERE id = :id', ['id' => $clientId]) ?? [];
            $this->clients[$clientId] = [
                'rfq_response_days' => isset($row['rfq_response_days']) ? (int) $row['rfq_response_days'] : null,
                'quote_validity_days' => isset($row['quote_validity_days']) ? (int) $row['quote_validity_days'] : null,
            ];
        }
        return $this->clients[$clientId];
    }
}
