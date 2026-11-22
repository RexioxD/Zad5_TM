<?php declare(strict_types=1);

function media_config(): array
{
    static $config;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/' . APP_KEY . '/db.php';
        if (!preg_match('/^[a-z0-9_]*$/', $config['prefix'] ?? '')) {
            throw new RuntimeException('Nieprawidłowa konfiguracja tabel.');
        }
    }
    return $config;
}

function media_table(string $name): string
{
    return media_config()['prefix'] . $name;
}

function media_kind(): array
{
    return APP_KEY === 'z6a'
        ? ['table' => 'song', 'id' => 'ids', 'type' => 'musictype', 'type_id' => 'idmt',
            'creator' => 'musician', 'text' => 'lyrics', 'file' => 'mp3', 'mime' => 'audio/mpeg',
            'title' => 'Muzyka', 'item' => 'Utwór', 'creator_label' => 'Wykonawca',
            'genres' => ['pop', 'rock', 'hip-hop', 'electronic dance', 'R&B', 'latin', 'country', 'metal', 'jazz', 'classic', 'inny']]
        : ['table' => 'film', 'id' => 'idf', 'type' => 'filmtype', 'type_id' => 'idft',
            'creator' => 'director', 'text' => 'subtitles', 'file' => 'mp4', 'mime' => 'video/mp4',
            'title' => 'Filmy', 'item' => 'Film', 'creator_label' => 'Reżyser',
            'genres' => ['dokument', 'reportaż', 'publicystyka', 'film akcji', 'sci-fi', 'horror', 'familijny', 'przyrodniczy', 'koncert', 'animowany', 'inny']];
}

