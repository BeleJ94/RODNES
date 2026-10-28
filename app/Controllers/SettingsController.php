<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\CurrencyRepository;
use App\Security\Auth;
use App\Domain\Sales\Deadlines;
use App\Services\ActivityLogger;
use App\Services\DeadlinePolicy;
use App\Services\Settings;

final class SettingsController extends Controller
{
    public function __construct(
        private Settings $settings,
        private CurrencyRepository $currencies,
        private Database $db,
        private ActivityLogger $logger,
        private Auth $auth,
    ) {
    }

    public function company(Request $request): Response
    {
        return $this->view('settings/company', ['title' => t('settings.company_title'), 's' => $this->settings->all(), 'logo' => company_logo_data_uri()]);
    }

    public function saveCompany(Request $request): Response
    {
        $validator = Validator::make($request->body(), [
            'company_name' => 'required|max:160',
            'company_rccm' => 'nullable|max:64',
            'company_nif' => 'nullable|max:64',
            'company_idnat' => 'nullable|max:64',
            'company_address' => 'nullable|max:255',
            'company_phone' => 'nullable|max:64',
            'company_email' => 'nullable|email|max:190',
            'company_bank' => 'nullable|max:500',
            'company_website' => 'nullable|max:120',
            'quote_certification' => 'nullable|max:600',
            'quote_payment_basis' => 'required|in:delivery,invoice',
            'vat_rate_bp' => 'required|percent',
            'default_margin_bp' => 'required|percent',
            'quote_validity_days' => 'required|int|between:1,365',
            'margin_approval_enabled' => 'bool',
            'margin_approval_threshold_bp' => 'required|percent',
            'sourcing_reminder_days' => 'required|int|between:1,60',
            'supplier_price_tolerance_bp' => 'required|percent',
            'delay_mode' => 'required|in:business,calendar',
            'rfq_response_days' => 'required|int|between:1,60',
            'quote_followup_days' => 'nullable|max:40',
            'client_po_followup_days' => 'nullable|max:40',
            'supplier_confirm_followup_days' => 'nullable|max:40',
            'supplier_ready_check_days' => 'required|int|between:0,30',
        ]);
        $values = $validator->validated();
        $errors = $validator->errors();
        // Rythmes de relance : « 5, 10 » → jours croissants, enregistrés sous forme normalisée.
        foreach (DeadlinePolicy::SCHEDULES as $field) {
            $days = Deadlines::parseSchedule($request->str($field));
            if ($days === null) {
                $errors[$field] = t('settings.schedule_invalid');
            } else {
                $values[$field] = implode(', ', $days);
            }
        }
        // Logo : image remplacée ou retirée ; type vérifié par le contenu (FileStorage).
        $logo = $request->file('company_logo_file');
        if ($logo !== null && $errors === []) {
            try {
                $values['company_logo'] = (string) app(\App\Services\FileStorage::class)->store($logo, ['image/png', 'image/jpeg', 'image/webp'])['id'];
            } catch (\App\Domain\Shared\RuleViolation $e) {
                $errors['company_logo_file'] = t($e->key);
            }
        } elseif ($request->str('company_logo_remove') === '1') {
            $values['company_logo'] = '';
        }
        if ($errors !== []) {
            return $this->invalid('/settings/company', $request, $errors);
        }
        $this->settings->save($values);
        return $this->redirect('/settings/company', 'common.flash_saved');
    }

    public function currencies(Request $request): Response
    {
        return $this->view('settings/currencies', [
            'title' => t('settings.currencies_title'),
            'currencies' => $this->currencies->all(),
            'latest' => $this->currencies->latestRates(),
            'history' => $this->currencies->history(),
        ]);
    }

    public function saveRate(Request $request): Response
    {
        $validator = Validator::make($request->body(), [
            'currency_code' => 'required|currency',
            'rate_date' => 'required|date',
            'rate' => 'required|rate',
        ]);
        $data = $validator->validated();
        $errors = $validator->errors();
        if ($validator->passes() && ($data['currency_code'] === 'USD' || !in_array($data['currency_code'], $this->currencies->activeCodes(), true))) {
            $errors['currency_code'] = t('validation.in');
        }
        if ($errors !== []) {
            return $this->invalid('/settings/currencies', $request, $errors);
        }
        $this->db->transaction(function () use ($data): void {
            $this->currencies->saveRate($data['currency_code'], $data['rate_date'], $data['rate'], $this->auth->user()?->id);
            $this->logger->log('exchange_rate', null, 'saved', [], $data);
        });
        return $this->redirect('/settings/currencies', 'settings.flash_rate_saved');
    }
}
