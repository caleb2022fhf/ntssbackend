<?php
// ================== SETTINGS ==================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

// ================== DATABASE INFO ==================
$host = "localhost";
$user = "root";
$pass = "";
$db   = "netstorage";

// ================== CONNECT TO MYSQL ==================
$conn = new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    die(json_encode(["status" => "database_connection_failed"]));
}

// ================== CREATE DATABASE IF NOT EXISTS ==================
$conn->query("CREATE DATABASE IF NOT EXISTS $db");
$conn->select_db($db);

// ================== CREATE TABLE IF NOT EXISTS ==================
$conn->query("
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE,
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ================== GET INPUT DATA ==================
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data["action"])) {
    echo json_encode(["status" => "no_action_provided"]);
    exit;
}

$action = $data["action"];

// ================== REGISTER ==================
if ($action === "register") {

    if (!isset($data["username"], $data["email"], $data["password"])) {
        echo json_encode(["status" => "missing_fields"]);
        exit;
    }

    $username = $conn->real_escape_string($data["username"]);
    $email = $conn->real_escape_string($data["email"]);
    $password = password_hash($data["password"], PASSWORD_DEFAULT);

    $check = $conn->query("SELECT id FROM users WHERE username='$username' OR email='$email'");
    if ($check->num_rows > 0) {
        echo json_encode(["status" => "user_exists"]);
        exit;
    }

    $insert = $conn->query("INSERT INTO users (username, email, password) 
                            VALUES ('$username', '$email', '$password')");

    if ($insert) {
        echo json_encode(["status" => "registered_successfully"]);
    } else {
        echo json_encode(["status" => "registration_failed"]);
    }
}

// ================== LOGIN ==================
if ($action === "login") {

    if (!isset($data["username"], $data["password"])) {
        echo json_encode(["status" => "missing_fields"]);
        exit;
    }

    $username = $conn->real_escape_string($data["username"]);
    $password = $data["password"];

    $result = $conn->query("SELECT * FROM users WHERE username='$username'");

    if ($result->num_rows === 0) {
        echo json_encode(["status" => "user_not_found"]);
        exit;
    }

    $user = $result->fetch_assoc();

    if (password_verify($password, $user["password"])) {
        echo json_encode([
            "status" => "login_success",
            "username" => $user["username"],
            "email" => $user["email"]
        ]);
    } else {
        echo json_encode(["status" => "wrong_password"]);
    }
}

$conn->close();
?>
