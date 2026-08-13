<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;

/**
 * RFC 6238 time-based one-time passwords (SEC-AUTH-07, T-09).
 *
 * **No package, deliberately.** TOTP is HMAC-SHA1 over a 30-second counter plus
 * a base32 alphabet - about sixty lines, all of it specified by an RFC that has
 * not changed since 2011. A dependency here would buy nothing and cost the one
 * thing this project cannot spend on a shared-hosting target (T-03): another
 * package in the supply chain of the login path.
 *
 * SHA1 and six digits are not a weak choice - they are the interoperable one.
 * Google Authenticator, Authy, 1Password and every hardware token default to
 * them, and an authenticator that cannot read the QR code is a 2FA rollout that
 * ends in support tickets. The secret's entropy, not the digest, is what
 * carries the security here, and it is 160 bits.
 *
 * Pure functions only: no database, no session, no clock beyond the one passed
 * in. That is what makes the replay and drift behaviour testable without
 * freezing time in a controller.
 */
class Totp
{
    /** RFC 4648 base32 alphabet - the encoding every authenticator app reads. */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD = 30;

    public const DIGITS = 6;

    /**
     * A fresh 160-bit secret, base32 encoded.
     *
     * 160 bits because that is HMAC-SHA1's block-relevant key size; shorter
     * secrets are common in the wild and are the actual weakness in most TOTP
     * deployments.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The `otpauth://` URI an authenticator app scans.
     *
     * The issuer appears twice - once in the label prefix and once as a
     * parameter - because older apps read only one of them, and the two must
     * agree or the account shows up twice under different names.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** The counter value for a moment in time - the "time step". */
    public static function timestep(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    /** The six-digit code for a given time step. */
    public static function codeAt(string $secret, int $timestep): string
    {
        $key = self::base32Decode($secret);

        if ($key === null) {
            return '';
        }

        // The counter is a 64-bit big-endian integer (RFC 4226 §5.1).
        $hash = hash_hmac('sha1', pack('J', $timestep), $key, true);

        // Dynamic truncation: the low nibble of the last byte picks the offset.
        $offset = ord($hash[19]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad(
            (string) ($binary % (10 ** self::DIGITS)),
            self::DIGITS,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * The time step a code is valid for, or null if it matches none.
     *
     * Returning the STEP rather than a boolean is the whole point: the caller
     * stores it and refuses anything at or below it next time, which is what
     * makes a code single-use. A `verify(): bool` API cannot express that, and
     * every TOTP implementation that exposes one is replayable for the
     * remainder of its window.
     *
     * @param  int  $window  How many steps either side of now to accept. 1 is
     *                       the conventional value: a phone clock is routinely
     *                       a few seconds out, and a user typing a code as it
     *                       rolls over would otherwise be told they are wrong.
     */
    public static function match(string $secret, string $code, int $window = 1, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now = self::timestep($timestamp);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $now + $offset;

            // hash_equals, not ===: a timing oracle on a six-digit code is a
            // small leak, but it is a free one to close.
            if (hash_equals(self::codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * A recovery code, formatted `abcde-fghij`.
     *
     * Str::random is cryptographically secure; the hyphen exists so a person
     * reading one off paper down a phone line does not lose their place.
     */
    public static function recoveryCode(): string
    {
        return Str::lower(Str::random(5).'-'.Str::random(5));
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        // Right-pad to a multiple of 5 so the final group is a whole symbol.
        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    /** @return string|null null when the secret is not valid base32 */
    private static function base32Decode(string $secret): ?string
    {
        // Authenticator apps display secrets in groups with spaces, and users
        // paste them back that way; padding is stripped for the same reason.
        $secret = strtoupper(str_replace([' ', '-', '='], '', $secret));

        if ($secret === '' || strspn($secret, self::ALPHABET) !== strlen($secret)) {
            return null;
        }

        $bits = '';

        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            // A trailing partial group is encoding padding, not data.
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
