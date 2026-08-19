<?php

namespace App\Enums;

enum DonationResponseStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
