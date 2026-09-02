<?php

namespace App\Enums;

enum DonationStatus: string
{
    case Pending = 'pending';
    case AwaitingReview = 'awaiting_review';
    case Matched = 'matched';
    case Accepted = 'accepted';
    case Failed = 'failed';
    case Expired = 'expired';
}
