<?php

namespace App\Enums;

enum DirectoryRelationType: string
{
    case WorksFor = 'works_for';
    case Manages = 'manages';
    case ReportsTo = 'reports_to';
    case PartnerOf = 'partner_of';
    case ClientOf = 'client_of';
    case ContractorFor = 'contractor_for';
    case Represents = 'represents';
    case CollaboratesWith = 'collaborates_with';
    case AdvisorTo = 'advisor_to';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
