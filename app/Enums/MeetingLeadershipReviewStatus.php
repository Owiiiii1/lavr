<?php

namespace App\Enums;

enum MeetingLeadershipReviewStatus: string
{
    case Skipped = 'skipped';
    case PendingSubject = 'pending_subject';
    case Completed = 'completed';
}
