<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\Incoterm;
use VeryCodeCom\Cargoboard\Enum\Product;

/**
 * The request body of a quotation and of a booking - one DTO, because Cargoboard uses one
 * schema for both. In their words: *"The request body for quotation and booking requests is
 * completely identical. But for a quotation request, significantly less address information is
 * mandatory than for a booking request."*
 *
 * That means the natural workflow is to build this once and use it twice:
 *
 *   $request = new ShipmentRequest(Product::Standard, $shipper, $consignee, [$line]);
 *
 *   $quotation = $client->quote($request);        // price it
 *   $order     = $client->placeOrder($request);   // book it
 *
 * or, to guarantee the booking is at the quoted price, {@see \VeryCodeCom\Cargoboard\CargoboardClient::bookQuotation()}.
 *
 * The boolean options are all nullable on purpose: null means "do not send the field", letting
 * Cargoboard's own default apply (notably `wantsClimateContribution`, which defaults to **true**
 * on their side).
 *
 * @see https://docs.cargoboard.com/reference/place-a-quotation
 * @see https://docs.cargoboard.com/reference/place-an-order
 */
final class ShipmentRequest
{
    /**
     * @param list<Line>  $lines                    The goods; at least one line.
     * @param string|null $customerOrderCode        Your own order number; printed as CustomerOrderNo.
     * @param string|null $couponCode               Discount/action code.
     * @param bool|null   $wantsExportDeclaration   Cargoboard handles the customs export declaration.
     * @param bool|null   $wantsClimateContribution Climate contribution surcharge; Cargoboard defaults this to true.
     * @param bool|null   $wantsInsurance           Cargoboard arranges transport insurance; needs a goods value.
     * @param bool|null   $wantsNonTransshipment    Goods must not be reloaded en route.
     * @param bool|null   $wantsSignatureRequiredParcel Parcel only: signature on delivery.
     * @param bool|null   $wantsDirectDeliveryParcel    Parcel only: no rerouting to an alternative address.
     * @param float|null  $valueOfGoodsAmount       Goods value, for insurance and/or customs.
     * @param string|null $valueOfGoodsCurrency     Currency of the goods value; Cargoboard accepts EUR.
     * @param float|null  $valueOfGoodsAmountEur    The same value converted to EUR, when you send another currency.
     * @param float|null  $customsTariffQuantity    Customs tariff quantity.
     * @param bool|null   $isSupplyingCompanyOrReceivingCustomer Tax flag for shipments leaving the EU.
     */
    public function __construct(
        public readonly Product $product,
        public readonly Shipper $shipper,
        public readonly Consignee $consignee,
        public readonly array $lines,
        public readonly ?string $customerOrderCode = null,
        public readonly ?string $couponCode = null,
        public readonly ?bool $wantsExportDeclaration = null,
        public readonly ?bool $wantsClimateContribution = null,
        public readonly ?bool $wantsInsurance = null,
        public readonly ?bool $wantsNonTransshipment = null,
        public readonly ?bool $wantsSignatureRequiredParcel = null,
        public readonly ?bool $wantsDirectDeliveryParcel = null,
        public readonly ?float $valueOfGoodsAmount = null,
        public readonly ?string $valueOfGoodsCurrency = null,
        public readonly ?float $valueOfGoodsAmountEur = null,
        public readonly ?float $customsTariffQuantity = null,
        public readonly ?Incoterm $incoterm = null,
        public readonly ?bool $isSupplyingCompanyOrReceivingCustomer = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'product'                               => $this->product->value,
            'shipper'                               => $this->shipper->toArray(),
            'consignee'                             => $this->consignee->toArray(),
            'lines'                                 => array_map(static fn (Line $l): array => $l->toArray(), $this->lines),
            'customerOrderCode'                     => $this->customerOrderCode,
            'couponCode'                            => $this->couponCode,
            'wantsExportDeclaration'                => $this->wantsExportDeclaration,
            'wantsClimateContribution'              => $this->wantsClimateContribution,
            'wantsInsurance'                        => $this->wantsInsurance,
            'wantsNonTransshipment'                 => $this->wantsNonTransshipment,
            'wantsSignatureRequiredParcel'          => $this->wantsSignatureRequiredParcel,
            'wantsDirectDeliveryParcel'             => $this->wantsDirectDeliveryParcel,
            'valueOfGoodsAmount'                    => $this->valueOfGoodsAmount,
            'valueOfGoodsCurrency'                  => $this->valueOfGoodsCurrency,
            'valueOfGoodsAmountEur'                 => $this->valueOfGoodsAmountEur,
            'customsTariffQuantity'                 => $this->customsTariffQuantity,
            'incoterm'                              => $this->incoterm?->value,
            'isSupplyingCompanyOrReceivingCustomer' => $this->isSupplyingCompanyOrReceivingCustomer,
        ];

