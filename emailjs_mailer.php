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

function sendEmailJsResetCode($recipient, $code) {
    $configPath = __DIR__ . DIRECTORY_SEPARATOR . "vetsched_emailjs_config.php";
    if (!is_file($configPath)) {
        $configPath = __DIR__ . DIRECTORY_SEPARATOR . "emailjs_config.php";
    }

    $config = [];
    if (is_file($configPath)) {
        $config = require $configPath;
    }

    $serviceId = vetschedReadEnvValue('VETSCHED_EMAILJS_SERVICE_ID') ?: vetschedReadEnvValue('EMAILJS_SERVICE_ID') ?: ($config["service_id"] ?? "");
    $templateId = vetschedReadEnvValue('VETSCHED_EMAILJS_TEMPLATE_ID') ?: vetschedReadEnvValue('EMAILJS_TEMPLATE_ID') ?: ($config["template_id"] ?? "");
    $publicKey = vetschedReadEnvValue('VETSCHED_EMAILJS_PUBLIC_KEY') ?: vetschedReadEnvValue('EMAILJS_PUBLIC_KEY') ?: ($config["public_key"] ?? "");
    $privateKey = vetschedReadEnvValue('VETSCHED_EMAILJS_PRIVATE_KEY') ?: vetschedReadEnvValue('EMAILJS_PRIVATE_KEY') ?: ($config["private_key"] ?? "");

    if (!$serviceId || !$templateId || !$publicKey || !$privateKey ||
        strpos($templateId, "YOUR_") === 0 || strpos($publicKey, "YOUR_") === 0 ||
        strpos($privateKey, "YOUR_") === 0) {
        error_log("EmailJS service ID, template ID, public key, and private key must be configured.");
        return false;
    }

    $payload = json_encode([
        "service_id" => $serviceId,
        "template_id" => $templateId,
        "user_id" => $publicKey,
        "accessToken" => $privateKey,
        "template_params" => [
            "to_email" => $recipient,
            "otp_code" => $code,
            "app_name" => "VETSCHED",
            "expiration_minutes" => 15,
        ],
    ]);
    if ($payload === false) {
        error_log("Could not encode EmailJS request.");
        return false;
    }

    $curl = curl_init("https://api.emailjs.com/api/v1.0/email/send");
    if ($curl === false) {
        error_log("Could not initialize EmailJS HTTP request.");
        return false;
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
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
        error_log("EmailJS request failed (HTTP " . $status . "): " . $error . " " . $response);
        return false;
    }
    return true;
}
?>
