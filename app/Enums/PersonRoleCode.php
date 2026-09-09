<?php

namespace App\Enums;

enum PersonRoleCode: string
{
    case Employee = 'employee';
    case Client = 'client';
    case Partner = 'partner';
    case Contractor = 'contractor';
    case Supplier = 'supplier';
    case Advisor = 'advisor';
    case Lead = 'lead';
    case Investor = 'investor';
    case Media = 'media';
    case Model = 'model';
    case BrandContact = 'brand_contact';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
