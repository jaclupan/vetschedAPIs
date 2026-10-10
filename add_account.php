<?php
header("Content-Type: application/json");
require_once "db.php";

function verifyEmailAwesome($email) {
    $apiKey = "nWtxNmCWuQ2Bp8hPgLDcz6we73ZkvTlR87RS6CjN";

    // 1. Check domain MX DNS records
    $domain = substr(strrchr($email, "@"), 1);
    if (!$domain || !checkdnsrr($domain, "MX")) {
        return false;
    }

    // 2. Call EmailAwesome Verification API safely
    try {
        $url = "https://emailawesome.com/api/v1/verify?email=" . urlencode($email) . "&api_key=" . urlencode($apiKey);

        $ch = curl_init();
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER => [
                    "Accept: application/json",
                    "X-API-Key: " . $apiKey
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);

            $response = @curl_exec($ch);
            $httpCode = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
            @curl_close($ch);

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                $result = json_decode($response, true);
                if (is_array($result)) {
                    if (isset($result['status']) && in_array(strtolower($result['status']), ['invalid', 'undeliverable', 'disposable', 'bounced', 'false'])) {
                        return false;
                    }
                    if (isset($result['is_valid']) && $result['is_valid'] === false) {
                        return false;
                    }
                    if (isset($result['deliverable']) && $result['deliverable'] === false) {
                        return false;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log("EmailAwesome check warning: " . $e->getMessage());
    }

    return true;
}

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

$email = strtolower(trim($data["email"]));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || substr($email, -13) !== "@phinmaed.com") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Only @phinmaed.com email addresses are allowed", "error_field" => "email"]);
    exit;
}

if ($data["year_level"] < 1 || $data["year_level"] > 5) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Year level must be between 1 and 5", "error_field" => "year_level"]);
    exit;
}

if (strlen($data["password"]) < 10) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Password must be at least 10 characters"]);
    exit;
}

// Check Email deliverability / existence via EmailAwesome & MX check
if (!verifyEmailAwesome($email)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "This email address does not exist or cannot receive emails.",
        "error_field" => "email"
    ]);
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
    $checkEmail->execute([":email" => $email]);
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
        ":email"      => $email,
        ":password_hash" => $hashedPassword,
        ":year_level" => $data["year_level"]
    ]);

    echo json_encode(["success" => true, "message" => "Account created successfully"]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "DB Error: " . $e->getMessage()]);
}
?>
