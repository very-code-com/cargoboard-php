<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `status` of an order: the state of the *booking*, not of the physical shipment
 * (that one is {@see ShipmentStatus}).
 */
enum OrderStatus: string
{
    /** Created internally but not yet placed. */
    case Initialized = 'INITIALIZED';
    /** Placed successfully. */
    case Created = 'CREATED';
    /** Placed, waiting for payment to clear (relevant for prepayment methods). */
    case WaitingForPayment = 'WAITING_FOR_PAYMENT';
    /** Cancelled, see {@see \VeryCodeCom\Cargoboard\CargoboardClient::cancelOrder()}. */
    case Cancelled = 'CANCELLED';
}
