<?php
declare(strict_types=1);

/**
 * Every knob for the enquiry API. Nothing else in api/ should need editing to
 * change an address, a limit or a label.
 */

return [
    // --- Mail --------------------------------------------------------------
    'mail' => [
        // Where enquiries land.
        'to' => 'info@clickfieldmedia.com',

        // Who it appears to come from. Must be an address on a domain this
        // server may send for, or the mail is rejected or filed as spam. The
        // lead's own address goes in Reply-To, so replying still reaches them.
        'from' => 'noreply@clickfieldmedia.com',
        'from_name' => 'Clickfield Media website',
        'subject_prefix' => 'Website enquiry',
    ],

    // Where a no-JavaScript submission is sent after success.
    'thank_you' => '/thank-you/',

    // --- The form ----------------------------------------------------------
    // Field name => label used in the email, the CSV header and error copy.
    'fields' => [
        'name' => 'Name',
        'email' => 'Work email',
        'phone' => 'Phone',
        'company' => 'Company or project',
        'service' => 'What they need',
        'message' => 'About the work',
    ],

    // The field bots fill and people never see.
    'honeypot' => 'website',

    // Longest accepted value per field. Beyond this is a paste or a bot.
    'max_len' => 1500,

    // --- Abuse limits ------------------------------------------------------
    'limits' => [
        // Refuse a body larger than this outright (bytes).
        'max_body_bytes' => 64 * 1024,

        // Refuse a request carrying more fields than the form has.
        'max_fields' => 24,

        // Every request that gets past the honeypot, valid or not. Loose
        // enough that correcting a mistyped email several times is fine;
        // tight enough that nothing can hammer the endpoint. window is in
        // seconds, max is how many are allowed inside it.
        'per_ip_attempts' => [
            ['window' => 60,   'max' => 12, 'label' => 'minute'],
            ['window' => 3600, 'max' => 60, 'label' => 'hour'],
        ],

        // Submissions that passed validation and are about to be emailed.
        // This is what stops duplicate leads and inbox flooding.
        'per_ip_submissions' => [
            ['window' => 60,    'max' => 2,  'label' => 'minute'],
            ['window' => 3600,  'max' => 6,  'label' => 'hour'],
            ['window' => 86400, 'max' => 15, 'label' => 'day'],
        ],

        // A ceiling across everyone, so a distributed flood cannot fill the
        // disk or the inbox. Generous enough that real traffic never sees it.
        'global' => ['window' => 3600, 'max' => 300],
    ],

    // --- Origin ------------------------------------------------------------
    // A submission whose Origin matches the host it was sent to is always
    // allowed, so this list does not need editing when the site moves or gains
    // a hostname. It exists only for genuinely cross-domain cases: a landing
    // page on another domain posting to this endpoint, for instance.
    'allowed_origins' => [
        'https://realestate.clickfieldmedia.com',
        'https://clickfieldmedia.com',
        'http://localhost:4321',
        'http://localhost:3000',
    ],

    // --- Storage -----------------------------------------------------------
    // Resolved at runtime. The first writable option wins; see Storage.php.
    // Kept out of the web root wherever the host allows it.
    'storage_dir_name' => 'clickfield-agency-data',

    // Roll a log once it passes this size, keeping one previous generation.
    'log_max_bytes' => 2 * 1024 * 1024,

    // --- Diagnostics -------------------------------------------------------
    // Unlocks api/health.php, which reports whether the endpoint is wired up
    // correctly and lists recent failures by reason. It never returns a lead,
    // a name or an address, so the worst this token leaks is error counts.
    // Set it to an empty string to switch the endpoint off entirely.
    'health_token' => '',
];
