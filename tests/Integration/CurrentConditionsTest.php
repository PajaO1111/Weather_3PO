<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\CurrentConditionsService;

return static function (): int {
    $settings = weather_settings();
    $utc = new DateTimeZone('UTC');
    $pdo = Fixtures::database();
    $rows = [
        // [čas UTC, teplota, tlak]
        ['2026-07-14 21:55:00', 30.0, 1013.0], // 23:55 místního času předchozího dne – do „dnes“ nepatří
        ['2026-07-14 22:00:00', 15.0, 1013.0], // 00:00 místního času = začátek dne (letní čas UTC+2)
        ['2026-07-15 08:55:00', 22.0, 1012.0],
        ['2026-07-15 09:00:00', 25.0, 1011.0],
        ['2026-07-15 11:55:00', 24.0, 1009.5], // poslední měření
    ];
    foreach ($rows as $i => $data) {
        $row = Fixtures::row($i + 1, $data[0], $data[1]);
        $row['pressure'] = $data[2];
        $row['wind_gust'] = 10.0 + $i;
        $row['precip_total'] = 2.4;
        Fixtures::insert($pdo, $row);
    }
    $service = new CurrentConditionsService(new ObservationRepository($pdo), $settings);

    $result = $service->build('TEST', new DateTimeImmutable('2026-07-15 12:00:00', $utc));
    check($result !== null, 'result exists');
    check($result['observed']['utc'] === '2026-07-15T11:55:00Z', 'latest observation');
    check($result['observed']['local'] === '2026-07-15T13:55:00+02:00', 'local time with DST');
    check($result['observed']['ageSeconds'] === 300 && $result['observed']['stale'] === false, 'fresh data');
    check($result['current']['temp_c'] === 24.0 && $result['current']['wind_direction'] === 'Z', 'current values');
    check($result['station']['neighborhood'] === 'Most' && $result['station']['elevation'] === 331.0, 'station info');
    check($result['today']['date'] === '2026-07-15' && $result['today']['samples'] === 4, 'local day boundaries');
    check($result['today']['temp_min'] === ['value' => 15.0, 'time' => '2026-07-15T00:00:00+02:00'], 'today minimum with time');
    check($result['today']['temp_max'] === ['value' => 25.0, 'time' => '2026-07-15T11:00:00+02:00'], 'today maximum with time');
    check($result['today']['gust_max']['value'] === 14.0, 'gust maximum');
    check($result['today']['rainCounter'] === 2.4, 'rain counter from latest');
    check($result['pressureTrend']['change'] === -2.5 && $result['pressureTrend']['label'] === 'výrazně klesá', 'pressure trend');
    check($result['astro']['sun']['isDay'] === true && $result['astro']['sun']['sunrise'] !== null && $result['astro']['moon']['illumination'] >= 0, 'astro block');
    check(isset($result['condition']['code'], $result['condition']['label']) && $result['condition']['estimated'] === true, 'condition estimate');
    check(array_key_exists('beaufort', $result['current']) && array_key_exists('precip_intensity', $result['current']), 'beaufort + intensity');

    $stale = $service->build('TEST', new DateTimeImmutable('2026-07-15 13:30:00', $utc));
    check($stale['observed']['stale'] === true, 'stale after threshold (30 min)');
    check($result['today']['averageIntervalMinutes'] === round((13 * 60 + 55) / 3, 0), 'average interval today');

    // Druhý den: dnešní souhrn je prázdný a čítač srážek ze včerejška se nezobrazí.
    $nextDay = $service->build('TEST', new DateTimeImmutable('2026-07-16 06:00:00', $utc));
    check($nextDay['today']['samples'] === 0 && $nextDay['today']['rainCounter'] === null, 'new day without data');

    // Bez měření ~3 h zpět se tendence nepočítá.
    $short = Fixtures::database();
    Fixtures::insert($short, Fixtures::row(1, '2026-07-15 11:55:00'));
    $shortResult = (new CurrentConditionsService(new ObservationRepository($short), $settings))
        ->build('TEST', new DateTimeImmutable('2026-07-15 12:00:00', $utc));
    check($shortResult['pressureTrend'] === null, 'no trend without history');

    check($service->build('UNKNOWN', new DateTimeImmutable('now', $utc)) === null, 'unknown station');
    $decimalPdo = Fixtures::database();
    Fixtures::insert($decimalPdo, Fixtures::row(1, '2026-07-15 11:55:00', 7.9));
    $decimalResult = (new CurrentConditionsService(new ObservationRepository($decimalPdo), $settings))
        ->build('TEST', new DateTimeImmutable('2026-07-15 12:00:00', $utc));
    check($decimalResult['current']['temp_c'] === 7.9, 'preserve measured tenths from database to current API');
    check($decimalResult['today']['temp_min']['value'] === 7.9
        && $decimalResult['today']['temp_max']['value'] === 7.9, 'preserve tenths in daily extremes');
    return 22;
};
