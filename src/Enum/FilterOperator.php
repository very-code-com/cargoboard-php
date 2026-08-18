<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `filterOperator` query parameter of the list endpoints: how several `filter` expressions
 * are combined. Defaults to `AND` on Cargoboard's side.
 */
enum FilterOperator: string
{
    case And = 'AND';
    case Or  = 'OR';
}
