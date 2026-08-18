<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Thrown when a Cargoboard response cannot be interpreted: malformed JSON, a JSON body that is
 * not an object, or a success response missing its `data` envelope.
 */
class CargoboardResponseParseException extends CargoboardException
{
}
