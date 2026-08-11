<?php

namespace App\Enums;

use Symfony\Component\HttpFoundation\Response;

/**
 * Stable, machine-readable error codes (API_DOCUMENTATION §6).
 *
 * Clients - the Web CRM today, the Flutter app later - branch on these strings.
 * They are part of the API contract: a code's meaning and its HTTP status must
 * not change within /api/v1, and a code is never reused for a different
 * condition. Adding new codes is safe; repurposing one is a breaking change.
 *
 * Each code owns its HTTP status here so a controller cannot accidentally
 * return `dnc.suppressed` with a 500.
 */
enum ErrorCode: string
{
    // ---- Authentication / authorisation -----------------------------------
    case Unauthenticated = 'auth.unauthenticated';
    case TokenExpired = 'auth.token_expired';
    case Forbidden = 'auth.forbidden';

    // ---- Validation --------------------------------------------------------
    case ValidationFailed = 'validation.failed';

    // ---- Leads -------------------------------------------------------------
    case LeadDuplicate = 'lead.duplicate';
    case LeadInvalidStatusTransition = 'lead.invalid_status_transition';
    case LeadArchived = 'lead.archived';

    // ---- DNC (BR-DNC-01) ---------------------------------------------------
    case DncSuppressed = 'dnc.suppressed';

    // ---- Campaigns ---------------------------------------------------------
    case CampaignInvalidState = 'campaign.invalid_state';
    case CampaignFrequencyCapped = 'campaign.frequency_capped';

    // ---- Calling -----------------------------------------------------------
    case CallOutsideCallingHours = 'call.outside_calling_hours';

    // ---- Payments ----------------------------------------------------------
    case PaymentInvalidTransition = 'payment.invalid_transition';
    case PaymentOrphanNotAllowed = 'payment.orphan_not_allowed';

    // ---- Providers ---------------------------------------------------------
    case ProviderUnavailable = 'provider.unavailable';
    case ProviderRejected = 'provider.rejected';

    // ---- Generic -----------------------------------------------------------
    case NotFound = 'resource.not_found';
    case Conflict = 'resource.conflict';
    case RateLimitExceeded = 'rate_limit.exceeded';
    case ServerError = 'server.error';

    /** The HTTP status this error always returns with. */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Unauthenticated,
            self::TokenExpired => Response::HTTP_UNAUTHORIZED,               // 401

            self::Forbidden,
            self::DncSuppressed,
            self::CallOutsideCallingHours => Response::HTTP_FORBIDDEN,       // 403

            self::NotFound => Response::HTTP_NOT_FOUND,                      // 404

            self::LeadDuplicate,
            self::LeadArchived,
            self::CampaignInvalidState,
            self::Conflict => Response::HTTP_CONFLICT,                       // 409

            self::ValidationFailed,
            self::LeadInvalidStatusTransition,
            self::PaymentInvalidTransition,
            self::PaymentOrphanNotAllowed => Response::HTTP_UNPROCESSABLE_ENTITY, // 422

            self::CampaignFrequencyCapped,
            self::RateLimitExceeded => Response::HTTP_TOO_MANY_REQUESTS,     // 429

            self::ProviderRejected => Response::HTTP_BAD_GATEWAY,            // 502
            self::ProviderUnavailable => Response::HTTP_SERVICE_UNAVAILABLE, // 503

            self::ServerError => Response::HTTP_INTERNAL_SERVER_ERROR,       // 500
        };
    }

    /** Default human-readable message. Callers may override with more detail. */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Authentication required.',
            self::TokenExpired => 'Your session has expired. Please sign in again.',
            self::Forbidden => 'You do not have permission to perform this action.',
            self::ValidationFailed => 'The given data was invalid.',
            self::LeadDuplicate => 'A lead with this phone number already exists.',
            self::LeadInvalidStatusTransition => 'This lead status change is not allowed.',
            self::LeadArchived => 'This lead is archived and cannot be modified.',
            self::DncSuppressed => 'This lead is on the do-not-contact list for this channel.',
            self::CampaignInvalidState => 'This action is not allowed in the campaign\'s current state.',
            self::CampaignFrequencyCapped => 'The messaging frequency cap for this lead has been reached.',
            self::CallOutsideCallingHours => 'Calling is not permitted outside configured calling hours.',
            self::PaymentInvalidTransition => 'This payment status change is not allowed.',
            self::PaymentOrphanNotAllowed => 'A payment must be linked to a lead, customer, product and sale.',
            self::ProviderUnavailable => 'The provider is currently unavailable. Please try again shortly.',
            self::ProviderRejected => 'The provider rejected this request.',
            self::NotFound => 'The requested resource was not found.',
            self::Conflict => 'The request conflicts with the current state of the resource.',
            self::RateLimitExceeded => 'Too many requests. Please slow down.',
            self::ServerError => 'An unexpected error occurred.',
        };
    }
}
