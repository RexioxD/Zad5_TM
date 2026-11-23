<?php declare(strict_types=1);

function cloud_root(): string
{
    $name = user()['username'];
    $folder = preg_match('/^[A-Za-z0-9._-]+$/', $name) && $name !== '.' && $name !== '..'
        ? $name
        : 'u' . user()['id'];
    $root = dirname(__DIR__) . '/storage/cloud/' . $folder;
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('Nie udało się utworzyć katalogu użytkownika.');
    }
    return $root;
}

function cloud_dir(string $folder): string
{
    $root = cloud_root();
    if ($folder === '') {
        return $root;
    }
    if (!preg_match('/^[\pL\pN _.-]{1,100}$/u', $folder) || $folder === '.' || $folder === '..') {
        throw new InvalidArgumentException('Nieprawidłowa nazwa folderu.');
    }
    $path = $root . '/' . $folder;
    if (!is_dir($path) || is_link($path)) {
        throw new InvalidArgumentException('Nie znaleziono folderu.');
    }
    return $path;
}

function cloud_name(string $name): string
{
    if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 240 ||
        preg_match('~[/\\\\\x00-\x1F]~', $name)) {
        throw new InvalidArgumentException('Nieprawidłowa nazwa pliku.');
    }
    return $name;
}

function cloud_folder_name(string $name): string
{
    if (!preg_match('/^[\pL\pN _.-]{1,100}$/u', $name) || $name === '.' || $name === '..') {
        throw new InvalidArgumentException('Podaj poprawną nazwę folderu.');
    }
    return $name;
}

function cloud_file(string $folder, string $name): string
{
    $path = cloud_dir($folder) . '/' . cloud_name($name);
    if (!is_file($path) || is_link($path)) {
        throw new InvalidArgumentException('Nie znaleziono pliku.');
    }
    return $path;
}

function cloud_url(string $folder, string $name, bool $download = false): string
{
    return 'file.php?dir=' . rawurlencode($folder) . '&amp;name=' . rawurlencode($name) .
        ($download ? '&amp;download=1' : '');
}

