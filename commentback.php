<?php
// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Collect form data safely
    $email       = $_POST["email"] ?? "";
    $firstName   = $_POST["first_name"] ?? "";
    $secondName  = $_POST["second_name"] ?? "";
    $password    = $_POST["password"] ?? "";
    $confirmPass = $_POST["confirm_password"] ?? "";
    $pin         = $_POST["pin"] ?? "";

    $errors = [];

    // Basic validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    if (empty($firstName) || empty($secondName)) {
        $errors[] = "Name fields cannot be empty.";
    }
    if ($password !== $confirmPass) {
        $errors[] = "Passwords do not match.";
    }
    if (strlen($pin) < 4) {
        $errors[] = "PIN must be at least 4 digits.";
    }

    // Handle file upload if no errors
    if (empty($errors) && isset($_FILES["photo"])) {
        $targetDir = "uploads/";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $targetFile = $targetDir . basename($_FILES["photo"]["name"]);
        $fileType   = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

        // Allow only images
        $allowed = ["jpg", "jpeg", "png", "gif", "webp"];
        if (in_array($fileType, $allowed)) {
            if (move_uploaded_file($_FILES["photo"]["tmp_name"], $targetFile)) {
                $success = "Registration successful! File uploaded.";
            } else {
                $errors[] = "Error uploading file.";
            }
        } else {
            $errors[] = "Only image files are allowed.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NYANZA TSS</title>
    <style>
        body { margin:0; font-family:Arial,sans-serif; background:#f4f6f8; color:#222; }
        header { background:#0b5d2a; padding:20px; }
        .header-content { display:flex; align-items:center; justify-content:center; gap:15px; }
        header h1 { margin:0; color:white; letter-spacing:1px; }
        header img { width:60px; }
        .container { display:flex; justify-content:center; margin:50px 0; }
        .form-box { background:white; width:360px; padding:30px; border-radius:10px;
                    box-shadow:0 8px 20px rgba(0,0,0,0.15); }
        .form-box input { width:100%; padding:12px; margin:10px 0; border:1px solid #ccc;
                          border-radius:6px; font-size:14px; }
        .form-box input:focus { border-color:#0b5d2a; outline:none; }
        .form-box button { width:100%; padding:12px; background:#0b5d2a; color:white;
                           border:none; border-radius:6px; cursor:pointer; font-size:15px; margin-top:12px; }
        .form-box button:hover { background:#094a22; }
        #fileInput { display:none; }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <img src="Downloads/nyanza%20tss.webp" alt="NYANZA TSS Logo">
        <h1>NYANZA TSS</h1>
    </div>
</header>

<div class="container">
    <div class="form-box">
        <!-- Show errors or success -->
        <?php
        if (!empty($errors)) {
            echo "<div style='color:red;'><ul>";
            foreach ($errors as $e) echo "<li>$e</li>";
            echo "</ul></div>";
        }
        if (!empty($success)) {
            echo "<div style='color:green;'>$success</div>";
        }
        ?>

        <!-- Registration form -->
        <form method="post" enctype="multipart/form-data">
            <input type="email" name="email" placeholder="Email" required>
            <input type="text" name="first_name" placeholder="First Name" required>
            <input type="text" name="second_name" placeholder="Second Name" required>
            <input type="password" name="password" placeholder="Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <input type="number" name="pin" placeholder="PIN" required>

            <input type="file" id="fileInput" name="photo" accept="image/*" onchange="showSubmit()">

            <button type="button" id="uploadBtn" onclick="openFile()">Upload Photo</button>
            <button type="submit" id="submitBtn" style="display:none;">Submit</button>
        </form>
    </div>
</div>

<script>
    function openFile() {
        document.getElementById("uploadBtn").style.display = "none";
        document.getElementById("fileInput").click();
    }
    function showSubmit() {
        document.getElementById("submitBtn").style.display = "block";
    }
</script>
</body>
</html>
