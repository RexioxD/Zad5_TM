<?php declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (ACTION === 'enter' || (TASK === 1 && ACTION === 'home')) {
    // Wejście z listy zadań wymaga nowego logowania; nawigacja wewnętrzna korzysta z panel.php.
    unset($_SESSION['user'],$_SESSION['loggedin'],$_SESSION['shared_accounts']);
    session_regenerate_id(true);
    if (TASK !== 1) { go('logowanie.php'); }
}
if (ACTION === 'logout') { $_SESSION = []; session_destroy(); go(TASK === 1 ? 'index3.php' : 'logowanie.php'); }
if (ACTION === 'client') {
    header('Content-Type: application/json; charset=UTF-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{}'); }
    check_csrf(); $id = (int)($_POST['id'] ?? 0);
    if (!in_array($id, $_SESSION['visits'] ?? [], true)) { http_response_code(403); exit('{}'); }
    $visits = visit_table();
    query("UPDATE `$visits` SET screen_size=?,window_size=?,colors=?,cookies=?,java=?,language=? WHERE id=?", 'ssiiisi', [substr((string)($_POST['screen'] ?? ''),0,30), substr((string)($_POST['window'] ?? ''),0,30), min(64,max(0,(int)($_POST['colors'] ?? 0))), (int)!empty($_POST['cookies']), (int)!empty($_POST['java']), substr((string)($_POST['language'] ?? ''),0,50), $id]); exit('{"ok":true}');
}
if (ACTION === 'login' || ACTION === 'register') { log_visit(); require __DIR__ . '/auth.php'; return; }
if (TASK === 1 && ACTION === 'home') {
    page_start('zadanie 1', false);
    echo '<a href="index1.php">1. logowanie podatne na SQL Injection</a><br><a href="index2.php">2. logowanie zabezpieczone</a><br><a href="index3.php">3. logowanie z sesją</a><br><a href="index4.php">4. strona chroniona</a><br><a href="rejestruj.php">5. rejestracja</a><p><a href="../">powrót</a></p>'; page_end(); return;
}
guard();
if (ACTION === 'header') { navbar(); return; }
log_visit();
if (ACTION === 'guests') { require __DIR__ . '/guests.php'; return; }
if (ACTION === 'diagnostic') { require __DIR__ . '/diagnostics.php'; return; }
if (TASK === 4 && in_array(ACTION, ['monitor','add','delete','staff','test','check'], true)) { require __DIR__ . '/monitor.php'; return; }
if (TASK === 5 && ACTION === 'home') { require __DIR__ . '/cloud.php'; cloud_page(); return; }
if (TASK === 6) { require __DIR__ . '/media.php'; media_page(); return; }
page_start(TASK === 2 ? (ACTION === 'command' ? 'Polecenie ' . COMMAND : '') : (TASK === 1 ? 'Strona chroniona' : 'Zadanie ' . TASK));
if (ACTION === 'command') { /* Pusta przestrzeń na treść polecenia w szablonie. */ }
elseif (TASK === 1) { echo '<p>Jestem zalogowany jako <strong>' . h(user()['username']) . '</strong>.</p><p><a href="logout.php">Wyloguj się</a></p>'; }
elseif (TASK === 2) { /* Strona startowa szablonu pozostaje pusta. */ }
elseif (TASK === 3) { echo '<ul class="task-links"><li><a href="goscie.php">Lista gości portalu</a></li><li><a href="index8.php">Geolokalizacja IP</a></li></ul>'; }
elseif (TASK === 4) { echo '<ul class="task-links"><li><a href="index1.php">Monitorowanie usług</a></li><li><a href="dodaj1.php">Dodaj host i port</a></li><li><a href="test.php">Test połączenia</a></li><li><a href="goscie.php">Goście portalu</a></li>' . (admin() ? '<li><a href="staff.php">Pracownicy</a></li>' : '') . '</ul>'; }
page_end();
