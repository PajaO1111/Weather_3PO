<?php
declare(strict_types=1);

use OuBrix\Weather\Infrastructure\Database;

return static function (): int {
    $root = DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'domains' . DIRECTORY_SEPARATOR . 'oubrix.eu' . DIRECTORY_SEPARATOR . 'Weather';
    check(Database::configPath($root) === DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'domains' . DIRECTORY_SEPARATOR . 'config.php', 'default config two levels up');
    check(Database::configPath($root, '/custom/config.php') === '/custom/config.php', 'override');
    check(Database::configPath($root, '') === dirname($root, 2) . DIRECTORY_SEPARATOR . 'config.php', 'empty override ignored');

    try {
        Database::loadConfig($root, __DIR__ . '/missing-config.php');
        check(false, 'missing config must fail');
    } catch (RuntimeException $exception) {
        check($exception->getMessage() === 'CONFIG_NOT_FOUND', 'missing config code');
        check(Database::failureDetails($exception, 'configuration') === ['stage' => 'configuration', 'reason' => 'CONFIG_NOT_FOUND'], 'config diagnostics');
    }

    // Text chyby ovladače (může obsahovat host/uživatele) se do diagnostiky nesmí dostat.
    $pdoError = new PDOException('SQLSTATE[HY000] [1045] Access denied for user secret@host');
    $pdoError->errorInfo = ['HY000', 1045, 'Access denied for user secret@host'];
    $details = Database::failureDetails($pdoError, 'connection');
    check($details === ['stage' => 'connection', 'sqlState' => 'HY000', 'driverCode' => 1045], 'pdo diagnostics');
    check(strpos(json_encode($details), 'secret') === false, 'no secrets in diagnostics');
    check(Database::failureDetails(new RuntimeException('other'), 'query') === ['stage' => 'query'], 'unknown error');
    return 8;
};
