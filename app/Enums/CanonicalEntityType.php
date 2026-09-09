<?php

namespace App\Enums;

enum CanonicalEntityType: string
{
    case Person = 'person';
    case Organization = 'organization';
    case Project = 'project';
}
