<?php
declare(strict_types=1);

/**
 * Job application endpoint for /hiring. Three actions, all JSON:
 *
 *   send_code  Validate the form and CV, park them as a pending application,
 *              and email a 6-digit code to the applicant.
 *   verify     Check the code. On a match, email the application with the CV
 *              attached, record it, and clear the pending copy.
 *   resend     Send a fresh code for a pending application.
 *
 * Nothing reaches the inbox until the applicant has proved the address is
 * theirs. As in contact.php, the cheapest refusals come first.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/ApplicationValidator.php';
require_once __DIR__ . '/lib/Smtp.php';
require_once __DIR__ . '/lib/HiringMailer.php';

/** @var array $config */
/** @var Storage $storage */
/** @var Logger $logger */
/** @var Http $http */

$hiring = $config['hiring'];
$limits = $hiring['limits'];
$inbox = $hiring['mail']['to'];

// --- 1. Method, origin, JavaScript -------------------------------------------

if ($http->method() !== 'POST') {
    header('Allow: POST');
    $http->failure(405, 'bad_method', 'This address only accepts submitted forms.');
}

if (!$http->originAllowed($config['allowed_origins'])) {
    $logger->failure('bad_origin', 'Cross-origin application refused');
    $http->failure(403, 'bad_origin', 'This form can only be sent from the Clickfield Media website.');
}

// The code step needs the page's script. Without it, say how else to apply.
if (!$http->wantsJson()) {
    $http->failure(400, 'needs_javascript', "Applying needs JavaScript switched on. You can also email your CV to {$inbox}.");
}

// --- 2. Size -------------------------------------------------------------------
// When a body passes PHP's post_max_size, PHP silently drops every field, which
// would otherwise read as a blank form.

$length = $http->contentLength();
if ($length > $limits['max_body_bytes'] || ($length > 0 && $_POST === [] && $_FILES === [])) {
    $logger->failure('body_too_large', 'Rejected oversized application', ['bytes' => $length]);
    $http->failure(413, 'body_too_large', 'Your CV is larger than 5 MB. Please upload a smaller file.', 'cv');
}

if (count($_POST) > $limits['max_fields']) {
    $http->failure(422, 'too_many_fields', 'That submission was not in the expected shape. Please reload the page and try again.');
}

// --- 3. Honeypot ---------------------------------------------------------------
// Looks like success to a bot. The token leads nowhere.

if (trim((string) ($_POST[$config['honeypot']] ?? '')) !== '') {
    $logger->failure('honeypot', 'Discarded bot application');
    $http->success(['token' => bin2hex(random_bytes(16)), 'resend_after' => $hiring['resend_after']]);
}

// --- 4. Attempts limit ---------------------------------------------------------

$ip = $logger->clientIp();
$limiter = new RateLimiter($storage);
$limiter->maybeSweep(86400);

$waitText = static function (int $seconds): string {
    $minutes = (int) ceil($seconds / 60);
    return $minutes <= 1 ? 'a minute' : "about {$minutes} minutes";
};

$refuseRate = static function (array $hit, string $code, string $message) use ($logger, $http, $waitText, $inbox): never {
    $logger->failure($code, $message, ['rule' => $hit['max'] . ' per ' . $hit['label'], 'retry_after' => $hit['retry_after']]);
    $http->failure(
        429,
        'rate_limited',
        'That is a lot of tries in a short time. Please wait ' . $waitText($hit['retry_after']) . " and try again, or email your CV to {$inbox}.",
        null,
        ['Retry-After: ' . $hit['retry_after']],
    );
};

if ($hit = $limiter->consume('applyattempt', $ip, $limits['per_ip_attempts'])) {
    $refuseRate($hit, 'rate_limited_attempts', 'Too many application requests from one visitor');
}

// --- Pending applications ------------------------------------------------------

