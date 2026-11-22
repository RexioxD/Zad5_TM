<?php declare(strict_types=1);
$register = ACTION === 'register';
$stage = defined('STAGE') ? STAGE : 3;
$error = '';
$username = trim((string)($_POST['user'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pass = (string)($_POST['pass'] ?? '');
    if ($username === '' || $pass === '' || strlen($username) > 128 || strlen($pass) > 255) { $error = 'Wpisz login i hasło (login do 128, hasło do 255 znaków).'; }
    elseif ($register) {
        if ($pass !== (string)($_POST['pass2'] ?? '')) { $error = 'Podane hasła różnią się.'; }
        elseif (account_query('SELECT `' . account_id_column() . '` FROM `' . account_table() . '` WHERE `' . account_login_column() . '`=?', 's', [$username])->get_result()->fetch_assoc()) { $error = 'Ten login jest już zajęty.'; }
        else {
            $avatar = null;
            if (TASK >= 2 && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['avatar'];
                $image = $file['error'] === UPLOAD_ERR_OK ? @getimagesize($file['tmp_name']) : false;
                $ext = $image ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$image[2]] ?? '') : '';
                if (!$ext || $file['size'] > 2 * 1024 * 1024) { $error = 'Avatar: wybierz obraz JPG, PNG, GIF lub WebP do 2 MB.'; }
                else {
                    $uploadFolder = defined('APP_KEY') ? APP_KEY : 'z' . TASK;
                    $dir = dirname(__DIR__) . '/uploads/' . $uploadFolder;
                    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
                    $avatar = 'uploads/' . $uploadFolder . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], dirname(__DIR__) . '/' . $avatar)) { $error = 'Nie udało się zapisać avataru.'; }
                }
            }
            if (!$error) {
                $stored = password_hash($pass, PASSWORD_DEFAULT);
                account_query('INSERT INTO `' . account_table() . '`(`' . account_login_column() . '`,password,avatar) VALUES(?,?,?)', 'sss', [$username, $stored, $avatar]);
                flash('Konto zostało utworzone. Możesz się zalogować.'); go(TASK === 1 ? 'index3.php' : 'logowanie.php');
            }
        }
    } else {
        $record = null;
        if (TASK === 1 && $stage === 1) {
            // Celowo podatny przykład z instrukcji. Ma dostęp tylko do bazy z1.
            try { $record = db()->query("SELECT * FROM users WHERE (username='$username') AND (password='$pass')")->fetch_assoc(); }
            catch (mysqli_sql_exception $e) { $error = 'Błąd zapytania SQL w wersji demonstracyjnej.'; }
        }
        // Wspólne konta działają we wszystkich formularzach. Podatny przykład
        // powyżej ma dostęp tylko do odrębnej bazy demonstracyjnej z1.
        if (!$record) {
            $candidate = account_query('SELECT `' . account_id_column() . '` AS id,`' . account_login_column() . '` AS username,password,avatar FROM `' . account_table() . '` WHERE `' . account_login_column() . '`=?', 's', [$username])->get_result()->fetch_assoc();
            if ($candidate && password_verify($pass,$candidate['password'])) { $record = $candidate; $error = ''; }
        }
        log_login_attempt($username, (bool)$record);
        if ($record) {
            if (TASK === 1 && $stage < 3) {
                page_start('Wynik logowania', false); notice('Logowanie OK. Użytkownik: ' . $record['username'], 'success');
                echo '<a href="index' . $stage . '.php">Spróbuj ponownie</a> · <a href="index.php">Menu zadania</a>'; page_end(); return;
            }
            session_regenerate_id(true);
            $_SESSION['loggedin'] = true;
            $_SESSION['shared_accounts'] = false;
            $_SESSION['user'] = ['id' => (int)$record['id'], 'username' => $record['username'], 'avatar' => $record['avatar']];
            go(TASK === 1 ? 'index4.php' : 'panel.php');
        }
        if (!$error) { $error = 'Nieprawidłowy login lub hasło.'; }
    }
}
page_start($register ? 'Rejestracja' : 'Logowanie' . (TASK === 1 ? ' - wersja ' . $stage : ''), false);
echo '<div class="auth-box">';
if ($error) { notice($error, 'danger'); }
echo '<form method="post" action="' . ($register ? 'add.php' : (TASK === 1 ? 'weryfikuj' . $stage . '.php' : 'weryfikuj.php')) . '" enctype="multipart/form-data">' . csrf();
echo '<label class="form-label" for="user">Login: </label><input class="form-control mb-3" id="user" name="user" maxlength="128" value="' . h($username) . '" autocomplete="username" required>' . (TASK === 1 ? '<br><br>' : '');
echo '<label class="form-label" for="pass">Hasło: </label><input class="form-control mb-3" id="pass" name="pass" type="password" autocomplete="' . ($register ? 'new-password' : 'current-password') . '" required>' . (TASK === 1 ? '<br><br>' : '');
if ($register) {
    echo '<label class="form-label" for="pass2">Powtórz hasło: </label><input class="form-control mb-3" id="pass2" name="pass2" type="password" autocomplete="new-password" required>' . (TASK === 1 ? '<br><br>' : '');
    if (TASK >= 2) { echo '<label class="form-label" for="avatar">Avatar (opcjonalnie)</label><input class="form-control mb-3" type="file" name="avatar" id="avatar" accept="image/png,image/jpeg,image/gif,image/webp">'; }
}
echo '<button class="btn btn-primary" type="submit">' . ($register ? 'Utwórz konto' : 'Zaloguj') . '</button></form>';
echo '<p class="mt-3"><a href="' . ($register ? (TASK === 1 ? 'index3.php' : 'logowanie.php') : 'rejestruj.php') . '">' . ($register ? 'Mam już konto' : 'Zarejestruj konto') . '</a></p>';
echo '<p class="mt-3"><a href="' . (TASK === 1 ? 'index.php' : '../') . '">Powrót do listy zadań</a></p></div>';
page_end();
