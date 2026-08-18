<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/** Thrown on network-level failures: cURL errors, DNS problems, SSL failures, timeouts. */
class CargoboardTransportException extends CargoboardException
{
}
