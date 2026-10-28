<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\ClimateService;

require_once dirname(__DIR__) . '/support/ClimateFixtures.php';

return static function (): int {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-climate-' . bin2hex(random_bytes(6));
    $sources = ClimateFixtures::sources();
    $requests = [];
    $paths = ['meta1.json' => 'climate_history_station', 'meta2.json' => 'climate_history_elements',
        'mly-0-20000-0-11433.json' => 'kopisty_monthly',
        '0-20000-0-11433_T_1991_2020_normal.csv' => 'kopisty_normal_temperature',
        '0-20000-0-11433_SRA_1991_2020_normal.csv' => 'kopisty_normal_precipitation'];
    $gateway = new ChmiGateway($directory, static function (string $url) use (&$sources, &$requests, $paths): string {
        $key = $paths[basename(parse_url($url, PHP_URL_PATH))];
        $requests[] = $key;
        return $sources[$key];
    }, new DateTimeImmutable('2026-10-27T12:00:00Z'));
    $service = new ClimateService($gateway);
    $checks = 0;
    $assert = static function (bool $ok, string $message) use (&$checks): void {
        check($ok, $message);
        $checks++;
    };
    try {
        $result = $service->build();
        $assert(count($requests) === 5 && count($result['items']) === 12, 'five allowlisted sources, twelve months');
        $assert($result['year'] === 2020 && count($result['years']) === 30, 'default latest published year');
        $assert(abs($result['reference']['months'][0]['temperature']['value'] - 2.45) < 1e-10,
            'monthly temperature reference averages thirty same-calendar months');
        $assert($result['reference']['months'][0]['precipitation']['value'] === 24.5,
            'monthly rainfall reference is not regional normal');
        $assert($result['reference']['rainfallAnnual'] === 954.0 && $result['reference']['completeYears'] === 30,
            'annual rainfall averages complete annual sums');
        $assert($result['officialNormal']['annual']['temperature'] === 9.4
            && $result['officialNormal']['annual']['precipitation'] === 493.5,
            'load official annual normals directly, not by summing rounded monthly values');
        $assert($result['officialNormal']['months'][0]['temperature'] === -0.3
            && $result['officialNormal']['months'][0]['precipitation'] === 29.8,
            'load the exact official monthly normals for Kopisty');
        $assert($result['items'][0]['officialNormal']['temperatureDifference'] !== null
            && $result['items'][0]['officialNormal']['precipitationPercent'] !== null
            && $result['officialComparison']['annualRainfallPercent'] !== null,
            'compare accepted closed-month/year observations with the official normal');
        $assert($result['items'][0]['temperature']['value'] === 3.9
            && abs($result['items'][0]['temperatureDifference'] - 1.45) < 1e-10, 'raw decimal and signed anomaly');
        $assert(count($requests) === 5 && $service->build(1991)['year'] === 1991
            && count($requests) === 5, 'cached requests, selectable historical year');
        $document = json_decode($sources['kopisty_monthly'], true);
        $rows = &$document['data']['data']['values'];
        $rows[0][6] = 0;
        $rows[1][6] = 0;
        $rows[2][6] = '-7,9';
        $rows[3][6] = '';
        $rows[4][7] = '*';
        $rows[5][8] = 'X';
        $rows[] = ['elsewhere', 'T', '1991', '01', 'AVG', 'AVG', 999, '', ''];
        $rows[] = ['0-20000-0-11433', 'T', '1991', '01', 'AVG', 'MAX', 999, '', ''];
        $rows[] = ['0-20000-0-11433', 'SRA', '1991', '01', '13:00', 'SUM', 999, '', ''];
        $sources['kopisty_monthly'] = json_encode($document, JSON_THROW_ON_ERROR);
        $gateway->invalidate('kopisty_monthly');
        $result = $service->build(1991);
        $assert($result['items'][0]['temperature']['value'] === 0.0
            && $result['items'][0]['precipitation']['value'] === 0.0, 'zero preserved, exact station and functions');
        $assert($result['items'][1]['temperature']['value'] === -7.9, 'negative decimals preserved');
        $assert($result['items'][1]['precipitation']['status'] === 'missing'
            && $result['reference']['months'][1]['precipitation']['value'] === null
            && $result['reference']['months'][1]['precipitation']['count'] === 29, 'missing is not zero or partial reference');
        $assert($result['items'][2]['temperature']['flagRepeat'] === '*'
            && $result['items'][2]['temperature']['rawValue'] !== null
            && $result['items'][2]['temperature']['value'] === null, 'repeat flag and raw value retained but excluded');
        $assert($result['items'][2]['precipitation']['flagInterrupted'] === 'X'
            && $result['reference']['rainfallAnnual'] === null, 'interrupted excludes complete annual reference');
        $assert(count($result['meta']['warnings']) > 0, 'incomplete reference warning');
        $rows[] = ['0-20000-0-11433', 'T', '1991', '01', 'AVG', 'AVG', 50, '', ''];
        $sources['kopisty_monthly'] = json_encode($document, JSON_THROW_ON_ERROR);
        $gateway->invalidate('kopisty_monthly');
        $failed = false;
        try { $service->build(1991); } catch (RuntimeException $exception) { $failed = true; }
        $assert($failed, 'conflicting duplicate rejected, never double counted');
        unset($rows);
        foreach (['wrong_stream', 'wrong_unit', 'bad_header', 'bad_number', 'invalid_json', 'wrong_schedule',
            'out_of_period_metadata', 'bad_date', 'short_row', 'negative_rainfall', 'invalid_month', 'invalid_flag'] as $case) {
            $sources = ClimateFixtures::sources();
            $key = 'kopisty_monthly';
            $document = json_decode($sources[$key], true);
            if ($case === 'wrong_stream') $document['datovyTokID'] = 'Open.Data.DLY';
            if ($case === 'bad_header') $document['data']['data']['header'] .= ',VALUE';
            if ($case === 'bad_number') $document['data']['data']['values'][0][6] = 'NaN';
            if ($case === 'bad_date') $document['datumVytvoreni'] = '2026-02-31T12:00:00Z';
            if ($case === 'short_row') array_pop($document['data']['data']['values'][0]);
            if ($case === 'negative_rainfall') $document['data']['data']['values'][1][6] = -1;
            if ($case === 'invalid_month') $document['data']['data']['values'][0][3] = '13';
            if ($case === 'invalid_flag') $document['data']['data']['values'][0][7] = null;
            if (in_array($case, ['wrong_unit', 'wrong_schedule', 'out_of_period_metadata'], true)) {
                $key = 'climate_history_elements';
                $document = json_decode($sources[$key], true);
                if ($case === 'wrong_unit') $document['data']['data']['values'][0][6] = 'K';
                if ($case === 'wrong_schedule') $document['data']['data']['values'][0][7] = '13:00';
                if ($case === 'out_of_period_metadata') $document['data']['data']['values'][0][3] = '1999-12-31T23:59:00Z';
            }
            $sources[$key] = $case === 'invalid_json' ? '{broken' : json_encode($document, JSON_THROW_ON_ERROR);
            foreach (array_keys($sources) as $resource) $gateway->invalidate($resource);
            $failed = false;
            try { $service->build(); } catch (RuntimeException $exception) { $failed = true; }
            $assert($failed, 'reject ' . $case);
        }
        $sources = ClimateFixtures::sources();
        foreach (array_keys($sources) as $resource) $gateway->invalidate($resource);
        $document = json_decode($sources['kopisty_monthly'], true);
        array_shift($document['data']['data']['values']);
        $sources['kopisty_monthly'] = json_encode($document, JSON_THROW_ON_ERROR);
        $result = $service->build(1991);
        $assert(count($result['items']) === 12 && $result['items'][0]['temperature']['status'] === 'missing'
            && $result['reference']['months'][0]['temperature']['count'] === 29
            && $result['items'][0]['temperatureDifference'] === null, 'absent row remains gap with incomplete reference');
        $sources = ClimateFixtures::sources();
        foreach (array_keys($sources) as $resource) $gateway->invalidate($resource);
        $failed = false;
        try { $service->build(1800); } catch (InvalidArgumentException $exception) { $failed = true; }
        $assert($failed, 'reject unpublished year');
        $service->build();
        $document = json_decode($sources['kopisty_monthly'], true);
        $document['datumVytvoreni'] = '2026-05-06T10:00:00Z';
        $document['verzeDat'] = '1.1';
        $document['data']['data']['values'][0][6] = 9.5;
        $sources['kopisty_monthly'] = json_encode($document, JSON_THROW_ON_ERROR);
        $before = count($requests);
        $revised = new ChmiGateway($directory, static function (string $url) use (&$sources, &$requests, $paths): string {
            $key = $paths[basename(parse_url($url, PHP_URL_PATH))];
            $requests[] = $key;
            return $sources[$key];
        }, new DateTimeImmutable('2026-10-28T13:00:00Z'));
        $result = (new ClimateService($revised))->build(1991);
        $assert(count($requests) === $before + 1 && $result['items'][0]['temperature']['value'] === 9.5
            && $result['meta']['sourceVersion'] === '1.1'
            && $result['meta']['sourceCreatedAt'] === '2026-05-06T10:00:00Z',
            'archive refresh exposes revisions after 24 hours without repeatedly downloading weekly metadata');
        $offline = new ChmiGateway($directory, static function (): string {
            throw new RuntimeException('offline');
        }, new DateTimeImmutable('2026-11-30T12:00:00Z'));
        $assert(count((new ClimateService($offline))->build()['meta']['warnings']) > 0,
            'upstream failure uses explicitly stale cached historical version');
    } finally {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) unlink($file);
        if (is_dir($directory)) rmdir($directory);
    }
    return $checks;
};
