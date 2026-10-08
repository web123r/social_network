<?php 
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/database.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), 
            '', 
            time() - 42000,
            $params["path"], 
            $params["domain"],
            $params["secure"], 
            $params["httponly"]
        );
    }

    session_destroy();
    header("Location: login.php");
    exit();
}

if (isset($_SESSION["flash_alert"])) {
    renderAlert($_SESSION['flash_alert']["message"], $_SESSION['flash_alert']["type"]);
    unset($_SESSION["flash_alert"]);
}

if (!isset($_SESSION['ID'])) {
    header("Location: login.php");
    exit();
}

$currentUserId = (int)$_SESSION['ID'];
$defaultAvatar = "https://i.pravatar.cc/150?img=11";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_post'])) {
    $content = trim($_POST['post_content'] ?? '');
    $mediaPath = null;
    $mediaType = 'text';

    if (isset($_FILES['post_media']) && $_FILES['post_media']['error'] === UPLOAD_ERR_OK) {
        $filename = $_FILES['post_media']['name'];
        $tempname = $_FILES['post_media']['tmp_name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tempname);
        finfo_close($finfo);

        $imageMimes = ["image/jpeg", "image/png", "image/gif", "image/webp"];
        $videoMimes = ["video/mp4", "video/webm", "video/quicktime", "video/ogg"];

        if (in_array($mime, $imageMimes)) {
            $mediaType = 'image';
            $targetFolder = dirname(__DIR__) . '/uploads';
            $dbFolder = 'uploads';
        } elseif (in_array($mime, $videoMimes)) {
            $mediaType = 'video';
            $targetFolder = __DIR__ . '/videos';
            $dbFolder = 'videos';
        }

        if ($mediaType !== 'text') {
            if (!is_dir($targetFolder)) {
                mkdir($targetFolder, 0755, true);
            }
            $uniqueFilename = uniqid('post_', true) . '.' . $ext;
            if (move_uploaded_file($tempname, $targetFolder . '/' . $uniqueFilename)) {
                $mediaPath = $dbFolder . '/' . $uniqueFilename;
            }
        }
    }

    if (!empty($content) || !empty($mediaPath)) {
        $postSql = "INSERT INTO `posts` (`user_id`, `content`, `media_type`, `media_path`, `is_story`) VALUES (?, ?, ?, ?, 0)";
        $postStmt = $conn->prepare($postSql);
        $postStmt->bind_param("isss", $currentUserId, $content, $mediaType, $mediaPath);
        $postStmt->execute();
        $postStmt->close();
    }

    header("Location: profile.php");
    exit();
}

$userStmt = $conn->prepare("SELECT name, email, bio FROM users WHERE id = ? LIMIT 1");
$userStmt->bind_param("i", $currentUserId);
$userStmt->execute();
$userData = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$userData) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$name = htmlspecialchars($userData['name']);
$bio  = !empty($userData['bio']) ? htmlspecialchars($userData['bio']) : "No bio added yet.";

$imgStmt = $conn->prepare("SELECT path FROM user_images WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$imgStmt->bind_param("i", $currentUserId);
$imgStmt->execute();
$imgRes = $imgStmt->get_result()->fetch_assoc();
$imgStmt->close();

$profilePic = (!empty($imgRes['path']) && file_exists(__DIR__ . '/' . $imgRes['path'])) 
    ? htmlspecialchars($imgRes['path']) 
    : $defaultAvatar;

$friendsSql = "
    SELECT 
        u.id AS friend_id,
        u.name AS friend_name,
        COALESCE(img.path, ?) AS friend_avatar
    FROM users u
    INNER JOIN friendships f 
        ON (f.requester_id = u.id AND f.receiver_id = ?) 
        OR (f.receiver_id = u.id AND f.requester_id = ?)
    LEFT JOIN (
        SELECT ui1.user_id, ui1.path 
        FROM user_images ui1
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id 
            FROM user_images 
            GROUP BY user_id
        ) ui2 ON ui1.id = ui2.max_id
    ) img ON img.user_id = u.id
    WHERE f.status = 'accepted'
    ORDER BY f.updated_at DESC
";

