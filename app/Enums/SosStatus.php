<?php

namespace App\Enums;

enum SosStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
