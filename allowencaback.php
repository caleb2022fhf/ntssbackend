<?php
// Define the correct password (in real apps, store securely in DB with hashing)
$correct_password = "nyanza123";

$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST["password"];

    if ($password === $correct_password) {
        $message = "<h2 style='color:green;'>Access Granted ✅</h2>";
        // Example: redirect to another page
        // header("Location: dashboard.php");
        // exit();
    } else {
        $message = "<h2 style='color:red;'>Access Denied ❌</h2>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NYANZA TSS</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #222;
        }
        header {
            background: #0b5d2a;
            padding: 20px;
        }
        .header-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        header h1 {
            margin: 0;
            color: white;
            letter-spacing: 1px;
        }
        header img {
            width: 60px;
        }
        .container {
            display: flex;
            justify-content: center;
            margin-top: 60px;
        }
        .form-box {
            background: white;
            width: 350px;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            text-align: center;
        }
        .form-box h2 {
            color: #0b5d2a;
            margin-bottom: 20px;
        }
        .form-box input {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;
        }
        .form-box button {
            width: 100%;
            padding: 12px;
            background: #0b5d2a;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            cursor: pointer;
        }
        .form-box button:hover {
            background: #094a22;
        }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <img src="nyanza%20tss.webp" alt="NYANZA TSS Logo">
        <h1>NYANZA TSS</h1>
    </div>
</header>

<div class="container">
    <div class="form-box">
        <h2>Enter Password</h2>
        <form method="post">
            <input type="password" name="password" placeholder="Enter password" required>
            <button type="submit">Submit</button>
        </form>
        <!-- Show message after submission -->
        <?php
        if (!empty($message)) {
            echo $message;
        }
        ?>
    </div>
</div>
</body>
</html>
