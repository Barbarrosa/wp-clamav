<?php
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . '/../../../wp-integration-site' . $path)) {
    return false;
}
require '/workspace/wp-integration-site/index.php';
