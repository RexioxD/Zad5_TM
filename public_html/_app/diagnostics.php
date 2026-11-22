<?php declare(strict_types=1);
$titles = [1=>'Netstat i porty hostingu',2=>'PHP Info',3=>'Whoami',4=>'Obciążenie serwera',5=>'Pliki i uprawnienia',6=>'Rekordy DNS',7=>'Adresy IP i nazwy hostów',8=>'Geolokalizacja IP'];
page_start($titles[NUMBER]);
function command_output(string $command): void {
    if (!function_exists('shell_exec')) { notice('Funkcja shell_exec jest wyłączona na tym hostingu.'); return; }
    $output = @shell_exec($command . ' 2>&1');
    if (!$output) { notice('Polecenie nie zwróciło danych lub jest zablokowane przez hosting.'); }
    else { echo '<pre>' . h($output) . '</pre>'; }
}
switch (NUMBER) {
case 1:
    command_output('netstat -tuln');
    echo '<h2>Próby połączenia z wybranymi portami własnego hostingu</h2><div class="table-wrap"><table class="table"><thead><tr><th>Usługa</th><th>Port</th><th>Wynik TCP</th></tr></thead><tbody>';
    foreach ([21=>'FTP',25=>'SMTP',80=>'HTTP',443=>'HTTPS'] as $port=>$name) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        echo '<tr><td>' . $name . '</td><td>' . $port . '</td><td>' . ($socket ? 'Połączono' : h($errstr . ' (' . $errno . ')')) . '</td></tr>'; if ($socket) { fclose($socket); }
    }
    echo '</tbody></table></div>'; break;
case 2:
    echo '<div class="table-wrap">';
    ob_start(); phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES); $info=ob_get_clean();
    if (preg_match('~<body[^>]*>(.*)</body>~is', $info, $match)) { $info=$match[1]; }
    echo $info; echo '</div>'; break;
case 3: command_output('whoami'); echo ''; break;
case 4: command_output('TERM=xterm top -b -n 1'); break;
case 5:
    command_output('ls -al ' . escapeshellarg(dirname(__DIR__) . '/z' . TASK)); break;
case 6:
    $result=function_exists('dns_get_record') ? @dns_get_record('pbs.edu.pl', DNS_A | DNS_MX | DNS_NS) : false;
    echo '<p>pbs.edu.pl</p><pre>' . h($result ? print_r($result,true) : 'Brak odpowiedzi DNS lub funkcja zablokowana.') . '</pre>'; break;
case 7:
    $ip=$_SERVER['REMOTE_ADDR'] ?? '';
    $result=['IP pbs.edu.pl'=>gethostbyname('pbs.edu.pl'),'IP gościa'=>$ip,'Nazwa hosta 8.8.8.8'=>@gethostbyaddr('8.8.8.8'),'Nazwa hosta gościa'=>@gethostbyaddr($ip)];
    echo '<dl>'; foreach($result as $k=>$v){echo '<dt>' . h($k) . '</dt><dd>' . h($v) . '</dd>';} echo '</dl>'; break;
case 8:
    $ip=$_SERVER['REMOTE_ADDR'] ?? ''; $g=geo($ip);
    echo '<p>IP połączenia: <strong>' . h($ip) . '</strong></p>';
    if(isset($g['error'])){notice($g['error']);} else {
        echo '<dl>';foreach(['city'=>'Miasto','region'=>'Region','country'=>'Kraj','loc'=>'Współrzędne','org'=>'Operator'] as $k=>$v){echo '<dt>' . $v . '</dt><dd>' . h($g[$k] ?? '-') . '</dd>';}echo '</dl>';
        if(!empty($g['loc'])){echo '<a class="btn btn-primary" rel="noopener noreferrer" target="_blank" href="https://www.google.com/maps/search/?api=1&amp;query=' . rawurlencode($g['loc']) . '">Pokaż na mapie</a>';}
    }
    echo ''; break;
}
page_end();
