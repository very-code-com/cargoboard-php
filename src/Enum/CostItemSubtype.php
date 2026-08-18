<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `subtype` of a cost item, narrowing a {@see CostItemType} down to the exact service billed
 * (which end of the transport the tail lift was needed at, which timed product was booked, ...).
 *
 * `DELIVERY_WHITOUT_CONSIGNEE_PRESENCE` is spelled exactly like that in Cargoboard's API: the
 * typo is theirs, and the value is kept verbatim so `tryFrom()` keeps working against live data.
 */
enum CostItemSubtype: string
{
    case PickupAdvise                       = 'PICKUP_ADVISE';
    case DeliveryAdvise                     = 'DELIVERY_ADVISE';
    case PhoneCallFromDriverBeforePickup    = 'PHONE_CALL_FROM_DRIVER_BEFORE_PICKUP';
    case PhoneCallFromDriverBeforeDelivery  = 'PHONE_CALL_FROM_DRIVER_BEFORE_DELIVERY';
    case TailLift                           = 'TAIL_LIFT';
    case TailLiftPickup                     = 'TAIL_LIFT_PICKUP';
    case TailLiftDelivery                   = 'TAIL_LIFT_DELIVERY';
    /** Cargoboard's own spelling ("WHITOUT"), kept verbatim. */
    case DeliveryWithoutConsigneePresence   = 'DELIVERY_WHITOUT_CONSIGNEE_PRESENCE';
    case PremiumDelivery                    = 'PREMIUM_DELIVERY';
    case PrivateConsignee                   = 'PRIVATE_CONSIGNEE';
    case Express16                          = 'EXPRESS_16';
    case Express12                          = 'EXPRESS_12';
    case Express10                          = 'EXPRESS_10';
    case Express8                           = 'EXPRESS_8';
    case Fix16                              = 'FIX_16';
    case Fix12                              = 'FIX_12';
    case Fix10                              = 'FIX_10';
    case Fix8                               = 'FIX_8';
    case PickupBefore12                     = 'PICKUP_BEFORE_12';
    case PickupAfter12                      = 'PICKUP_AFTER_12';
    case DeliveryBefore12                   = 'DELIVERY_BEFORE_12';
    case DeliveryAfter12                    = 'DELIVERY_AFTER_12';
    case ExportDeclaration                  = 'EXPORT_DECLARATION';
    case ImportDeclaration                  = 'IMPORT_DECLARATION';
}
