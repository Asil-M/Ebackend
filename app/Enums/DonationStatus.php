<?php

namespace App\Enums;

enum DonationStatus: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case Accepted = 'accepted';
    case Failed = 'failed';
    case Expired = 'expired';
}
