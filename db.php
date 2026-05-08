<?php
declare(strict_types=1);

function mosais_db(): PDO
{
    $dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }

    $db = new PDO('sqlite:' . $dataDir . DIRECTORY_SEPARATOR . 'mosais.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    mosais_schema($db);
    mosais_seed($db);
    mosais_ensure_admin($db);

    return $db;
}

function mosais_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            name TEXT NOT NULL,
            surname TEXT NOT NULL,
            city TEXT NOT NULL,
            headline TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            bio TEXT NOT NULL,
            avatar TEXT NOT NULL,
            friends TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            mood TEXT NOT NULL,
            visibility TEXT NOT NULL DEFAULT 'public',
            created_at TEXT NOT NULL,
            updated_at TEXT,
            comments_locked INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT,
            FOREIGN KEY (post_id) REFERENCES posts(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS post_likes (
            post_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            PRIMARY KEY (post_id, user_id),
            FOREIGN KEY (post_id) REFERENCES posts(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            type TEXT NOT NULL,
            entity_type TEXT,
            entity_id INTEGER,
            meta TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS friend_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            receiver_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL,
            FOREIGN KEY (sender_id) REFERENCES users(id),
            FOREIGN KEY (receiver_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            receiver_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (sender_id) REFERENCES users(id),
            FOREIGN KEY (receiver_id) REFERENCES users(id)
        );
    ");

    mosais_migrate($db);
}

function mosais_seed(PDO $db): void
{
    $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $users = [
        ['mohamed', 'Benyahia', 'Algiers', 'Product thinker and night reader', 'mohamed@mosais.local', '+213 555 100 001', 'Building calm digital spaces with a sharp eye for detail.', 'M', [2, 3, 5, 8]],
        ['sadjed', 'Mansouri', 'Oran', 'Designer of quiet interfaces', 'sadjed@mosais.local', '+213 555 100 002', 'I like simple layouts, strong coffee, and practical ideas.', 'S', [1, 4, 6, 10]],
        ['islam', 'Kerrouche', 'Constantine', 'Frontend craftsman', 'islam@mosais.local', '+213 555 100 003', 'Turning rough concepts into smooth web experiences.', 'I', [1, 5, 7, 9]],
        ['louay', 'Haddad', 'Blida', 'Community organizer', 'louay@mosais.local', '+213 555 100 004', 'Bringing people together around useful projects.', 'L', [2, 6, 8]],
        ['nacer', 'Belkacem', 'Setif', 'Backend engineer', 'nacer@mosais.local', '+213 555 100 005', 'Databases, clean APIs, and systems that do not complain.', 'N', [1, 3, 7, 10]],
        ['marinou', 'Saadi', 'Annaba', 'Music and motion', 'marinou@mosais.local', '+213 555 100 006', 'Sharing playlists, photos, and small wins from the week.', 'M', [2, 4, 8, 9]],
        ['noui', 'Ziani', 'Tlemcen', 'Startup operator', 'noui@mosais.local', '+213 555 100 007', 'Focused on shipping useful things and learning quickly.', 'N', [3, 5, 9]],
        ['abdllah', 'Rahmani', 'Batna', 'Photographer', 'abdllah@mosais.local', '+213 555 100 008', 'I collect light, street corners, and honest conversations.', 'A', [1, 4, 6, 10]],
        ['abdou', 'Djebbar', 'Bejaia', 'Mobile developer', 'abdou@mosais.local', '+213 555 100 009', 'Mobile apps, long walks, and small notebooks.', 'A', [3, 6, 7]],
        ['taha', 'Merabet', 'Tipaza', 'Data analyst', 'taha@mosais.local', '+213 555 100 010', 'Charts, strategy, and making numbers easier to trust.', 'T', [2, 5, 8]],
    ];

    $insertUser = $db->prepare('
        INSERT INTO users (username, password, name, surname, city, headline, email, phone, bio, avatar, friends)
        VALUES (:username, :password, :name, :surname, :city, :headline, :email, :phone, :bio, :avatar, :friends)
    ');

    foreach ($users as $user) {
        [$username, $surname, $city, $headline, $email, $phone, $bio, $avatar, $friends] = $user;
        $insertUser->execute([
            ':username' => $username,
            ':password' => 'mosais',
            ':name' => ucfirst($username),
            ':surname' => $surname,
            ':city' => $city,
            ':headline' => $headline,
            ':email' => $email,
            ':phone' => $phone,
            ':bio' => $bio,
            ':avatar' => $avatar,
            ':friends' => json_encode($friends),
        ]);
    }

    // Keep seed users as normal users; admin is ensured separately.

    $posts = [
        [1, 'Mosais should feel focused: black, white, fast, and made for real conversations.', 'Vision', '2026-04-28 09:10:00'],
        [2, 'Sketching a new profile layout today. The best interface disappears when the content matters.', 'Design', '2026-04-28 10:24:00'],
        [3, 'A good feed needs rhythm: short updates, thoughtful comments, and no visual noise.', 'Frontend', '2026-04-28 11:40:00'],
        [4, 'Organized a small meetup this weekend. The agenda is simple: ideas, demos, and tea.', 'Community', '2026-04-28 12:55:00'],
        [5, 'Clean database schemas make future features feel almost polite.', 'Backend', '2026-04-28 14:12:00'],
        [6, 'Found a playlist that makes debugging feel strangely cinematic.', 'Music', '2026-04-28 15:30:00'],
        [7, 'The best startup metric this week: people came back without being reminded.', 'Growth', '2026-04-28 16:45:00'],
        [8, 'Golden hour in Batna today. Some streets already look like portraits.', 'Photo', '2026-04-28 18:05:00'],
        [9, 'Mobile screens reward discipline. Every pixel has to earn rent.', 'Mobile', '2026-04-29 08:20:00'],
        [10, 'A chart is useful only when someone can make a decision after seeing it.', 'Data', '2026-04-29 09:35:00'],
        [1, 'Updated my public profile details and kept the private fields for friends only.', 'Profile', '2026-04-29 10:50:00'],
        [3, 'I like when JavaScript handles the experience and PHP quietly keeps the data honest.', 'Stack', '2026-04-29 12:05:00'],
        [5, 'Authentication can start simple for a class project, then grow into sessions and hashed passwords.', 'Security', '2026-04-29 13:20:00'],
        [2, 'Black and white is not boring when spacing, contrast, and type do the heavy lifting.', 'Style', '2026-04-29 14:40:00'],
        [6, 'Posted a few weekend notes. Small communities move faster when the tone is generous.', 'Social', '2026-04-29 16:00:00'],
        [8, 'Profile photos are not necessary when initials are designed with confidence.', 'Identity', '2026-04-29 17:15:00'],
        [10, 'Seventeen posts, every one with comments. A feed starts to feel alive when replies exist.', 'Launch', '2026-04-29 18:30:00'],
    ];

    $insertPost = $db->prepare('INSERT INTO posts (user_id, body, mood, visibility, created_at) VALUES (?, ?, ?, "public", ?)');
    foreach ($posts as $post) {
        $insertPost->execute($post);
    }

    $comments = [
        [1, 2, 'That tone fits Mosais perfectly. Minimal, but still warm.'],
        [1, 5, 'I can already picture the API staying clean behind it.'],
        [2, 1, 'The profile layout is where the whole product starts to feel personal.'],
        [2, 8, 'Strong spacing will make the black and white palette shine.'],
        [3, 10, 'Rhythm matters. Too many feeds forget that people are scanning.'],
        [3, 4, 'Thoughtful comments are the real social layer.'],
        [4, 6, 'Count me in for the demos. I will bring the playlist.'],
        [4, 7, 'A small room with focused people beats a giant noisy launch.'],
        [5, 3, 'Future features always thank the clean schema later.'],
        [5, 9, 'That is the calmest backend sentence I have read today.'],
        [6, 2, 'Debugging deserves better music than silence.'],
        [6, 1, 'Send the playlist into the group later.'],
        [7, 5, 'Retention without reminders is a beautiful signal.'],
        [7, 10, 'That metric belongs at the top of the dashboard.'],
        [8, 4, 'Street corners with stories are the best kind.'],
        [8, 6, 'Post the photos when you finish editing.'],
        [9, 3, 'Every mobile layout teaches humility eventually.'],
        [9, 7, 'Small screens make priorities very obvious.'],
        [10, 1, 'Clear decision charts are rare and valuable.'],
        [10, 5, 'Agreed. Pretty charts are not enough.'],
        [11, 8, 'The public/private split makes the profile feel intentional.'],
        [11, 2, 'Nice detail. Friends should get the closer context.'],
        [12, 5, 'That division of labor is exactly right for this stack.'],
        [12, 9, 'The browser experience still has to feel instant.'],
        [13, 1, 'Good note for the roadmap: simple first, stronger next.'],
        [13, 3, 'Hashing passwords would be the next serious upgrade.'],
        [14, 8, 'Contrast and whitespace are doing the whole performance.'],
        [14, 10, 'Black and white feels premium when it is restrained.'],
        [15, 4, 'Generous tone scales better than loud features.'],
        [15, 7, 'Small communities are where products learn fastest.'],
        [16, 2, 'Initials can be elegant when the system is consistent.'],
        [16, 6, 'It also keeps the demo light and fast.'],
        [17, 1, 'This makes the feed feel complete right away.'],
        [17, 3, 'Comments on every post are a smart seed detail.'],
    ];

    $insertComment = $db->prepare('INSERT INTO comments (post_id, user_id, body, created_at) VALUES (?, ?, ?, ?)');
    foreach ($comments as $index => $comment) {
        $created = date('Y-m-d H:i:s', strtotime('2026-04-29 19:00:00 +' . ($index * 7) . ' minutes'));
        $insertComment->execute([$comment[0], $comment[1], $comment[2], $created]);
    }
}

function mosais_ensure_admin(PDO $db): void
{
    $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute(['admin']);
    $adminId = (int) ($stmt->fetchColumn() ?: 0);

    if ($adminId < 1) {
        $insertUser = $db->prepare('
            INSERT INTO users (username, password, name, surname, city, headline, email, phone, bio, avatar, friends, is_admin)
            VALUES (:username, :password, :name, :surname, :city, :headline, :email, :phone, :bio, :avatar, :friends, 1)
        ');
        $insertUser->execute([
            ':username' => 'admin',
            ':password' => 'mosais',
            ':name' => 'Admin',
            ':surname' => 'Mosais',
            ':city' => 'Mosais HQ',
            ':headline' => 'System administrator',
            ':email' => 'admin@mosais.local',
            ':phone' => '+000 000 000 000',
            ':bio' => 'Admin account for managing Mosais.',
            ':avatar' => 'A',
            ':friends' => json_encode([]),
        ]);
        $adminId = (int) $db->lastInsertId();
    }

    $db->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$adminId]);
}

function mosais_migrate(PDO $db): void
{
    $db->exec('PRAGMA foreign_keys = ON');

    $usersColumns = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('is_admin', $usersColumns, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0');
    }

    $postsColumns = array_column($db->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
    if (!in_array('updated_at', $postsColumns, true)) {
        $db->exec('ALTER TABLE posts ADD COLUMN updated_at TEXT');
    }
    if (!in_array('comments_locked', $postsColumns, true)) {
        $db->exec('ALTER TABLE posts ADD COLUMN comments_locked INTEGER NOT NULL DEFAULT 0');
    }

    $commentsColumns = array_column($db->query('PRAGMA table_info(comments)')->fetchAll(), 'name');
    if (!in_array('updated_at', $commentsColumns, true)) {
        $db->exec('ALTER TABLE comments ADD COLUMN updated_at TEXT');
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS friend_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            receiver_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL,
            FOREIGN KEY (sender_id) REFERENCES users(id),
            FOREIGN KEY (receiver_id) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            receiver_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (sender_id) REFERENCES users(id),
            FOREIGN KEY (receiver_id) REFERENCES users(id)
        );
    ");
}
