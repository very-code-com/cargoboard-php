<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

/**
 * An estimated time window from a tracking event: collection or delivery, `from` to `until`.
 *
 * Cargoboard reports these on the status feed and refines them as the transport progresses, so
 * the newest event that carries one holds the current estimate. Either end can be missing: an
 * event may narrow only the start of a window.
 *
 * This is deliberately not {@see DeliveryWindow}, which is the window Cargoboard *commits to*
 * when it prices an order. This one is an estimate that moves.
 */
final class TrackingWindow
{
    public function __construct(
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $until = null,
    ) {
    }

    /**
     * Build a window, or null when neither end is known - which is what callers want, so that
     * "no estimate" is a null window rather than an empty one they have to test for.
     */
    public static function of(?\DateTimeImmutable $from, ?\DateTimeImmutable $until): ?self
    {
        return $from === null && $until === null ? null : new self($from, $until);
    }

    /** True when both ends are known and fall on the same calendar day. */
    public function isSingleDay(): bool
    {
        return $this->from !== null
            && $this->until !== null
            && $this->from->format('Y-m-d') === $this->until->format('Y-m-d');
    }

    /** True when only one end of the window is known. */
    public function isOpenEnded(): bool
    {
        return $this->from === null || $this->until === null;
    }

    /**
     * A human-readable rendering, in the day-first format Cargoboard's own German-language
     * portal uses:
     *
     *   same day       18.08.2026 07:00-15:00
     *   across days    19.08.2026 06:00 - 21.08.2026 14:00
     *   one end only   from 18.08.2026 07:00
     *
     * Timestamps arrive in UTC. Pass the timezone you display in, or the window is rendered
     * exactly as the API sent it.
     */
    public function format(?\DateTimeZone $timezone = null): string
    {
        $from  = $this->shift($this->from, $timezone);
        $until = $this->shift($this->until, $timezone);

        if ($from !== null && $until !== null) {
            // Compared after the timezone shift, not before: 22:00-23:00 UTC is one day there
            // and the next day in Berlin, and the reader cares about the day they see.
            return $from->format('Y-m-d') === $until->format('Y-m-d')
                ? $from->format('d.m.Y H:i') . '-' . $until->format('H:i')
                : $from->format('d.m.Y H:i') . ' - ' . $until->format('d.m.Y H:i');
        }
        if ($from !== null) {
            return 'from ' . $from->format('d.m.Y H:i');
        }
        if ($until !== null) {
            return 'until ' . $until->format('d.m.Y H:i');
        }

        return '';
    }

    private function shift(?\DateTimeImmutable $value, ?\DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if ($value === null || $timezone === null) {
            return $value;
        }

        return $value->setTimezone($timezone);
    }
}
