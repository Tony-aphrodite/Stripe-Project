<?php
require_once __DIR__ . '/../bootstrap.php';
$_SESSION = [];
session_destroy();
puntoJsonOut(['ok' => true]);
