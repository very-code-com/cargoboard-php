<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `paymentMethod` of an order.
 *
 * API-key integrations are invoiced accounts, so orders placed through this library normally
 * come back as `INVOICE`; the other values exist for orders placed through Cargoboard's web
 * checkout on the same customer account.
 */
enum PaymentMethod: string
{
    case Invoice      = 'INVOICE';
    case Sepa         = 'SEPA';
    case PayPal       = 'PAY_PAL';
    case Giropay      = 'GIROPAY';
    case CreditCard   = 'CREDIT_CARD';
    case DirectDebit  = 'DIRECT_DEBIT';
    case Prepayment   = 'PREPAYMENT';
}
