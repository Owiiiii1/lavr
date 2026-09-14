<?php

namespace App\Enums;

enum ProactiveAuditAction: string
{
    case Created = 'created';
    case Approved = 'approved';
    case Edited = 'edited';
    case Executed = 'executed';
    case Dismissed = 'dismissed';
    case Failed = 'failed';
    case AutoResolved = 'auto_resolved';
    case Snoozed = 'snoozed';
    case Superseded = 'superseded';
}
