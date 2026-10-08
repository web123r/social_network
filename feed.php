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

    header("Location: feed.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $postId = (int)($_POST['post_id'] ?? 0);
    $commentText = trim($_POST['comment_text'] ?? '');

    if ($postId > 0 && !empty($commentText)) {
        $cmtSql = "INSERT INTO `comments` (`post_id`, `user_id`, `comment`) VALUES (?, ?, ?)";
        $cmtStmt = $conn->prepare($cmtSql);
        $cmtStmt->bind_param("iis", $postId, $currentUserId, $commentText);
        $cmtStmt->execute();
        $cmtStmt->close();
    }

    header("Location: feed.php#post-" . $postId);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['target_user_id'])) {
    $action = $_POST['action'];
    $targetUserId = (int)$_POST['target_user_id'];

    if ($targetUserId > 0 && $targetUserId !== $currentUserId) {
        if ($action === 'send_request') {
            $checkSql = "SELECT id FROM friendships 
                         WHERE (requester_id = ? AND receiver_id = ?) 
                            OR (requester_id = ? AND receiver_id = ?) LIMIT 1";
            $chk = $conn->prepare($checkSql);
            $chk->bind_param("iiii", $currentUserId, $targetUserId, $targetUserId, $currentUserId);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $sendSql = "INSERT INTO friendships (requester_id, receiver_id, status) VALUES (?, ?, 'pending')";
                $sendStmt = $conn->prepare($sendSql);
                $sendStmt->bind_param("ii", $currentUserId, $targetUserId);
                $sendStmt->execute();
                $sendStmt->close();
            }
            $chk->close();
        } elseif ($action === 'accept_request') {
            $acceptSql = "UPDATE friendships SET status = 'accepted' 
                          WHERE requester_id = ? AND receiver_id = ? AND status = 'pending'";
            $accStmt = $conn->prepare($acceptSql);
            $accStmt->bind_param("ii", $targetUserId, $currentUserId);
            $accStmt->execute();
            $accStmt->close();
        }
    }

    header("Location: feed.php");
    exit();
}

$userStmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
$userStmt->bind_param("i", $currentUserId);
$userStmt->execute();
$userData = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

$currentUserName = htmlspecialchars($userData['name'] ?? 'User');

$imgStmt = $conn->prepare("SELECT path FROM user_images WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$imgStmt->bind_param("i", $currentUserId);
$imgStmt->execute();
$currentUserImgRow = $imgStmt->get_result()->fetch_assoc();
$imgStmt->close();

$currentUserPic = (!empty($currentUserImgRow['path']) && file_exists(__DIR__ . '/' . $currentUserImgRow['path'])) 
    ? htmlspecialchars($currentUserImgRow['path']) 
    : $defaultAvatar;

$storiesSql = "
    SELECT 
        p.id AS story_id,
        p.media_path,
        p.content AS caption,
        u.id AS user_id,
        u.name AS user_name,
        COALESCE(img.path, ?) AS user_avatar
    FROM posts p
    INNER JOIN users u ON p.user_id = u.id
    LEFT JOIN (
        SELECT ui1.user_id, ui1.path 
        FROM user_images ui1
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id 
            FROM user_images 
            GROUP BY user_id
        ) ui2 ON ui1.id = ui2.max_id
    ) img ON img.user_id = u.id
    WHERE p.is_story = 1
      AND p.created_at >= NOW() - INTERVAL 1 DAY
      AND (
          p.user_id = ?
          OR p.user_id IN (
              SELECT CASE 
                  WHEN requester_id = ? THEN receiver_id 
                  ELSE requester_id 
              END
              FROM friendships
              WHERE (requester_id = ? OR receiver_id = ?)
                AND status = 'accepted'
          )
      )
    ORDER BY p.created_at DESC
";

$storiesStmt = $conn->prepare($storiesSql);
$storiesStmt->bind_param("siiii", $defaultAvatar, $currentUserId, $currentUserId, $currentUserId, $currentUserId);
$storiesStmt->execute();
$storiesRes = $storiesStmt->get_result();

