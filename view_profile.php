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
$defaultAvatar = "https://i.pravatar.cc/150?img=11";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['target_user_id'])) {
    $action = $_POST['action'];
    $targetUserId = (int)$_POST['target_user_id'];

    if ($targetUserId > 0 && $targetUserId !== $currentUserId) {
        if ($action === 'send_request') {
            $chk = $conn->prepare("SELECT id FROM friendships WHERE (requester_id = ? AND receiver_id = ?) OR (requester_id = ? AND receiver_id = ?) LIMIT 1");
            $chk->bind_param("iiii", $currentUserId, $targetUserId, $targetUserId, $currentUserId);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $sendStmt = $conn->prepare("INSERT INTO friendships (requester_id, receiver_id, status) VALUES (?, ?, 'pending')");
                $sendStmt->bind_param("ii", $currentUserId, $targetUserId);
                $sendStmt->execute();
                $sendStmt->close();
            }
            $chk->close();
        } elseif ($action === 'accept_request') {
            $accStmt = $conn->prepare("UPDATE friendships SET status = 'accepted' WHERE requester_id = ? AND receiver_id = ? AND status = 'pending'");
            $accStmt->bind_param("ii", $targetUserId, $currentUserId);
            $accStmt->execute();
            $accStmt->close();
        }
    }

    header("Location: view_profile.php?id=" . $targetUserId);
    exit();
}

$searchQuery = trim($_GET['search'] ?? '');
$searchResults = [];

if (!empty($searchQuery)) {
    $searchParam = "%" . $searchQuery . "%";
    $searchSql = "
        SELECT u.id, u.name, COALESCE(img.path, ?) AS avatar
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
        WHERE u.name LIKE ?
        ORDER BY u.name ASC
        LIMIT 20
    ";
    $sStmt = $conn->prepare($searchSql);
    $sStmt->bind_param("ss", $defaultAvatar, $searchParam);
    $sStmt->execute();
    $sRes = $sStmt->get_result();
    while ($row = $sRes->fetch_assoc()) {
        $avatar = (!empty($row['avatar']) && (filter_var($row['avatar'], FILTER_VALIDATE_URL) || file_exists(__DIR__ . '/' . $row['avatar'])))
            ? $row['avatar'] : $defaultAvatar;
        $searchResults[] = [
            'id' => (int)$row['id'],
            'name' => htmlspecialchars($row['name']),
            'avatar' => htmlspecialchars($avatar)
        ];
    }
    $sStmt->close();
}

$targetProfileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($targetProfileId === 0 && empty($searchQuery)) {
    $targetProfileId = $currentUserId;
}

$profileUser = null;
$profilePic = $defaultAvatar;
$friendshipStatus = null;
$relationshipMeta = null;
$profileFriends = [];
$profilePosts = [];

