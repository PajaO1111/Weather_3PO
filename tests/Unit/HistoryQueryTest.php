<?php
declare(strict_types=1);

use OuBrix\Weather\Http\Validation\HistoryQuery;

return static function (): int {
    $settings = [
        'timezone' => 'Europe/Prague', 'history_max_days' => 366, 'history_raw_max_days' => 7,
        'history_hour_max_days' => 186, 'history_auto_raw_days' => 3, 'history_auto_hour_days' => 62,
    ];
    $now = new DateTimeImmutable('2026-10-23 23:30:00', new DateTimeZone('UTC')); // místně už 24. 10.
    $fails = static function (array $query) use ($now, $settings): bool {
        try {
            HistoryQuery::parse($query, $now, $settings);
            return false;
        } catch (InvalidArgumentException $exception) {
            return true;
        }
    };

    $default = HistoryQuery::parse([], $now, $settings);
    check($default['from']->format('Y-m-d') === '2026-10-18' && $default['to']->format('Y-m-d') === '2026-10-24', 'default last 7 local days');
    check($default['days'] === 7 && $default['resolution'] === 'hour' && $default['requested'] === 'auto' && $default['format'] === 'json', 'default auto hour json');
    check($default['to']->getTimezone()->getName() === 'Europe/Prague' && $default['to']->format('H:i') === '00:00', 'local midnight');

    check(HistoryQuery::parse(['from' => '2026-10-22', 'to' => '2026-10-24'], $now, $settings)['resolution'] === 'raw', 'auto raw <= 3 days');
    check(HistoryQuery::parse(['from' => '2026-01-01', 'to' => '2026-10-24'], $now, $settings)['resolution'] === 'day', 'auto day for long range');
    check(HistoryQuery::parse(['from' => '2026-10-20', 'to' => '2026-10-24', 'resolution' => 'raw', 'format' => 'csv'], $now, $settings)['format'] === 'csv', 'explicit raw + csv');
    check(HistoryQuery::days(new DateTimeImmutable('2026-10-25', new DateTimeZone('Europe/Prague')), new DateTimeImmutable('2026-10-26', new DateTimeZone('Europe/Prague'))) === 2, 'days across DST');

    check($fails(['to' => '2026-10-25']), 'future to');
    check($fails(['from' => '2026-10-20', 'to' => '2026-10-19']), 'from after to');
    check($fails(['from' => '2025-01-01', 'to' => '2026-10-24']), 'range over max');
    check($fails(['from' => '2026-10-01', 'to' => '2026-10-24', 'resolution' => 'raw']), 'raw over limit');
    check($fails(['from' => '2026-02-30']) && $fails(['from' => '1999-12-31']) && $fails(['from' => ['x']]) && $fails(['to' => '24.10.2026']), 'invalid dates');
    check($fails(['resolution' => 'week']) && $fails(['format' => 'xml']), 'invalid enums');
    return 12;
};
