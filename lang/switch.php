<?php
require_once dirname(__DIR__).'/config.php';

$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, ['en', 'id'])) {
    $lang = 'en';
}

setLang($lang);

$redirect = $_GET['return'] ?? '/';
header('Location: '.$redirect);
exit;
