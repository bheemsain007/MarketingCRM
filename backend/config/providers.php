<?php

/**
 * External provider credentials (SEC-CFG-01).
 *
 * This is the ONLY layer allowed to call env() for these values. Once config is
 * cached in production env() returns null everywhere else, so reading a
 * credential from env() in a service is a bug that only appears after
 * `config:cache` runs.
 *
 * These are DEFAULTS. `SettingsService::get()` checks the `settings` table
 * first and falls back here, so a credential can be rotated through the admin
 * UI without shell access - which the Hostinger target may not provide
 * (SEC-CFG-04, T-12). Nothing here is required for the app to boot; every
 * value is null until a provider is actually configured.
 *
 * Vendors marked "not chosen" are open decisions, not oversights - see T-31
 * (WhatsApp BSP), T-32 (RCS), T-33 (Voice), T-34 (payment gateway).
 */
return [

    // Phase 13 - Email
    'mailercloud' => [
        'api_key' => env('MAILERCLOUD_API_KEY'),
        'from_email' => env('MAILERCLOUD_FROM_EMAIL'),
        'from_name' => env('MAILERCLOUD_FROM_NAME'),
        // Shared secret the delivery webhook must present. Provisional until
        // Mailercloud's own signing scheme is documented (T-53).
        'webhook_secret' => env('MAILERCLOUD_WEBHOOK_SECRET'),
    ],

    // Phase 15 - SMS
    'bhashsms' => [
        'user' => env('BHASHSMS_USER'),
        'password' => env('BHASHSMS_PASSWORD'),
        'sender_id' => env('BHASHSMS_SENDER_ID'),
    ],

    /*
     * Phase 14 - WhatsApp. BSP not chosen (T-31).
     *
     * The env names here match `.env.example`, which Phase 1 settled on and
     * which is the contract anybody deploying reads. Naming them anything else
     * means an operator fills in the documented key and the application never
     * sees it - a misconfiguration with no error message.
     */
    'whatsapp' => [
        'driver' => env('WHATSAPP_PROVIDER'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_API_KEY'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

    // Phase 12 - Facebook / Instagram lead capture
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
        'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
    ],

    // Phase 16 - RCS. Vendor not chosen (T-32).
    'rcs' => [
        'provider' => env('RCS_PROVIDER'),
        'api_key' => env('RCS_API_KEY'),
    ],

    // Phase 17 - Voice. Vendor not chosen (T-33).
    'voice' => [
        'provider' => env('VOICE_PROVIDER'),
        'api_key' => env('VOICE_API_KEY'),
    ],

    // Phase 19 - inbound keyword opt-out (STOP/UNSUBSCRIBE). Shared secret the
    // inbound webhook must present; provisional like the other webhook secrets
    // until a provider's own scheme is confirmed (T-53).
    'inbound' => [
        'webhook_secret' => env('INBOUND_WEBHOOK_SECRET'),
    ],

    // Phase 24 - AI calling
    'vaaad' => [
        'api_key' => env('VAAAD_API_KEY'),
        'webhook_secret' => env('VAAAD_WEBHOOK_SECRET'),
    ],

    /*
     * Phase 23 - Payment gateway. Razorpay is the default choice (T-34).
     *
     * The default names WHICH gateway, not that one is usable: the credentials
     * below stay null until an operator supplies them, and until then payment
     * links refuse with a 503 naming the missing field rather than issuing a
     * link that collects nothing (SEC-CFG-04). Those are separate questions and
     * conflating them produces the wrong error message for both.
     *
     * Unlike the channel providers, this vendor's contract is not provisional -
     * Razorpay's Payment Links REST API and its HMAC-SHA256 webhook signature
     * are publicly documented and implemented against.
     */
    'payment' => [
        'gateway' => env('PAYMENT_GATEWAY', 'razorpay'),
        'key_id' => env('PAYMENT_KEY_ID'),
        'key_secret' => env('PAYMENT_KEY_SECRET'),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
    ],

];
