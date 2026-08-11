<?php

namespace App\Enums;

/**
 * Quotation lifecycle (FR-SALE-03/04, BR-SALE-03).
 *
 * `PendingApproval` exists only because of the discount threshold. A quotation
 * discounted within the threshold goes straight from Draft to Issued; one above
 * it cannot reach Issued without a Manager+ decision, and that decision is
 * recorded on the row rather than inferred.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Issued = 'issued';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Issued => 'Issued',
        };
    }

    /**
     * Whether the quotation may still be edited.
     *
     * An issued quotation is a number a customer has been given. Editing it
     * after the fact means the figure on their copy and the figure in the CRM
     * differ, and only one of those is in the room during the argument.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /** Only an approved (or never-needed-approval) quotation can be issued. */
    public function canBeIssued(): bool
    {
        return in_array($this, [self::Draft, self::Approved], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