$friendsStmt = $conn->prepare($friendsSql);
$friendsStmt->bind_param("sii", $defaultAvatar, $currentUserId, $currentUserId);
$friendsStmt->execute();
$friendsResult = $friendsStmt->get_result();

$friendsList = [];
while ($row = $friendsResult->fetch_assoc()) {
    $avatar = (!empty($row['friend_avatar']) && (filter_var($row['friend_avatar'], FILTER_VALIDATE_URL) || file_exists(__DIR__ . '/' . $row['friend_avatar'])))
        ? $row['friend_avatar']
        : $defaultAvatar;

    $friendsList[] = [
        'id'     => (int)$row['friend_id'],
        'name'   => htmlspecialchars($row['friend_name']),
        'avatar' => htmlspecialchars($avatar)
    ];
}
$friendsStmt->close();

$totalFriends = count($friendsList);

$myPostsSql = "SELECT id, content, media_type, media_path, created_at FROM posts WHERE user_id = ? AND is_story = 0 ORDER BY created_at DESC";
$myPostsStmt = $conn->prepare($myPostsSql);
$myPostsStmt->bind_param("i", $currentUserId);
$myPostsStmt->execute();
$myPostsRes = $myPostsStmt->get_result();

$myPosts = [];
while ($pRow = $myPostsRes->fetch_assoc()) {
    $myPosts[] = [
        'id'         => (int)$pRow['id'],
        'content'    => $pRow['content'],
        'media_type' => $pRow['media_type'],
        'media_path' => $pRow['media_path'],
        'created_at' => date('M j, Y \a\t g:i A', strtotime($pRow['created_at']))
    ];
}
$myPostsStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $name; ?> - Profile</title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .post-media-container {
            margin-top: 10px;
            border-radius: 8px;
            overflow: hidden;
            background: #000;
        }
        .post-media-container img,
        .post-media-container video {
            width: 100%;
            max-height: 500px;
            display: block;
            object-fit: contain;
        }
        .custom-file-upload {
            cursor: pointer;
        }
        .post-submit-btn {
            border: none;
            background: #1877f2;
            color: #fff;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 6px;
            cursor: pointer;
        }
        .file-selected-indicator {
            font-size: 12px;
            color: #1877f2;
            display: none;
            margin-left: 8px;
        }
    </style>
