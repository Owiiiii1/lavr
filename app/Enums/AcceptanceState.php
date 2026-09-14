<?php

namespace App\Enums;

enum AcceptanceState: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case NotValidated = 'not_validated';
}
