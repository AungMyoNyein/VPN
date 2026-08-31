<?php

namespace App\Enums;

enum SubscriptionSource: string
{
    case Manual = 'MANUAL';
    case Crm = 'CRM';
    case Import = 'IMPORT';
    case PaymentGateway = 'PAYMENT_GATEWAY';
}
