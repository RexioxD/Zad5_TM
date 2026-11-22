<?php declare(strict_types=1);
// Wspólne konta w bazie z4. Sesje i dane ćwiczeń pozostają oddzielne.
date_default_timezone_set('Europe/Warsaw');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$appDirectory = defined('APP_KEY') ? APP_KEY : 'z' . TASK;
session_name('laboratorium_' . $appDirectory);
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/' . $appDirectory . '/']);
session_start();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(24)); }
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function go(string $path): void { header('Location: ' . $path); exit; }
function user(): ?array {
    static $checked = false, $current = null;
    if ($checked) { return $current; }
    $checked = true;
    if (empty($_SESSION['user'])) { return null; }
    if (!empty($_SESSION['shared_accounts']) && TASK !== 4) {
        unset($_SESSION['user'], $_SESSION['loggedin'], $_SESSION['shared_accounts']);
        return null;
    }
    // Usunięte konto nie może korzystać ze starej sesji.
    $table = account_table();
    $id = account_id_column();
    $login = account_login_column();
    $u = account_query("SELECT `$id` AS id,`$login` AS username,avatar FROM `$table` WHERE `$id`=? AND `$login`=?", 'is', [(int)$_SESSION['user']['id'],$_SESSION['user']['username']])->get_result()->fetch_assoc();
    if (!$u) { unset($_SESSION['user'], $_SESSION['loggedin']); return null; }
    $current = ['id'=>(int)$u['id'],'username'=>$u['username'],'avatar'=>$u['avatar']];
    $_SESSION['user'] = $current;
    return $current;
}
function admin(): bool { return user() && user()['username'] === 'admin'; }
function guard(): void { if (!user()) { go(TASK === 1 ? 'index3.php' : 'logowanie.php'); } }
function csrf(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Formularz wygasł. Odśwież stronę i spróbuj ponownie.'); }
}
function account_table(): string {
    if (TASK === 5) { return 'z5_users'; }
    if (TASK === 6 && APP_KEY === 'z6a') { return 'user'; }
    if (TASK === 6 && APP_KEY === 'z6b') { return 'user'; }
    return 'users';
}
function account_id_column(): string { return TASK === 6 ? 'idu' : 'id'; }
function account_login_column(): string { return TASK === 6 ? 'login' : 'username'; }
function visit_table(): string {
    if (TASK === 5) { return 'z5_goscieportalu'; }
    if (TASK === 6 && APP_KEY === 'z6a') { return 'z6a_goscieportalu'; }
    if (TASK === 6 && APP_KEY === 'z6b') { return 'goscieportalu'; }
    return 'goscieportalu';
}
function accounts_db(): mysqli {
    static $accounts;
    if ($accounts) { return $accounts; }
    $app = TASK === 5 ? 'z4' : (defined('APP_KEY') ? APP_KEY : 'z' . TASK);
    $c = require dirname(__DIR__) . '/' . $app . '/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $accounts = new mysqli($c['host'],$c['user'],$c['password'],$c['database']);
    $accounts->set_charset('utf8mb4');
    $table = account_table();
    $id = account_id_column();
    $login = account_login_column();
    $accounts->query("CREATE TABLE IF NOT EXISTS `$table` (`$id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `$login` VARCHAR(128) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, avatar VARCHAR(200) DEFAULT NULL, worker TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci");
    return $accounts;
}
function account_query(string $sql, string $types = '', array $args = []): mysqli_stmt {
    $s=accounts_db()->prepare($sql);
    if ($args) { $s->bind_param($types,...$args); }
    $s->execute(); return $s;
}
function db(): mysqli {
    static $link;
    if ($link) { return $link; }
    $c = require dirname(__DIR__) . '/' . (defined('APP_KEY') ? APP_KEY : 'z' . TASK) . '/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $link = new mysqli($c['host'], $c['user'], $c['password'], $c['database']);
    $link->set_charset('utf8mb4');
    // Automatyczne utworzenie struktur w pustej bazie danego zadania.
    if (TASK !== 6) {
        $link->query("CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(128) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, avatar VARCHAR(200) DEFAULT NULL, worker TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci");
    }
    if (TASK >= 3) {
        $visits = visit_table();
        $extra = TASK >= 5 ? ', login_username VARCHAR(128) NULL, login_success TINYINT(1) NULL' : '';
        $link->query("CREATE TABLE IF NOT EXISTS `$visits` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ipaddress VARCHAR(45) NOT NULL, datetime DATETIME NOT NULL, browser TEXT, screen_size VARCHAR(30), window_size VARCHAR(30), colors SMALLINT, cookies TINYINT, java TINYINT, language VARCHAR(50)$extra, INDEX(ipaddress)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci");
        $link->query("CREATE TABLE IF NOT EXISTS geo_cache (ipaddress VARCHAR(45) PRIMARY KEY, data TEXT NOT NULL, checked_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (TASK === 4) {
        accounts_db();
        $link->query("CREATE TABLE IF NOT EXISTS domeny (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, host VARCHAR(253) NOT NULL, port INT NOT NULL, user_id INT UNSIGNED NOT NULL, errors INT NOT NULL DEFAULT 0, failure DATETIME NULL, total_errors INT NOT NULL DEFAULT 0, checked_at DATETIME NULL, online TINYINT NULL, message VARCHAR(255) DEFAULT NULL, response_ms INT DEFAULT NULL, INDEX(user_id), CONSTRAINT fk_domeny_user FOREIGN KEY(user_id) REFERENCES users(id), UNIQUE KEY one_service(user_id,host,port)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci");
    }
    return $link;
}
function query(string $sql, string $types = '', array $args = []): mysqli_stmt {
    $s = db()->prepare($sql);
    if ($args) { $s->bind_param($types, ...$args); }
    $s->execute(); return $s;
}
function notice(string $message, string $kind = 'info'): void { echo '<div class="alert alert-' . h($kind) . '" role="status">' . h($message) . '</div>'; }
function flash(string $message): void { $_SESSION['flash'] = $message; }
function avatar(?array $u): string {
    if (!empty($u['avatar'])) { return '<img class="avatar" src="../' . h($u['avatar']) . '" alt="Avatar">'; }
    return '<svg class="avatar" viewBox="0 0 32 32" aria-label="Domyślny avatar" role="img"><circle cx="16" cy="16" r="16" fill="#d7eaff"/><circle cx="16" cy="11" r="5" fill="#2869ab"/><path d="M6 29c0-13 20-13 20 0" fill="#2869ab"/></svg>';
}
function page_start(string $title, bool $navigation = true): void {
    $boot = TASK >= 2;
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="Zadania laboratoryjne PHP"><title>Wnuk-Lipinski</title>';
    if (TASK === 1) {
        echo '</head><body><main><p>' . h($title) . '</p>';
        if (isset($_SESSION['flash'])) { notice($_SESSION['flash'],'success'); unset($_SESSION['flash']); }
        return;
    }
    if ($boot) { echo '<link rel="stylesheet" href="../assets/bootstrap.min.css"><script defer src="../assets/bootstrap.bundle.min.js"></script>'; }
    echo '<link rel="stylesheet" href="twoj_css.css?v=20261122"><script defer src="twoj_js.js"></script><script defer src="../assets/app.js?v=20261122"></script></head><body' . (isset($GLOBALS['visit_id']) ? ' data-visit="' . (int)$GLOBALS['visit_id'] . '" data-csrf="' . h($_SESSION['csrf']) . '"' : '') . '>';
    if ($navigation && user()) {
        echo '<div id="myHeader">';
        navbar();
        echo '</div>';
    }
    echo '<main class="container py-4">' . ($title !== '' ? '<h1>' . h($title) . '</h1>' : '');
    if (isset($_SESSION['flash'])) { notice($_SESSION['flash'], 'success'); unset($_SESSION['flash']); }
}
function page_end(): void {
    echo '</main>';
    if (TASK === 1) { echo '</body></html>'; return; }
    require dirname(__DIR__) . '/' . (defined('APP_KEY') ? APP_KEY : 'z' . TASK) . '/footer.php';
    echo '</body></html>';
}
function navbar(): void {
    if (!user()) { return; }
    echo '<nav class="navbar navbar-expand-lg navbar-dark"><div class="container"><a class="navbar-brand" href="panel.php">Zadanie ' . (defined('APP_KEY') ? h(substr(APP_KEY,1)) : TASK) . '</a><button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main_nav" aria-controls="main_nav" aria-expanded="false" aria-label="Rozwiń menu"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="main_nav"><ul class="navbar-nav me-auto">';
    if (TASK === 1) { echo '<li><a class="nav-link" href="index4.php">Strona chroniona</a></li>'; }
    if (TASK === 2) {
        for ($x = 1; $x <= 3; $x++) {
            echo '<li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Polecenia ' . $x . '.x</a><ul class="dropdown-menu">';
            for ($y = 1; $y <= ($x === 3 ? 3 : 2); $y++) { echo '<li><a class="dropdown-item" href="polecenie' . $x . '_' . $y . '.php">Polecenie ' . $x . '.' . $y . '</a></li>'; }
            echo '</ul></li>';
        }
    }
    if (TASK >= 3) { echo '<li class="nav-item"><a class="nav-link" href="goscie.php">Goście portalu</a></li>'; }
    if (TASK === 3) {
        echo '<li class="nav-item"><a class="nav-link" href="index8.php">Geolokalizacja IP</a></li>';
        echo '<li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Diagnostyka</a><ul class="dropdown-menu">';
        foreach (['index1.php' => 'Netstat', 'index2.php' => 'PHP Info', 'index3.php' => 'Whoami', 'index4.php' => 'Obciążenie serwera', 'index5.php' => 'Pliki i uprawnienia', 'index6.php' => 'Rekordy DNS', 'index7.php' => 'Adresy IP i nazwy hostów'] as $url => $label) { echo '<li><a class="dropdown-item" href="' . $url . '">' . $label . '</a></li>'; }
        echo '</ul></li>';
    }
    if (TASK === 4) {
        foreach (['index1.php' => 'Monitorowanie', 'dodaj1.php' => 'Dodaj usługę', 'test.php' => 'Test połączenia'] as $url => $label) { echo '<li class="nav-item"><a class="nav-link" href="' . $url . '">' . $label . '</a></li>'; }
        if (admin()) { echo '<li class="nav-item"><a class="nav-link" href="staff.php">Pracownicy</a></li>'; }
    }
    if (TASK === 6) {
        foreach (['panel.php' => 'Biblioteka', 'select.php' => 'Dodaj plik', 'playlist.php' => 'Playlisty'] as $url => $label) {
            echo '<li class="nav-item"><a class="nav-link" href="' . $url . '">' . $label . '</a></li>';
        }
    }
    echo '</ul><span class="navbar-text me-3">' . avatar(user()) . ' ' . h(user()['username']) . '</span><a class="nav-link text-white" href="logout.php"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 4H4v16h6M8 12h14m-5-5 5 5-5 5"/></svg> Wyloguj</a></div></div></nav>';
}
function log_visit(): void {
    if (TASK < 3 || $_SERVER['REQUEST_METHOD'] !== 'GET' || ACTION === 'header') { return; }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 2000);
    $visits = visit_table();
    query("INSERT INTO `$visits`(ipaddress,datetime,browser) VALUES(?,?,?)", 'sss', [$ip, date('Y-m-d H:i:s'), $ua]);
    $GLOBALS['visit_id'] = db()->insert_id;
    $_SESSION['visits'][] = $GLOBALS['visit_id'];
    $_SESSION['visits'] = array_slice($_SESSION['visits'], -50);
}
function log_login_attempt(string $username, bool $success): void {
    if (TASK < 5) { return; }
    $visits = visit_table();
    query("INSERT INTO `$visits`(ipaddress,datetime,browser,login_username,login_success) VALUES(?,?,?,?,?)",
        'ssssi', [$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', date('Y-m-d H:i:s'),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 2000), $username, $success ? 1 : 0]);
}
function geo(string $ip): array {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return ['error' => 'Adres lokalny lub zarezerwowany']; }
    $cached = query('SELECT data,checked_at FROM geo_cache WHERE ipaddress=? AND checked_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)', 's', [$ip])->get_result()->fetch_assoc();
    if ($cached) {
        $value = json_decode($cached['data'], true) ?: [];
        if (!isset($value['error']) || time() - strtotime($cached['checked_at']) < 300) {
            return $value;
        }
    }
    $url = 'https://ipinfo.io/' . rawurlencode($ip) . '/geo';
    $raw = false;
    if (function_exists('curl_init')) {
        $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3, CURLOPT_USERAGENT => 'PHP-laboratorium/1.0']);
        $raw = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        if ($code !== 200) { $raw = false; }
    } elseif (ini_get('allow_url_fopen')) {
        $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 3, 'header' => "User-Agent: PHP-laboratorium/1.0\r\n"]]));
    }
    $result = $raw ? json_decode($raw, true) : null;
    if (!is_array($result) || isset($result['error'])) { $result = ['error' => 'Brak odpowiedzi ipinfo.io lub limit zapytań']; }
    query('INSERT INTO geo_cache(ipaddress,data,checked_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE data=VALUES(data),checked_at=NOW()', 'ss', [$ip, json_encode($result)]);
    return $result;
}
function browser_name(string $ua): string {
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Version' => 'Safari'] as $token => $name) {
        if (preg_match('~' . $token . '/([\d.]+)~', $ua, $m)) { return $name . ' ' . $m[1]; }
    }
    return $ua ?: 'Brak danych';
}
function duration(int $seconds): string { return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60); }
set_exception_handler(function (Throwable $e): void {
    error_log((string)$e); http_response_code(500);
    echo '<!doctype html><html lang="pl"><meta charset="utf-8"><title>Wnuk-Lipinski</title><p>Nie udało się wykonać operacji. Sprawdź konfigurację bazy danych i dziennik błędów hostingu.</p><a href="index.php">Powrót</a></html>';
});
