<?php
declare(strict_types=1);

/**
 * Enquiry endpoint. Both the hero modal and the contact form post here.
 *
 * The order below is deliberate: the cheapest refusals happen first, and
 * nothing touches the disk or the mail queue until the request has earned it.
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
// A form on somebody else's page posting here is not a visitor we want.

if (!$http->originAllowed($config['allowed_origins'])) {
    $logger->failure('bad_origin', 'Cross-origin submission refused');
    $http->failure(403, 'bad_origin', 'This form can only be sent from the Clickfield Media website.');
}

// --- 3. Size ---------------------------------------------------------------

if ($http->contentLength() > $limits['max_body_bytes']) {
    $logger->failure('body_too_large', 'Rejected oversized body', ['bytes' => $http->contentLength()]);
    $http->failure(413, 'body_too_large', 'That submission was too large to accept.');
}

if (count($_POST) > $limits['max_fields']) {
    $logger->failure('too_many_fields', 'Rejected padded submission', ['fields' => count($_POST)]);
    $http->failure(422, 'too_many_fields', 'That submission was not in the expected shape. Please reload the page and try again.');
}

// --- 4. Honeypot -----------------------------------------------------------
// A field hidden from people and irresistible to bots. A filled one is told
// everything went fine, so the bot has no signal to adapt to, but nothing is
// emailed and nothing is stored as a lead.

if (trim((string) ($_POST[$config['honeypot']] ?? '')) !== '') {
    $logger->failure('honeypot', 'Discarded bot submission');
    $http->success();
}

// --- 5. Rate limit: attempts -----------------------------------------------
// Counts every request that got this far, whether or not the fields are any
// good. Only something hammering the endpoint should ever meet this.

$ip = $logger->clientIp();
$limiter = new RateLimiter($storage);
$limiter->maybeSweep(86400);

/** Turn a Retry-After in seconds into something worth reading on screen. */
$waitText = static function (int $seconds): string {
    $minutes = (int) ceil($seconds / 60);
    return $minutes <= 1 ? 'a minute' : "about {$minutes} minutes";
};

if ($hit = $limiter->consume('attempt', $ip, $limits['per_ip_attempts'])) {
    $logger->failure('rate_limited_attempts', 'Too many requests from one visitor', [
        'rule' => $hit['max'] . ' per ' . $hit['label'],
        'retry_after' => $hit['retry_after'],
    ]);
    $http->failure(
        429,
        'rate_limited',
        'That is a lot of tries in a short space of time. Please wait ' . $waitText($hit['retry_after'])
            . ' and try again, or email info@clickfieldmedia.com.',
        null,
        ['Retry-After: ' . $hit['retry_after']],
    );
}

// --- 6. Validate -----------------------------------------------------------

$validator = new Validator($config['fields'], $config['max_len']);
$result = $validator->validate($_POST);

if (isset($result['error'])) {
    $error = $result['error'];
    $logger->failure($error['code'], $error['message'], ['field' => $error['field']]);
    $http->failure(422, $error['code'], $error['message'], $error['field']);
}

$data = $result['data'];

// --- 7. Rate limit: real submissions ---------------------------------------
// Only now, with a submission we would actually act on, is the strict limit
// spent. Somebody fixing a typo is not charged for the attempts that failed.

if ($hit = $limiter->consume('submit', $ip, $limits['per_ip_submissions'])) {
    $logger->failure('rate_limited_submissions', 'Duplicate submission refused', [
        'rule' => $hit['max'] . ' per ' . $hit['label'],
        'retry_after' => $hit['retry_after'],
    ]);
    $http->failure(
        429,
        'rate_limited',
        'You have already sent this form. Please wait ' . $waitText($hit['retry_after'])
            . ' before sending another, or email info@clickfieldmedia.com if it is urgent.',
        null,
        ['Retry-After: ' . $hit['retry_after']],
    );
}

if ($hit = $limiter->consumeGlobal($limits['global'])) {
    $logger->failure('rate_limited_global', 'Site-wide submission ceiling reached', [
        'rule' => $hit['max'] . ' per ' . $hit['label'],
    ]);
    $http->failure(
        429,
        'rate_limited',
        'We are handling an unusual number of enquiries right now. Please try again in '
            . $waitText($hit['retry_after']) . ', or email info@clickfieldmedia.com.',
        null,
        ['Retry-After: ' . $hit['retry_after']],
    );
}

// --- 8. Send, then record --------------------------------------------------
// The mail is attempted first so its outcome can be recorded alongside the
// lead, but the lead is written whatever happens: a delivery problem is ours
// to chase, not something the visitor should have to retry.

$mailer = new Mailer($config['mail'], $config['fields']);
$mailed = $mailer->send($data, $logger->requestId());

$saved = $logger->lead($data, $config['fields'], $mailed);

if (!$saved && !$mailed) {
    $http->failure(503, 'delivery_failed', 'Your enquiry could not be saved or sent. Please email info@clickfieldmedia.com or try again later.');
}

if (!$mailed) {
    // Deliberately logged as a failure even though the visitor sees success:
    // the enquiry is safe in leads.csv, but nobody has been told about it yet.
    $logger->failure('mail_failed', 'mail() did not accept the message', [
        'email' => $data['email'],
        'name' => $data['name'],
    ]);
}

$http->success();
