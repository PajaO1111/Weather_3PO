<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Repositories\AggregateCache;
use OuBrix\Weather\Weather\Repositories\ObservationRepository;
use OuBrix\Weather\Weather\Services\HistoryService;
use OuBrix\Weather\Weather\Services\StatsService;

return static function (): int {
    $tz = new DateTimeZone('Europe/Prague');
    $utc = new DateTimeZone('UTC');
    $pdo = Fixtures::database();

    // Měření po 3 h od 20. 8. do 14. 11. 2026 (místní čas), denní čítač srážek.
    $id = 1;
    $day = new DateTimeImmutable('2026-08-20 00:00:00', $tz);
    $end = new DateTimeImmutable('2026-11-15 00:00:00', $tz);
    while ($day < $end) {
        $precip = 0.0;
        for ($h = 0; $h < 24; $h += 3) {
            $local = $day->setTime($h, 10);
            $temp = round(10 + 12 * sin($id / 7) + ($h - 12) / 3, 1);
            $precip += ($id % 5 === 0) ? 0.4 : 0.0;
            $row = Fixtures::row($id, $local->setTimezone($utc)->format('Y-m-d H:i:s'), $temp);
            $row['precip_total'] = $precip;
            $row['wind_gust'] = (float) ($id % 37);
            Fixtures::insert($pdo, $row);
            $id++;
        }
        $day = $day->modify('+1 day');
    }
    // Shoda maxima nárazu ve dvou měsících: vyhrát musí dřívější výskyt.
    $tie = Fixtures::row($id++, '2026-09-10 10:00:00', 15.0);
    $tie['wind_gust'] = 80.0;
    Fixtures::insert($pdo, $tie);
    $tie = Fixtures::row($id++, '2026-10-10 10:00:00', 15.0);
    $tie['wind_gust'] = 80.0;
    Fixtures::insert($pdo, $tie);
    Fixtures::insert($pdo, Fixtures::row($id++, '2026-09-15 10:00:00', 60.0, 'OTHER'));

    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oubrix-cache-test-' . bin2hex(random_bytes(4));
    $now = new DateTimeImmutable('2026-11-14 12:00:00', $utc);
    $repo = new ObservationRepository($pdo);
    $plain = new HistoryService($repo, $tz);
    $from = new DateTimeImmutable('2026-08-25', $tz);
    $to = new DateTimeImmutable('2026-11-12', $tz);
    $checks = 0;

    try {
        $cache = new AggregateCache($repo, $tz, $dir, $now);
        $cached = new HistoryService($repo, $tz, $cache);
        foreach (['day', 'hour'] as $resolution) {
            $expected = $plain->build('TEST', $from, $to, $resolution, 100000);
            check($cached->build('TEST', $from, $to, $resolution, 100000) == $expected, "cached $resolution equals live");
            $checks++;
        }
        $stats = $cache->stats();
        check($stats['built'] === 2 && $stats['live'] > 0, 'September and October built, rest live');
        check(is_file($dir . '/TEST/2026-09.json') && is_file($dir . '/TEST/2026-10.json') && !is_file($dir . '/TEST/2026-08.json') && !is_file($dir . '/TEST/2026-11.json'), 'only closed full months stored');
        $checks += 2;

        // Druhá instance čte ze souborů, nic nestaví.
        $second = new AggregateCache($repo, $tz, $dir, $now);
        $expected = $plain->build('TEST', $from, $to, 'day', 100000);
        check((new HistoryService($repo, $tz, $second))->build('TEST', $from, $to, 'day', 100000) == $expected, 'from files equals live');
        check($second->stats()['built'] === 0 && $second->stats()['hits'] > 0, 'second run hits files');
        $checks += 2;

        $gust = $second->extreme('TEST', 'wind_gust', true, '2026-08-31 22:00:00', '2026-10-31 23:00:00');
        check($gust !== null && (float) $gust['value'] === 80.0 && $gust['time'] === $repo->extreme('TEST', 'wind_gust', true, '2026-08-31 22:00:00', '2026-10-31 23:00:00')['time'], 'tie keeps earlier occurrence');
        $checks++;

        // Poškozený soubor a starý formát se přepočítají.
        file_put_contents($dir . '/TEST/2026-09.json', '{broken');
        $json = json_decode((string) file_get_contents($dir . '/TEST/2026-10.json'), true);
        $json['format'] = 'old';
        file_put_contents($dir . '/TEST/2026-10.json', json_encode($json));
        $third = new AggregateCache($repo, $tz, $dir, $now);
        check((new HistoryService($repo, $tz, $third))->build('TEST', $from, $to, 'day', 100000) == $expected, 'rebuilt after corruption');
        check($third->stats()['built'] === 2, 'corrupt and outdated files rebuilt');
        $checks += 2;

        // Měsíc, který neskončil aspoň před 24 h, zůstává živý.
        $early = new AggregateCache($repo, $tz, $dir . '-early', new DateTimeImmutable('2026-11-01 12:00:00', $utc));
        $early->hourly('TEST', '2026-09-30 22:00:00', '2026-10-31 23:00:00');
        check($early->stats()['built'] === 0 && !is_dir($dir . '-early/TEST'), 'unsettled month stays live');
        $checks++;

        // Vypnutá cache = průchod do repozitáře.
        $off = new AggregateCache($repo, $tz, null, $now);
        check(!$off->enabled() && $off->summary('TEST', '2026-09-01 00:00:00', '2026-10-01 00:00:00') == $repo->summary('TEST', '2026-09-01 00:00:00', '2026-10-01 00:00:00'), 'disabled pass-through');
        check(AggregateCache::directoryFromSettings(['aggregate_cache' => false]) === null, 'settings disable');
        $checks += 2;

        // Statistiky s cache dávají totéž co bez ní.
        $statsNow = new DateTimeImmutable('2026-11-14 13:00:00', $tz);
        $plainStats = (new StatsService($repo, $tz))->build('TEST', 'all', $statsNow);
        $cachedStats = (new StatsService($repo, $tz, new AggregateCache($repo, $tz, $dir, $now)))->build('TEST', 'all', $statsNow);
        check($cachedStats == $plainStats, 'stats equal with cache');
        $checks++;

        $merged = AggregateCache::mergeSummaries([
            ['samples' => 2, 'first_time' => '2026-09-01 00:00:00', 'last_time' => '2026-09-02 00:00:00', 'temp_n' => 2, 'temp_avg' => 10.0, 'temp_min' => 5.0, 'wind_max' => 3.0],
            ['samples' => 6, 'first_time' => '2026-10-01 00:00:00', 'last_time' => '2026-10-02 00:00:00', 'temp_n' => 6, 'temp_avg' => 2.0, 'temp_min' => -1.0, 'wind_max' => 9.0],
        ]);
        check((float) $merged['samples'] === 8.0 && abs((float) $merged['temp_avg'] - 4.0) < 1e-9, 'weighted average');
        check($merged['first_time'] === '2026-09-01 00:00:00' && $merged['last_time'] === '2026-10-02 00:00:00' && (float) $merged['temp_min'] === -1.0 && (float) $merged['wind_max'] === 9.0, 'merged first/last/min/max');
        $checks += 2;
    } finally {
        foreach ([$dir, $dir . '-early'] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }

    return $checks;
};