function cloud_mime(string $name): string
{
    $types = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4', 'pdf' => 'application/pdf',
    ];
    return $types[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

function cloud_serve(): void
{
    $folder = (string)($_GET['dir'] ?? '');
    $name = (string)($_GET['name'] ?? '');
    try {
        $path = cloud_file($folder, $name);
    } catch (InvalidArgumentException $error) {
        http_response_code(404);
        exit('Nie znaleziono pliku.');
    }

    $mime = cloud_mime($name);
    $download = isset($_GET['download']) || $mime === 'application/octet-stream';
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;

    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') .
        "; filename*=UTF-8''" . rawurlencode($name));
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');

    if (!$download && preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $range)) {
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

function cloud_page(): void
{
    $folder = (string)($_REQUEST['dir'] ?? '');
    try {
        $directory = cloud_dir($folder);
    } catch (InvalidArgumentException $error) {
        http_response_code(404);
        page_start('Zadanie 5');
        notice($error->getMessage(), 'danger');
        page_end();
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $operation = (string)($_POST['operation'] ?? '');
        if ($operation === 'upload_chunk') {
            header('Content-Type: application/json; charset=UTF-8');
            try {
                $name = cloud_name((string)($_POST['name'] ?? ''));
                $uploadId = (string)($_POST['upload_id'] ?? '');
                $index = filter_var($_POST['index'] ?? null, FILTER_VALIDATE_INT);
                $count = filter_var($_POST['count'] ?? null, FILTER_VALIDATE_INT);
                $total = filter_var($_POST['total'] ?? null, FILTER_VALIDATE_INT);
                $file = $_FILES['file'] ?? null;
                if (!preg_match('/^[a-f0-9]{32}$/', $uploadId) || $index === false ||
                    $count === false || $total === false || $index < 0 || $count < 1 ||
                    $count > 256 || $index >= $count || $total < 1 ||
                    $total > 512 * 1024 * 1024 || !$file ||
                    $file['error'] !== UPLOAD_ERR_OK || $file['size'] < 1 ||
                    $file['size'] > 2 * 1024 * 1024) {
                    throw new InvalidArgumentException('Nieprawidłowa część pliku.');
                }
                $destination = $directory . '/' . $name;
                $partial = $directory . '/.upload-' . $uploadId . '.part';
                if (file_exists($destination)) {
                    throw new RuntimeException('Plik o tej nazwie już istnieje.');
                }
                $stream = fopen($partial, $index === 0 ? 'wb' : 'c+b');
                if (!$stream || !flock($stream, LOCK_EX)) {
                    throw new RuntimeException('Nie udało się zapisać pliku.');
                }
                if ($index > 0 && filesize($partial) !== $index * 2 * 1024 * 1024) {
                    throw new RuntimeException('Części pliku dotarły w złej kolejności.');
                }
                fseek($stream, 0, SEEK_END);
                $input = fopen($file['tmp_name'], 'rb');
                $written = stream_copy_to_stream($input, $stream);
                fclose($input);
                fflush($stream);
                clearstatcache(true, $partial);
                $complete = $index === $count - 1;
                if ($written !== $file['size'] ||
                    ($complete && filesize($partial) !== $total)) {
                    throw new RuntimeException('Plik został przesłany niekompletnie.');
                }
                if ($complete && !rename($partial, $destination)) {
                    throw new RuntimeException('Nie udało się zapisać pliku.');
                }
                flock($stream, LOCK_UN);
                fclose($stream);
                echo json_encode(['ok' => true, 'complete' => $complete]);
            } catch (Throwable $error) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
            }
            return;
        }
        try {
            if ($operation === 'folder' && $folder === '') {
                $newName = cloud_folder_name(trim((string)($_POST['name'] ?? '')));
                if (file_exists(cloud_root() . '/' . $newName) ||
                    !mkdir(cloud_root() . '/' . $newName, 0750)) {
                    throw new RuntimeException('Nie udało się utworzyć folderu.');
                }
                flash('Utworzono folder.');
            } elseif ($operation === 'move') {
                $name = cloud_name((string)($_POST['name'] ?? ''));
                $source = cloud_file($folder, $name);
                $targetFolder = (string)($_POST['target_dir'] ?? '');
                if ($targetFolder === $folder) {
                    throw new InvalidArgumentException('Wybierz inny folder.');
                }
                $destination = cloud_dir($targetFolder) . '/' . $name;
                if (file_exists($destination) || !rename($source, $destination)) {
                    throw new RuntimeException('Nie udało się dodać pliku do folderu. Sprawdź, czy nazwa nie jest zajęta.');
                }
                flash('Dodano plik do folderu.');
            } elseif ($operation === 'rename') {
                $name = cloud_name((string)($_POST['name'] ?? ''));
                $source = $directory . '/' . $name;
                $isFolder = $folder === '' && is_dir($source) && !is_link($source);
                if (!$isFolder && (!is_file($source) || is_link($source))) {
                    throw new InvalidArgumentException('Nie znaleziono pliku lub folderu.');
                }
                $newName = trim((string)($_POST['new_name'] ?? ''));
                $newName = $isFolder ? cloud_folder_name($newName) : cloud_name($newName);
                $destination = $directory . '/' . $newName;
                if ($newName === $name) {
                    throw new InvalidArgumentException('Podaj inną nazwę.');
                }
                if (file_exists($destination) || !rename($source, $destination)) {
                    throw new RuntimeException('Nie udało się zmienić nazwy. Sprawdź, czy nazwa nie jest zajęta.');
                }
                flash('Zmieniono nazwę.');
            } elseif ($operation === 'upload') {
                $file = $_FILES['file'] ?? null;
                if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Nie udało się przesłać pliku. Sprawdź limit hostingu.');
                }
                $name = cloud_name(basename((string)$file['name']));
                if (file_exists($directory . '/' . $name)) {
                    throw new RuntimeException('Plik o tej nazwie już istnieje.');
                }
                if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $name)) {
                    throw new RuntimeException('Nie udało się zapisać pliku.');
                }
                flash('Przesłano plik.');
            } elseif ($operation === 'delete') {
                $name = cloud_name((string)($_POST['name'] ?? ''));
                $path = $directory . '/' . $name;
                if ($folder === '' && is_dir($path) && !is_link($path)) {
                    foreach (new DirectoryIterator($path) as $item) {
                        if (!$item->isDot() && $item->isFile()) {
                            unlink($item->getPathname());
                        }
                    }
                    if (!rmdir($path)) {
                        throw new RuntimeException('Nie udało się usunąć folderu.');
                    }
                } elseif (is_file($path) && !is_link($path)) {
                    unlink($path);
                } else {
                    throw new InvalidArgumentException('Nie znaleziono pliku lub folderu.');
                }
                flash('Usunięto element.');
            } else {
                throw new InvalidArgumentException('Nieprawidłowa operacja.');
            }
            go('panel.php' . ($folder !== '' ? '?dir=' . rawurlencode($folder) : ''));
        } catch (Throwable $error) {
            $message = $error->getMessage();
        }
    }

    page_start('Zadanie 5');
    if (isset($message)) {
        notice($message, 'danger');
    }
    echo '<div class="cloud-toolbar"><h2>' . ($folder === '' ? 'Moje pliki' : h($folder)) . '</h2>';
    if ($folder !== '') {
        echo '<a class="btn btn-outline-primary" href="panel.php">↑ Folder główny</a>';
    }
    echo '</div><div class="cloud-actions">';
    if ($folder === '') {
        echo '<form method="post" class="panel cloud-action">' . csrf() .
            '<input type="hidden" name="operation" value="folder"><label class="form-label" for="folder-name">Nowy folder</label>' .
            '<div class="input-group"><input class="form-control" id="folder-name" name="name" required>' .
            '<button class="btn btn-primary">Utwórz</button></div></form>';
    }
    echo '<form method="post" enctype="multipart/form-data" class="panel cloud-action" data-cloud-upload>' . csrf() .
        '<input type="hidden" name="operation" value="upload"><input type="hidden" name="dir" value="' . h($folder) . '">' .
        '<label class="form-label" for="cloud-file">Prześlij plik</label><div class="input-group">' .
        '<input class="form-control" type="file" id="cloud-file" name="file" required>' .
        '<button class="btn btn-primary">Prześlij</button></div><div class="help" data-upload-status aria-live="polite"></div></form></div>';

    $folders = [];
    foreach (new DirectoryIterator(cloud_root()) as $entry) {
        if (!$entry->isDot() && $entry->isDir() && !$entry->isLink()) {
            $folders[] = $entry->getFilename();
        }
    }
    natcasesort($folders);
    $items = [];
    foreach (new DirectoryIterator($directory) as $item) {
        if (!$item->isDot() && !$item->isLink() && strpos($item->getFilename(), '.upload-') !== 0) {
            $items[] = ['name' => $item->getFilename(), 'folder' => $item->isDir(),
                'size' => $item->isFile() ? $item->getSize() : null];
        }
    }
    usort($items, fn($a, $b) => ($b['folder'] <=> $a['folder']) ?: strnatcasecmp($a['name'], $b['name']));
    echo '<div class="cloud-grid">';
    foreach ($items as $item) {
        $name = $item['name'];
        $url = cloud_url($folder, $name);
        $mime = cloud_mime($name);
        echo '<article class="cloud-card">';
        if ($item['folder'] && $folder === '') {
            echo '<a class="cloud-preview cloud-folder" href="panel.php?dir=' . rawurlencode($name) . '">📁</a>';
        } elseif (strpos($mime, 'image/') === 0) {
            echo '<a class="cloud-preview" href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="' . h($name) . '" loading="lazy"></a>';
        } elseif ($mime === 'video/mp4') {
            echo '<video class="cloud-preview" controls preload="metadata" src="' . $url . '"></video>';
        } elseif ($mime === 'audio/mpeg') {
            echo '<div class="cloud-preview cloud-audio">♪<audio controls preload="none" src="' . $url . '"></audio></div>';
        } else {
            $extension = strtoupper(pathinfo($name, PATHINFO_EXTENSION));
            echo '<div class="cloud-preview cloud-file"><svg width="54" height="62" viewBox="0 0 54 62" fill="none" aria-hidden="true"><path d="M8 2h27l11 11v47H8z" fill="#f8fbff" stroke="#1767b1" stroke-width="2"/><path d="M35 2v11h11M15 29h24M15 37h24M15 45h17" stroke="#1767b1" stroke-width="2"/></svg><span>' . h($extension ?: 'PLIK') . '</span></div>';
        }
        echo '<div class="cloud-card-body"><a class="cloud-name" href="' .
            ($item['folder'] ? 'panel.php?dir=' . rawurlencode($name) : cloud_url($folder, $name, true)) . '">' .
            h($name) . '</a><div class="help">' . ($item['folder'] ? 'Folder' : number_format($item['size'] / 1024, 1, ',', ' ') . ' KB') .
            '</div><div class="cloud-controls">';
        if (!$item['folder']) {
            $targets = array_values(array_filter($folders, fn($target) => $target !== $folder));
            if ($folder !== '') {
                array_unshift($targets, '');
            }
            if ($targets) {
                echo '<details class="cloud-popover"><summary class="btn btn-sm btn-outline-primary">Dodaj do folderu</summary>' .
                    '<form method="post" class="cloud-popover-content">' . csrf() .
                    '<input type="hidden" name="operation" value="move">' .
                    '<input type="hidden" name="dir" value="' . h($folder) . '">' .
                    '<input type="hidden" name="name" value="' . h($name) . '">' .
                    '<label for="move-' . h(md5($folder . '/' . $name)) . '">Wybierz folder</label>' .
                    '<select class="form-select form-select-sm" id="move-' . h(md5($folder . '/' . $name)) . '" name="target_dir">';
                foreach ($targets as $target) {
                    echo '<option value="' . h($target) . '">' . h($target === '' ? 'Folder główny' : $target) . '</option>';
                }
                echo '</select><button class="btn btn-sm btn-primary">Dodaj</button></form></details>';
            } else {
                echo '<button class="btn btn-sm btn-outline-primary" disabled title="Utwórz najpierw folder">Dodaj do folderu</button>';
            }
        }
        echo '<form method="post" class="cloud-delete">' . csrf() . '<input type="hidden" name="operation" value="delete">' .
            '<input type="hidden" name="dir" value="' . h($folder) . '"><input type="hidden" name="name" value="' . h($name) . '">' .
            '<button class="btn btn-sm btn-outline-danger">Usuń</button></form>' .
            '<details class="cloud-popover cloud-more"><summary class="btn btn-sm btn-outline-primary" aria-label="Więcej opcji dla ' . h($name) . '">⋮</summary>' .
            '<form method="post" class="cloud-popover-content">' . csrf() .
            '<input type="hidden" name="operation" value="rename">' .
            '<input type="hidden" name="dir" value="' . h($folder) . '">' .
            '<input type="hidden" name="name" value="' . h($name) . '">' .
            '<label for="rename-' . h(md5($folder . '/' . $name)) . '">Zmień nazwę</label>' .
            '<input class="form-control form-control-sm" id="rename-' . h(md5($folder . '/' . $name)) . '" name="new_name" value="' . h($name) . '" required>' .
            '<button class="btn btn-sm btn-primary">Zapisz</button></form></details></div></div></article>';
    }
    echo '</div>';
    if (!$items) {
        notice('Ten folder jest pusty.');
    }
    page_end();
}
