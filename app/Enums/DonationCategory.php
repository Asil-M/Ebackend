<?php

namespace App\Enums;

enum DonationCategory: string
{
    case Blood = 'blood';
    case Money = 'money';
    case Clothes = 'clothes';
    case Food = 'food';
    case Medicine = 'medicine';
    case Other = 'other';
}
