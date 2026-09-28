<?php
header("Content-Type: application/json");
require_once "db.php";
require_once __DIR__ . "/emailjs_mailer.php";

function respond($status, $success, $message) {
    http_response_code($status);
    echo json_encode(["success" => $success, "message" => $message]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$email = isset($data["email"]) ? strtolower(trim($data["email"])) : "";
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, false, "Enter a valid email address");
}

try {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_otps (
            email TEXT PRIMARY KEY,
            code_hash TEXT NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );

    $account = $conn->prepare("SELECT email FROM account WHERE LOWER(email) = :email");
    $account->execute([":email" => $email]);
    $registeredEmail = $account->fetchColumn();
    if ($registeredEmail === false) {
        respond(200, true, "If an account exists, a verification code has been sent to its registered email.");
    }

    $registeredEmail = trim($registeredEmail);
    $storedEmail = strtolower($registeredEmail);
    $recent = $conn->prepare(
        "SELECT 1 FROM password_reset_otps
         WHERE email = :email AND created_at > CURRENT_TIMESTAMP - INTERVAL '60 seconds'"
    );
    $recent->execute([":email" => $storedEmail]);
    if ($recent->fetchColumn()) {
        respond(200, true, "If an account exists, a verification code has been sent to its registered email.");
    }

    $code = (string) random_int(100000, 999999);
    $save = $conn->prepare(
        "INSERT INTO password_reset_otps (email, code_hash, expires_at, attempts, created_at)
         VALUES (:email, :code_hash, CURRENT_TIMESTAMP + INTERVAL '15 minutes', 0, CURRENT_TIMESTAMP)
         ON CONFLICT (email) DO UPDATE SET
             code_hash = EXCLUDED.code_hash,
             expires_at = EXCLUDED.expires_at,
             attempts = 0,
             created_at = CURRENT_TIMESTAMP"
    );
    $save->execute([
        ":email" => $storedEmail,
        ":code_hash" => password_hash($code, PASSWORD_DEFAULT),
    ]);

    if (!sendEmailJsResetCode($registeredEmail, $code)) {
        $removeUnsent = $conn->prepare("DELETE FROM password_reset_otps WHERE email = :email");
        $removeUnsent->execute([":email" => $storedEmail]);
        respond(502, false, "Email delivery failed. Check the EmailJS configuration and template.");
    }

    respond(200, true, "If an account exists, a verification code has been sent to its registered email.");
} catch (Throwable $e) {
    error_log("Password reset request failed: " . $e->getMessage());
    respond(500, false, "Could not request a password reset. Please try again later.");
}
?>
