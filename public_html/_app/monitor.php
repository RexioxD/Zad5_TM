<?php declare(strict_types=1);
function service_rows(): array {
    return (admin() ? db()->query('SELECT d.*,u.username FROM domeny d JOIN users u ON u.id=d.user_id ORDER BY d.id DESC') : query('SELECT d.*,u.username FROM domeny d JOIN users u ON u.id=d.user_id WHERE d.user_id=? ORDER BY d.id DESC','i',[user()['id']])->get_result())->fetch_all(MYSQLI_ASSOC);
}
function valid_service(string $host, int $port): bool {
    return $port >= 1 && $port <= 65535 && strlen($host) <= 253 && (filter_var($host,FILTER_VALIDATE_IP) || filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME));
}
function check_services(): void {
    // Blokada wiersza i odstęp 10 s zapobiegają wielokrotnemu naliczaniu z kilku otwartych kart.
    foreach (service_rows() as $r) {
        if ($r['checked_at'] && time()-strtotime($r['checked_at']) < 10) { continue; }
        db()->begin_transaction();
        try {
            $current=query('SELECT * FROM domeny WHERE id=? FOR UPDATE','i',[(int)$r['id']])->get_result()->fetch_assoc();
            if (!$current || ($current['checked_at'] && time()-strtotime($current['checked_at'])<10)) { db()->commit(); continue; }
            $start=microtime(true); $errno=0; $errstr='';
            $socket=@fsockopen($current['host'],(int)$current['port'],$errno,$errstr,1.2);
            $ms=(int)round((microtime(true)-$start)*1000); $online=$socket?1:0;
            if($socket){fclose($socket);}
            $errors=$online?0:(int)$current['errors']+1;
            $failure=$online?null:($current['failure'] ?: date('Y-m-d H:i:s'));
            $total=(int)$current['total_errors']+($online?0:1);
            $message=$online?'Połączenie TCP OK':substr($errstr . ' (' . $errno . ')',0,255);
            query('UPDATE domeny SET online=?,errors=?,failure=?,total_errors=?,message=?,response_ms=?,checked_at=? WHERE id=?','iisisisi',[$online,$errors,$failure,$total,$message,$ms,date('Y-m-d H:i:s'),(int)$current['id']]);
            db()->commit();
        } catch(Throwable $e){db()->rollback();throw $e;}
    }
}
$error=''; $host=trim((string)($_POST['host'] ?? '')); $port=(int)($_POST['port'] ?? 80);
if (ACTION==='staff' && !admin()) { http_response_code(403); page_start('Brak dostępu'); notice('Ta strona jest dostępna dla administratora.'); page_end(); return; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    if (ACTION==='staff' && ($_POST['operation'] ?? '')==='worker') {
        $id=(int)($_POST['worker_id'] ?? 0); $worker=!empty($_POST['worker'])?1:0;
        query("UPDATE users SET worker=? WHERE id=? AND username<>'admin'",'ii',[$worker,$id]); flash('Zmieniono status pracownika.'); go('staff.php');
    }
    if (in_array(ACTION,['add','staff'],true)) {
        if (!valid_service($host,$port)) { $error='Podaj nazwę hosta lub adres IP oraz port od 1 do 65535. Bez http:// i ścieżki.'; }
        else {
            $owner=user()['id'];
            if (ACTION==='staff') {
                $owner=(int)($_POST['owner'] ?? 0);
                if(!query('SELECT id FROM users WHERE id=? AND worker=1','i',[$owner])->get_result()->fetch_assoc()){$error='Wybierz pracownika z listy.';}
            }
            if(!$error){
                try{query('INSERT INTO domeny(host,port,user_id) VALUES(?,?,?)','sii',[$host,$port,$owner]);flash('Dodano usługę do monitorowania.');go('index1.php');}
                catch(mysqli_sql_exception $e){if($e->getCode()===1062){$error='Ten host i port są już na liście tego użytkownika.';}else{throw $e;}}
            }
        }
    }
    if(ACTION==='delete'){
        $id=(int)($_POST['id'] ?? 0);
        $s=query('DELETE FROM domeny WHERE id=? AND user_id=?','ii',[$id,user()['id']]);
        flash($s->affected_rows?'Usunięto usługę.':'Nie znaleziono takiej usługi na Twojej liście.');go('index1.php');
    }
}
if(ACTION==='check'){check_services();go('index1.php');}
if(ACTION==='test'){
    page_start('Test połączenia TCP');$testHost='fb.com';$testPort=80;$start=microtime(true);$socket=@fsockopen($testHost,$testPort,$errno,$errstr,2);
    notice($testHost . ':' . $testPort . ' - ' . ($socket?'OK':'Awaria: ' . $errstr . ' (' . $errno . ')'),$socket?'success':'danger');
    if($socket){fclose($socket);}echo '<p>Czas próby: ' . (int)round((microtime(true)-$start)*1000) . ' ms.</p>';page_end();return;
}
if(ACTION==='add'||ACTION==='staff'){
    page_start(ACTION==='staff'?'Pracownicy i przypisanie usług':'Dodaj usługę');if($error){notice($error,'danger');}
    $workers=ACTION==='staff'?db()->query('SELECT id,username FROM users WHERE worker=1 ORDER BY username')->fetch_all(MYSQLI_ASSOC):[];
    echo '<form class="panel" method="post" action="' . (ACTION==='staff'?'staff.php':'dodaj2.php') . '">' . csrf() . '<div class="form-grid"><div><label class="form-label" for="host">Host lub adres IP</label><input id="host" name="host" class="form-control" placeholder="np. pbs.edu.pl" value="' . h($host) . '" required></div><div><label class="form-label" for="port">Port TCP</label><input type="number" id="port" name="port" class="form-control" min="1" max="65535" value="' . $port . '" required></div></div>';
    if(ACTION==='staff'){
        echo '<label class="form-label mt-3" for="owner">Odpowiedzialny pracownik</label><select id="owner" name="owner" class="form-select mb-3" required><option value="">Wybierz pracownika</option>';
        foreach($workers as $w){echo '<option value="' . (int)$w['id'] . '">' . h($w['username']) . '</option>';}echo '</select>';
    }
    echo '<button class="btn btn-primary mt-3">Dodaj do monitorowania</button></form>';
    if(ACTION==='staff'){
        echo '<h2>Lista użytkowników</h2><div class="table-wrap"><table class="table"><thead><tr><th>Login</th><th>Pracownik</th></tr></thead><tbody>';
        foreach(db()->query("SELECT id,username,worker FROM users WHERE username<>'admin' ORDER BY username") as $u){
            echo '<tr><td>' . h($u['username']) . '</td><td><form method="post">' . csrf() . '<input type="hidden" name="operation" value="worker"><input type="hidden" name="worker_id" value="' . (int)$u['id'] . '"><label><input type="checkbox" name="worker" value="1" ' . ($u['worker']?'checked':'') . '> Aktywny pracownik</label> <button class="btn btn-sm btn-outline-primary">Zapisz</button></form></td></tr>';
        }echo '</tbody></table></div>';
    }page_end();return;
}
if(ACTION==='delete'){go('index1.php');}
check_services();$rows=service_rows();$alarm=false;foreach($rows as $r){if($r['failure']&&time()-strtotime($r['failure'])>40){$alarm=true;}}
page_start(admin()?'Monitorowanie - wszystkie usługi':'Monitorowanie - moje usługi');
echo '<div data-monitor data-alarm="' . ($alarm?'1':'0') . '"><p><a class="btn btn-primary" href="dodaj1.php">Dodaj usługę</a> <a class="btn btn-outline-primary" href="index1.php">Odśwież teraz</a></p><div class="panel"><label><input type="checkbox" id="auto-refresh" checked> Odświeżaj co 10 sekund</label> · <span id="refresh-counter">10 s</span><hr><label class="me-3"><input type="checkbox" id="sound-enabled" checked> Alarm dźwiękowy</label><label for="sound-tone">Dźwięk </label> <select id="sound-tone"><option value="1">Sygnał 1</option><option value="2">Sygnał 2</option><option value="3">Sygnał 3</option></select> <button type="button" id="test-sound" class="btn btn-sm btn-outline-primary">Testuj dźwięk</button><p id="sound-help" class="help mb-0 mt-2"></p><audio id="alarm-audio" preload="none"></audio></div>';
echo '<label class="form-label" for="table-search">Szukaj usługi</label><input id="table-search" class="form-control" type="search" placeholder="Host, port lub stan"><div class="table-wrap"><table class="table table-striped align-middle" data-table><thead><tr><th>ID</th><th>Host</th><th>Port</th><th>Właściciel</th><th>Stan</th><th>Kolejne błędy</th><th>Awaria od</th><th>Wszystkie błędy</th><th>Łączny czas awarii</th><th>Czas próby</th><th>Ostatni test</th><th>Usuń</th></tr></thead><tbody>';
foreach($rows as $r){
    $ok=(bool)$r['online'];$urgent=!$ok&&$r['failure']&&time()-strtotime($r['failure'])>20;
    $svg='<svg class="status-svg ' . ($ok?'':'offline ') . ($urgent?'urgent':'') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-label="' . ($ok?'Usługa działa':'Awaria') . '"><rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M6 6.5h2m-2 11h2"/>' . ($ok?'':'<path d="m14 5 5 5m0-5-5 5"/>') . '</svg>';
    echo '<tr><td>' . (int)$r['id'] . '</td><td>' . h($r['host']) . '</td><td>' . (int)$r['port'] . '</td><td>' . h($r['username']) . '</td><td>' . $svg . ' <span class="' . ($ok?'badge-ok':'badge-fail') . '">' . ($ok?'OK':'Awaria') . '</span><div class="help">' . h($r['message']) . '</div></td><td>' . (int)$r['errors'] . '</td><td>' . h($r['failure'] ?? '-') . '</td><td>' . (int)$r['total_errors'] . '</td><td data-sort="' . (int)$r['total_errors']*10 . '">' . duration((int)$r['total_errors']*10) . '</td><td>' . (int)$r['response_ms'] . ' ms</td><td>' . h($r['checked_at'] ?? '-') . '</td><td>' . ((int)$r['user_id']===user()['id']?'<form method="post" action="kasuj2.php">' . csrf() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn btn-sm btn-outline-danger" aria-label="Usuń ' . h($r['host']) . ':' . (int)$r['port'] . '">Usuń</button></form>':'') . '</td></tr>';
}
echo '</tbody></table></div>';
if(!$rows){notice('Lista jest pusta. Dodaj pierwszy host i port.');}
echo '</div>';page_end();
