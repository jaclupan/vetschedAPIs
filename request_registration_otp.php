<?php
header("Content-Type: application/json");
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/emailjs_mailer.php";

function registrationRespond(
    $status,
    $success,
    $message,
    $errorField = null,
    $resendAfterSeconds = null
) {
    http_response_code($status);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "error_field" => $errorField,
        "resend_after_seconds" => $resendAfterSeconds,
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    registrationRespond(400, false, "Invalid request data");
}

$firstName = trim((string) ($data["first_name"] ?? ""));
$lastName = trim((string) ($data["last_name"] ?? ""));
$email = strtolower(trim((string) ($data["email"] ?? "")));
$studentId = preg_replace('/\D/', '', (string) ($data["student_id"] ?? ""));
$yearLevel = filter_var($data["year_level"] ?? null, FILTER_VALIDATE_INT);
$password = (string) ($data["password"] ?? "");

if ($firstName === "" || $lastName === "" || $email === "" || $studentId === "" ||
    $yearLevel === false || $password === "") {
    registrationRespond(400, false, "All fields are required");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    registrationRespond(400, false, "Enter a valid email address", "email");
}
if (strlen($studentId) < 9 || strlen($studentId) > 12) {
    registrationRespond(400, false, "Student ID must contain 9 to 12 digits", "student_id");
}
if ($yearLevel < 1 || $yearLevel > 4) {
    registrationRespond(400, false, "Year level must be between 1 and 4", "year_level");
}
if (strlen($password) < 10) {
    registrationRespond(400, false, "Password must be at least 10 characters");
}

try {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS pending_registrations (
            email TEXT PRIMARY KEY,
            student_id TEXT NOT NULL UNIQUE,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            year_level INTEGER NOT NULL,
            password_hash TEXT NOT NULL,
            code_hash TEXT NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $conn->exec("DELETE FROM pending_registrations WHERE expires_at <= CURRENT_TIMESTAMP");

    $accountByEmail = $conn->prepare("SELECT 1 FROM account WHERE LOWER(email) = :email");
    $accountByEmail->execute([":email" => $email]);
    if ($accountByEmail->fetchColumn()) {
        registrationRespond(409, false, "Email already registered", "email");
    }

    $accountById = $conn->prepare("SELECT 1 FROM account WHERE student_id = :student_id");
    $accountById->execute([":student_id" => $studentId]);
    if ($accountById->fetchColumn()) {
        registrationRespond(409, false, "Student ID already registered", "student_id");
    }

    $pendingById = $conn->prepare(
        "SELECT email FROM pending_registrations WHERE student_id = :student_id AND email <> :email"
    );
    $pendingById->execute([":student_id" => $studentId, ":email" => $email]);
    if ($pendingById->fetchColumn()) {
        registrationRespond(409, false, "Student ID is being used by another pending registration", "student_id");
    }

    $recent = $conn->prepare(
        "SELECT GREATEST(0, CEIL(60 - EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - created_at))))::INTEGER
         FROM pending_registrations
         WHERE email = :email AND created_at > CURRENT_TIMESTAMP - INTERVAL '60 seconds'"
    );
    $recent->execute([":email" => $email]);
    $resendAfterSeconds = $recent->fetchColumn();
    if ($resendAfterSeconds !== false) {
        $update = $conn->prepare(
            "UPDATE pending_registrations
             SET student_id = :student_id, first_name = :first_name, last_name = :last_name,
                 year_level = :year_level, password_hash = :password_hash
             WHERE email = :email"
        );
        $update->execute([
            ":student_id" => $studentId,
            ":first_name" => $firstName,
            ":last_name" => $lastName,
            ":year_level" => $yearLevel,
            ":password_hash" => password_hash($password, PASSWORD_DEFAULT),
            ":email" => $email,
        ]);
        registrationRespond(
            200,
            true,
            "Registration details updated. Use the verification code already sent to your email.",
            null,
            (int) $resendAfterSeconds
        );
    }

    $code = (string) random_int(100000, 999999);
    $save = $conn->prepare(
        "INSERT INTO pending_registrations
            (email, student_id, first_name, last_name, year_level, password_hash,
             code_hash, expires_at, attempts, created_at)
         VALUES
            (:email, :student_id, :first_name, :last_name, :year_level, :password_hash,
             :code_hash, CURRENT_TIMESTAMP + INTERVAL '15 minutes', 0, CURRENT_TIMESTAMP)
         ON CONFLICT (email) DO UPDATE SET
            student_id = EXCLUDED.student_id,
            first_name = EXCLUDED.first_name,
            last_name = EXCLUDED.last_name,
            year_level = EXCLUDED.year_level,
            password_hash = EXCLUDED.password_hash,
            code_hash = EXCLUDED.code_hash,
            expires_at = EXCLUDED.expires_at,
            attempts = 0,
            created_at = CURRENT_TIMESTAMP"
    );
    $save->execute([
        ":email" => $email,
        ":student_id" => $studentId,
        ":first_name" => $firstName,
        ":last_name" => $lastName,
        ":year_level" => $yearLevel,
        ":password_hash" => password_hash($password, PASSWORD_DEFAULT),
        ":code_hash" => password_hash($code, PASSWORD_DEFAULT),
    ]);

    if (!sendEmailJsResetCode($email, $code)) {
        $delete = $conn->prepare("DELETE FROM pending_registrations WHERE email = :email");
        $delete->execute([":email" => $email]);
        registrationRespond(502, false, "Email delivery failed. Check the EmailJS configuration and template.");
    }

    $markSent = $conn->prepare(
        "UPDATE pending_registrations SET created_at = CURRENT_TIMESTAMP WHERE email = :email"
    );
    $markSent->execute([":email" => $email]);
    registrationRespond(
        200,
        true,
        "Verification code sent. It expires in 15 minutes.",
        null,
        60
    );
} catch (PDOException $e) {
    if ($e->getCode() === "23505") {
        registrationRespond(409, false, "Email or student ID is already registered");
    }
    error_log("Registration OTP request failed: " . $e->getMessage());
    registrationRespond(500, false, "Could not send a registration code. Please try again later.");
} catch (Throwable $e) {
    error_log("Registration OTP request failed: " . $e->getMessage());
    registrationRespond(500, false, "Could not send a registration code. Please try again later.");
}
?>