$activeStories = [];
while ($sRow = $storiesRes->fetch_assoc()) {
    $activeStories[] = [
        'id'          => (int)$sRow['story_id'],
        'video_path'  => htmlspecialchars($sRow['media_path']),
        'caption'     => htmlspecialchars($sRow['caption'] ?? ''),
        'user_id'     => (int)$sRow['user_id'],
        'user_name'   => htmlspecialchars($sRow['user_name']),
        'user_avatar' => htmlspecialchars($sRow['user_avatar'])
    ];
}
$storiesStmt->close();

$postsSql = "
    SELECT 
        p.id AS post_id,
        p.content,
        p.media_type,
        p.media_path,
        p.created_at,
        u.id AS author_id,
        u.name AS author_name,
        COALESCE(img.path, ?) AS author_avatar,
        (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
    FROM posts p
    INNER JOIN users u ON p.user_id = u.id
    LEFT JOIN (
        SELECT ui1.user_id, ui1.path 
        FROM user_images ui1
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id 
            FROM user_images 
            GROUP BY user_id
        ) ui2 ON ui1.id = ui2.max_id
    ) img ON img.user_id = u.id
    WHERE p.is_story = 0
      AND (
          p.user_id = ?
          OR p.user_id IN (
              SELECT CASE 
                  WHEN requester_id = ? THEN receiver_id 
                  ELSE requester_id 
              END
              FROM friendships
              WHERE (requester_id = ? OR receiver_id = ?)
                AND status = 'accepted'
          )
      )
    ORDER BY p.created_at DESC
";

$postsStmt = $conn->prepare($postsSql);
$postsStmt->bind_param("siiii", $defaultAvatar, $currentUserId, $currentUserId, $currentUserId, $currentUserId);
$postsStmt->execute();
$postsRes = $postsStmt->get_result();

$feedPosts = [];
$postIds = [];
while ($pRow = $postsRes->fetch_assoc()) {
    $postId = (int)$pRow['post_id'];
    $postIds[] = $postId;
    $feedPosts[$postId] = [
        'id'            => $postId,
        'content'       => $pRow['content'],
        'media_type'    => $pRow['media_type'],
        'media_path'    => $pRow['media_path'],
        'created_at'    => date('M j, Y \a\t g:i A', strtotime($pRow['created_at'])),
        'author_id'     => (int)$pRow['author_id'],
        'author_name'   => htmlspecialchars($pRow['author_name']),
        'author_avatar' => htmlspecialchars($pRow['author_avatar']),
        'comment_count' => (int)$pRow['comment_count'],
        'comments'      => []
    ];
}
$postsStmt->close();

if (!empty($postIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($postIds), '?'));
    $types = "s" . str_repeat('i', count($postIds));

    $cmtsQuery = "
        SELECT 
            c.id AS comment_id,
            c.post_id,
            c.comment,
            c.created_at,
            u.id AS commenter_id,
            u.name AS commenter_name,
            COALESCE(img.path, ?) AS commenter_avatar
        FROM comments c
        INNER JOIN users u ON c.user_id = u.id
        LEFT JOIN (
            SELECT ui1.user_id, ui1.path 
            FROM user_images ui1
            INNER JOIN (
                SELECT user_id, MAX(id) AS max_id 
                FROM user_images 
                GROUP BY user_id
            ) ui2 ON ui1.id = ui2.max_id
        ) img ON img.user_id = u.id
        WHERE c.post_id IN ($inPlaceholders)
        ORDER BY c.created_at ASC
    ";

    $cmtStmt = $conn->prepare($cmtsQuery);
    $bindParams = array_merge([$defaultAvatar], $postIds);
    $cmtStmt->bind_param($types, ...$bindParams);
    $cmtStmt->execute();
    $cmtsRes = $cmtStmt->get_result();

    while ($cRow = $cmtsRes->fetch_assoc()) {
        $targetPostId = (int)$cRow['post_id'];
        if (isset($feedPosts[$targetPostId])) {
            $feedPosts[$targetPostId]['comments'][] = [
                'id'         => (int)$cRow['comment_id'],
                'comment'    => htmlspecialchars($cRow['comment']),
                'created_at' => date('M j, g:i A', strtotime($cRow['created_at'])),
                'user_id'    => (int)$cRow['commenter_id'],
                'user_name'  => htmlspecialchars($cRow['commenter_name']),
                'avatar'     => htmlspecialchars($cRow['commenter_avatar'])
            ];
        }
    }
    $cmtStmt->close();
}

