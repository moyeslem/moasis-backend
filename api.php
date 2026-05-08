<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Allow any domain to access this API (CORS) - important when frontend is on Netlify and backend on Render!
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db = mosais_db();
    $action = $_GET['action'] ?? 'bootstrap';

    if ($action === 'bootstrap') {
        $viewerId = (int) ($_GET['viewerId'] ?? 0);
        $friendRequests = [];
        if ($viewerId > 0) {
            $friendRequests = $db->query("
                SELECT fr.*, 
                       s.name as sender_name, s.surname as sender_surname, s.avatar as sender_avatar,
                       r.name as receiver_name, r.surname as receiver_surname, r.avatar as receiver_avatar
                FROM friend_requests fr
                JOIN users s ON fr.sender_id = s.id
                JOIN users r ON fr.receiver_id = r.id
                WHERE fr.sender_id = $viewerId OR fr.receiver_id = $viewerId
            ")->fetchAll();
        }
        echo json_encode([
            'users' => public_users($db),
            'posts' => posts_with_comments($db, $viewerId),
            'friendRequests' => $friendRequests
        ]);
        exit;
    }

    if ($action === 'login') {
        $payload = json_payload();
        $username = strtolower(trim((string) ($payload['username'] ?? '')));
        $password = (string) ($payload['password'] ?? '');

        $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || $user['password'] !== $password) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid Mosais username or password.']);
            exit;
        }

        echo json_encode(['user' => user_payload($user, true)]);
        exit;
    }

    if ($action === 'profile') {
        $userId = (int) ($_GET['userId'] ?? 0);
        $viewerId = (int) ($_GET['viewerId'] ?? 0);

        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'Profile not found.']);
            exit;
        }

        $includePrivate = $viewerId === $userId;
        if (!$includePrivate && $viewerId > 0) {
            $viewer = $db->prepare('SELECT friends FROM users WHERE id = ?');
            $viewer->execute([$viewerId]);
            $friends = json_decode((string) $viewer->fetchColumn(), true);
            $includePrivate = is_array($friends) && in_array($userId, $friends, true);
        }

        echo json_encode(['user' => user_payload($user, $includePrivate)]);
        exit;
    }

    if ($action === 'updateProfile') {
        $payload = json_payload();
        $id = (int) ($payload['id'] ?? 0);
        $allowed = ['name', 'surname', 'city', 'headline', 'email', 'phone', 'bio'];
        $fields = [];
        $values = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $payload)) {
                $fields[] = $field . ' = ?';
                $values[] = trim((string) $payload[$field]);
            }
        }

        if ($id < 1 || count($fields) === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Missing editable profile fields.']);
            exit;
        }

        $values[] = $id;
        $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);

        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        echo json_encode([
            'user' => user_payload($user, true),
            'users' => public_users($db),
            'posts' => posts_with_comments($db, $id),
        ]);
        exit;
    }

    if ($action === 'createPost') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $body = trim((string) ($payload['body'] ?? ''));
        $mood = trim((string) ($payload['mood'] ?? 'Update'));

        $user = require_user($db, $userId);
        if ($body === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Post body is required.']);
            exit;
        }

        $createdAt = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare('INSERT INTO posts (user_id, body, mood, visibility, created_at) VALUES (?, ?, ?, "public", ?)');
        $stmt->execute([$userId, $body, $mood, $createdAt]);
        activity_log($db, $userId, 'post_create', 'post', (int) $db->lastInsertId(), ['mood' => $mood]);

        echo json_encode([
            'posts' => posts_with_comments($db, $userId),
        ]);
        exit;
    }

    if ($action === 'updatePost') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $postId = (int) ($payload['postId'] ?? 0);
        $body = trim((string) ($payload['body'] ?? ''));
        $mood = trim((string) ($payload['mood'] ?? ''));

        require_user($db, $userId);
        $post = require_post($db, $postId);
        if (!can_edit_post($db, $userId, $post)) {
            http_response_code(403);
            echo json_encode(['error' => 'You cannot edit this post.']);
            exit;
        }
        if ($body === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Post body is required.']);
            exit;
        }

        $updatedAt = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare('UPDATE posts SET body = ?, mood = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$body, $mood ?: $post['mood'], $updatedAt, $postId]);
        activity_log($db, $userId, 'post_update', 'post', $postId, []);

        echo json_encode([
            'posts' => posts_with_comments($db, $userId),
        ]);
        exit;
    }

    if ($action === 'deletePost') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $postId = (int) ($payload['postId'] ?? 0);

        require_user($db, $userId);
        $post = require_post($db, $postId);
        if (!can_edit_post($db, $userId, $post)) {
            http_response_code(403);
            echo json_encode(['error' => 'You cannot delete this post.']);
            exit;
        }

        $db->prepare('DELETE FROM post_likes WHERE post_id = ?')->execute([$postId]);
        $db->prepare('DELETE FROM comments WHERE post_id = ?')->execute([$postId]);
        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
        activity_log($db, $userId, 'post_delete', 'post', $postId, []);

        echo json_encode([
            'posts' => posts_with_comments($db, $userId),
        ]);
        exit;
    }

    if ($action === 'toggleLike') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $postId = (int) ($payload['postId'] ?? 0);

        require_user($db, $userId);
        require_post($db, $postId);

        $exists = $db->prepare('SELECT 1 FROM post_likes WHERE post_id = ? AND user_id = ?');
        $exists->execute([$postId, $userId]);
        $liked = (bool) $exists->fetchColumn();

        if ($liked) {
            $db->prepare('DELETE FROM post_likes WHERE post_id = ? AND user_id = ?')->execute([$postId, $userId]);
            activity_log($db, $userId, 'post_unlike', 'post', $postId, []);
        } else {
            $createdAt = gmdate('Y-m-d H:i:s');
            $db->prepare('INSERT OR IGNORE INTO post_likes (post_id, user_id, created_at) VALUES (?, ?, ?)')->execute([$postId, $userId, $createdAt]);
            activity_log($db, $userId, 'post_like', 'post', $postId, []);
        }

        echo json_encode([
            'posts' => posts_with_comments($db, $userId),
        ]);
        exit;
    }

    if ($action === 'addComment') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $postId = (int) ($payload['postId'] ?? 0);
        $body = trim((string) ($payload['body'] ?? ''));

        require_user($db, $userId);
        $post = require_post($db, $postId);
        if ((int) $post['comments_locked'] === 1) {
            http_response_code(403);
            echo json_encode(['error' => 'Comments are locked for this post.']);
            exit;
        }
        if ($body === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Comment body is required.']);
            exit;
        }

        $createdAt = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare('INSERT INTO comments (post_id, user_id, body, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$postId, $userId, $body, $createdAt]);
        activity_log($db, $userId, 'comment_create', 'comment', (int) $db->lastInsertId(), ['postId' => $postId]);

        echo json_encode([
            'posts' => posts_with_comments($db, $userId),
        ]);
        exit;
    }

    if ($action === 'updateComment') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $commentId = (int) ($payload['commentId'] ?? 0);
        $body = trim((string) ($payload['body'] ?? ''));

        $user = require_user($db, $userId);
        
        $stmt = $db->prepare('SELECT * FROM comments WHERE id = ?');
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        
        if (!$comment) {
            http_response_code(404);
            echo json_encode(['error' => 'Comment not found.']);
            exit;
        }
        
        if ((int) $comment['user_id'] !== $userId && (int) $user['is_admin'] !== 1) {
            http_response_code(403);
            echo json_encode(['error' => 'You cannot edit this comment.']);
            exit;
        }

        if ($body === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Comment body is required.']);
            exit;
        }

        $updatedAt = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare('UPDATE comments SET body = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$body, $updatedAt, $commentId]);
        activity_log($db, $userId, 'comment_update', 'comment', $commentId, []);

        echo json_encode([
            'posts' => posts_with_comments($db, $userId),
        ]);
        exit;
    }

    if ($action === 'deleteComment') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $commentId = (int) ($payload['commentId'] ?? 0);

        $user = require_user($db, $userId);
        
        $stmt = $db->prepare('SELECT * FROM comments WHERE id = ?');
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        
        if (!$comment) {
            http_response_code(404);
            echo json_encode(['error' => 'Comment not found.']);
            exit;
        }
        
        if ((int) $comment['user_id'] !== $userId && (int) $user['is_admin'] !== 1) {
            http_response_code(403);
            echo json_encode(['error' => 'You cannot delete this comment.']);
            exit;
        }

        $db->prepare('DELETE FROM comments WHERE id = ?')->execute([$commentId]);
        activity_log($db, $userId, 'comment_delete', 'comment', $commentId, []);

        echo json_encode([
            'posts' => posts_with_comments($db, $userId),
        ]);
        exit;
    }

    if ($action === 'sendFriendRequest') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $targetId = (int) ($payload['targetId'] ?? 0);
        require_user($db, $userId);

        if ($userId === $targetId) {
            http_response_code(422);
            echo json_encode(['error' => 'Cannot send request to yourself.']);
            exit;
        }

        $createdAt = gmdate('Y-m-d H:i:s');
        $db->prepare('INSERT INTO friend_requests (sender_id, receiver_id, created_at) VALUES (?, ?, ?)')
           ->execute([$userId, $targetId, $createdAt]);
        
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'respondFriendRequest') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $requestId = (int) ($payload['requestId'] ?? 0);
        $accept = (bool) ($payload['accept'] ?? false);
        require_user($db, $userId);

        $stmt = $db->prepare('SELECT * FROM friend_requests WHERE id = ? AND receiver_id = ? AND status = "pending"');
        $stmt->execute([$requestId, $userId]);
        $req = $stmt->fetch();

        if (!$req) {
            http_response_code(404);
            echo json_encode(['error' => 'Request not found.']);
            exit;
        }

        $newStatus = $accept ? 'accepted' : 'rejected';
        $db->prepare('UPDATE friend_requests SET status = ? WHERE id = ?')->execute([$newStatus, $requestId]);

        if ($accept) {
            // Update friends array for both users
            $stmt = $db->prepare('SELECT friends FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $userFriends = json_decode((string) $stmt->fetchColumn(), true) ?: [];
            
            $stmt->execute([$req['sender_id']]);
            $senderFriends = json_decode((string) $stmt->fetchColumn(), true) ?: [];

            if (!in_array($req['sender_id'], $userFriends)) {
                $userFriends[] = (int) $req['sender_id'];
                $db->prepare('UPDATE users SET friends = ? WHERE id = ?')->execute([json_encode($userFriends), $userId]);
            }
            if (!in_array($userId, $senderFriends)) {
                $senderFriends[] = $userId;
                $db->prepare('UPDATE users SET friends = ? WHERE id = ?')->execute([json_encode($senderFriends), $req['sender_id']]);
            }
        }
        
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'getMessages') {
        $userId = (int) ($_GET['userId'] ?? 0);
        $friendId = (int) ($_GET['friendId'] ?? 0);
        require_user($db, $userId);

        $messages = $db->query("
            SELECT m.*, u.name, u.avatar 
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = $userId AND m.receiver_id = $friendId) 
               OR (m.sender_id = $friendId AND m.receiver_id = $userId)
            ORDER BY datetime(m.created_at) ASC
        ")->fetchAll();

        echo json_encode(['messages' => $messages]);
        exit;
    }

    if ($action === 'sendMessage') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $friendId = (int) ($payload['friendId'] ?? 0);
        $body = trim((string) ($payload['body'] ?? ''));
        require_user($db, $userId);

        if ($body === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Message cannot be empty.']);
            exit;
        }

        $createdAt = gmdate('Y-m-d H:i:s');
        $db->prepare('INSERT INTO messages (sender_id, receiver_id, body, created_at) VALUES (?, ?, ?, ?)')
           ->execute([$userId, $friendId, $body, $createdAt]);
        
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'lockComments') {
        $payload = json_payload();
        $userId = (int) ($payload['userId'] ?? 0);
        $postId = (int) ($payload['postId'] ?? 0);
        $locked = (int) ($payload['locked'] ?? 0) === 1 ? 1 : 0;

        require_user($db, $userId);
        $post = require_post($db, $postId);
        if (!can_edit_post($db, $userId, $post)) {
            http_response_code(403);
            echo json_encode(['error' => 'You cannot lock comments for this post.']);
            exit;
        }

        $db->prepare('UPDATE posts SET comments_locked = ?, updated_at = ? WHERE id = ?')->execute([$locked, gmdate('Y-m-d H:i:s'), $postId]);
        activity_log($db, $userId, $locked ? 'comments_lock' : 'comments_unlock', 'post', $postId, []);

        echo json_encode([
            'posts' => posts_with_comments($db, $userId),
        ]);
        exit;
    }

    if ($action === 'adminStats') {
        $viewerId = (int) ($_GET['viewerId'] ?? 0);
        $viewer = require_user($db, $viewerId);
        if ((int) $viewer['is_admin'] !== 1) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin only.']);
            exit;
        }

        $stats = [
            'users' => (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'posts' => (int) $db->query('SELECT COUNT(*) FROM posts')->fetchColumn(),
            'comments' => (int) $db->query('SELECT COUNT(*) FROM comments')->fetchColumn(),
            'likes' => (int) $db->query('SELECT COUNT(*) FROM post_likes')->fetchColumn(),
        ];

        $recent = $db->query('
            SELECT activity_log.*, users.username
            FROM activity_log
            LEFT JOIN users ON users.id = activity_log.user_id
            ORDER BY datetime(activity_log.created_at) DESC
            LIMIT 40
        ')->fetchAll();

        echo json_encode([
            'stats' => $stats,
            'recent' => $recent,
            'users' => public_users($db),
            'posts' => posts_with_comments($db, $viewerId),
        ]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Unknown action.']);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => $error->getMessage()]);
}

function json_payload(): array
{
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '{}', true);
    return is_array($payload) ? $payload : [];
}

function public_users(PDO $db): array
{
    $users = $db->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
    return array_map(fn (array $user): array => user_payload($user, false), $users);
}

function posts_with_comments(PDO $db, int $viewerId): array
{
    $posts = $db->query('
        SELECT posts.*, users.name, users.surname, users.city, users.avatar
        FROM posts
        JOIN users ON users.id = posts.user_id
        ORDER BY datetime(posts.created_at) DESC
    ')->fetchAll();

    $comments = $db->query('
        SELECT comments.*, users.name, users.avatar
        FROM comments
        JOIN users ON users.id = comments.user_id
        ORDER BY datetime(comments.created_at) ASC
    ')->fetchAll();

    $likes = $db->query('SELECT post_id, user_id FROM post_likes')->fetchAll();
    $likesByPost = [];
    $likedByViewer = [];
    foreach ($likes as $like) {
        $postId = (int) $like['post_id'];
        $likesByPost[$postId] = ($likesByPost[$postId] ?? 0) + 1;
        if ((int) $like['user_id'] === $viewerId) {
            $likedByViewer[$postId] = true;
        }
    }

    $byPost = [];
    foreach ($comments as $comment) {
        $byPost[(int) $comment['post_id']][] = [
            'id' => (int) $comment['id'],
            'postId' => (int) $comment['post_id'],
            'userId' => (int) $comment['user_id'],
            'author' => $comment['name'],
            'avatar' => $comment['avatar'],
            'body' => $comment['body'],
            'createdAt' => $comment['created_at'],
        ];
    }

    return array_map(function (array $post) use ($byPost, $likesByPost, $likedByViewer): array {
        $id = (int) $post['id'];
        return [
            'id' => $id,
            'userId' => (int) $post['user_id'],
            'author' => $post['name'],
            'surname' => $post['surname'],
            'city' => $post['city'],
            'avatar' => $post['avatar'],
            'body' => $post['body'],
            'mood' => $post['mood'],
            'visibility' => $post['visibility'],
            'createdAt' => $post['created_at'],
            'updatedAt' => $post['updated_at'],
            'commentsLocked' => (int) $post['comments_locked'] === 1,
            'likeCount' => (int) ($likesByPost[$id] ?? 0),
            'liked' => isset($likedByViewer[$id]),
            'comments' => $byPost[$id] ?? [],
        ];
    }, $posts);
}

function user_payload(array $user, bool $includePrivate): array
{
    $payload = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'name' => $user['name'],
        'surname' => $user['surname'],
        'city' => $user['city'],
        'headline' => $user['headline'],
        'avatar' => $user['avatar'],
        'friends' => json_decode($user['friends'], true),
        'isAdmin' => (int) ($user['is_admin'] ?? 0) === 1,
    ];

    if ($includePrivate) {
        $payload['email'] = $user['email'];
        $payload['phone'] = $user['phone'];
        $payload['bio'] = $user['bio'];
    }

    return $payload;
}

function require_user(PDO $db, int $userId): array
{
    if ($userId < 1) {
        http_response_code(401);
        echo json_encode(['error' => 'Sign in required.']);
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unknown user.']);
        exit;
    }

    return $user;
}

function require_post(PDO $db, int $postId): array
{
    $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    if (!$post) {
        http_response_code(404);
        echo json_encode(['error' => 'Post not found.']);
        exit;
    }

    return $post;
}

function can_edit_post(PDO $db, int $userId, array $post): bool
{
    if ((int) $post['user_id'] === $userId) {
        return true;
    }

    $user = require_user($db, $userId);
    return (int) ($user['is_admin'] ?? 0) === 1;
}

function activity_log(PDO $db, int $userId, string $type, ?string $entityType, ?int $entityId, array $meta): void
{
    $stmt = $db->prepare('INSERT INTO activity_log (user_id, type, entity_type, entity_id, meta, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $userId > 0 ? $userId : null,
        $type,
        $entityType,
        $entityId,
        json_encode($meta),
        gmdate('Y-m-d H:i:s'),
    ]);
}
