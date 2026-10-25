<?php
header("Content-Type: application/json");
require_once __DIR__ . "/db.php";

function respond($status, $success, $message) {
    http_response_code($status);
    echo json_encode(["success" => $success, "message" => $message]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$email = isset($data["email"]) ? strtolower(trim($data["email"])) : "";
$otp = isset($data["otp"]) ? trim($data["otp"]) : "";

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($otp)) {
    respond(400, false, "Email and verification code are required");
}

try {
    $stmt = $conn->prepare("SELECT code_hash, registration_data, expires_at, attempts FROM account_otps WHERE email = :email");
    $stmt->execute([":email" => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        respond(400, false, "No pending verification found for this email. Please request a new code.");
    }

    if (strtotime($row["expires_at"]) < time()) {
        $conn->prepare("DELETE FROM account_otps WHERE email = :email")->execute([":email" => $email]);
        respond(400, false, "Verification code has expired. Please request a new code.");
    }

    if ((int)$row["attempts"] >= 5) {
        $conn->prepare("DELETE FROM account_otps WHERE email = :email")->execute([":email" => $email]);
        respond(400, false, "Too many incorrect attempts. Please request a new code.");
    }

    if (!password_verify($otp, $row["code_hash"])) {
        $conn->prepare("UPDATE account_otps SET attempts = attempts + 1 WHERE email = :email")->execute([":email" => $email]);
        respond(400, false, "Invalid verification code");
    }

    $regData = json_decode($row["registration_data"], true);
    if (!is_array($regData)) {
        respond(500, false, "Invalid registration data stored");
    }

    $conn->beginTransaction();

    // Final check for existing student ID or email
    $check = $conn->prepare("SELECT 1 FROM account WHERE student_id = :id OR email = :email");
    $check->execute([":id" => $regData["student_id"], ":email" => $email]);
    if ($check->fetch()) {
        $conn->rollBack();
        $conn->prepare("DELETE FROM account_otps WHERE email = :email")->execute([":email" => $email]);
        respond(409, false, "Account already exists");
    }

    $insert = $conn->prepare("INSERT INTO account (student_id, first_name, last_name, email, password_hash, year_level) VALUES (:student_id, :first_name, :last_name, :email, :password_hash, :year_level)");
    $insert->execute([
        ":student_id" => $regData["student_id"],
        ":first_name" => $regData["first_name"],
        ":last_name" => $regData["last_name"],
        ":email" => $regData["email"],
        ":password_hash" => $regData["password_hash"],
        ":year_level" => $regData["year_level"]
    ]);

    $conn->prepare("DELETE FROM account_otps WHERE email = :email")->execute([":email" => $email]);

    $conn->commit();
    respond(200, true, "Account created successfully!");
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("OTP verification and registration failed: " . $e->getMessage());
    respond(500, false, "Could not verify code and create account. Please try again.");
}
?>
