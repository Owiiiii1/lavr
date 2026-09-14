<?php

namespace App\Enums;

enum BusinessMapStep: string
{
    case OwnerProfile = 'owner_profile';
    case BusinessContexts = 'business_contexts';
    case KeyPeople = 'key_people';
    case Responsibilities = 'responsibilities';
    case Sources = 'sources';
    case Mappings = 'mappings';
    case ExecutiveBrief = 'executive_brief';
    case Proactivity = 'proactivity';
    case Review = 'review';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return self::cases();
    }
}