        return array_filter($data, static fn (mixed $v): bool => $v !== null);
    }

    /** Total gross weight of all lines, in kilograms. */
    public function totalWeightKg(): float
    {
        return array_sum(array_map(static fn (Line $l): float => $l->totalWeightKg(), $this->lines));
    }

    /** Total volume of all lines, in cubic metres. */
    public function totalVolumeM3(): float
    {
        return array_sum(array_map(static fn (Line $l): float => $l->totalVolumeM3(), $this->lines));
    }

    /** Total number of handling units across all lines. */
    public function totalUnits(): int
    {
        return array_sum(array_map(static fn (Line $l): int => $l->unitQuantity, $this->lines));
    }

    /** True when any line carries an ADR declaration. */
    public function hasDangerousGoods(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->hasDangerousGoods()) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the shipment crosses the EU customs border in either direction, i.e. when
     * customs handling (`incoterm` DAP, usually `wantsExportDeclaration`) is relevant.
     */
    public function crossesCustomsBorder(): bool
    {
        return $this->shipper->address->countryCode->isEuCustomsTerritory()
            !== $this->consignee->address->countryCode->isEuCustomsTerritory();
    }

    /** The same request with a different product, e.g. to price several products in a loop. */
    public function withProduct(Product $product): self
    {
        return new self(
            product:                               $product,
            shipper:                               $this->shipper,
            consignee:                             $this->consignee,
            lines:                                 $this->lines,
            customerOrderCode:                     $this->customerOrderCode,
            couponCode:                            $this->couponCode,
            wantsExportDeclaration:                $this->wantsExportDeclaration,
            wantsClimateContribution:              $this->wantsClimateContribution,
            wantsInsurance:                        $this->wantsInsurance,
            wantsNonTransshipment:                 $this->wantsNonTransshipment,
            wantsSignatureRequiredParcel:          $this->wantsSignatureRequiredParcel,
            wantsDirectDeliveryParcel:             $this->wantsDirectDeliveryParcel,
            valueOfGoodsAmount:                    $this->valueOfGoodsAmount,
            valueOfGoodsCurrency:                  $this->valueOfGoodsCurrency,
            valueOfGoodsAmountEur:                 $this->valueOfGoodsAmountEur,
            customsTariffQuantity:                 $this->customsTariffQuantity,
            incoterm:                              $this->incoterm,
            isSupplyingCompanyOrReceivingCustomer: $this->isSupplyingCompanyOrReceivingCustomer,
        );
    }

    /**
     * The same request with a different customer order code, for booking the same goods twice
     * under two of your own references.
     */
    public function withCustomerOrderCode(?string $customerOrderCode): self
    {
        return new self(
            product:                               $this->product,
            shipper:                               $this->shipper,
            consignee:                             $this->consignee,
            lines:                                 $this->lines,
            customerOrderCode:                     $customerOrderCode,
            couponCode:                            $this->couponCode,
            wantsExportDeclaration:                $this->wantsExportDeclaration,
            wantsClimateContribution:              $this->wantsClimateContribution,
            wantsInsurance:                        $this->wantsInsurance,
            wantsNonTransshipment:                 $this->wantsNonTransshipment,
            wantsSignatureRequiredParcel:          $this->wantsSignatureRequiredParcel,
            wantsDirectDeliveryParcel:             $this->wantsDirectDeliveryParcel,
            valueOfGoodsAmount:                    $this->valueOfGoodsAmount,
            valueOfGoodsCurrency:                  $this->valueOfGoodsCurrency,
            valueOfGoodsAmountEur:                 $this->valueOfGoodsAmountEur,
            customsTariffQuantity:                 $this->customsTariffQuantity,
            incoterm:                              $this->incoterm,
            isSupplyingCompanyOrReceivingCustomer: $this->isSupplyingCompanyOrReceivingCustomer,
        );
    }
}
