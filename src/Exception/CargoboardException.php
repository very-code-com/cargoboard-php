<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Base exception for all Cargoboard errors.
 *
 * Every exception can optionally carry the raw JSON body Cargoboard returned. This is populated
 * by the client when debugging is enabled (see {@see \VeryCodeCom\Cargoboard\CargoboardConfig::$debug})
 * so that unexpected or unrecognised errors can be inspected verbatim, together with a full
 * stack trace, via {@see self::getDebugReport()}.
 */
class CargoboardException extends \RuntimeException
{
    /** Raw Cargoboard response body associated with this error, if captured. */
    protected ?string $rawResponse = null;

    /** The raw Cargoboard response body that triggered this error, or null. */
    public function getRawResponse(): ?string
    {
        return $this->rawResponse;
    }

    /**
     * Attach the raw Cargoboard response body to this exception (fluent).
     *
     * @return $this
     */
    public function withRawResponse(?string $rawResponse): static
    {
        $this->rawResponse = $rawResponse;
        return $this;
    }

    /**
     * Build a verbose debug report: exception class + message, the raw Cargoboard response
     * (when captured) and the full stack trace. Intended for logs or developer-facing output
     * when an unexpected error needs investigation.
     */
    public function getDebugReport(): string
    {
        $report = sprintf('%s: %s', static::class, $this->getMessage());

        if ($this->rawResponse !== null && $this->rawResponse !== '') {
            $report .= "\n\n--- Raw Cargoboard response ---\n" . $this->rawResponse;
        }

        if ($this->getPrevious() !== null) {
            $report .= "\n\n--- Caused by ---\n"
                . get_class($this->getPrevious()) . ': ' . $this->getPrevious()->getMessage();
        }

        $report .= "\n\n--- Stack trace ---\n" . $this->getTraceAsString();

        return $report;
    }
}
