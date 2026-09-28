<?php
header("Content-Type: application/json");
require_once "db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data["first_name"]) || empty($data["last_name"]) ||
    empty($data["email"]) || empty($data["student_id"]) ||
    empty($data["password"]) || empty($data["year_level"])) {

    $errorField = null;
    if (empty($data["student_id"])) $errorField = "student_id";
    else if (empty($data["email"])) $errorField = "email";
    else if (empty($data["year_level"])) $errorField = "year_level";

    http_response_code(400);
    echo json_encode(["success" => false, "message" => "All fields are required", "error_field" => $errorField]);
    exit;
}

if ($data["year_level"] < 1 || $data["year_level"] > 4) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Year level must be between 1 and 4", "error_field" => "year_level"]);
    exit;
}

if (strlen($data["password"]) < 10) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Password must be at least 10 characters"]);
    exit;
}

try {
    // Check for existing Student ID
    $checkId = $conn->prepare("SELECT student_id FROM account WHERE student_id = :id");
    $checkId->execute([":id" => $data["student_id"]]);
    if ($checkId->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Student ID already registered", "error_field" => "student_id"]);
        exit;
    }

    // Check for existing Email
    $checkEmail = $conn->prepare("SELECT email FROM account WHERE email = :email");
    $checkEmail->execute([":email" => $data["email"]]);
    if ($checkEmail->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Email already registered", "error_field" => "email"]);
        exit;
    }

    $hashedPassword = password_hash($data["password"], PASSWORD_DEFAULT);

    $sql = "INSERT INTO account (student_id, first_name, last_name, email, password_hash, year_level)
            VALUES (:student_id, :first_name, :last_name, :email, :password_hash, :year_level)";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ":student_id" => $data["student_id"],
        ":first_name" => $data["first_name"],
        ":last_name"  => $data["last_name"],
        ":email"      => $data["email"],
        ":password_hash" => $hashedPassword,
        ":year_level" => $data["year_level"]
    ]);

    echo json_encode(["success" => true, "message" => "Account created successfully"]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "DB Error: " . $e->getMessage()]);
}
?>
