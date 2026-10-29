<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Services\PhenologyService;

require_once dirname(__DIR__) . '/support/PhenologyFixtures.php';

return static function (): int {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    PhenologyFixtures::seed($pdo);
    $service = new PhenologyService($pdo);
    $result = $service->build([]);
    check($result['selection']['station']['code'] === 'U1DOKS31', 'Doksany is default station');
    check($result['selection']['phase']['code'] === 'PK50' && $result['selection']['year'] === 2017,
        'default phase and latest observed-or-not-occurred year');
    check($result['series']['medianCalendarDay'] === 100.0 && $result['series']['observedYears'] === 3,
        'median excludes not-occurred observation and normalizes leap years');
    $calendarPhase = array_values(array_filter($result['calendar'], static function (array $entry): bool {
        return $entry['phaseCode'] === 'PK50';
    }));
    check($calendarPhase[0]['status'] === 'observed', 'selected year phase calendar');
    check($result['comparison']['commonYears'] === [2014, 2016],
        'station comparison uses common years and excludes 444');
    check(count($result['comparison']['items']) === 3
        && $result['comparison']['items'][2]['elevationM'] !== null,
        'comparison includes station elevation');
    check($result['source']['label'] === 'ČHMÚ – fenologická pozorování', 'source attribution');

    $calendar = $service->build(['year' => '2015']);
    $notOccurred = array_values(array_filter($calendar['calendar'], static function (array $entry): bool {
        return $entry['phaseCode'] === 'PK50';
    }));
    check($notOccurred[0]['status'] === 'not_occurred'
        && $notOccurred[0]['date'] === null, 'calendar exposes special 444 status');
    $invalid = false;
    try {
        $service->build(['station' => 'UNKNOWN']);
    } catch (InvalidArgumentException $exception) {
        $invalid = true;
    }
    check($invalid, 'reject a station outside imported data');
    $invalid = false;
    try {
        $service->build(['year' => '2010']);
    } catch (InvalidArgumentException $exception) {
        $invalid = true;
    }
    check($invalid, 'reject unavailable year');
    $invalid = false;
    try {
        $service->build(['compare' => 'U1DOKS31,U1BRAN31,U1MEDE31', 'station' => 'U1DOKS31']);
    } catch (InvalidArgumentException $exception) {
        $invalid = false;
    }
    check(!$invalid, 'accept selected stations with common observed years');
    $invalid = false;
    try {
        $service->build(['unexpected' => '1']);
    } catch (InvalidArgumentException $exception) {
        $invalid = true;
    }
    check($invalid, 'reject unknown query parameters');
    $singleStation = $service->build(['phase' => 'BT']);
    check($singleStation['comparison']['items'] === []
        && $singleStation['comparison']['warning'] !== null,
        'explain when no second station has common observations');
    return 13;
};
