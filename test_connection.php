<?php
header("Content-Type: application/json");
require_once __DIR__ . "/db.php";

echo json_encode(["success" => true, "message" => "Connected to PostgreSQL"]);
?>
