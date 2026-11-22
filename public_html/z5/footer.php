<?php declare(strict_types=1);
// Stopka wstawiana przez page_end; samodzielne otwarcie także sprawdza sesję.
if (!defined('TASK')) { define('TASK',5); require dirname(__DIR__) . '/_app/bootstrap.php'; guard(); }
?>
<footer class="page-footer"><div class="container">Zadanie 5 · PHP / Bootstrap · <a href="../">Lista zadań</a><?php if (TASK === 4 && user()) { echo '<span id="sound-status" class="ms-3"></span>'; } ?></div></footer>
