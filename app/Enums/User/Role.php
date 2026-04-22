<?php

declare(strict_types=1);

namespace App\Enums\User;

use App\Traits\EnumUtils;

enum Role: string
{
    use EnumUtils;

    case OWNER = 'OWNER';
    case PHARMACY_OWNER = 'PHARMACY_OWNER';
    case REORGANIZATION_OWNER = 'REORGANIZATION_OWNER';
    case ADMIN = 'ADMIN';
    case DOCTOR = 'DOCTOR';
    case HR = 'HR';
    case SPECIALIST = 'SPECIALIST';
    case ASSISTANT = 'ASSISTANT';
    case LABORANT = 'LABORANT';
    case RECEPTIONIST = 'RECEPTIONIST';
    case MED_ADMIN = 'MED_ADMIN';
    case MED_COORDINATOR = 'MED_COORDINATOR';
    case PHARMACIST = 'PHARMACIST';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => __('users.role.OWNER'),
            self::PHARMACY_OWNER => __('users.role.PHARMACY_OWNER'),
            self::REORGANIZATION_OWNER => __('users.role.REORGANIZATION_OWNER'),
            self::ADMIN => __('users.role.ADMIN'),
            self::DOCTOR => __('users.role.DOCTOR'),
            self::HR => __('users.role.HR'),
            self::SPECIALIST => __('users.role.SPECIALIST'),
            self::ASSISTANT => __('users.role.ASSISTANT'),
            self::LABORANT => __('users.role.LABORANT'),
            self::RECEPTIONIST => __('users.role.RECEPTIONIST'),
            self::MED_ADMIN => __('users.role.MED_ADMIN'),
            self::MED_COORDINATOR => __('users.role.MED_COORDINATOR'),
            self::PHARMACIST => __('users.role.PHARMACIST')
        };
    }
}
