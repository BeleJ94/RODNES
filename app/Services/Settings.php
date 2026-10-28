<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Paramètres de la société (table settings). Toute clé absente prend sa valeur par défaut. */
final class Settings
{
    public const DEFAULTS = [
        'company_name' => 'Ma société SARL',
        'company_rccm' => '',
        'company_nif' => '',
        'company_idnat' => '',
        'company_address' => '',
        'company_phone' => '',
        'company_email' => '',
        'company_bank' => '',
        'company_website' => '',
        'company_logo' => '',
        'quote_certification' => '',
        'quote_payment_basis' => 'delivery',
        'vat_rate_bp' => '1600',
        'default_margin_bp' => '2000',
        'quote_validity_days' => '30',
        'margin_approval_enabled' => '0',
        'margin_approval_threshold_bp' => '1500',
        'sourcing_reminder_days' => '3',
        'supplier_price_tolerance_bp' => '0',
        // Délais et relances : jours ouvrés (business) ou calendaires (calendar) ; rythmes = jours après le départ.
        'delay_mode' => 'business',
        'rfq_response_days' => '3',
        'quote_followup_days' => '5,10',
        'client_po_followup_days' => '3,7',
        'supplier_confirm_followup_days' => '2,4',
        'supplier_ready_check_days' => '3',
    ];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private Database $db, private ActivityLogger $logger)
    {
    }

    public function get(string $name): string
    {
        return $this->all()[$name] ?? '';
    }

    public function int(string $name): int
    {
        return (int) $this->get($name);
    }

    public function bool(string $name): bool
    {
        return $this->get($name) === '1';
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->values === null) {
            $rows = $this->db->fetchAll('SELECT name, value FROM settings');
            $this->values = array_column($rows, 'value', 'name') + self::DEFAULTS;
        }
        return $this->values;
    }

    /** @param array<string, scalar|null> $values clés inconnues ignorées */
    public function save(array $values): void
    {
        $before = $this->all();
        $after = $before;
        $this->db->transaction(function () use ($values, &$after): void {
            foreach ($values as $name => $value) {
                if (!array_key_exists($name, self::DEFAULTS)) {
                    continue;
                }
                $value = is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');
                $after[$name] = $value;
                $this->db->run(
                    'INSERT INTO settings (name, value) VALUES (:n, :v) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                    ['n' => $name, 'v' => $value],
                );
            }
            $this->logger->log('settings', null, 'updated', $this->values ?? [], $after);
        });
        $this->values = $after;
    }
}
