<?php

namespace App\Enums;

enum OwnerContextItemStatus: string
{
    case Candidate = 'candidate';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
    case NeedsReview = 'needs_review';
}
