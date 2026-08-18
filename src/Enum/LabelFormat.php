<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `format` query parameter of `GET /v1/orders/{id}/print-shipment-labels`.
 *
 * A4 puts several labels on one sheet (for a normal office printer); A6 emits one label per
 * page, which is what label printers expect. Both return a single PDF stream.
 */
enum LabelFormat: string
{
    case A4 = 'A4';
    case A6 = 'A6';
}
