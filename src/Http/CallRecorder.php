<?php

declare(strict_types=1);

namespace HamadaEmamTech\Nafath\Http;

/**
 * Receives one entry per Nafath call, for your audit trail.
 *
 * Separate from a PSR-3 logger on purpose. A log line is for a human reading an
 * incident; this is a durable record you can query — "how many verifications
 * failed last week, and with which Nafath codes". The `reference` on each entry
 * is Nafath's own trace number, which is what makes their support useful.
 *
 * Implementations MUST NOT throw. Auditing that breaks a verification is worse
 * than no auditing.
 */
interface CallRecorder
{
    /**
     * @param array{
     *   endpoint:string, method:string, http_status:?int, nafath_code:?string,
     *   reference:?int, outcome:string, duration_ms:int, environment:string
     * } $entry
     */
    public function record(array $entry): void;
}
