<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/** `tariffCategory` of an order: Cargoboard's internal rate band for the lane. */
enum TariffCategory: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
}
