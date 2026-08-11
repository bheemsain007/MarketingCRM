<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 *
 * Deliberately has no default for `sale_id`, `customer_id`, `lead_id` or
 * `product_id`. BR-PAY-03 forbids orphan payments, and a factory that
 * cheerfully invented four unrelated parents would let a test build a record
 * the application refuses to create.
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'reference' => 'P-TEST-'.Str::random(8),
            'amount' => 1000,
            'currency' => 'INR',
            'status' => PaymentStatus::Pending->value,
            'method' => 'other',
        ];
    }

    public function status(PaymentStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }
}