$storage->subdir('applications/pending');
$storage->subdir('applications/cv');
if (random_int(1, 20) === 1) {
    $storage->sweep('applications/pending', 2 * 3600);
}

$codeHash = static fn(string $token, string $code): string => hash('sha256', $token . '|' . $code);
$newCode = static fn(): string => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$pendingJson = static fn(string $token): string => "applications/pending/{$token}.json";

/** A token from the client, or a refusal. Never used in a path unchecked. */
$readToken = static function () use ($http, $storage, $pendingJson): string {
    $token = (string) ($_POST['token'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1 || !is_file($storage->path($pendingJson($token)))) {
        $http->failure(410, 'code_expired', 'This code has expired. Please press “Verify email and continue” again to get a new one.');
    }
    return $token;
};

$removePending = static function (string $token, ?string $ext) use ($storage, $pendingJson): void {
    @unlink($storage->path($pendingJson($token)));
    if ($ext !== null) {
        @unlink($storage->path("applications/pending/{$token}.{$ext}"));
    }
};

$mailer = new HiringMailer($hiring['mail'], $hiring['smtp']);

/** An SMTP failure is logged even when the mail() fallback then went through. */
$logSmtp = static function (string $what) use ($mailer, $logger): void {
    if ($mailer->lastError !== '') {
        $logger->failure('smtp_failed', "SMTP could not send the {$what}", ['detail' => $mailer->lastError]);
    }
};
$ttlMinutes = (int) round($hiring['code_ttl'] / 60);

/**
 * The listing's own block of config laid over the shared one: the questions it
 * asks, the answers it offers and the file it is recorded in. A role that is
 * no longer listed can no longer be applied to, including a pending
 * application parked before the listing closed.
 */
$forRole = static function (string $role) use ($hiring, $logger, $http): array {
    if (!isset($hiring['roles'][$role])) {
        $logger->failure('invalid_role', 'Application for a role that is not open', ['role' => mb_substr($role, 0, 80)]);
        $http->failure(422, 'invalid_role', 'This role is no longer open. Please reload the page.');
    }
    return array_replace($hiring, $hiring['roles'][$role]);
};

$action = (string) ($_POST['action'] ?? '');

// --- send_code -----------------------------------------------------------------

if ($action === 'send_code') {
    $validator = new ApplicationValidator($forRole((string) ($_POST['role'] ?? '')));

    $result = $validator->validate($_POST);
    if (isset($result['error'])) {
        $e = $result['error'];
        $logger->failure($e['code'], $e['message'], ['field' => $e['field']]);
        $http->failure(422, $e['code'], $e['message'], $e['field']);
    }
    $data = $result['data'];

    $cvResult = $validator->validateCv($_FILES['cv'] ?? null);
    if (isset($cvResult['error'])) {
        $e = $cvResult['error'];
        $logger->failure($e['code'], $e['message'], ['field' => 'cv']);
        $http->failure(422, $e['code'], $e['message'], 'cv');
    }
    $cv = $cvResult['file'];

    if ($hit = $limiter->consume('applycode', $ip, $limits['per_ip_codes'])) {
        $refuseRate($hit, 'rate_limited_codes', 'Too many code emails requested from one visitor');
    }
    if ($hit = $limiter->consume('applyemail', $data['email'], $limits['per_email_codes'])) {
        $refuseRate($hit, 'rate_limited_email', 'Too many code emails requested for one address');
    }
    if ($hit = $limiter->consumeGlobal($limits['global'], 'hiring')) {
        $refuseRate($hit, 'rate_limited_global', 'Site-wide application ceiling reached');
    }

    $token = bin2hex(random_bytes(16));
    $code = $newCode();
    $now = time();

    if (!@move_uploaded_file($cv['tmp'], $storage->path("applications/pending/{$token}.{$cv['ext']}"))) {
        $logger->failure('cv_store_failed', 'Could not park the uploaded CV');
        $http->failure(503, 'cv_store_failed', "Your CV could not be saved just now. Please try again, or email it to {$inbox}.");
    }
    @chmod($storage->path("applications/pending/{$token}.{$cv['ext']}"), 0600);

    $state = [
        'created' => $now,
        'expires' => $now + $hiring['code_ttl'],
        'last_sent' => $now,
        'code' => $codeHash($token, $code),
        'attempts' => 0,
        'resends' => 0,
        'used' => false,
        'data' => $data,
        'cv' => ['name' => $cv['name'], 'ext' => $cv['ext']],
    ];
    $file = $storage->path($pendingJson($token));
    if (@file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        $removePending($token, $cv['ext']);
        $logger->failure('pending_store_failed', 'Could not write the pending application');
        $http->failure(503, 'pending_store_failed', "Your application could not be saved just now. Please try again, or email your CV to {$inbox}.");
    }
    @chmod($file, 0600);

    $codeSent = $mailer->sendCode($data['email'], $data['name'], $code, $ttlMinutes);
    $logSmtp('code email');
    if (!$codeSent) {
        $removePending($token, $cv['ext']);
        $logger->failure('code_mail_failed', 'mail() did not accept the code email', ['email' => $data['email']]);
        $http->failure(503, 'code_mail_failed', "We couldn’t send the code email just now. Please try again in a moment, or email your CV to {$inbox}.");
    }

    $http->success(['token' => $token, 'resend_after' => $hiring['resend_after']]);
}

// --- resend --------------------------------------------------------------------

if ($action === 'resend') {
    $token = $readToken();
    $code = $newCode();

    $outcome = $storage->withJson($pendingJson($token), static function (?array $state) use ($hiring, $token, $code, $codeHash) {
        $now = time();
        if ($state === null || !isset($state['data']) || $state['used'] || $now > $state['created'] + 2 * 3600) {
            return [null, ['status' => 'expired']];
        }
        if ($state['resends'] >= $hiring['max_resends']) {
            return [null, ['status' => 'too_many']];
        }
        $wait = $state['last_sent'] + $hiring['resend_after'] - $now;
        if ($wait > 0) {
            return [null, ['status' => 'wait', 'wait' => $wait]];
        }
        $state['code'] = $codeHash($token, $code);
        $state['expires'] = $now + $hiring['code_ttl'];
        $state['last_sent'] = $now;
        $state['attempts'] = 0;
        $state['resends']++;
        return [$state, ['status' => 'ok', 'email' => $state['data']['email'], 'name' => $state['data']['name']]];
    });

    match ($outcome['status']) {
        'expired' => $http->failure(410, 'code_expired', 'This application has expired. Please press “Verify email and continue” again.'),
        'too_many' => $http->failure(429, 'too_many_resends', "We’ve sent the most codes we can for this application. Please check your spam folder, or email your CV to {$inbox}."),
        'wait' => $http->failure(429, 'resend_too_soon', "Please wait {$outcome['wait']} seconds before asking for another code."),
        default => null,
    };

    if ($hit = $limiter->consume('applycode', $ip, $limits['per_ip_codes'])) {
        $refuseRate($hit, 'rate_limited_codes', 'Too many code emails requested from one visitor');
    }
    if ($hit = $limiter->consume('applyemail', $outcome['email'], $limits['per_email_codes'])) {
        $refuseRate($hit, 'rate_limited_email', 'Too many code emails requested for one address');
    }

    $codeSent = $mailer->sendCode($outcome['email'], $outcome['name'], $code, $ttlMinutes);
    $logSmtp('resent code email');
    if (!$codeSent) {
        $logger->failure('code_mail_failed', 'mail() did not accept the resent code', ['email' => $outcome['email']]);
        $http->failure(503, 'code_mail_failed', "We couldn’t send the code email just now. Please try again in a moment, or email your CV to {$inbox}.");
    }

    $http->success(['resend_after' => $hiring['resend_after']]);
}

// --- verify --------------------------------------------------------------------

if ($action === 'verify') {
    $token = $readToken();
    $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')) ?? '';

    if (strlen($code) !== 6) {
        $http->failure(422, 'invalid_code', 'Please enter the 6-digit code from the email.', 'code');
    }

    $outcome = $storage->withJson($pendingJson($token), static function (?array $state) use ($hiring, $token, $code, $codeHash) {
        if ($state === null || !isset($state['data'])) {
            return [null, ['status' => 'expired']];
        }
        if ($state['used']) {
            return [null, ['status' => 'used']];
        }
        if (time() > $state['expires']) {
            return [null, ['status' => 'expired']];
        }
        if ($state['attempts'] >= $hiring['code_attempts']) {
            return [null, ['status' => 'too_many']];
        }
        if (!hash_equals($state['code'], $codeHash($token, $code))) {
            $state['attempts']++;
            return [$state, ['status' => 'wrong', 'left' => $hiring['code_attempts'] - $state['attempts']]];
        }
        // Claimed under the lock, so a double tap cannot send it twice.
        $state['used'] = true;
        return [$state, ['status' => 'ok', 'data' => $state['data'], 'cv' => $state['cv']]];
    });

    match ($outcome['status']) {
        'expired' => $http->failure(410, 'code_expired', 'This code has expired. Press “Resend code” to get a new one.', 'code'),
        'used' => $http->failure(409, 'already_sent', 'This application has already been sent. Thank you!'),
        'too_many' => $http->failure(429, 'too_many_attempts', 'Too many wrong codes. Press “Resend code” to get a new one.', 'code'),
        'wrong' => $http->failure(
            422,
            'wrong_code',
            $outcome['left'] > 0
                ? 'That code isn’t right. Please check the email and try again (' . $outcome['left'] . ' ' . ($outcome['left'] === 1 ? 'try' : 'tries') . ' left).'
                : 'That code isn’t right. Press “Resend code” to get a new one.',
            'code',
        ),
        default => null,
    };

    $data = $outcome['data'];
    $ext = $outcome['cv']['ext'];

    if ($hit = $limiter->consume('applysubmit', $ip, $limits['per_ip_submissions'])) {
        $removePending($token, $ext);
        $refuseRate($hit, 'rate_limited_submissions', 'Too many verified applications from one visitor');
    }

    // Keep the CV with the application record, named so it sorts by date.
    $cvStored = date('Ymd-His') . '-' . $logger->requestId() . '.' . $ext;
    $cvPath = $storage->path("applications/cv/{$cvStored}");
    if (!@rename($storage->path("applications/pending/{$token}.{$ext}"), $cvPath)) {
        $cvPath = $storage->path("applications/pending/{$token}.{$ext}");
        $cvStored = 'NOT SAVED';
    }

    $role = $forRole((string) ($data['role'] ?? ''));

    $mailed = $mailer->sendApplication($data, $role['fields'], $cvPath, $outcome['cv']['name'], $logger->requestId());
    $logSmtp('application');

    // A file per role: the columns follow that listing's questions.
    $fields = $role['fields'] + ['cv' => 'CV file', 'cv_name' => 'CV original name'];
    $record = $data + ['cv' => $cvStored, 'cv_name' => $outcome['cv']['name']];
    $saved = $cvStored !== 'NOT SAVED' && $logger->lead($record, $fields, $mailed, $role['csv']);

    $removePending($token, $cvStored === 'NOT SAVED' ? $ext : null);

    if (!$saved && !$mailed) {
        $http->failure(503, 'delivery_failed', "Your application could not be sent. Please email your CV to {$inbox}.");
    }
    if (!$mailed) {
        $logger->failure('mail_failed', 'mail() did not accept the application', ['email' => $data['email'], 'name' => $data['name']]);
    }

    $http->success(['name' => $data['name']]);
}

$http->failure(400, 'bad_action', 'That request was not understood. Please reload the page and try again.');
