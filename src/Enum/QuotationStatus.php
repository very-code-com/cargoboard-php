<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/** `status` of a quotation. A quotation turns into an order through `POST /v1/quotations/{id}/book`. */
enum QuotationStatus: string
{
    case Initialized = 'INITIALIZED';
    case Created     = 'CREATED';
    case Cancelled   = 'CANCELLED';
}
