<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use OuBrix\Weather\Http\Api;
use OuBrix\Weather\Weather\Services\PhenologyService;

Api::run(static function (PDO $pdo, string $station) {
    return (new PhenologyService($pdo))->build($_GET);
}, 3600);
