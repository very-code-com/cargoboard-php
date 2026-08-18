<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/** `refundType` of an order: how much of the price was refunded, e.g. after a cancellation. */
enum RefundType: string
{
    case Full    = 'FULL';
    case Partial = 'PARTIAL';
    case None    = 'NO';
}
