<?php
header("Content-Type: application/json");
require_once "db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data["email"]) || empty($data["password"])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Email and password required"]);
    exit;
}

try {
    // First, find the user by email
    $sql = "SELECT student_id, first_name, last_name, email, password_hash, year_level FROM account
            WHERE email = :email";

    $stmt = $conn->prepare($sql);
    $stmt->execute([":email" => $data["email"]]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify the password using the hash stored in the DB
    if ($user && password_verify($data["password"], $user["password_hash"])) {
        // Remove the password hash from the response for security
        unset($user["password_hash"]);

        echo json_encode([
            "success" => true,
            "message" => "Login successful",
            "user" => $user
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Invalid email or password"]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
