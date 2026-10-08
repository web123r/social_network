<?php
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/database.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


if (!isset($_SESSION['ID'])) {
    header("Location: login.php");
    exit();
}

$currentUserId = (int)$_SESSION['ID'];
$errorMessage = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['upload_story'])) {
    if (!isset($_FILES['story_video']) || $_FILES['story_video']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = "Please select a valid video file to upload.";
    } else {
        $filename = $_FILES['story_video']['name'];
        $tempname = $_FILES['story_video']['tmp_name'];
        $fileSize = $_FILES['story_video']['size'];

   
        $maxSize = 50 * 1024 * 1024;
        if ($fileSize > $maxSize) {
            $errorMessage = "The video file size must be under 50MB.";
        } else {
          
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $allowedExts = ["mp4", "webm", "mov", "ogg"];

            if (!in_array($ext, $allowedExts)) {
                $errorMessage = "Invalid video extension. Allowed: mp4, webm, mov, ogg.";
            } else {
               
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tempname);
                finfo_close($finfo);

                $allowedMimes = [
                    "video/mp4",
                    "video/webm",
                    "video/quicktime",
                    "video/ogg"
                ];

                if (!in_array($mime, $allowedMimes)) {
                    $errorMessage = "Invalid file type. Please upload a standard video format.";
                } else {
                   
                    $videosDir = __DIR__ . '/videos';
                    if (!is_dir($videosDir)) {
                        mkdir($videosDir, 0755, true);
                    }

                    
                    $uniqueFilename = uniqid('story_', true) . '.' . $ext;
                    $serverDestination = $videosDir . '/' . $uniqueFilename;
                    $databasePath = 'videos/' . $uniqueFilename;

                    if (move_uploaded_file($tempname, $serverDestination)) {
                        
                        $caption = trim($_POST['caption'] ?? '');

                        $insertSql = "INSERT INTO `posts` (`user_id`, `content`, `media_type`, `media_path`, `is_story`) 
                                      VALUES (?, ?, 'video', ?, 1)";
                        $stmt = $conn->prepare($insertSql);
                        $stmt->bind_param("iss", $currentUserId, $caption, $databasePath);

                        if ($stmt->execute()) {
                            $stmt->close();
                            $_SESSION['flash_alert'] = [
                                'message' => 'Your story has been uploaded!',
                                'type' => 'success'
                            ];
                            header("Location: feed.php");
                            exit();
                        } else {
                            $errorMessage = "Database error: " . $stmt->error;
                            $stmt->close();
                        }
                    } else {
                        $errorMessage = "Failed to move the uploaded video file.";
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Video Story</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .story-preview-container {
            background-color: #000;
            border-radius: 12px;
            overflow: hidden;
            width: 100%;
            height: 380px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .story-preview-container video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
    </style>
</head>

<body class="bg-light min-vh-100 d-flex align-items-center py-5">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-5">

                <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3">
                        <h4 class="fw-bold mb-0">Create Story</h4>
                        <a href="feed.php" class="text-secondary text-decoration-none fs-5">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    </div>

                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger py-2 small" role="alert">
                            <?php echo htmlspecialchars($errorMessage); ?>
                        </div>
                    <?php endif; ?>

                   
                    <div class="story-preview-container mb-3 text-center text-muted" id="previewWrapper">
                        <div id="previewPlaceholder">
                            <i class="fa-solid fa-video fs-1 mb-2"></i>
                            <p class="small mb-0">Video preview will appear here</p>
                        </div>
                        <video id="videoPreview" controls style="display: none;" loop></video>
                    </div>

                    <form action="create_story.php" method="POST" enctype="multipart/form-data">
                        
                        <div class="mb-3">
                            <label for="story_video" class="form-label fw-semibold small">Select Video File</label>
                            <input type="file" class="form-control" id="story_video" name="story_video" 
                                accept="video/mp4,video/webm,video/quicktime,video/ogg" required>
                            <div class="form-text small">MP4, WebM, or MOV up to 50MB.</div>
                        </div>

                        <div class="mb-3">
                            <label for="caption" class="form-label fw-semibold small">Caption <span class="text-muted fw-normal">(Optional)</span></label>
                            <input type="text" class="form-control" id="caption" name="caption" 
                                placeholder="Add a caption to your story...">
                        </div>

                        <button type="submit" name="upload_story" class="btn btn-primary w-100 py-2 rounded-3 fw-semibold">
                            <i class="fa-solid fa-upload me-1"></i> Share to Story
                        </button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="feed.php" class="small text-decoration-none text-secondary">
                            <i class="fa-solid fa-arrow-left me-1"></i> Back to Feed
                        </a>
                    </div>

                </div>

            </div>
        </div>
    </div>

    <script>
        const videoInput = document.getElementById('story_video');
        const videoPreview = document.getElementById('videoPreview');
        const previewPlaceholder = document.getElementById('previewPlaceholder');

        videoInput.addEventListener('change', function () {
            const file = this.files[0];
            if (file) {
                const objectUrl = URL.createObjectURL(file);
                videoPreview.src = objectUrl;
                videoPreview.style.display = 'block';
                previewPlaceholder.style.display = 'none';
                videoPreview.play().catch(() => {});
            } else {
                videoPreview.src = '';
                videoPreview.style.display = 'none';
                previewPlaceholder.style.display = 'block';
            }
        });
    </script>
</body>

</html>