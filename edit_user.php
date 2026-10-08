<?php
require_once __DIR__ . "/database.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


$targetId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);

if ($targetId <= 0) {
    die("Invalid or missing user ID.");
}

$errorMessage = "";
$successMessage = "";


if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_user'])) {
    $name = trim($_POST['edit_name'] ?? '');
    $email = trim($_POST['edit_email'] ?? '');
    $bio = trim($_POST['edit_bio'] ?? '');
    $newPassword = $_POST['edit_password'] ?? '';


    $emailCheck = $conn->prepare("SELECT id FROM `users` WHERE `email` = ? AND `id` != ? LIMIT 1");
    $emailCheck->bind_param("si", $email, $targetId);
    $emailCheck->execute();
    $emailCheckRes = $emailCheck->get_result();

    if ($emailCheckRes->num_rows > 0) {
        $errorMessage = "That email address is already in use by another account.";
    } else {
       
        if (!empty($newPassword)) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateUserSql = "UPDATE `users` SET `name` = ?, `email` = ?, `bio` = ?, `password` = ? WHERE `id` = ?";
            $userStmt = $conn->prepare($updateUserSql);
            $userStmt->bind_param("ssssi", $name, $email, $bio, $hashedPassword, $targetId);
        } else {
            $updateUserSql = "UPDATE `users` SET `name` = ?, `email` = ?, `bio` = ? WHERE `id` = ?";
            $userStmt = $conn->prepare($updateUserSql);
            $userStmt->bind_param("sssi", $name, $email, $bio, $targetId);
        }

        if ($userStmt->execute()) {
            $successMessage = "Profile updated successfully!";


            if (isset($_SESSION['ID']) && $_SESSION['ID'] === $targetId) {
                $_SESSION['Name'] = $name;
            }


            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $filename = $_FILES["image"]["name"];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $allowedExts = ["jpg", "png", "jpeg", "gif", "webp", "avif"];

                $tempname = $_FILES["image"]["tmp_name"];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tempname);
                finfo_close($finfo);
                $allowedMimes = ["image/jpeg", "image/png", "image/gif", "image/webp", "image/avif"];

                if (in_array($ext, $allowedExts) && in_array($mime, $allowedMimes)) {
                    $uploadDir = dirname(__DIR__) . '/uploads';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $uniqueFilename = uniqid('img_', true) . '.' . $ext;
                    $serverDestination = $uploadDir . '/' . $uniqueFilename;
                    $destination = "uploads/" . $uniqueFilename;

                    if (move_uploaded_file($tempname, $destination)) {
                        $destination = "uploads/" . $uniqueFilename;
                        $imgStmt = $conn->prepare("INSERT INTO `user_images` (`user_id`, `filename`, `path`) VALUES (?, ?, ?)");
                        $imgStmt->bind_param("iss", $targetId, $uniqueFilename, $destination);
                        $imgStmt->execute();
                        $imgStmt->close();
                   
                    }
                }
            }
                 $_SESSION['flash_alert'] = [
        'message' => 'User info updated Successfully',
        'type' => 'success'
    ];
    header("Location: profile.php");
    exit();
        } else {
            $errorMessage = "Error updating user: " . $userStmt->error;
        }
        $userStmt->close();
    }
    $emailCheck->close();
}


$query = "SELECT id, name, email, bio FROM `users` WHERE `id` = ? LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $targetId);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$userData) {
    die("User not found.");
}


$imgQuery = "SELECT path FROM `user_images` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1";
$imgStmt = $conn->prepare($imgQuery);
$imgStmt->bind_param("i", $targetId);

$imgStmt->execute(); 
    $imgRow = $imgStmt->get_result()->fetch_assoc();
    $currentAvatar = $imgRow['path'] ?? 'https://i.pravatar.cc/150?img=11';
    $imgStmt->close();
    

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>

<body class="bg-light d-flex align-items-center min-vh-100 py-5">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-8 col-lg-6">

                <div class="card border-0 shadow-lg rounded-4 p-4 p-sm-5 bg-white">
                    <div class="text-center mb-4">
                        <div class="position-relative d-inline-block mb-3">
                            <img src="<?php echo htmlspecialchars($currentAvatar); ?>" alt="Current Avatar"
                                class="rounded-circle object-fit-cover shadow-sm border" width="90" height="90">
                        </div>
                        <h3 class="fw-bold">Edit Profile</h3>
                        <p class="text-muted small">Update your account details below</p>
                    </div>

                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger py-2 small" role="alert">
                            <?php echo htmlspecialchars($errorMessage); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($successMessage)): ?>
                        <div class="alert alert-success py-2 small" role="alert">
                            <?php echo htmlspecialchars($successMessage); ?>
                        </div>
                    <?php endif; ?>

                    <form id="editForm" method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>"
                        enctype="multipart/form-data">


                        <input type="hidden" name="user_id" value="<?php echo (int) $userData['id']; ?>">
                        <input type="hidden" name="update_user" value="1">

                        <div class="mb-3">
                            <label for="name" class="form-label fw-semibold small">Full Name</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted">
                                    <i class="bi bi-person"></i>
                                </span>
                                <input type="text" class="form-control bg-light border-start-0 ps-0" id="name" required
                                    minlength="2" name="edit_name"
                                    value="<?php echo htmlspecialchars($userData['name']); ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold small">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted">
                                    <i class="bi bi-envelope"></i>
                                </span>
                                <input type="email" class="form-control bg-light border-start-0 ps-0" id="email"
                                    required name="edit_email"
                                    value="<?php echo htmlspecialchars($userData['email']); ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label fw-semibold small">
                                Password <span class="text-muted fw-normal">(Leave blank to keep current)</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted">
                                    <i class="bi bi-lock"></i>
                                </span>
                                <input type="password" class="form-control bg-light border-start-0 ps-0" id="password"
                                    placeholder="••••••••" minlength="6" name="edit_password">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="bio" class="form-label fw-semibold small">Bio</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted">
                                    <i class="bi bi-chat-quote"></i>
                                </span>
                                <textarea class="form-control bg-light border-start-0 ps-0" id="bio" rows="3"
                                    placeholder="Tell us about yourself..."
                                    name="edit_bio"><?php echo htmlspecialchars($userData['bio'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="image" class="form-label fw-semibold small">
                                Update Profile Picture <span class="text-muted fw-normal">(Optional)</span>
                            </label>
                            <div class="input-group">
                                <input type="file" class="form-control bg-light border-start-0 ps-0" id="image"
                                    name="image" accept="image/*">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 rounded-3 fw-semibold">
                            Save Changes
                        </button>
                    </form>

                    <div class="text-center mt-4 pt-2 border-top">
                        <a href="profile.php" class="small text-decoration-none fw-semibold text-secondary">
                            <i class="bi bi-arrow-left"></i> Return to Profile
                        </a>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>