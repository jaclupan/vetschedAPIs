<?php
header("Content-Type: application/json");
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/input_validation.php";

function changePasswordRespond(int $status, bool $success, string $message): void
{
    http_response_code($status);
    echo json_encode(["success" => $success, "message" => $message]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    changePasswordRespond(405, false, "POST required");
}

$data = vetschedReadJsonRequest();
$email = $data === null ? null : vetschedNormalizeEmail($data["email"] ?? null);
$oldPassword = $data["old_password"] ?? null;
$newPassword = $data["new_password"] ?? null;

if ($email === null || !is_string($oldPassword) || $oldPassword === "" || strlen($oldPassword) > 512) {
    changePasswordRespond(400, false, "Enter a valid account email and current password");
}
if (!vetschedIsValidPassword($newPassword)) {
    changePasswordRespond(400, false, "New password must contain 10 to 128 characters");
}

try {
    $conn->beginTransaction();
    $lookup = $conn->prepare(
        "SELECT password_hash FROM account WHERE LOWER(email) = :email FOR UPDATE"
    );
    $lookup->execute([":email" => $email]);
    $storedHash = $lookup->fetchColumn();

    if (!is_string($storedHash) || !password_verify($oldPassword, $storedHash)) {
        $conn->rollBack();
        changePasswordRespond(400, false, "Current password is incorrect");
    }

    $update = $conn->prepare(
        "UPDATE account SET password_hash = :password_hash WHERE LOWER(email) = :email"
    );
    $update->execute([
        ":password_hash" => password_hash($newPassword, PASSWORD_DEFAULT),
        ":email" => $email,
    ]);

    if ($update->rowCount() !== 1) {
        $conn->rollBack();
        changePasswordRespond(400, false, "Could not update this account password");
    }

    $conn->commit();
    changePasswordRespond(200, true, "Password updated successfully");
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Password change failed: " . $error->getMessage());
    changePasswordRespond(500, false, "Could not update password. Please try again later.");
}
?>