if ($targetProfileId > 0) {
    $userStmt = $conn->prepare("SELECT id, name, email, bio FROM users WHERE id = ? LIMIT 1");
    $userStmt->bind_param("i", $targetProfileId);
    $userStmt->execute();
    $profileUser = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if ($profileUser) {
        $imgStmt = $conn->prepare("SELECT path FROM user_images WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $imgStmt->bind_param("i", $targetProfileId);
        $imgStmt->execute();
        $imgRes = $imgStmt->get_result()->fetch_assoc();
        $imgStmt->close();

        if (!empty($imgRes['path']) && file_exists(__DIR__ . '/' . $imgRes['path'])) {
            $profilePic = htmlspecialchars($imgRes['path']);
        }

        if ($targetProfileId !== $currentUserId) {
            $fStmt = $conn->prepare("SELECT status, requester_id, receiver_id FROM friendships WHERE (requester_id = ? AND receiver_id = ?) OR (requester_id = ? AND receiver_id = ?) LIMIT 1");
            $fStmt->bind_param("iiii", $currentUserId, $targetProfileId, $targetProfileId, $currentUserId);
            $fStmt->execute();
            $rel = $fStmt->get_result()->fetch_assoc();
            if ($rel) {
                $friendshipStatus = $rel['status'];
                $relationshipMeta = $rel;
            }
            $fStmt->close();
        }

        $friendsSql = "
            SELECT u.id AS friend_id, u.name AS friend_name, COALESCE(img.path, ?) AS friend_avatar
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
        $friendsStmt->bind_param("sii", $defaultAvatar, $targetProfileId, $targetProfileId);
        $friendsStmt->execute();
        $friendsRes = $friendsStmt->get_result();
        while ($fRow = $friendsRes->fetch_assoc()) {
            $fAvatar = (!empty($fRow['friend_avatar']) && (filter_var($fRow['friend_avatar'], FILTER_VALIDATE_URL) || file_exists(__DIR__ . '/' . $fRow['friend_avatar'])))
                ? $fRow['friend_avatar'] : $defaultAvatar;
            $profileFriends[] = [
                'id' => (int)$fRow['friend_id'],
                'name' => htmlspecialchars($fRow['friend_name']),
                'avatar' => htmlspecialchars($fAvatar)
            ];
        }
        $friendsStmt->close();

        $postsSql = "SELECT id, content, media_type, media_path, created_at FROM posts WHERE user_id = ? AND is_story = 0 ORDER BY created_at DESC";
        $postsStmt = $conn->prepare($postsSql);
        $postsStmt->bind_param("i", $targetProfileId);
        $postsStmt->execute();
        $postsRes = $postsStmt->get_result();
        while ($pRow = $postsRes->fetch_assoc()) {
            $profilePosts[] = [
                'id' => (int)$pRow['id'],
                'content' => $pRow['content'],
                'media_type' => $pRow['media_type'],
                'media_path' => $pRow['media_path'],
                'created_at' => date('M j, Y \a\t g:i A', strtotime($pRow['created_at']))
            ];
        }
        $postsStmt->close();
    }
}

$viewerImgStmt = $conn->prepare("SELECT path FROM user_images WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$viewerImgStmt->bind_param("i", $currentUserId);
$viewerImgStmt->execute();
$viewerImgRes = $viewerImgStmt->get_result()->fetch_assoc();
$viewerImgStmt->close();

$viewerPic = (!empty($viewerImgRes['path']) && file_exists(__DIR__ . '/' . $viewerImgRes['path'])) 
    ? htmlspecialchars($viewerImgRes['path']) 
    : $defaultAvatar;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $profileUser ? htmlspecialchars($profileUser['name']) . " - Profile" : "Search Profiles"; ?></title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .search-results-box {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .search-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
            transition: background 0.2s;
        }
        .search-item:hover { background: #f0f2f5; }
        .search-item img {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 12px;
        }
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
    </style>
</head>
<body class="profile-page">
    <nav class="navbar">
        <div class="nav-left">
            <a href="feed.php"><img src="https://upload.wikimedia.org/wikipedia/commons/b/b8/2021_Facebook_icon.svg" alt="Logo" class="logo"></a>
            <form method="GET" action="view_profile.php" class="search-box m-0">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="search" placeholder="Search profiles by name..." value="<?php echo htmlspecialchars($searchQuery); ?>">
            </form>
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
                    <img src="<?php echo $viewerPic; ?>" alt="Your Profile">
                </a>
            </div>
            <div class="icon-btn"><i class="fa-solid fa-bars"></i></div>
            <div class="icon-btn"><i class="fa-brands fa-facebook-messenger"></i></div>
            <div class="icon-btn"><i class="fa-solid fa-bell"></i></div>
        </div>
    </nav>

    <div class="container my-3">
        <?php if (!empty($searchQuery)): ?>
            <div class="search-results-box">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-magnifying-glass me-2 text-primary"></i>Search Results for "<?php echo htmlspecialchars($searchQuery); ?>"</h5>
                <?php if (!empty($searchResults)): ?>
                    <div class="row g-2">
                        <?php foreach ($searchResults as $result): ?>
                            <div class="col-md-6 col-lg-4">
                                <a href="view_profile.php?id=<?php echo $result['id']; ?>" class="search-item border">
                                    <div class="d-flex align-items-center">
                                        <img src="<?php echo $result['avatar']; ?>" alt="<?php echo $result['name']; ?>">
                                        <span class="fw-semibold"><?php echo $result['name']; ?></span>
                                    </div>
                                    <span class="badge bg-primary rounded-pill">View</span>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No users found matching that name.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($profileUser): ?>
        <div class="profile-header-container">
            <div class="profile-header">
                <div class="cover-photo">
                    <img src="https://images.unsplash.com/photo-1506744626753-dba37c10e8c0?w=1200" alt="Cover Photo">
                </div>
                <div class="profile-info-section">
                    <div class="profile-img-container">
                        <img src="<?php echo $profilePic; ?>" alt="<?php echo htmlspecialchars($profileUser['name']); ?>" class="profile-main-img">
                    </div>
                    <div class="profile-details">
                        <h1><?php echo htmlspecialchars($profileUser['name']); ?></h1>
                        <p><?php echo count($profileFriends); ?> <?php echo (count($profileFriends) === 1) ? 'friend' : 'friends'; ?></p>
                        
                        <?php if (!empty($profileFriends)): ?>
                            <div class="friends-preview">
                                <?php foreach (array_slice($profileFriends, 0, 5) as $fPre): ?>
                                    <img src="<?php echo $fPre['avatar']; ?>" alt="<?php echo $fPre['name']; ?>" title="<?php echo $fPre['name']; ?>">
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="profile-actions">
                        <?php if ($targetProfileId === $currentUserId): ?>
                            <a href="profile.php" class="btn btn-primary text-decoration-none">
                                <i class="fa-solid fa-user me-1"></i> Go to My Profile
                            </a>
                        <?php else: ?>
                            <?php if ($friendshipStatus === 'accepted'): ?>
                                <button class="btn btn-secondary text-success fw-semibold" disabled>
                                    <i class="fa-solid fa-user-check me-1"></i> Friends
                                </button>
                                <button class="btn btn-primary">
                                    <i class="fa-brands fa-facebook-messenger me-1"></i> Message
                                </button>
                            <?php elseif ($friendshipStatus === 'pending'): ?>
                                <?php if ($relationshipMeta['requester_id'] === $currentUserId): ?>
                                    <button class="btn btn-secondary" disabled>
                                        <i class="fa-solid fa-clock me-1"></i> Request Sent
                                    </button>
                                <?php else: ?>
                                    <form method="POST" action="view_profile.php?id=<?php echo $targetProfileId; ?>" class="m-0 d-inline">
                                        <input type="hidden" name="action" value="accept_request">
                                        <input type="hidden" name="target_user_id" value="<?php echo $targetProfileId; ?>">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fa-solid fa-user-plus me-1"></i> Accept Request
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <form method="POST" action="view_profile.php?id=<?php echo $targetProfileId; ?>" class="m-0 d-inline">
                                    <input type="hidden" name="action" value="send_request">
                                    <input type="hidden" name="target_user_id" value="<?php echo $targetProfileId; ?>">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa-solid fa-user-plus me-1"></i> Add Friend
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>

                        <a href="feed.php" class="btn btn-light border text-decoration-none ms-1">
                            <i class="fa-solid fa-house me-1"></i> Feed
                        </a>
                    </div>
                </div>
                <hr class="header-divider">
                <div class="profile-nav">
                    <div class="nav-links">
                        <a href="#" class="active">Posts</a>
                        <a href="#">About</a>
                        <a href="#">Friends</a>
                        <a href="#">Photos</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="profile-content-container">
            <div class="profile-sidebar">
                <div class="intro-box">
                    <h3>Intro</h3>
                    <p class="bio"><?php echo !empty($profileUser['bio']) ? nl2br(htmlspecialchars($profileUser['bio'])) : "No bio added yet."; ?></p>
                    <ul class="intro-details">
                        <li><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($profileUser['email']); ?></li>
                    </ul>
                </div>

                <div class="friends-box">
                    <div class="box-header">
                        <div>
                            <h3>Friends</h3>
                            <p><?php echo count($profileFriends); ?> total</p>
                        </div>
                    </div>
                    <div class="friends-grid">
                        <?php if (!empty($profileFriends)): ?>
                            <?php foreach (array_slice($profileFriends, 0, 9) as $fr): ?>
                                <a href="view_profile.php?id=<?php echo $fr['id']; ?>" class="friend-card text-decoration-none text-dark">
                                    <img src="<?php echo $fr['avatar']; ?>" alt="<?php echo $fr['name']; ?>">
                                    <p><?php echo $fr['name']; ?></p>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted small p-2 mb-0">No friends yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="profile-timeline">
                <?php if (!empty($profilePosts)): ?>
                    <?php foreach ($profilePosts as $post): ?>
                        <div class="post">
                            <div class="post-header">
                                <img src="<?php echo $profilePic; ?>" alt="<?php echo htmlspecialchars($profileUser['name']); ?>">
                                <div class="post-info">
                                    <h4><?php echo htmlspecialchars($profileUser['name']); ?></h4>
                                    <span><?php echo $post['created_at']; ?> &middot; <i class="fa-solid fa-earth-americas"></i></span>
                                </div>
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
                                        <video controls playsinline>
                                            <source src="<?php echo htmlspecialchars($post['media_path']); ?>">
                                        </video>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="post text-center py-4 text-muted">
                        <p class="mb-0">No posts shared by this user yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="container text-center py-5">
            <i class="fa-solid fa-user-slash fs-1 text-secondary mb-3"></i>
            <h3>User Not Found</h3>
            <p class="text-muted">The requested profile does not exist or has been removed.</p>
            <a href="feed.php" class="btn btn-primary">Return to Feed</a>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/main.js"></script>
</body>
</html>