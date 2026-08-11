<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phone normalisation (BR-DUP-01).
 *
 * This is the lead identity key. If the same human number can produce two
 * different strings, duplicate detection fails silently and the same person is
 * called by three telecallers - so every format real data arrives in is
 * asserted, not spot-checked.
 */
class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function validNumbers(): array
    {
        return [
            'bare 10 digit' => ['9876543210', '+919876543210'],
            'leading zero' => ['09876543210', '+919876543210'],
            'country code no plus' => ['919876543210', '+919876543210'],
            'with plus' => ['+919876543210', '+919876543210'],
            'spaces' => ['+91 98765 43210', '+919876543210'],
            'dashes' => ['+91-98765-43210', '+919876543210'],
            'mixed punctuation' => ['(+91) 98765-43210', '+919876543210'],
            'dots' => ['98765.43210', '+919876543210'],
            'surrounding whitespace' => ['  9876543210  ', '+919876543210'],
            'starts with 6' => ['6012345678', '+916012345678'],
            'starts with 7' => ['7012345678', '+917012345678'],
            'starts with 8' => ['8012345678', '+918012345678'],
        ];
    }

    #[Test]
    #[DataProvider('validNumbers')]
    public function it_normalises_every_common_input_format(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalise($input));
    }

    #[Test]
    public function every_format_of_the_same_number_produces_one_identical_string(): void
    {
        // The property that makes duplicate detection work at all.
        $variants = ['9876543210', '09876543210', '919876543210', '+919876543210',
            '+91 98765 43210', '+91-98765-43210', ' 98765 43210 '];

        $normalised = array_unique(array_map(
            fn (string $v) => PhoneNumber::normalise($v),
            $variants,
        ));

        $this->assertCount(1, $normalised);
        $this->assertSame('+919876543210', reset($normalised));
    }

    /** @return array<string, array{string|null}> */
    public static function invalidNumbers(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'letters only' => ['not a phone'],
            'too short' => ['12345'],
            'landline style 8 digits' => ['12345678'],
            'starts with 5' => ['5012345678'],   // not a valid Indian mobile
            'starts with 1' => ['1234567890'],
            'too long' => ['98765432101234567890'],
        ];
    }

    #[Test]
    #[DataProvider('invalidNumbers')]
    public function it_returns_null_for_unusable_input(?string $input): void
    {
        // Null rather than an exception: an import must mark a row invalid and
        // carry on, not abort the batch.
        $this->assertNull(PhoneNumber::normalise($input));
        $this->assertFalse(PhoneNumber::isValid($input));
    }

    #[Test]
    public function it_keeps_an_explicit_international_number(): void
    {
        // A UK mobile typed with its country code is preserved, not mangled
        // into an Indian number.
        $this->assertSame('+447700900123', PhoneNumber::normalise('+44 7700 900123'));
    }

    #[Test]
    public function it_returns_the_national_number_for_gateways_that_want_it(): void
    {
        // Indian SMS aggregators take 10 digits, not E.164, so storage and the
        // wire format genuinely differ.
        $this->assertSame('9876543210', PhoneNumber::national('+919876543210'));

        // A non-Indian number returns null rather than a guessed substring -
        // a truncated number reaches somebody else entirely.
        $this->assertNull(PhoneNumber::national('+14155550123'));
        $this->assertNull(PhoneNumber::national(null));
    }

    #[Test]
    public function it_formats_for_display_without_changing_storage(): void
    {
        $this->assertSame('+91 98765 43210', PhoneNumber::format('+919876543210'));
        // Storage form is unchanged by formatting.
        $this->assertSame('+919876543210', PhoneNumber::normalise(PhoneNumber::format('+919876543210')));
    }
}