function media_init(): void
{
    $kind = media_kind();
    $type = media_table($kind['type']);
    $item = media_table($kind['table']);
    $playlists = media_table('playlistname');
    $members = media_table('playlistdatabase');
    $typeId = $kind['type_id'];
    $itemId = $kind['id'];
    $creator = $kind['creator'];
    $text = $kind['text'];
    $accounts = account_table();
    $accountId = account_id_column();
    accounts_db();

    db()->query("CREATE TABLE IF NOT EXISTS `$type` (
        `$typeId` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci");
    db()->query("CREATE TABLE IF NOT EXISTS `$item` (
        `$itemId` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title TEXT NOT NULL,
        `$creator` TEXT NOT NULL,
        datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        idu INT UNSIGNED NOT NULL,
        filename VARCHAR(255) NOT NULL,
        `$text` TEXT,
        `$typeId` INT UNSIGNED NOT NULL,
        INDEX(idu),
        FOREIGN KEY (idu) REFERENCES `$accounts`(`$accountId`) ON DELETE CASCADE,
        FOREIGN KEY (`$typeId`) REFERENCES `$type`(`$typeId`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci");
    db()->query("CREATE TABLE IF NOT EXISTS `$playlists` (
        idpl INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        idu INT UNSIGNED NOT NULL,
        name TEXT NOT NULL,
        public TINYINT(1) NOT NULL DEFAULT 0,
        datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX(idu),
        FOREIGN KEY (idu) REFERENCES `$accounts`(`$accountId`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci");
    db()->query("CREATE TABLE IF NOT EXISTS `$members` (
        idpldb INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        idpl INT UNSIGNED NOT NULL,
        `$itemId` INT UNSIGNED NOT NULL,
        UNIQUE KEY unique_member(idpl, `$itemId`),
        FOREIGN KEY (idpl) REFERENCES `$playlists`(idpl) ON DELETE CASCADE,
        FOREIGN KEY (`$itemId`) REFERENCES `$item`(`$itemId`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci");

    $statement = db()->prepare("INSERT IGNORE INTO `$type`(name) VALUES(?)");
    foreach ($kind['genres'] as $genre) {
        $statement->bind_param('s', $genre);
        $statement->execute();
    }
}

function media_query(string $sql, string $types = '', array $values = []): mysqli_stmt
{
    $statement = db()->prepare($sql);
    if ($values) {
        $statement->bind_param($types, ...$values);
    }
    $statement->execute();
    return $statement;
}

function media_items(): array
{
    $kind = media_kind();
    $item = media_table($kind['table']);
    $members = media_table('playlistdatabase');
    $playlists = media_table('playlistname');
    $type = media_table($kind['type']);
    $id = $kind['id'];
    $typeId = $kind['type_id'];
    $mine = user()['id'];
    $sql = "SELECT i.*, t.name AS genre FROM `$item` i JOIN `$type` t ON t.`$typeId`=i.`$typeId`
        WHERE i.idu=? OR EXISTS (SELECT 1 FROM `$members` m JOIN `$playlists` p ON p.idpl=m.idpl
        WHERE m.`$id`=i.`$id` AND p.public=1) ORDER BY i.datetime DESC, i.`$id` DESC";
    return media_query($sql, 'i', [$mine])->get_result()->fetch_all(MYSQLI_ASSOC);
}

function media_own_playlists(): array
{
    $table = media_table('playlistname');
    return media_query("SELECT * FROM `$table` WHERE idu=? ORDER BY datetime DESC,idpl DESC", 'i', [user()['id']])
        ->get_result()->fetch_all(MYSQLI_ASSOC);
}

function media_can_read(int $id): ?array
{
    $kind = media_kind();
    $item = media_table($kind['table']);
    $members = media_table('playlistdatabase');
    $playlists = media_table('playlistname');
    $key = $kind['id'];
    return media_query("SELECT i.* FROM `$item` i WHERE i.`$key`=? AND
        (i.idu=? OR EXISTS (SELECT 1 FROM `$members` m JOIN `$playlists` p ON p.idpl=m.idpl
        WHERE m.`$key`=i.`$key` AND p.public=1))", 'ii', [$id, user()['id']])
        ->get_result()->fetch_assoc();
}

function media_storage(array $item): string
{
    return dirname(__DIR__) . '/storage/media/' . APP_KEY . '/' . (int)$item['idu'] . '/' . $item['filename'];
}

function media_stream(): void
{
    $item = media_can_read((int)($_GET['id'] ?? 0));
    $path = $item ? media_storage($item) : '';
    if (!$item || !is_file($path)) {
        http_response_code(404);
        exit('Nie znaleziono pliku.');
    }
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    header('Content-Type: ' . media_kind()['mime']);
    header('Content-Disposition: inline');
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $range)) {
        $start = $range[1] === '' ? max(0, $size - (int)$range[2]) : (int)$range[1];
        $end = $range[1] === '' || $range[2] === '' ? $end : min($end, (int)$range[2]);
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $stream = fopen($path, 'rb');
    fseek($stream, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($stream)) {
        $chunk = fread($stream, min(65536, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($stream);
}

function media_upload(): void
{
    check_csrf();
    $kind = media_kind();
    $title = trim((string)($_POST['title'] ?? ''));
    $creator = trim((string)($_POST['creator'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $typeId = (int)($_POST['type_id'] ?? 0);
    $file = $_FILES['media'] ?? null;
    if ($title === '' || $creator === '' || mb_strlen($title) > 200 || mb_strlen($creator) > 200 ||
        !$file || $file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Wypełnij pola i wybierz plik. Sprawdź też limit przesyłania na hostingu.');
    }
    if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== $kind['file']) {
        throw new InvalidArgumentException('Wybierz plik ' . strtoupper($kind['file']) . '.');
    }
    $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($detected, APP_KEY === 'z6a' ? ['audio/mpeg', 'audio/mp3'] : ['video/mp4', 'application/mp4'], true)) {
        throw new InvalidArgumentException('Wybrany plik ma niewłaściwy format.');
    }
    $type = media_table($kind['type']);
    if (!media_query("SELECT `{$kind['type_id']}` FROM `$type` WHERE `{$kind['type_id']}`=?", 'i', [$typeId])
        ->get_result()->fetch_assoc()) {
        throw new InvalidArgumentException('Wybierz gatunek z listy.');
    }
    $dir = dirname(__DIR__) . '/storage/media/' . APP_KEY . '/' . user()['id'];
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Nie udało się utworzyć katalogu.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $kind['file'];
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new RuntimeException('Nie udało się zapisać pliku.');
    }
    $table = media_table($kind['table']);
    $creatorCol = $kind['creator'];
    $textCol = $kind['text'];
    $typeCol = $kind['type_id'];
    try {
        media_query("INSERT INTO `$table`(title,`$creatorCol`,idu,filename,`$textCol`,`$typeCol`)
            VALUES(?,?,?,?,?,?)", 'ssissi', [$title, $creator, user()['id'], $filename, $description, $typeId]);
    } catch (Throwable $error) {
        unlink($path);
        throw $error;
    }
    flash('Dodano plik do biblioteki.');
    go('panel.php');
}

function media_playlist_action(): void
{
    check_csrf();
    $table = media_table('playlistname');
    $members = media_table('playlistdatabase');
    $key = media_kind()['id'];
    $operation = (string)($_POST['operation'] ?? '');
    if ($operation === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Podaj nazwę playlisty do 120 znaków.');
        }
        media_query("INSERT INTO `$table`(idu,name,public) VALUES(?,?,?)", 'isi',
            [user()['id'], $name, !empty($_POST['public']) ? 1 : 0]);
        flash('Utworzono playlistę.');
    } elseif (in_array($operation, ['add', 'remove'], true)) {
        $playlistId = (int)($_POST['idpl'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);
        $playlist = media_query("SELECT idpl FROM `$table` WHERE idpl=? AND idu=?", 'ii',
            [$playlistId, user()['id']])->get_result()->fetch_assoc();
        if (!$playlist || ($operation === 'add' && !media_can_read($itemId))) {
            throw new InvalidArgumentException('Nie można zmienić tej playlisty.');
        }
        if ($operation === 'add') {
            media_query("INSERT IGNORE INTO `$members`(idpl,`$key`) VALUES(?,?)", 'ii', [$playlistId, $itemId]);
            flash('Dodano do playlisty.');
        } else {
            media_query("DELETE FROM `$members` WHERE idpl=? AND `$key`=?", 'ii', [$playlistId, $itemId]);
            flash('Usunięto z playlisty.');
        }
    } else {
        throw new InvalidArgumentException('Nieprawidłowa operacja.');
    }
    go($operation === 'remove' ? 'play.php?id=' . $playlistId : 'playlist.php');
}

function media_upload_form(): void
{
    $kind = media_kind();
    $type = media_table($kind['type']);
    $types = db()->query("SELECT * FROM `$type` ORDER BY `{$kind['type_id']}`")
        ->fetch_all(MYSQLI_ASSOC);
    page_start('Dodaj ' . ($kind['file'] === 'mp3' ? 'utwór' : 'film'));
    echo '<form class="panel media-form" method="post" action="upload.php" enctype="multipart/form-data">' . csrf() .
        '<label class="form-label" for="media-title">Tytuł</label><input class="form-control mb-3" id="media-title" name="title" required>' .
        '<label class="form-label" for="media-creator">' . $kind['creator_label'] . '</label>' .
        '<input class="form-control mb-3" id="media-creator" name="creator" required>' .
        '<label class="form-label" for="media-type">Gatunek</label><select class="form-select mb-3" id="media-type" name="type_id" required>';
    foreach ($types as $typeRow) {
        echo '<option value="' . (int)$typeRow[$kind['type_id']] . '">' . h($typeRow['name']) . '</option>';
    }
    echo '</select><label class="form-label" for="media-description">' .
        ($kind['file'] === 'mp3' ? 'Tekst utworu (opcjonalnie)' : 'Napisy (opcjonalnie)') . '</label>' .
        '<textarea class="form-control mb-3" id="media-description" name="description" rows="3"></textarea>' .
        '<label class="form-label" for="media-file">Plik ' . strtoupper($kind['file']) . '</label>' .
        '<input class="form-control mb-3" id="media-file" name="media" type="file" accept=".' . $kind['file'] . '" required>' .
        '<button class="btn btn-primary">Prześlij</button></form>';
    page_end();
}

function media_library(): void
{
    $kind = media_kind();
    $playlists = media_own_playlists();
    $items = media_items();
    page_start('Zadanie ' . substr(APP_KEY, 1));
    echo '<div class="cloud-toolbar"><h2>' . $kind['title'] . '</h2>' .
        '<a class="btn btn-primary" href="select.php">Dodaj ' . ($kind['file'] === 'mp3' ? 'utwór' : 'film') . '</a></div>';
    echo '<div class="media-grid">';
    foreach ($items as $item) {
        $id = (int)$item[$kind['id']];
        echo '<article class="media-card"><' . ($kind['file'] === 'mp3' ? 'audio' : 'video') .
            ' controls preload="metadata" src="file.php?id=' . $id . '"></' .
            ($kind['file'] === 'mp3' ? 'audio' : 'video') . '><div class="media-card-body"><h3>' .
            h($item['title']) . '</h3><p>' . h($item[$kind['creator']]) . ' · ' .
            h($item['genre']) . '</p><p class="help">' . h($item['datetime']) . '</p>';
        if ($playlists) {
            echo '<form method="post" action="playlist.php">' . csrf() .
                '<input type="hidden" name="operation" value="add">' .
                '<input type="hidden" name="item_id" value="' . $id . '"><div class="input-group">' .
                '<select class="form-select" name="idpl" aria-label="Wybierz playlistę">';
            foreach ($playlists as $playlist) {
                echo '<option value="' . (int)$playlist['idpl'] . '">' . h($playlist['name']) . '</option>';
            }
            echo '</select><button class="btn btn-outline-primary">Dodaj do playlisty</button></div></form>';
        }
        echo '</div></article>';
    }
    echo '</div>';
    if (!$items) {
        notice('Biblioteka jest pusta.');
    }
    page_end();
}

function media_playlists(): void
{
    $table = media_table('playlistname');
    $rows = media_query("SELECT * FROM `$table`
        WHERE public=1 OR idu=? ORDER BY datetime DESC,idpl DESC", 'i', [user()['id']])
        ->get_result()->fetch_all(MYSQLI_ASSOC);
    $owners = [];
    foreach (account_query('SELECT `' . account_id_column() . '` AS id,`' . account_login_column() . '` AS username FROM `' . account_table() . '`')->get_result() as $account) {
        $owners[(int)$account['id']] = $account['username'];
    }
    page_start('Playlisty');
    echo '<form class="panel media-form" method="post">' . csrf() .
        '<input type="hidden" name="operation" value="create"><label class="form-label" for="playlist-name">Nowa playlista</label>' .
        '<input class="form-control mb-2" id="playlist-name" name="name" required>' .
        '<label class="form-label"><input type="checkbox" name="public" value="1"> Publiczna</label><br>' .
        '<button class="btn btn-primary mt-2">Utwórz</button></form><div class="playlist-list">';
    foreach ($rows as $row) {
        echo '<a class="playlist-link" href="play.php?id=' . (int)$row['idpl'] . '"><strong>' .
            h($row['name']) . '</strong><span>' . h($owners[(int)$row['idu']] ?? 'Użytkownik') . ' · ' .
            ($row['public'] ? 'Publiczna' : 'Prywatna') . '</span></a>';
    }
    echo '</div>';
    if (!$rows) {
        notice('Nie ma jeszcze playlist.');
    }
    page_end();
}

function media_play(): void
{
    $playlistId = (int)($_GET['id'] ?? 0);
    $table = media_table('playlistname');
    $members = media_table('playlistdatabase');
    $kind = media_kind();
    $item = media_table($kind['table']);
    $key = $kind['id'];
    $playlist = media_query("SELECT * FROM `$table`
        WHERE idpl=? AND (public=1 OR idu=?)", 'ii', [$playlistId, user()['id']])
        ->get_result()->fetch_assoc();
    if (!$playlist) {
        http_response_code(404);
        page_start('Playlista');
        notice('Nie znaleziono playlisty.', 'danger');
        page_end();
        return;
    }
    $rows = media_query("SELECT i.* FROM `$members` m JOIN `$item` i ON i.`$key`=m.`$key`
        WHERE m.idpl=? ORDER BY m.idpldb", 'i', [$playlistId])->get_result()->fetch_all(MYSQLI_ASSOC);
    page_start($playlist['name']);
    $owner = account_query('SELECT `' . account_login_column() . '` AS username FROM `' . account_table() . '` WHERE `' . account_id_column() . '`=?', 'i', [(int)$playlist['idu']])
        ->get_result()->fetch_assoc();
    echo '<p>' . h($owner['username'] ?? 'Użytkownik') . ' · ' .
        ($playlist['public'] ? 'Playlista publiczna' : 'Playlista prywatna') . '</p>';
    if ($rows) {
        $first = (int)$rows[0][$key];
        $tag = $kind['file'] === 'mp3' ? 'audio' : 'video';
        echo '<' . $tag . ' id="playlist-player" class="playlist-player" controls src="file.php?id=' . $first . '"></' . $tag . '>' .
            '<ol id="playlist-queue" class="playlist-list">';
        foreach ($rows as $row) {
            $id = (int)$row[$key];
            echo '<li><button type="button" class="playlist-track" data-src="file.php?id=' . $id . '">' .
                h($row['title']) . ' · ' . h($row[$kind['creator']]) . '</button>';
            if ((int)$playlist['idu'] === user()['id']) {
                echo '<form method="post" action="playlist.php">' . csrf() .
                    '<input type="hidden" name="operation" value="remove">' .
                    '<input type="hidden" name="idpl" value="' . $playlistId . '">' .
                    '<input type="hidden" name="item_id" value="' . $id . '">' .
                    '<button class="btn btn-sm btn-outline-danger">Usuń z playlisty</button></form>';
            }
            echo '</li>';
        }
        echo '</ol><script defer src="../assets/media-player.js"></script>';
    } else {
        notice('Ta playlista jest pusta.');
    }
    page_end();
}

function media_page(): void
{
    media_init();
    try {
        if (ACTION === 'media_file') {
            media_stream();
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (ACTION === 'media_upload') {
                media_upload();
            } elseif (ACTION === 'media_playlist') {
                media_playlist_action();
            }
        }
        if (ACTION === 'media_select' || ACTION === 'media_upload') {
            media_upload_form();
        } elseif (ACTION === 'media_playlist') {
            media_playlists();
        } elseif (ACTION === 'media_play') {
            media_play();
        } else {
            media_library();
        }
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        page_start('Zadanie ' . substr(APP_KEY, 1));
        notice($error->getMessage(), 'danger');
        echo '<p><a href="panel.php">Powrót do biblioteki</a></p>';
        page_end();
    }
}
