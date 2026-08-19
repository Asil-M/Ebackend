<?php

namespace App\Enums;

enum UserRole: string
{
    case User = 'user';
    case SosTeam = 'sos_team';
    case DonationTeam = 'donation_team';
    case Admin = 'admin';
}