$contactsSql = "
    SELECT 
        u.id, 
        u.name, 
        COALESCE(img.path, ?) AS avatar,
        f.status,
        f.requester_id,
        f.receiver_id
    FROM users u
    LEFT JOIN (
        SELECT ui1.user_id, ui1.path 
        FROM user_images ui1
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id 
            FROM user_images 
            GROUP BY user_id
        ) ui2 ON ui1.id = ui2.max_id
    ) img ON img.user_id = u.id
    LEFT JOIN friendships f 
        ON (f.requester_id = u.id AND f.receiver_id = ?) 
        OR (f.receiver_id = u.id AND f.requester_id = ?)
    WHERE u.id != ?
    ORDER BY u.id DESC
";

$contactsStmt = $conn->prepare($contactsSql);
$contactsStmt->bind_param("siii", $defaultAvatar, $currentUserId, $currentUserId, $currentUserId);
$contactsStmt->execute();
$contactsRes = $contactsStmt->get_result();

$contacts = [];
while ($row = $contactsRes->fetch_assoc()) {
    $contacts[] = [
        'id'           => (int)$row['id'],
        'name'         => htmlspecialchars($row['name']),
        'avatar'       => htmlspecialchars($row['avatar']),
        'status'       => $row['status'],
        'requester_id' => (int)$row['requester_id'],
        'receiver_id'  => (int)$row['receiver_id']
    ];
}
$contactsStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feed - Social Network</title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .contact-item-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 10px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .contact-item-container:hover { background-color: rgba(0, 0, 0, 0.05); }
        .contact-info {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
            flex-grow: 1;
            overflow: hidden;
        }
        .contact-info span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 14px;
            font-weight: 500;
        }
        .friend-action-btn {
            border: none;
            outline: none;
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
            white-space: nowrap;
        }
        .friend-action-btn:hover { opacity: 0.9; }
        .btn-add { background-color: #e7f3ff; color: #1877f2; }
        .btn-accept { background-color: #1877f2; color: #ffffff; }
        .btn-sent { background-color: #e4e6eb; color: #65676b; cursor: default; }
        .btn-friends { background-color: transparent; color: #31a24c; cursor: default; }

        .story-clickable {
            cursor: pointer;
            position: relative;
            overflow: hidden;
            background: #000;
            transition: transform 0.2s ease;
        }
        .story-clickable:hover { transform: scale(1.02); }
        .story-video-bg {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 10px;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 1;
            pointer-events: none;
        }
        .story-author-tag {
            position: absolute;
            bottom: 8px;
            left: 8px;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            z-index: 2;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.8);
            margin: 0;
        }
        .story-avatar-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 3px solid #1877f2;
            z-index: 2;
            object-fit: cover;
        }

        .story-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background-color: rgba(0, 0, 0, 0.92);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .story-modal-overlay.active { display: flex; }
        .story-viewer-container {
            position: relative;
            width: 100%;
            max-width: 420px;
            height: 90vh;
            max-height: 800px;
            background: #000;
            border-radius: 14px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6);
        }
        .story-header-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            z-index: 10;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(180deg, rgba(0,0,0,0.7) 0%, transparent 100%);
        }
        .story-user-meta {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .story-user-meta img {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 2px solid #fff;
            object-fit: cover;
        }
        .story-user-meta span {
            color: #fff;
            font-size: 14px;
            font-weight: 600;
        }
        .close-story-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: #fff;
            font-size: 18px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .close-story-btn:hover { background: rgba(255, 255, 255, 0.4); }
        .story-modal-video {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #000;
        }
        .story-caption-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 16px;
            background: linear-gradient(0deg, rgba(0,0,0,0.8) 0%, transparent 100%);
            color: #fff;
            font-size: 14px;
            z-index: 10;
        }

        .post-media-container {
            margin-top: 12px;
            border-radius: 8px;
            overflow: hidden;
            background: #000;
        }
        .post-media-container img,
        .post-media-container video {
            width: 100%;
            max-height: 520px;
            display: block;
            object-fit: contain;
        }
        .custom-file-upload { cursor: pointer; }
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

        .nav-profile-btn {
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
        }

        .comments-section {
            border-top: 1px solid #e4e6eb;
            margin-top: 10px;
            padding-top: 12px;
        }
        .comment-item {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        .comment-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }
        .comment-bubble {
            background-color: #f0f2f5;
            border-radius: 16px;
            padding: 8px 12px;
            display: inline-block;
            max-width: calc(100% - 45px);
        }
        .comment-author-name {
            font-size: 13px;
            font-weight: 600;
            color: #050505;
            text-decoration: none;
            display: block;
            line-height: 1.2;
            margin-bottom: 2px;
        }
        .comment-text {
            font-size: 13px;
            color: #050505;
            margin: 0;
            word-break: break-word;
        }
        .comment-time {
            font-size: 11px;
            color: #65676b;
            margin-left: 12px;
            margin-top: 2px;
        }
        .comment-input-container {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 12px;
        }
        .comment-input-container input {
            border-radius: 20px;
            background-color: #f0f2f5;
            border: none;
            padding: 8px 14px;
            font-size: 13px;
            flex-grow: 1;
        }
        .comment-input-container input:focus {
            outline: none;
            background-color: #e4e6eb;
        }
        .comment-send-btn {
            background: none;
            border: none;
            color: #1877f2;
            font-size: 16px;
            cursor: pointer;
            padding: 4px 8px;
        }
    </style>
