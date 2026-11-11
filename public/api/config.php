<?php
declare(strict_types=1);

/**
 * Every knob for the enquiry API. Nothing else in api/ should need editing to
 * change an address, a limit or a label.
 */

/**
 * The mailbox everything is sent through, rather than the host's mail(), which
 * Hostinger accepts and Gmail then routinely drops. Shared by the enquiry form
 * and the hiring form. The password is NOT kept here (this file is in git): it
 * lives in api/secrets.php on the server only. See api/secrets.example.php.
 * With no password set, mail() is used as before.
 */
$smtp = [
    'host' => 'smtp.gmail.com',
    'port' => 465,
    'secure' => 'ssl',
    'username' => 'clickfieldmedia@gmail.com',
    // Google shows app passwords in groups of four; the spaces are not part of it.
    'password' => str_replace(' ', '', (string) ((is_file(__DIR__ . '/secrets.php') ? (require __DIR__ . '/secrets.php') : [])['smtp_password'] ?? '')),
];

return [
    // --- Mail --------------------------------------------------------------
    'mail' => [
        // Where enquiries land. Several addresses may be listed, separated by
        // commas; each one gets its own copy.
        'to' => 'info@clickfieldmedia.com, clickfieldmedia@gmail.com',

        // Who it appears to come from, when mail() is used. Must be an address
        // on a domain this server may send for, or the mail is rejected or
        // filed as spam. Sending through the mailbox below instead, the From is
        // that mailbox, because Gmail rewrites anything else. Either way the
        // lead's own address goes in Reply-To, so replying reaches them.
        'from' => 'noreply@clickfieldmedia.com',
        'from_name' => 'Clickfield Media website',
        'subject_prefix' => 'Website enquiry',
    ],

    // The mailbox enquiries are sent through. Defined at the top of this file.
    'smtp' => $smtp,

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

    // The field bots fill and people never see. Used by the hiring form only.
    // The enquiry form no longer carries one: a password manager autofilling a
    // field named "website" looked exactly like a bot, and the submission was
    // thrown away while the visitor was shown the thank-you page. Applications
    // prove the address with an emailed code, so they do not rely on it.
    'honeypot' => 'website',

    // Longest accepted value per field. Beyond this is a paste or a bot.
    'max_len' => 1500,

    // --- Enquiry limits ----------------------------------------------------
    // There is deliberately no rate limiting on the enquiry form. A per-address
    // ceiling cannot tell six colleagues behind one office address, or six
    // strangers behind one mobile carrier, from one person submitting six
    // times — and a refused enquiry is one we never hear about again. The two
    // caps below are not abuse limits: they stop a single enormous request
    // exhausting memory, and neither can be reached by a filled-in form.
    'limits' => [
        // Refuse a body larger than this outright (bytes).
        'max_body_bytes' => 64 * 1024,

        // Refuse a request carrying more fields than the form has.
        'max_fields' => 24,
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

    // --- Hiring (api/apply.php) ---------------------------------------------
    // Job applications from /hiring. The applicant proves their email with a
    // 6-digit code before anything is sent to the inbox below.
    'hiring' => [
        'mail' => [
            'to' => 'clickfieldmedia@gmail.com',
            'from' => 'noreply@clickfieldmedia.com',
            'from_name' => 'Clickfield Media careers',
            'subject_prefix' => 'Job application',
        ],

        // The mailbox applications are sent through. Defined at the top of
        // this file, and shared with the enquiry form.
        'smtp' => $smtp,

        // One block per open role. The hidden "role" field on the page picks
        // the block, so each listing asks its own questions and is recorded on
        // its own terms. A role missing from here can no longer be applied to.
        //
        //   fields      Field name => label used in the email and the CSV
        //               header. The order here is the order in both. Every
        //               role needs at least role, name, email, phone, city,
        //               experience and message.
        //   optional    Fields that may be left blank.
        //   experience  The years-of-experience answers this listing offers.
        //   multi       Tick-lists: field => the answers offered. Sent as
        //               name[] and recorded as one comma-separated line.
        //   choices     Pick-one lists, checked against these answers.
        //   links       Fields that must be a web address. 'linkedin' means it
        //               must sit on linkedin.com; 'any' takes any http(s) link.
        //   csv         Where verified applications for the role are recorded.
        //               A file each, because the columns differ by role.
        'roles' => [
            'Digital Marketing Manager' => [
                'fields' => [
                    'role' => 'Role',
                    'name' => 'Name',
                    'email' => 'Email (verified)',
                    'phone' => 'Phone',
                    'city' => 'City',
                    'experience' => 'Experience',
                    'job_title' => 'Current job title',
                    'company' => 'Company',
                    'linkedin' => 'LinkedIn',
                    'current_ctc' => 'Current CTC',
                    'expected_ctc' => 'Expected CTC',
                    'notice' => 'Notice period',
                    'message' => 'Campaign they ran',
                ],
                'optional' => ['current_ctc', 'expected_ctc', 'notice', 'message'],
                'experience' => ['Less than 2 years', '2 to 3 years', '4 to 5 years', 'More than 5 years'],
                'links' => ['linkedin' => 'linkedin'],
                'csv' => 'applications/applications.csv',
            ],

            'Video Editor' => [
                'fields' => [
                    'role' => 'Role',
                    'name' => 'Name',
                    'email' => 'Email (verified)',
                    'phone' => 'Phone',
                    'city' => 'City',
                    'experience' => 'Experience',
                    'job_title' => 'Current job title',
                    'company' => 'Company',
                    'portfolio' => 'Showreel / portfolio',
                    'software' => 'Software they edit on',
                    'laptop' => 'Own editing laptop',
                    'linkedin' => 'LinkedIn',
                    'current_ctc' => 'Current CTC',
                    'expected_ctc' => 'Expected CTC',
                    'notice' => 'Notice period',
                    'message' => 'A video they edited',
                ],
                'optional' => ['linkedin', 'current_ctc', 'expected_ctc', 'notice', 'message'],
                'experience' => ['Less than 1 year', '1 to 2 years', '2 to 4 years', 'More than 4 years'],
                'multi' => [
                    'software' => [
                        'Adobe Premiere Pro', 'Adobe After Effects', 'DaVinci Resolve',
                        'Final Cut Pro', 'Blender or other 3D', 'CapCut', 'Something else',
                    ],
                ],
                'choices' => [
                    'laptop' => [
                        'Yes, I have my own laptop for professional editing',
                        'No, I do not have one',
                    ],
                ],
                'links' => ['portfolio' => 'any', 'linkedin' => 'linkedin'],
                'csv' => 'applications/video-editor.csv',
            ],
        ],

        // Shared by every role.
        'notice' => ['Can join immediately', '15 days', '30 days', '60 days', '90 days or more'],
        'max_len' => 2000,

        // CV upload.
        'cv_max_bytes' => 5 * 1024 * 1024,

        // The emailed code.
        'code_ttl' => 15 * 60,       // seconds a code stays valid
        'code_attempts' => 5,        // wrong guesses before the code is thrown away
        'resend_after' => 45,        // seconds before "resend code" works again
        'max_resends' => 3,

        'limits' => [
            // Uploads are bigger than enquiries: CV plus the form.
            'max_body_bytes' => 6 * 1024 * 1024,
            'max_fields' => 34,

            // Every request past the honeypot: send code, verify, resend.
            'per_ip_attempts' => [
                ['window' => 60,   'max' => 15, 'label' => 'minute'],
                ['window' => 3600, 'max' => 80, 'label' => 'hour'],
            ],
            // Code emails from one visitor. This is what stops the form being
            // used to flood someone else's inbox.
            'per_ip_codes' => [
                ['window' => 600,   'max' => 5,  'label' => '10 minutes'],
                ['window' => 86400, 'max' => 15, 'label' => 'day'],
            ],
            // Code emails to one address, whoever asks.
            'per_email_codes' => [
                ['window' => 3600, 'max' => 5, 'label' => 'hour'],
            ],
            // Verified applications from one visitor.
            'per_ip_submissions' => [
                ['window' => 3600,  'max' => 3, 'label' => 'hour'],
                ['window' => 86400, 'max' => 6, 'label' => 'day'],
            ],
            'global' => ['window' => 3600, 'max' => 200],
        ],
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
