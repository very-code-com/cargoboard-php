<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `type` of a cost item in the price breakdown of a quotation or order.
 *
 * `SHIPMENT` is the base freight rate; everything else is a surcharge, a discount or an
 * internal margin line. Note that some of these never appear on a customer-facing breakdown
 * (`GENERAL_MARGIN`, `CUSTOMIZED_MARGIN`, `ACTUAL_COST_CORRECTION`), but the API schema lists
 * them, so they are kept here 1:1.
 */
enum CostItemType: string
{
    case DangerousGoods                     = 'DANGEROUS_GOODS';
    case PrivateCustomer                    = 'PRIVATE_CUSTOMER';
    case ImportDuties                       = 'IMPORT_DUTIES';
    case ContactBeforePickup                = 'CONTACT_BEFORE_PICKUP';
    case TailLiftTruck                      = 'TAIL_LIFT_TRUCK';
    case PremiumDelivery                    = 'PREMIUM_DELIVERY';
    case ContactBeforeDelivery              = 'CONTACT_BEFORE_DELIVERY';
    case PhoneCallFromDriverBeforeDelivery  = 'PHONE_CALL_FROM_DRIVER_BEFORE_DELIVERY';
    case PhoneCallFromDriverBeforePickup    = 'PHONE_CALL_FROM_DRIVER_BEFORE_PICKUP';
    case DeliveryWithoutConsigneePresence   = 'DELIVERY_WITHOUT_CONSIGNEE_PRESENCE';
    case TransportInsurance                 = 'TRANSPORT_INSURANCE';
    case ExportDeclaration                  = 'EXPORT_DECLARATION';
    case AdditionalProducts                 = 'ADDITIONAL_PRODUCTS';
    case PalletExchange                     = 'PALLET_EXCHANGE';
    case ClimateNeutralSurcharge            = 'CLIMATE_NEUTRAL_SURCHARGE';
    case Shipment                           = 'SHIPMENT';
    case GeneralMargin                      = 'GENERAL_MARGIN';
    case CustomizedMargin                   = 'CUSTOMIZED_MARGIN';
    case ActualCostCorrection               = 'ACTUAL_COST_CORRECTION';
    case FuelSurcharge                      = 'FUEL_SURCHARGE';
    case CustomerDiscount                   = 'CUSTOMER_DISCOUNT';
    case Coupon                             = 'COUPON';
}
