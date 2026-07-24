<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Traits\EnumUtils;

/**
 * eHealth nhs_payment_method values (CONTRACT_PAYMENT_METHOD).
 */
enum PaymentMethod: string
{
    use EnumUtils;

    case FORWARD = 'FORWARD';
    case BACKWARD = 'BACKWARD';
    case PREPAYMENT = 'PREPAYMENT';
    case POSTPAYMENT = 'POSTPAYMENT';

    public function label(): string
    {
        return match ($this) {
            self::FORWARD => __('contracts.payment_methods.forward'),
            self::BACKWARD => __('contracts.payment_methods.backward'),
            self::PREPAYMENT => __('contracts.payment_methods.prepayment'),
            self::POSTPAYMENT => __('contracts.payment_methods.postpayment'),
        };
    }

    public static function resolveLabel(mixed $paymentMethod): string
    {
        if ($paymentMethod === null || $paymentMethod === '') {
            return '-';
        }

        if ($paymentMethod instanceof self) {
            return $paymentMethod->label();
        }

        $value = strtoupper((string) $paymentMethod);
        $method = self::tryFrom($value);

        return $method?->label() ?? (string) $paymentMethod;
    }
}
