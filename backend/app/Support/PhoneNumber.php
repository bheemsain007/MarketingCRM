<?php

namespace App\Support;

/**
 * Phone normalisation to E.164 (BR-DUP-01).
 *
 * This is the identity key for a lead, so it must be deterministic: the same
 * human phone number typed five different ways has to produce one string, or
 * duplicate detection silently fails and the same person gets called by three
 * telecallers.
 *
 * Handles the formats Indian data actually arrives in:
 *   9876543210          -> +919876543210
 *   09876543210         -> +919876543210
 *   919876543210        -> +919876543210
 *   +91 98765-43210     -> +919876543210
 *   91-9876 543 210     -> +919876543210
 *
 * Deliberately NOT a full libphonenumber implementation. If the CRM ever sells
 * outside India, swap this for giggsey/libphonenumber-for-php - the interface is
 * designed so nothing else changes.
 */
class PhoneNumber
{
    private const DEFAULT_COUNTRY_CODE = '91';

    /** Indian mobile numbers are 10 digits starting 6-9. */
    private const INDIAN_MOBILE_PATTERN = '/^[6-9]\d{9}$/';

    /**
     * Returns the E.164 form, or null when the input cannot be a valid number.
     *
     * Returning null rather than throwing is intentional: imports must be able
     * to mark a row invalid and carry on rather than aborting the batch.
     */
    public static function normalise(?string $input, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $hadPlus = str_starts_with(trim($input), '+');

        // Strip everything that is not a digit: spaces, dashes, brackets, dots.
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if ($digits === '') {
            return null;
        }

        // Leading zero is a domestic trunk prefix, not part of the number.
        $digits = ltrim($digits, '0');

        // Already carries the country code.
        if (str_starts_with($digits, $countryCode) && strlen($digits) === strlen($countryCode) + 10) {
            $national = substr($digits, strlen($countryCode));

            return self::isValidNational($national) ? '+'.$countryCode.$national : null;
        }

        // Bare national number.
        if (strlen($digits) === 10) {
            return self::isValidNational($digits) ? '+'.$countryCode.$digits : null;
        }

        // An explicit + with some other country code: keep it, but only if the
        // length is plausible for E.164 (max 15 digits).
        if ($hadPlus && strlen($digits) >= 8 && strlen($digits) <= 15) {
            return '+'.$digits;
        }

        return null;
    }

    /** True when the input can be normalised to a usable number. */
    public static function isValid(?string $input): bool
    {
        return self::normalise($input) !== null;
    }

    /**
     * Display form for the UI: +91 98765 43210.
     * Storage always stays E.164 - this is presentation only.
     */
    public static function format(?string $e164): ?string
    {
        if ($e164 === null || ! str_starts_with($e164, '+'.self::DEFAULT_COUNTRY_CODE)) {
            return $e164;
        }

        $national = substr($e164, 3);

        return strlen($national) === 10
            ? '+'.self::DEFAULT_COUNTRY_CODE.' '.substr($national, 0, 5).' '.substr($national, 5)
            : $e164;
    }

    /**
     * The bare national number, e.g. +919876543210 -> 9876543210.
     *
     * Indian SMS aggregators take a 10-digit number, not E.164, so storage and
     * the wire format genuinely differ. Returns null for a non-Indian number
     * rather than guessing at a length - a truncated number reaches somebody
     * else.
     */
    public static function national(?string $e164, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        if ($e164 === null || ! str_starts_with($e164, '+'.$countryCode)) {
            return null;
        }

        $national = substr($e164, strlen($countryCode) + 1);

        return self::isValidNational($national) ? $national : null;
    }

    private static function isValidNational(string $national): bool
    {
        return (bool) preg_match(self::INDIAN_MOBILE_PATTERN, $national);
    }
}
