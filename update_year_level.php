<?php
header("Content-Type: application/json");
require_once "db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data["email"]) || empty($data["year_level"])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Email and Year Level required"]);
    exit;
}

if ($data["year_level"] < 1 || $data["year_level"] > 4) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Year level must be between 1 and 4"]);
    exit;
}

try {
    $conn->beginTransaction();

    // 1. Update year level in account table
    $stmt = $conn->prepare("UPDATE account SET year_level = :year_level WHERE email = :email");
    $stmt->execute([
        ":year_level" => $data["year_level"],
        ":email" => $data["email"]
    ]);

    // 2. Clear schedule data (plotting table) for this student
    // First we need to get the student_id from the account table
    $getId = $conn->prepare("SELECT student_id FROM account WHERE email = :email");
    $getId->execute([":email" => $data["email"]]);
    $student = $getId->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        $clearSchedule = $conn->prepare("DELETE FROM plotting WHERE student_id = :student_id");
        $clearSchedule->execute([":student_id" => $student["student_id"]]);
    }

    $conn->commit();
    echo json_encode(["success" => true, "message" => "Year level updated and schedule cleared"]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
