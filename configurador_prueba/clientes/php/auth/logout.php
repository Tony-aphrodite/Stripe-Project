<?php
require_once __DIR__ . '/../bootstrap.php';
portalLog('logout', ['success' => 1]);
$_SESSION = [];
session_destroy();
portalJsonOut(['status' => 'ok']);
