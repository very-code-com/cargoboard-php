<?php

/**
 * Example 05. Ship dangerous goods without your own ADR database.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - looking ADR master data up by UN number
 *   - turning that lookup straight into a shipment declaration
 *   - the 1000-points rule
 *   - why dangerous goods and parcel mode do not mix
 *
 * Run:
 *   CARGOBOARD_API_KEY=xxx php examples/05_dangerous_goods.php 3480
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\Cargoboard\CargoboardClient;
use VeryCodeCom\Cargoboard\Dto\Address;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\Line;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Exception\CargoboardAdrSyncPendingException;
use VeryCodeCom\Cargoboard\Exception\CargoboardNotFoundException;

$client = CargoboardClient::sandbox(getenv('CARGOBOARD_API_KEY') ?: 'your-api-key');

$unNumber = $argv[1] ?? '3480';   // 3480 = lithium-ion batteries

try {
    // -----------------------------------------------------------------------
    // 1. Look the substance up. This is the whole point of the endpoint: no
    //    local ADR table to maintain, and a clear error for an unknown number.
    // -----------------------------------------------------------------------
    $adr = $client->fetchAdrData($unNumber);

    echo "UN {$adr->unNo}: {$adr->substanceName}\n";
    echo "  Main hazard        : " . ($adr->riskMain ?? '-') . "\n";
    echo "  Sub-risks          : " . ($adr->subRisks === [] ? '-' : implode(', ', $adr->subRisks)) . "\n";
    echo "  Classification code: " . ($adr->classificationCode ?? '-') . "\n";
    echo "  Packing group      : " . ($adr->packagingGroup ?? '- (none for this entry)') . "\n";
    echo "  Transport category : " . ($adr->transportCategory ?? '-') . "\n";
    echo "  Tunnel restriction : " . ($adr->tunnelRestriction ?? '-') . "\n";
    echo "  LQ eligible        : " . var_export($adr->isLimitedQuantityEligible, true) . "\n";
    echo "  High consequence   : " . var_export($adr->isHighConsequencesDangerousGood, true) . "\n";

    if ($adr->packagingInstructionList() !== []) {
        echo "  Packing instr.     : " . implode(' ', $adr->packagingInstructionList()) . "\n";
    }
    if ($adr->hasSpecialProvision188()) {
        echo "  Special provision 188 applies (small lithium cells/batteries).\n";
    }

    // -----------------------------------------------------------------------
    // 2. Turn it into a declaration. Only the quantities are yours to supply;
    //    everything classification-related comes from the lookup, and the
    //    points total is computed from the transport-category multiplier.
    // -----------------------------------------------------------------------
    $declaration = $adr->toDangerousGood(
        quantity: 1,
        weightGross: 11.0,
        weightNetOrVolume: 9.0,
        weightNetOrVolumeUnit: 'NGW',
        packageType: 'Kiste',
        packageQuantity: 1,
    );

    echo "\nDeclaration\n";
    echo "  Points total : " . ($declaration->pointsTotal ?? '-') . "\n";
    echo "  Over 1000    : " . var_export($declaration->exceedsThousandPoints(), true) . "\n";
    echo "    (above 1000 points the shipment leaves the ADR 1.1.3.6 exemption\n";
    echo "     and needs a fully placarded vehicle, which is priced differently)\n";

    // -----------------------------------------------------------------------
    // 3. Attach it to a line and price the shipment.
    // -----------------------------------------------------------------------
    $request = new ShipmentRequest(
        product: Product::Standard,
        shipper: new Shipper(
            address: new Address('40239', CountryCode::DE, 'Düsseldorf', 'Examplestreet 12a'),
            name: 'Battery Producer GmbH',
            pickupOn: (new DateTimeImmutable('+2 weekdays'))->format('Y-m-d'),
        ),
        consignee: new Consignee(
            address: new Address('41061', CountryCode::DE, 'Mönchengladbach', 'Examplestreet 5'),
            name: 'Consignee ABC AG',
        ),
        lines: [
            new Line(
                content: 'Batteries',
                unitQuantity: 1,
                unitPackageType: PackageType::Carton,
                unitLength: 33,
                unitWidth: 31,
                unitHeight: 45,
                unitWeight: 11.0,
                isStackable: false,
                dangerousGoods: [$declaration],
            ),
        ],
    );

    echo "\nLocal validation: " . ($client->validateLocally($request) === [] ? 'OK' : 'failed') . "\n";

    $quotation = $client->quote($request);
    echo "Quoted at {$quotation->price} ({$quotation->runtime})\n";

    foreach ($quotation->costItems as $item) {
        printf("  %-34s %10s\n", $item->description, (string) $item->price);
    }

    // -----------------------------------------------------------------------
    // 4. The same shipment as a parcel is refused locally: Cargoboard does not
    //    carry dangerous goods on the parcel product, LQ and EQ included.
    // -----------------------------------------------------------------------
    $parcelErrors = $client->withParcelMode()->validateLocally($request);
    echo "\nAs a parcel shipment:\n";
    foreach ($parcelErrors as $error) {
        echo "  - {$error}\n";
    }
} catch (CargoboardAdrSyncPendingException $e) {
    // HTTP 202: Cargoboard has not cached this UN number yet and has queued a sync.
    // Common on the sandbox, whose ADR table is seeded lazily. Retry the same lookup.
    echo "UN {$e->unNumber}: ADR data is still being synchronised, retry in a moment.\n";
} catch (CargoboardNotFoundException) {
    echo "UN number \"{$unNumber}\" is not in Cargoboard's ADR data.\n";
} catch (CargoboardApiException $e) {
    echo "Cargoboard API error (HTTP {$e->statusCode}): {$e->getMessage()}\n";
}
