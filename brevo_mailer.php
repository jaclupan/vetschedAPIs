<?php
function vetschedReadEnvValue(string $name): string {
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }

    $envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envFile)) {
        return '';
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return '';
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
            continue;
        }

        if ($matches[1] !== $name) {
            continue;
        }

        $value = trim($matches[2]);
        if (strlen($value) >= 2 &&
            (($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
             ($value[0] === "'" && $value[strlen($value) - 1] === "'"))) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    return '';
}

function sendBrevoOtpEmail($recipient, $code) {
    $apiKey = vetschedReadEnvValue('BREVO_API_KEY') ?: vetschedReadEnvValue('VETSCHED_BREVO_API_KEY') ?: '';
    $senderEmail = vetschedReadEnvValue('BREVO_SENDER_EMAIL') ?: vetschedReadEnvValue('VETSCHED_BREVO_SENDER_EMAIL') ?: 'no-reply@vetsched.com';
    $senderName = vetschedReadEnvValue('BREVO_SENDER_NAME') ?: 'VETSCHED';

    if (!$apiKey || strpos($apiKey, 'xkeysib-') !== 0) {
        error_log("Brevo API key is not configured or invalid.");
        return false;
    }

    $payload = json_encode([
        "sender" => [
            "name" => $senderName,
            "email" => $senderEmail
        ],
        "to" => [
            [
                "email" => $recipient
            ]
        ],
        "subject" => "VETSCHED Account Verification OTP",
        "htmlContent" => "<html><body style='font-family: Arial, sans-serif; padding: 20px;'><h2>VETSCHED Account Verification</h2><p>Your one-time verification code for account registration is:</p><h1 style='color: #5C6D4F; letter-spacing: 2px;'>$code</h1><p>This code will expire in 15 minutes.</p><p>If you did not request this, please ignore this email.</p></body></html>"
    ]);

    $curl = curl_init("https://api.brevo.com/v3/smtp/email");
    if ($curl === false) {
        error_log("Could not initialize Brevo HTTP request.");
        return false;
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "accept: application/json",
            "api-key: " . $apiKey,
            "content-type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log("Brevo API request failed (HTTP " . $status . "): " . $error . " " . $response);
        return false;
    }

    return true;
}
?>
