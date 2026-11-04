<?php
// Ajusta estos datos a tu instalación local de MySQL.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'cashly');
define('DB_USER', 'root');
define('DB_PASS', '');
define('APP_NAME', 'Cashly');
// Detecta automáticamente la carpeta del proyecto (cashly o cashly1).
// Así los enlaces y redirecciones no saltan al proyecto original por error.
$scriptDir = str_replace('\\\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/cashly/public/index.php'));
define('BASE_URL', rtrim($scriptDir, '/'));
date_default_timezone_set('America/Bogota');
