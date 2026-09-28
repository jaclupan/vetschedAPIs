<?php
function sendEmailJsResetCode($recipient, $code) {
    $configPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "private" .
        DIRECTORY_SEPARATOR . "vetsched_emailjs_config.php";
    if (!is_file($configPath)) {
        error_log("EmailJS is not configured. Expected config at " . $configPath);
        return false;
    }

    $config = require $configPath;
    $serviceId = $config["service_id"] ?? "";
    $templateId = $config["template_id"] ?? "";
    $publicKey = $config["public_key"] ?? "";
    $privateKey = $config["private_key"] ?? "";
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
