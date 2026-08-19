<?php

namespace App\Enums;

enum MatchStatus: string
{
    case Matched = 'matched';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
