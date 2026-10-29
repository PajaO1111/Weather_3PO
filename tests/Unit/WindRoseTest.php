<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Services\WindRoseService;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;

return static function (): int {
    $row = static function (string $time, $dir, $speed): array {
        return ['obs_time_utc' => $time, 'winddir' => $dir, 'wind_speed' => $speed];
    };
    $rose = WindRoseService::aggregate([
        $row('2026-10-23 10:00:00', '0', '3'),      // S, třída 1–5
        $row('2026-10-23 10:00:00', '0', '3'),      // duplicita – nepočítá se
        $row('2026-10-23 10:05:00', '350', '8'),    // S (348,75–11,25), třída 6–11
        $row('2026-10-23 10:10:00', '270', '45'),   // Z, třída ≥ 39
        $row('2026-10-23 10:15:00', '90', '0.5'),   // bezvětří
        $row('2026-10-23 10:20:00', null, '10'),    // bez směru – vynechat
        $row('2026-10-23 10:25:00', '180', null),   // bez rychlosti – vynechat
        $row('2026-10-23 10:30:00', '-1', '10'),    // neplatný směr – vynechat
        $row('2026-10-23 10:35:00', '361', '10'),   // neplatný směr – vynechat
    ]);
    check($rose['samples'] === 4 && $rose['calm'] === 25.0, 'samples + calm');
    check(count($rose['sectors']) === 16 && $rose['sectors'][0] === 'S' && $rose['sectors'][12] === 'Z', 'sector labels');
    check($rose['classes'][0]['percent'][0] === 25.0 && $rose['classes'][1]['percent'][0] === 25.0, 'north classes');
    check($rose['classes'][5]['percent'][12] === 25.0 && $rose['classes'][5]['label'] === '≥ 39 km/h' && $rose['classes'][0]['label'] === '1 až méně než 6 km/h', 'strong west and precise speed range');
    check($rose['prevailing'] === 'S' && $rose['speedMax'] === 45.0 && $rose['speedAvg'] === 14.1, 'summary');
    $empty = WindRoseService::aggregate([]);
    check($empty['samples'] === 0 && $empty['prevailing'] === null && $empty['speedAvg'] === null, 'empty');

    $pdo = Fixtures::database();
    $times = [
        ['2026-10-25 09:00:00', 3.0],
        ['2026-10-25 09:30:00', 7.0],
        ['2026-10-25 10:00:00', 100.0], // horní hranice intervalu je vyloučená
    ];
    foreach ($times as $id => [$time, $speed]) {
        $measurement = Fixtures::row($id + 1, $time, 10.0);
        $measurement['winddir'] = 0.0;
        $measurement['wind_speed'] = $speed;
        Fixtures::insert($pdo, $measurement);
    }
    $service = new WindRoseService(new ObservationRepository($pdo));
    $now = new DateTimeImmutable('2026-10-25 10:00:00', new DateTimeZone('UTC'));
    $exactLimit = $service->build('TEST', 1, $now, 2);
    check($exactLimit['samples'] === 2 && $exactLimit['truncated'] === false, 'exact row limit is not truncated and future boundary is excluded');
    check($exactLimit['from'] === '2026-10-25T09:00:00Z' && $exactLimit['to'] === '2026-10-25T10:00:00Z', 'build uses exact UTC interval');

    $measurement = Fixtures::row(4, '2026-10-25 09:45:00', 10.0);
    $measurement['winddir'] = 0.0;
    $measurement['wind_speed'] = 9.0;
    Fixtures::insert($pdo, $measurement);
    $truncated = $service->build('TEST', 1, $now, 2);
    check($truncated['samples'] === 2 && $truncated['truncated'] === true && $truncated['speedAvg'] === 8.0, 'over-limit result keeps latest rows and reports truncation');

    // Pevný interval v místním čase (Europe/Prague, říjen = UTC+1 po změně času 25. 10.).
    $prague = new DateTimeZone('Europe/Prague');
    $range = $service->buildRange('TEST', new DateTimeImmutable('2026-10-25 10:15:00', $prague), new DateTimeImmutable('2026-10-25 10:50:00', $prague), 100);
    check($range['samples'] === 2 && $range['from'] === '2026-10-25T09:15:00Z' && $range['to'] === '2026-10-25T09:50:00Z' && $range['hours'] === 1, 'buildRange converts local bounds to UTC');
    return 10;
};
