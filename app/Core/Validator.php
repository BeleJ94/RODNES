<?php

declare(strict_types=1);

namespace App\Core;

use App\Domain\Shared\Fx;
use App\Domain\Shared\Milli;
use App\Domain\Shared\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Validation déclarative : ['email' => 'required|email|max:190', 'price' => 'required|money'].
 * validated() ne renvoie que les champs déclarés (pas d'affectation de masse), déjà typés :
 * int → int, money → centimes, percent → points de base, rate → millionièmes, qty → millièmes, bool → bool.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $clean = [];

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $rules
     */
    private function __construct(array $input, array $rules)
    {
        foreach ($rules as $field => $spec) {
            $this->check($field, $input[$field] ?? null, explode('|', $spec));
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $rules
     */
    public static function make(array $input, array $rules): self
    {
        return new self($input, $rules);
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, string> champ → message traduit */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->clean;
    }

    /** @param list<string> $rules */
    private function check(string $field, mixed $value, array $rules): void
    {
        if ($value !== null && !is_scalar($value)) {
            $this->errors[$field] = t('validation.invalid');
            return;
        }
        $value = is_string($value) ? trim($value) : $value;
        if ($value === null || $value === '') {
            if (in_array('bool', $rules, true)) {
                $this->clean[$field] = false;
            } elseif (in_array('required', $rules, true)) {
                $this->errors[$field] = t('validation.required');
            } else {
                $this->clean[$field] = null;
            }
            return;
        }
        $value = (string) $value;
        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $error = match ($name) {
                'required', 'nullable', 'string' => null,
                'bool' => in_array($value, ['0', '1', 'on', 'true'], true) ? null : 'validation.invalid',
                'int' => preg_match('/^-?\d{1,18}$/', $value) ? null : 'validation.int',
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : 'validation.email',
                'max' => mb_strlen($value) <= (int) $arg ? null : 'validation.max',
                'min' => mb_strlen($value) >= (int) $arg ? null : 'validation.min',
                'between' => self::between($value, (string) $arg) ? null : 'validation.between',
                'in' => in_array($value, explode(',', (string) $arg), true) ? null : 'validation.in',
                'date' => self::isDate($value, 'Y-m-d') ? null : 'validation.date',
                'datetime' => self::isDate($value, 'Y-m-d\TH:i') ? null : 'validation.date',
                'currency' => preg_match('/^[A-Z]{3}$/', $value) ? null : 'validation.currency',
                'money' => self::parses(static fn () => Money::parse($value)) ? null : 'validation.money',
                'percent' => self::parses(static fn () => Money::parse($value)) && Money::parse($value) < 10000 ? null : 'validation.percent',
                'rate' => self::parses(static fn () => Fx::parseRate($value)) ? null : 'validation.rate',
                'qty' => self::parses(static fn () => Milli::parse($value)) && Milli::parse($value) > 0 ? null : 'validation.qty',
                default => throw new InvalidArgumentException("Règle inconnue : {$name}"),
            };
            if ($error !== null) {
                $this->errors[$field] = t($error, ['n' => (string) $arg]);
                return;
            }
        }
        $this->clean[$field] = match (true) {
            in_array('bool', $rules, true) => $value !== '0',
            in_array('int', $rules, true) => (int) $value,
            in_array('money', $rules, true), in_array('percent', $rules, true) => Money::parse($value),
            in_array('rate', $rules, true) => Fx::parseRate($value),
            in_array('qty', $rules, true) => Milli::parse($value),
            default => $value,
        };
    }

    private static function isDate(string $value, string $format): bool
    {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        return $date !== false && $date->format($format) === $value;
    }

    /** « between:0,365 » pour un entier. */
    private static function between(string $value, string $range): bool
    {
        [$min, $max] = array_map('intval', explode(',', $range, 2));
        return preg_match('/^\d{1,9}$/', $value) === 1 && (int) $value >= $min && (int) $value <= $max;
    }

    private static function parses(callable $parse): bool
    {
        try {
            $parse();
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
