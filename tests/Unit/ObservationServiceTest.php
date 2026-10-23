<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\ObservationService;

return static function (): int {
    $pdo = Fixtures::database();
    Fixtures::insert($pdo, Fixtures::row(1, '2026-10-22 10:00:00', 12.0));
    Fixtures::insert($pdo, Fixtures::row(2, '2026-10-22 10:05:00', null));
    Fixtures::insert($pdo, Fixtures::row(3, '2026-10-22 10:05:00', null));   // přesná duplicita
    Fixtures::insert($pdo, Fixtures::row(4, '2026-10-22 10:10:00', 13.0));
    Fixtures::insert($pdo, Fixtures::row(5, '2026-10-22 10:10:00', 14.0));   // konflikt
    Fixtures::insert($pdo, Fixtures::row(6, '2026-10-22 10:15:00', 99.0, 'OTHER'));

    $service = new ObservationService(new ObservationRepository($pdo));
    $result = $service->series('TEST', 10);
    $items = $result['items'];
    check(count($items) === 3, 'deduplicated points');
    check($items[0]['time'] === '2026-10-22T10:00:00Z' && $items[0]['temp_c'] === 12.0, 'ascending ISO UTC');
    check($items[1]['temp_c'] === null, 'null preserved');
    check(($items[2]['conflict'] ?? false) === true && $items[2]['temp_c'] === null, 'conflict nulled');
    check($result['meta']['duplicates'] === 2 && $result['meta']['conflicts'] === 1, 'meta counts');
    check($result['units']['wind_speed'] === 'km/h', 'wind unit');
    check(count($service->series('TEST', 2)['items']) === 1, 'limit applies to latest raw rows');

    // Časové okno: posledních N hodin podle času, ne podle počtu řádků.
    $window = $service->window('TEST', 1, new DateTimeImmutable('2026-10-22 11:05:00', new DateTimeZone('UTC')), 100);
    check(count($window['items']) === 2 && $window['items'][0]['time'] === '2026-10-22T10:05:00Z', 'window by time');
    check($window['meta']['hours'] === 1 && $window['meta']['from'] === '2026-10-22T10:05:00Z' && $window['meta']['truncated'] === false, 'window meta');
    check($result['meta']['medianIntervalMinutes'] === 5.0, 'median interval');
    check(ObservationService::medianIntervalMinutes([['time' => '2026-10-22T10:00:00Z'], ['time' => '2026-10-22T10:05:00Z'], ['time' => '2026-10-22T11:05:00Z']]) === 32.5, 'median irregular');
    check($service->window('TEST', 24, new DateTimeImmutable('2026-10-22 11:05:00', new DateTimeZone('UTC')), 1)['meta']['truncated'] === true, 'row cap');
    return 12;
};
