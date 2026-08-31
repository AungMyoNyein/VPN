<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'CASH';
    case BankTransfer = 'BANK_TRANSFER';
    case KbzPay = 'KBZPAY';
    case WavePay = 'WAVEPAY';
    case Manual = 'MANUAL';
    case Other = 'OTHER';
}
