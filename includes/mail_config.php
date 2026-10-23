<?php
declare(strict_types=1);

// Gmail sender used for registration emails. Environment variables override the
// values below, so a deployment can keep the App Password out of the code.
function jpcs_mail_config(): array
{
    $address = getenv('JPCS_GMAIL_ADDRESS') ?: 'samanthayvette041@gmail.com';
    $password = getenv('JPCS_GMAIL_APP_PASSWORD') ?: 'qwxj ugjw wioo ofqn';
    return ['address' => $address, 'app_password' => (string) preg_replace('/\s+/', '', $password)];
}