</head>
<body class="feed-page">
    <nav class="navbar">
        <div class="nav-left">
            <a href="feed.php"><img src="https://upload.wikimedia.org/wikipedia/commons/b/b8/2021_Facebook_icon.svg" alt="Logo" class="logo"></a>
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" placeholder="Search Facebook">
            </div>
        </div>
        <div class="nav-center">
            <a href="feed.php" class="nav-icon active"><i class="fa-solid fa-house"></i></a>
            <a href="#" class="nav-icon"><i class="fa-regular fa-circle-play"></i></a>
            <a href="#" class="nav-icon"><i class="fa-solid fa-store"></i></a>
            <a href="#" class="nav-icon"><i class="fa-solid fa-users"></i></a>
            <a href="#" class="nav-icon"><i class="fa-solid fa-gamepad"></i></a>
        </div>
        <div class="nav-right">
            <div class="dropdown">
                <button class="nav-profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="<?php echo $currentUserPic; ?>" alt="<?php echo $currentUserName; ?>" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2">
                    <li>
                        <a class="dropdown-item d-flex align-items-center py-2" href="profile.php">
                            <i class="fa-solid fa-user me-2 text-secondary"></i> View Profile
                        </a>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <form method="POST" action="feed.php" class="m-0">
                            <button type="submit" name="logout" class="dropdown-item text-danger d-flex align-items-center py-2">
                                <i class="fa-solid fa-right-from-bracket me-2"></i> Log Out
                            </button>
                        </form>
                    </li>
                </ul>
            </div>

            <div class="icon-btn"><i class="fa-solid fa-bars"></i></div>
            <div class="icon-btn"><i class="fa-brands fa-facebook-messenger"></i></div>
            <div class="icon-btn"><i class="fa-solid fa-bell"></i></div>
        </div>
    </nav>

    <div class="main-container">
        <div class="left-sidebar">
            <a href="profile.php" class="sidebar-item">
                <img src="<?php echo $currentUserPic; ?>" alt="<?php echo $currentUserName; ?>">
                <span><?php echo $currentUserName; ?></span>
            </a>
            <a href="#" class="sidebar-item">
                <i class="fa-solid fa-user-group" style="color: #1877f2;"></i>
                <span>Friends</span>
            </a>
            <a href="#" class="sidebar-item">
                <i class="fa-solid fa-clock-rotate-left" style="color: #1877f2;"></i>
                <span>Memories</span>
            </a>
            <a href="#" class="sidebar-item">
                <i class="fa-solid fa-bookmark" style="color: #a322c3;"></i>
                <span>Saved</span>
            </a>
            <a href="#" class="sidebar-item">
                <i class="fa-solid fa-users" style="color: #1877f2;"></i>
                <span>Groups</span>
            </a>
            <a href="#" class="sidebar-item">
                <i class="fa-solid fa-video" style="color: #1877f2;"></i>
                <span>Video</span>
            </a>
            <a href="#" class="sidebar-item">
                <i class="fa-solid fa-store" style="color: #1877f2;"></i>
                <span>Marketplace</span>
            </a>
            <hr>
            <h4>Your shortcuts</h4>
            <a href="#" class="sidebar-item">
                <img src="https://i.pravatar.cc/150?img=3" alt="Group">
                <span>Web Developers Group</span>
            </a>
        </div>

        <div class="main-content">
            <div class="stories-container">
                <a href="create_story.php" class="story create-story text-decoration-none">
                    <img src="<?php echo $currentUserPic; ?>" alt="Profile" class="story-img">
                    <div class="create-story-btn"><i class="fa-solid fa-plus"></i></div>
                    <p>Create story</p>
                </a>

                <?php if (!empty($activeStories)): ?>
                    <?php foreach ($activeStories as $story): ?>
                        <div class="story story-clickable" 
                             data-video="<?php echo $story['video_path']; ?>"
                             data-author="<?php echo $story['user_name']; ?>"
                             data-avatar="<?php echo $story['user_avatar']; ?>"
                             data-caption="<?php echo $story['caption']; ?>">
                            <video class="story-video-bg" muted loop playsinline preload="metadata">
                                <source src="<?php echo $story['video_path']; ?>" type="video/mp4">
                            </video>
                            <img src="<?php echo $story['user_avatar']; ?>" alt="<?php echo $story['user_name']; ?>" class="story-avatar-badge">
                            <p class="story-author-tag"><?php echo $story['user_name']; ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="create-post-box">
                <form action="feed.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="create_post" value="1">
                    <div class="create-post-top">
                        <a href="profile.php"><img src="<?php echo $currentUserPic; ?>" alt="Profile"></a>
                        <input type="text" name="post_content" placeholder="What's on your mind, <?php echo $currentUserName; ?>?">
                    </div>
                    <hr>
                    <div class="create-post-bottom" style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; align-items: center;">
                            <label class="action-btn custom-file-upload" style="margin-bottom: 0;">
                                <input type="file" name="post_media" id="postMediaInput" accept="image/*,video/*" style="display: none;">
                                <i class="fa-regular fa-image" style="color: #45bd62;"></i>
                                <span>Photo/video</span>
                            </label>
                            <span id="fileBadge" class="file-selected-indicator"><i class="fa-solid fa-paperclip"></i> Media ready</span>
                        </div>
                        <button type="submit" class="post-submit-btn">Post</button>
                    </div>
                </form>
            </div>

            <?php if (!empty($feedPosts)): ?>
                <?php foreach ($feedPosts as $post): ?>
                    <div class="post" id="post-<?php echo $post['id']; ?>">
                        <div class="post-header">
                            <a href="view_profile.php?id=<?php echo $post['author_id']; ?>">
                                <img src="<?php echo $post['author_avatar']; ?>" alt="<?php echo $post['author_name']; ?>">
                            </a>
                            <div class="post-info">
                                <h4>
                                    <a href="view_profile.php?id=<?php echo $post['author_id']; ?>" style="text-decoration: none; color: inherit;">
                                        <?php echo $post['author_name']; ?>
                                    </a>
                                </h4>
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
                                    <img src="<?php echo htmlspecialchars($post['media_path']); ?>" alt="Post image">
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
                                <span class="comment-toggle-trigger" onclick="toggleComments(<?php echo $post['id']; ?>)" style="cursor: pointer;">
                                    <?php echo $post['comment_count']; ?> <?php echo ($post['comment_count'] === 1) ? 'comment' : 'comments'; ?>
                                </span>
                            </div>
                        </div>
                        <hr>
                        <div class="post-actions">
                            <div class="action-btn like-btn">
                                <i class="fa-regular fa-thumbs-up"></i>
                                <span>Like</span>
                            </div>
                            <div class="action-btn" onclick="toggleComments(<?php echo $post['id']; ?>)" style="cursor: pointer;">
                                <i class="fa-regular fa-comment"></i>
                                <span>Comment</span>
                            </div>
                            <div class="action-btn">
                                <i class="fa-solid fa-share"></i>
                                <span>Share</span>
                            </div>
                        </div>

                        <!-- Expandable Comments Drawer -->
                        <div class="comments-section" id="comments-box-<?php echo $post['id']; ?>" style="<?php echo ($post['comment_count'] > 0) ? 'display: block;' : 'display: none;'; ?>">
                            <div class="comments-list mb-2">
                                <?php if (!empty($post['comments'])): ?>
                                    <?php foreach ($post['comments'] as $cmt): ?>
                                        <div class="comment-item">
                                            <a href="view_profile.php?id=<?php echo $cmt['user_id']; ?>">
                                                <img src="<?php echo $cmt['avatar']; ?>" alt="<?php echo $cmt['user_name']; ?>" class="comment-avatar">
                                            </a>
                                            <div>
                                                <div class="comment-bubble">
                                                    <a href="view_profile.php?id=<?php echo $cmt['user_id']; ?>" class="comment-author-name">
                                                        <?php echo $cmt['user_name']; ?>
                                                    </a>
                                                    <p class="comment-text"><?php echo nl2br($cmt['comment']); ?></p>
                                                </div>
                                                <div class="comment-time"><?php echo $cmt['created_at']; ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Comment Input Form -->
                            <form action="feed.php" method="POST" class="comment-input-container">
                                <input type="hidden" name="add_comment" value="1">
                                <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                <img src="<?php echo $currentUserPic; ?>" alt="<?php echo $currentUserName; ?>" class="comment-avatar">
                                <input type="text" name="comment_text" placeholder="Write a comment..." required autocomplete="off">
                                <button type="submit" class="comment-send-btn"><i class="fa-solid fa-paper-plane"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="post text-center py-4 text-muted">
                    <p class="mb-0">No posts in your feed yet. Add friends or share an update!</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="right-sidebar">
            <h4>Sponsored</h4>
            <div class="sponsored-item">
                <img src="https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=150" alt="Ad">
                <div class="sponsored-text">
                    <p>Learn Web Development</p>
                    <span>course.example.com</span>
                </div>
            </div>
            <hr>
            <div class="contacts-header">
                <h4>Contacts</h4>
                <div class="contacts-icons">
                    <i class="fa-solid fa-video"></i>
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <i class="fa-solid fa-ellipsis"></i>
                </div>
            </div>

            <div class="contacts-list">
                <?php if (!empty($contacts)): ?>
                    <?php foreach ($contacts as $contact): ?>
                        <div class="contact-item-container">
                            <a href="view_profile.php?id=<?php echo $contact['id']; ?>" class="contact-info">
                                <div class="contact-img-wrapper">
                                    <img src="<?php echo $contact['avatar']; ?>" alt="<?php echo $contact['name']; ?>">
                                    <span class="online-dot"></span>
                                </div>
                                <span><?php echo $contact['name']; ?></span>
                            </a>

                            <div class="contact-action">
                                <?php if ($contact['status'] === 'accepted'): ?>
                                    <span class="friend-action-btn btn-friends"><i class="fa-solid fa-check"></i> Friends</span>
                                <?php elseif ($contact['status'] === 'pending'): ?>
                                    <?php if ($contact['requester_id'] === $currentUserId): ?>
                                        <button class="friend-action-btn btn-sent" disabled>Requested</button>
                                    <?php else: ?>
                                        <form method="post" action="feed.php" style="margin: 0;">
                                            <input type="hidden" name="action" value="accept_request">
                                            <input type="hidden" name="target_user_id" value="<?php echo $contact['id']; ?>">
                                            <button type="submit" class="friend-action-btn btn-accept">Accept</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <form method="post" action="feed.php" style="margin: 0;">
                                        <input type="hidden" name="action" value="send_request">
                                        <input type="hidden" name="target_user_id" value="<?php echo $contact['id']; ?>">
                                        <button type="submit" class="friend-action-btn btn-add"><i class="fa-solid fa-user-plus"></i> Add</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted small px-2">No other users found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="story-modal-overlay" id="storyModal">
        <div class="story-viewer-container">
            <div class="story-header-bar">
                <div class="story-user-meta">
                    <img src="" alt="Story author" id="modalStoryAvatar">
                    <span id="modalStoryAuthor">Author</span>
                </div>
                <button class="close-story-btn" id="closeStoryBtn">&times;</button>
            </div>
            <video class="story-modal-video" id="modalStoryVideo" controls autoplay playsinline></video>
            <div class="story-caption-overlay" id="modalStoryCaption" style="display: none;"></div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const mediaInput = document.getElementById('postMediaInput');
        const fileBadge = document.getElementById('fileBadge');
        if (mediaInput) {
            mediaInput.addEventListener('change', function () {
                if (this.files && this.files.length > 0) {
                    fileBadge.style.display = 'inline';
                } else {
                    fileBadge.style.display = 'none';
                }
            });
        }

        const storyCards = document.querySelectorAll('.story-clickable');
        const storyModal = document.getElementById('storyModal');
        const modalVideo = document.getElementById('modalStoryVideo');
        const modalAvatar = document.getElementById('modalStoryAvatar');
        const modalAuthor = document.getElementById('modalStoryAuthor');
        const modalCaption = document.getElementById('modalStoryCaption');
        const closeBtn = document.getElementById('closeStoryBtn');

        storyCards.forEach(card => {
            card.addEventListener('click', function () {
                const videoSrc = this.getAttribute('data-video');
                const author = this.getAttribute('data-author');
                const avatar = this.getAttribute('data-avatar');
                const caption = this.getAttribute('data-caption');

                modalVideo.src = videoSrc;
                modalAvatar.src = avatar;
                modalAuthor.textContent = author;

                if (caption && caption.trim() !== '') {
                    modalCaption.textContent = caption;
                    modalCaption.style.display = 'block';
                } else {
                    modalCaption.style.display = 'none';
                }

                storyModal.classList.add('active');
                modalVideo.currentTime = 0;
                modalVideo.play().catch(() => {});
            });
        });

        function closeStoryViewer() {
            modalVideo.pause();
            modalVideo.src = '';
            storyModal.classList.remove('active');
        }

        closeBtn.addEventListener('click', closeStoryViewer);

        storyModal.addEventListener('click', function (e) {
            if (e.target === storyModal) {
                closeStoryViewer();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && storyModal.classList.contains('active')) {
                closeStoryViewer();
            }
        });

        function toggleComments(postId) {
            const box = document.getElementById('comments-box-' + postId);
            if (box) {
                box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
                const input = box.querySelector('input[name="comment_text"]');
                if (input && box.style.display === 'block') {
                    input.focus();
                }
            }
        }
    </script>
    <script src="js/main.js"></script>
</body>
</html>