</head>
<body class="profile-page">
    <nav class="navbar">
        <div class="nav-left">
            <a href="feed.php"><img src="https://upload.wikimedia.org/wikipedia/commons/b/b8/2021_Facebook_icon.svg" alt="Logo" class="logo"></a>
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" placeholder="Search Facebook">
            </div>
        </div>
        <div class="nav-center">
            <a href="feed.php" class="nav-icon"><i class="fa-solid fa-house"></i></a>
            <a href="#" class="nav-icon"><i class="fa-regular fa-circle-play"></i></a>
            <a href="#" class="nav-icon"><i class="fa-solid fa-store"></i></a>
            <a href="#" class="nav-icon"><i class="fa-solid fa-users"></i></a>
            <a href="#" class="nav-icon"><i class="fa-solid fa-gamepad"></i></a>
        </div>
        <div class="nav-right">
            <div class="profile-link">
                <a href="profile.php">
                    <img src="<?php echo $profilePic; ?>" alt="<?php echo $name; ?>">
                </a>
            </div>
            <div class="icon-btn"><i class="fa-solid fa-bars"></i></div>
            <div class="icon-btn"><i class="fa-brands fa-facebook-messenger"></i></div>
            <div class="icon-btn"><i class="fa-solid fa-bell"></i></div>
        </div>
    </nav>

    <div class="profile-header-container">
        <div class="profile-header">
            <div class="cover-photo">
                <img src="https://images.unsplash.com/photo-1506744626753-dba37c10e8c0?w=1200" alt="Cover Photo">
                <button class="edit-cover-btn"><i class="fa-solid fa-camera"></i> Edit Cover Photo</button>
            </div>
            <div class="profile-info-section">
                <div class="profile-img-container">
                    <img src="<?php echo $profilePic; ?>" alt="<?php echo $name; ?>" class="profile-main-img">
                    <div class="camera-icon"><i class="fa-solid fa-camera"></i></div>
                </div>
                <div class="profile-details">
                    <h1><?php echo $name; ?></h1>
                    <p><?php echo $totalFriends; ?> <?php echo ($totalFriends === 1) ? 'friend' : 'friends'; ?></p>
                    
                    <?php if ($totalFriends > 0): ?>
                        <div class="friends-preview">
                            <?php foreach (array_slice($friendsList, 0, 5) as $fPreview): ?>
                                <img src="<?php echo $fPreview['avatar']; ?>" alt="<?php echo $fPreview['name']; ?>" title="<?php echo $fPreview['name']; ?>">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="profile-actions">
                    <a href="feed.php" class="btn btn-primary text-decoration-none d-inline-flex align-items-center">
                        <i class="fa-solid fa-house me-2"></i> Go to Feed
                    </a>
                    <a href="create_story.php" class="btn btn-secondary text-decoration-none d-inline-flex align-items-center">
                        <i class="fa-solid fa-plus me-2"></i> Add to Story
                    </a>
                    <a href="edit_user.php?id=<?php echo $currentUserId; ?>" class="btn btn-secondary text-decoration-none d-inline-flex align-items-center">
                        <i class="fa-solid fa-pen me-2"></i> Edit Profile
                    </a>
                    
                    <div class="dropdown d-inline-block">
                        <button class="btn btn-secondary dropdown-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li>
                                <a href="feed.php" class="dropdown-item d-flex align-items-center">
                                    <i class="fa-solid fa-house me-2 text-primary"></i> Go to Feed
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="profile.php" class="m-0">
                                    <button type="submit" name="logout" class="dropdown-item text-danger d-flex align-items-center">
                                        <i class="fa-solid fa-right-from-bracket me-2"></i> Log Out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <hr class="header-divider">
            <div class="profile-nav">
                <div class="nav-links">
                    <a href="#" class="active">Posts</a>
                    <a href="#">About</a>
                    <a href="#">Friends</a>
                    <a href="#">Photos</a>
                    <a href="#">Videos</a>
                    <a href="#">Check-ins</a>
                    <a href="#">More <i class="fa-solid fa-caret-down"></i></a>
                </div>
                <div class="nav-actions">
                    <button class="btn btn-secondary"><i class="fa-solid fa-ellipsis"></i></button>
                </div>
            </div>
        </div>
    </div>

    <div class="profile-content-container">
        <div class="profile-sidebar">
            <div class="intro-box">
                <h3>Intro</h3>
                <p class="bio"><?php echo nl2br($bio); ?></p>
                <a href="edit_user.php?id=<?php echo $currentUserId; ?>" class="btn btn-secondary w-100 mb-3 text-decoration-none">Edit Bio</a>
                <ul class="intro-details">
                    <li><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($userData['email']); ?></li>
                </ul>
                <a href="edit_user.php?id=<?php echo $currentUserId; ?>" class="btn btn-secondary w-100 text-decoration-none">Edit Details</a>
            </div>

            <div class="photos-box">
                <div class="box-header">
                    <h3>Photos</h3>
                    <a href="#">See All Photos</a>
                </div>
                <div class="photo-grid">
                    <img src="https://images.unsplash.com/photo-1542204165-65bf26472b9b?w=300" alt="photo">
                    <img src="https://images.unsplash.com/photo-1517694712202-14dd9538aa97?w=300" alt="photo">
                    <img src="https://images.unsplash.com/photo-1498050108023-c5249f4df085?w=300" alt="photo">
                    <img src="https://images.unsplash.com/photo-1504639725590-34d0984388bd?w=300" alt="photo">
                    <img src="https://images.unsplash.com/photo-1555066931-4365d14bab8c?w=300" alt="photo">
                    <img src="https://images.unsplash.com/photo-1493119508027-2b584f234d6c?w=300" alt="photo">
                </div>
            </div>

            <div class="friends-box">
                <div class="box-header">
                    <div>
                        <h3>Friends</h3>
                        <p><?php echo $totalFriends; ?> <?php echo ($totalFriends === 1) ? 'friend' : 'friends'; ?></p>
                    </div>
                    <?php if ($totalFriends > 0): ?>
                        <a href="view_profile.php">See All Friends</a>
                    <?php endif; ?>
                </div>

                <div class="friends-grid">
                    <?php if ($totalFriends > 0): ?>
                        <?php foreach (array_slice($friendsList, 0, 9) as $friend): ?>
                            <a href="view_profile.php?id=<?php echo $friend['id']; ?>" class="friend-card text-decoration-none text-dark">
                                <img src="<?php echo $friend['avatar']; ?>" alt="<?php echo $friend['name']; ?>">
                                <p><?php echo $friend['name']; ?></p>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted small p-2 mb-0">No friends to display yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="profile-timeline">
            <div class="create-post-box">
                <form action="profile.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="create_post" value="1">
                    <div class="create-post-top">
                        <img src="<?php echo $profilePic; ?>" alt="<?php echo $name; ?>">
                        <input type="text" name="post_content" placeholder="What's on your mind, <?php echo $name; ?>?">
                    </div>
                    <hr>
                    <div class="create-post-bottom" style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; align-items: center;">
                            <label class="action-btn custom-file-upload" style="margin-bottom: 0;">
                                <input type="file" name="post_media" id="profileMediaInput" accept="image/*,video/*" style="display: none;">
                                <i class="fa-regular fa-image" style="color: #45bd62;"></i>
                                <span>Photo/video</span>
                            </label>
                            <span id="profileFileBadge" class="file-selected-indicator"><i class="fa-solid fa-paperclip"></i> Media ready</span>
                        </div>
                        <button type="submit" class="post-submit-btn">Post</button>
                    </div>
                </form>
            </div>

            <?php if (!empty($myPosts)): ?>
                <?php foreach ($myPosts as $post): ?>
                    <div class="post">
                        <div class="post-header">
                            <img src="<?php echo $profilePic; ?>" alt="<?php echo $name; ?>">
                            <div class="post-info">
                                <h4><?php echo $name; ?></h4>
                                <span><?php echo $post['created_at']; ?> &middot; <i class="fa-solid fa-earth-americas"></i></span>
                            </div>
                            <i class="fa-solid fa-ellipsis post-options"></i>
                        </div>

                        <?php if (!empty($post['content'])): ?>
                            <div class="post-content">
                                <p><?php echo nl2br(htmlspecialchars($post['content'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($post['media_path'])): ?>
                            <div class="post-media-container">
                                <?php if ($post['media_type'] === 'image'): ?>
                                    <img src="<?php echo htmlspecialchars($post['media_path']); ?>" alt="Post media">
                                <?php elseif ($post['media_type'] === 'video'): ?>
                                    <video controls playsinline preload="metadata">
                                        <source src="<?php echo htmlspecialchars($post['media_path']); ?>">
                                        Your browser does not support HTML video.
                                    </video>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="post-stats">
                            <div class="likes">
                                <i class="fa-solid fa-thumbs-up" style="color: #1877f2;"></i>
                                <span>0</span>
                            </div>
                            <div class="comments-shares">
                                <span>0 comments</span>
                            </div>
                        </div>
                        <hr>
                        <div class="post-actions">
                            <div class="action-btn like-btn">
                                <i class="fa-regular fa-thumbs-up"></i>
                                <span>Like</span>
                            </div>
                            <div class="action-btn">
                                <i class="fa-regular fa-comment"></i>
                                <span>Comment</span>
                            </div>
                            <div class="action-btn">
                                <i class="fa-solid fa-share"></i>
                                <span>Share</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="post text-center py-4 text-muted">
                    <p class="mb-0">You haven't posted anything yet. Share an update above!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const mediaInput = document.getElementById('profileMediaInput');
        const fileBadge = document.getElementById('profileFileBadge');
        if (mediaInput) {
            mediaInput.addEventListener('change', function () {
                if (this.files && this.files.length > 0) {
                    fileBadge.style.display = 'inline';
                } else {
                    fileBadge.style.display = 'none';
                }
            });
        }
    </script>
    <script src="js/main.js"></script>
</body>
</html>