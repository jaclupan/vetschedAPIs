<?php
header("Content-Type: application/json");
require_once __DIR__ . "/db.php";

function verifyRegistrationRespond($status, $success, $message) {
    http_response_code($status);
    echo json_encode(["success" => $success, "message" => $message]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$email = is_array($data) ? strtolower(trim((string) ($data["email"] ?? ""))) : "";
$code = is_array($data) ? trim((string) ($data["code"] ?? "")) : "";
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
    verifyRegistrationRespond(400, false, "Enter the email address and 6-digit code");
}

try {
    $conn->beginTransaction();
    $lookup = $conn->prepare(
        "SELECT student_id, first_name, last_name, year_level, password_hash,
                code_hash, attempts, expires_at > CURRENT_TIMESTAMP AS is_valid
         FROM pending_registrations WHERE email = :email FOR UPDATE"
    );
    $lookup->execute([":email" => $email]);
    $pending = $lookup->fetch();

    if (!$pending) {
        $conn->rollBack();
        verifyRegistrationRespond(400, false, "No pending registration found. Request a new code.");
    }
    if ((int) $pending["attempts"] >= 5) {
        $conn->rollBack();
        verifyRegistrationRespond(429, false, "Too many incorrect attempts. Request a new code.");
    }
    if (!in_array($pending["is_valid"], [true, "t", "1", 1], true)) {
        $delete = $conn->prepare("DELETE FROM pending_registrations WHERE email = :email");
        $delete->execute([":email" => $email]);
        $conn->commit();
        verifyRegistrationRespond(400, false, "The verification code expired. Request a new code.");
    }
    if (!password_verify($code, $pending["code_hash"])) {
        $increment = $conn->prepare(
            "UPDATE pending_registrations SET attempts = attempts + 1 WHERE email = :email"
        );
        $increment->execute([":email" => $email]);
        $conn->commit();
        verifyRegistrationRespond(400, false, "The verification code is incorrect.");
    }

    $existing = $conn->prepare(
        "SELECT student_id FROM account WHERE LOWER(email) = :email OR student_id = :student_id"
    );
    $existing->execute([":email" => $email, ":student_id" => $pending["student_id"]]);
    if ($existing->fetch()) {
        $conn->rollBack();
        verifyRegistrationRespond(409, false, "Email or student ID has already been registered.");
    }

    $insert = $conn->prepare(
        "INSERT INTO account (student_id, first_name, last_name, email, password_hash, year_level)
         VALUES (:student_id, :first_name, :last_name, :email, :password_hash, :year_level)"
    );
    $insert->execute([
        ":student_id" => $pending["student_id"],
        ":first_name" => $pending["first_name"],
        ":last_name" => $pending["last_name"],
        ":email" => $email,
        ":password_hash" => $pending["password_hash"],
        ":year_level" => $pending["year_level"],
    ]);
    $delete = $conn->prepare("DELETE FROM pending_registrations WHERE email = :email");
    $delete->execute([":email" => $email]);
    $conn->commit();

    verifyRegistrationRespond(200, true, "Email verified and account created. You can now log in.");
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($e->getCode() === "23505") {
        verifyRegistrationRespond(409, false, "Email or student ID has already been registered.");
    }
    error_log("Registration OTP verification failed: " . $e->getMessage());
    verifyRegistrationRespond(500, false, "Could not complete registration. Please try again later.");
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Registration OTP verification failed: " . $e->getMessage());
    verifyRegistrationRespond(500, false, "Could not complete registration. Please try again later.");
}
?>
