<?php
declare(strict_types=1);

/**
 * Enquiry endpoint. Both the hero modal and the contact form post here.
 *
 * One rule outranks everything else here: a submission that reaches this file
 * is written down. Nothing is refused on suspicion and nothing is discarded.
 *
 * That is why there is no honeypot and no abuse ceiling. Both of them turn
 * away real enquiries as readily as fake ones — a hidden field filled in by a
 * password manager's autofill, an office or mobile-carrier address shared by
 * dozens of genuine visitors — and a refused enquiry is one we never learn
 * about. The checks that remain either cost a real visitor nothing (the
 * method, the origin, a size cap that a filled-in form cannot reach) or they
 * record what they turn away.
 *
 * The order below is deliberate: the cheapest refusals happen first, and the
 * lead is on disk before anything slow is attempted.
 */

require_once __DIR__ . '/bootstrap.php';

/** @var array $config */
/** @var Storage $storage */
/** @var Logger $logger */
/** @var Http $http */

$limits = $config['limits'];

// --- 1. Method -------------------------------------------------------------

if ($http->method() !== 'POST') {
    header('Allow: POST');
    $logger->failure('bad_method', 'Non-POST request', ['method' => $http->method()]);
    $http->failure(405, 'bad_method', 'This address only accepts submitted forms.');
}

// --- 2. Origin -------------------------------------------------------------
// A form on somebody else's page posting here is not a visitor we want. The
// test is against the request's own host, and a missing Origin is allowed, so
// this cannot refuse a submission from our own site.

if (!$http->originAllowed($config['allowed_origins'])) {
    $logger->failure('bad_origin', 'Cross-origin submission refused');
    $http->failure(403, 'bad_origin', 'This form can only be sent from the Clickfield Media website.');
}

// --- 3. Size ---------------------------------------------------------------
// Not an abuse limit but a memory one: without it a single huge POST can
// exhaust the process before any of our code runs. The form's own maxlength
// attributes keep a real submission two orders of magnitude below this.

if ($http->contentLength() > $limits['max_body_bytes']) {
    $logger->failure('body_too_large', 'Rejected oversized body', ['bytes' => $http->contentLength()]);
    $http->failure(413, 'body_too_large', 'That submission was too large to accept.');
}

if (count($_POST) > $limits['max_fields']) {
    $logger->failure('too_many_fields', 'Rejected padded submission', ['fields' => count($_POST)]);
    $http->failure(422, 'too_many_fields', 'That submission was not in the expected shape. Please reload the page and try again.');
}

// --- 4. Validate -----------------------------------------------------------
// A refusal here is shown on screen and the visitor can fix it, but some of
// them close the tab instead — so whatever they had typed is recorded first.
// A name and a phone number with the email still missing is a lead we can
// chase; nothing at all is not.

$validator = new Validator($config['fields'], $config['max_len']);
$result = $validator->validate($_POST);

if (isset($result['error'])) {
    $error = $result['error'];

    $logger->lead(
        $result['data'],
        $config['fields'],
        $error['code'],
        'incomplete.csv',
        'Rejected because',
    );
    $logger->failure($error['code'], $error['message'], ['field' => $error['field']]);

    $http->failure(422, $error['code'], $error['message'], $error['field']);
}

$data = $result['data'];

// --- 5. Nothing is lost from here on ---------------------------------------
// Everything above is fast. What follows talks to Gmail, which may take tens
// of seconds or hang, and the visitor's browser gives up before then.
//
// So: a closed tab or an abandoned fetch must not take the script down with
// it, PHP's own clock is given room for a slow handshake, and the lead is
// written to disk BEFORE the mail is attempted rather than after. The row
// starts as 'pending' and is settled once the outcome is known, which means
// no sequence of events during delivery can lose the enquiry.

ignore_user_abort(true);
@set_time_limit(120);

$saved = $logger->lead($data, $config['fields'], 'pending');

// --- 6. Send ---------------------------------------------------------------

$mailer = new Mailer($config['mail'], $config['fields'], $config['smtp']);

try {
    $mailed = $mailer->send($data, $logger->requestId());
} catch (Throwable $e) {
    // Caught rather than left to the handler in bootstrap, so the row above is
    // still settled and the visitor is still told the truth: the enquiry is
    // saved, whatever the mailer just did.
    $mailed = false;
    $mailer->lastError = $e::class . ': ' . $e->getMessage();
}

if ($saved) {
    $logger->settleMailed($mailed);
}

// Recorded even when the mail() fallback then went through, because a mailbox
// that has stopped accepting logins is worth knowing about before it matters.
if ($mailer->lastError !== '') {
    $logger->failure('smtp_failed', 'SMTP could not send the enquiry', ['detail' => $mailer->lastError]);
}

// --- 7. Answer -------------------------------------------------------------

if (!$saved && !$mailed) {
    $http->failure(503, 'delivery_failed', 'Your enquiry could not be saved or sent. Please email info@clickfieldmedia.com or try again later.');
}

if (!$mailed) {
    // Deliberately logged as a failure even though the visitor sees success:
    // the enquiry is safe in leads.csv, but nobody has been told about it yet.
    $logger->failure('mail_failed', 'The message was not accepted for delivery', [
        'email' => $data['email'],
        'name' => $data['name'],
    ]);
}

$http->success();
