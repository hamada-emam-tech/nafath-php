<?php

declare(strict_types=1);

namespace HamadaEmam\Nafath\Identity;

/**
 * What kind of identity document the person authenticated with.
 *
 * Nafath encodes this in the first digit of the identifier, and — importantly —
 * returns a COMPLETELY DIFFERENT set of attribute names for each. A mapper
 * written against the national-ID shape silently returns nulls for an Iqama
 * holder, which is a bug that only appears once a resident tries to sign up.
 */
enum UserType: string
{
    case National = 'national';   // 1… — Saudi citizen
    case Resident = 'resident';   // 2… — Iqama holder
    case Visitor  = 'visitor';    // 3–6… — Visa or Border number
    case Unknown  = 'unknown';

    public static function fromIdentifier(string $identifier): self
    {
        return match (substr(trim($identifier), 0, 1)) {
            '1'                     => self::National,
            '2'                     => self::Resident,
            '3', '4', '5', '6'      => self::Visitor,
            default                 => self::Unknown,
        };
    }
}
