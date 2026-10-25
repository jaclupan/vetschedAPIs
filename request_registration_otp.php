<?php
header("Content-Type: application/json");
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/brevo_mailer.php";

function respond($status, $success, $message, $errorField = null) {
    http_response_code($status);
    $res = ["success" => $success, "message" => $message];
    if ($errorField) {
        $res["error_field"] = $errorField;
    }
    echo json_encode($res);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data["first_name"]) || empty($data["last_name"]) ||
    empty($data["email"]) || empty($data["student_id"]) ||
    empty($data["password"]) || empty($data["year_level"])) {

    $errorField = null;
    if (empty($data["student_id"])) $errorField = "student_id";
    else if (empty($data["email"])) $errorField = "email";
    else if (empty($data["year_level"])) $errorField = "year_level";

    respond(400, false, "All fields are required", $errorField);
}

$email = strtolower(trim($data["email"]));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || substr($email, -13) !== "@phinmaed.com") {
    respond(400, false, "Only @phinmaed.com email addresses are allowed", "email");
}

if ($data["year_level"] < 1 || $data["year_level"] > 5) {
    respond(400, false, "Year level must be between 1 and 5", "year_level");
}

if (strlen($data["password"]) < 10) {
    respond(400, false, "Password must be at least 10 characters");
}

try {
    // Check existing student_id in account table
    $checkId = $conn->prepare("SELECT student_id FROM account WHERE student_id = :id");
    $checkId->execute([":id" => $data["student_id"]]);
    if ($checkId->fetch()) {
        respond(409, false, "Student ID already registered", "student_id");
    }

    // Check existing email in account table
    $checkEmail = $conn->prepare("SELECT email FROM account WHERE email = :email");
    $checkEmail->execute([":email" => $email]);
    if ($checkEmail->fetch()) {
        respond(409, false, "Email already registered", "email");
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS account_otps (
            email TEXT PRIMARY KEY,
            student_id TEXT NOT NULL,
            code_hash TEXT NOT NULL,
            registration_data JSONB NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );

    // Rate limit check (60 seconds)
    $recent = $conn->prepare(
        "SELECT 1 FROM account_otps
         WHERE email = :email AND created_at > CURRENT_TIMESTAMP - INTERVAL '60 seconds'"
    );
    $recent->execute([":email" => $email]);
    if ($recent->fetchColumn()) {
        respond(200, true, "Verification code already sent. Please check your inbox.");
    }

    $code = (string) random_int(100000, 999999);
    $hashedPassword = password_hash($data["password"], PASSWORD_DEFAULT);

    $regData = [
        "student_id" => $data["student_id"],
        "first_name" => $data["first_name"],
        "last_name" => $data["last_name"],
        "email" => $email,
        "password_hash" => $hashedPassword,
        "year_level" => (int) $data["year_level"]
    ];

    $save = $conn->prepare(
        "INSERT INTO account_otps (email, student_id, code_hash, registration_data, expires_at, attempts, created_at)
         VALUES (:email, :student_id, :code_hash, :reg_data, CURRENT_TIMESTAMP + INTERVAL '15 minutes', 0, CURRENT_TIMESTAMP)
         ON CONFLICT (email) DO UPDATE SET
             student_id = EXCLUDED.student_id,
             code_hash = EXCLUDED.code_hash,
             registration_data = EXCLUDED.registration_data,
             expires_at = EXCLUDED.expires_at,
             attempts = 0,
             created_at = CURRENT_TIMESTAMP"
    );
    $save->execute([
        ":email" => $email,
        ":student_id" => $data["student_id"],
        ":code_hash" => password_hash($code, PASSWORD_DEFAULT),
        ":reg_data" => json_encode($regData)
    ]);

    if (!sendBrevoOtpEmail($email, $code)) {
        $remove = $conn->prepare("DELETE FROM account_otps WHERE email = :email");
        $remove->execute([":email" => $email]);
        respond(502, false, "Email delivery failed. Please check your Brevo API key and configuration.");
    }

    respond(200, true, "Verification code sent to your email.");
} catch (Throwable $e) {
    error_log("Registration OTP request failed: " . $e->getMessage());
    respond(500, false, "Could not send verification code. Please try again later.");
}
?>
