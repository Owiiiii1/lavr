<?php

namespace App\Enums;

enum ProjectSourceType: string
{
    case IntegrationAccount = 'integration_account';
    case TelegramGroup = 'telegram_group';
    case Conversation = 'conversation';
    case StoredFile = 'stored_file';
    case Other = 'other';
}
