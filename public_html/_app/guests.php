<?php declare(strict_types=1);
page_start('Goście portalu');
$grouped = ($_GET['view'] ?? '') === 'ip';
echo '<p><a href="goscie.php">Wszystkie wizyty</a> · <a href="goscie.php?view=ip">Unikalne IP i liczba wejść</a></p>' .
    '<div class="guest-tools"><div><label class="form-label" for="table-search">Szukaj w tabeli</label>' .
    '<input id="table-search" class="form-control" type="search" placeholder="IP, data, miasto lub przeglądarka"></div>' .
    '<div><label class="form-label" for="table-sort">Sortuj według</label><select id="table-sort" class="form-select"></select></div>' .
    '<button type="button" id="table-sort-direction" class="btn btn-outline-primary">Rosnąco</button></div>';
$visits = visit_table();
if ($grouped) { $rows = db()->query("SELECT ipaddress,COUNT(*) AS visits,MAX(datetime) AS datetime FROM `$visits` GROUP BY ipaddress ORDER BY visits DESC")->fetch_all(MYSQLI_ASSOC); }
else { $rows = db()->query("SELECT * FROM `$visits` ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC); }
echo '<div class="table-wrap visits-cards"><table class="table table-striped align-middle" data-table><thead><tr><th>IP</th>' . ($grouped ? '<th>Liczba wejść</th><th>Ostatnie wejście</th>' : '<th>Data i godzina</th>') . '<th>Miasto</th><th>Region</th><th>Kraj</th><th>Współrzędne</th><th>Mapa</th>';
if (!$grouped) { echo '<th>Przeglądarka</th><th>Ekran</th><th>Okno</th><th>Kolory</th><th>Cookies</th><th>Java</th><th>Język</th>'; if (TASK >= 5) { echo '<th>Logowanie</th>'; } }
echo '</tr></thead><tbody>';
$details = []; $lookups = 0;
foreach ($rows as $r) {
    $ip = $r['ipaddress'];
    // Ograniczenie czasu otwierania tabeli przy wielu różnych IP; kolejne odświeżenia uzupełniają cache.
    if (!isset($details[$ip])) {
        $cached = query('SELECT data,checked_at FROM geo_cache WHERE ipaddress=? AND checked_at > DATE_SUB(NOW(),INTERVAL 24 HOUR)', 's', [$ip])->get_result()->fetch_assoc();
        $value = $cached ? (json_decode($cached['data'], true) ?: []) : [];
        $retry = !$cached || (isset($value['error']) && time() - strtotime($cached['checked_at']) >= 300);
        $details[$ip] = $retry && ++$lookups <= 2 ? geo($ip) : $value;
    }
    $g = $details[$ip];
    echo '<tr' . ($grouped ? '' : ' data-visit-id="' . (int)$r['id'] . '"') . '><td>' . h($ip) . '</td>' . ($grouped ? '<td>' . (int)$r['visits'] . '</td>' : '') . '<td>' . h($r['datetime']) . '</td>';
    foreach (['city','region','country','loc'] as $key) { echo '<td>' . h($g[$key] ?? '-') . '</td>'; }
    echo '<td>' . (!empty($g['loc']) ? '<a target="_blank" rel="noopener noreferrer" href="https://www.google.com/maps/search/?api=1&amp;query=' . rawurlencode($g['loc']) . '">Mapa</a>' : '<span title="' . h($g['error'] ?? 'Dane jeszcze niepobrane') . '">Brak danych</span>') . '</td>';
    if (!$grouped) {
        echo '<td title="' . h($r['browser']) . '">' . h(browser_name($r['browser'] ?? '')) . '</td><td>' . h($r['screen_size'] ?? '-') . '</td><td>' . h($r['window_size'] ?? '-') . '</td><td>' . ($r['colors'] !== null ? (int)$r['colors'] . ' bit' : '-') . '</td>';
        foreach (['cookies','java'] as $key) { echo '<td>' . ($r[$key] === null ? '-' : ($r[$key] ? 'Tak' : 'Nie')) . '</td>'; }
        echo '<td>' . h($r['language'] ?? '-') . '</td>';
        if (TASK >= 5) { echo '<td>' . ($r['login_success'] === null ? '-' : h($r['login_username']) . ': ' . ($r['login_success'] ? 'udane' : 'błędne')) . '</td>'; }
    }
    echo '</tr>';
}
echo '</tbody></table></div>';
if (!$rows) { notice('Nie zarejestrowano jeszcze wizyt.'); }
page_end();
