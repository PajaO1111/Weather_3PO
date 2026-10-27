<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Services\ForecastService;

require_once dirname(__DIR__) . '/support/ForecastFixtures.php';

return static function (): int {
    $now = new DateTimeImmutable('2026-10-27T22:00:00Z');
    $document = ForecastFixtures::document($now);
    $body = json_encode($document, JSON_THROW_ON_ERROR);
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weather-forecast-' . bin2hex(random_bytes(6));
    $calls = [];
    $gateway = new ChmiGateway($directory, static function (string $url, int $maxBytes) use (&$body, &$calls): string {
        $calls[] = [$url, $maxBytes];
        return $body;
    }, $now);
    $service = new ForecastService($gateway);
    $count = 0;
    $assert = static function (bool $ok, string $message) use (&$count): void {
        check($ok, $message);
        $count++;
    };
    $load = static function (array $data) use (&$body, $gateway, $service, $now): array {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $gateway->invalidate('forecast_today');
        return $service->build($now);
    };
    try {
        $result = $service->build($now);
        $assert(count($result['items']) === 1 && count($result['items'][0]['sections']) === 3,
            'retain variable sections without requiring headline on each');
        $assert($calls[0] === ['https://data-provider.chmi.cz/api/data/txtf/meteo.today', 1000000],
            'fixed alternate official host and bounded body');
        $assert($result['items'][0]['endTime'] === null && $result['items'][0]['status'] === 'published'
            && !$result['items'][0]['stale'], 'missing end is not invented or asserted as current validity');
        $assert($result['scope'] === 'Obecná textová předpověď ČHMÚ; není lokální předpovědí pro Most.',
            'explicit general geography');
        $assert($result['items'][0]['ageSeconds'] === 3600 && $result['items'][0]['sent'] === '2026-10-27T21:00:00Z',
            'preserve issuance and calculate actual age');
        $service->build($now);
        $assert(count($calls) === 1, 'fresh cache avoids extra network request');
        $data = $document;
        unset($data[0]['mainHeadline']);
        $data[0]['endTime'] = '2026-10-27T23:00:00Z';
        $data[0]['data'][0]['displayText'] = '<img src=x onerror=alert(1)>';
        $result = $load($data);
        $assert($result['items'][0]['mainHeadline'] === null && $result['items'][0]['status'] === 'active'
            && $result['items'][0]['sections'][0]['text'] === '<img src=x onerror=alert(1)>',
            'optional main headline and explicit end; raw text is not interpreted as markup');
        $data = $document;
        $data[0]['startTime'] = '2026-10-28T00:00:00Z';
        $assert($load($data)['items'][0]['status'] === 'upcoming', 'future start labelled upcoming');
        $data = $document;
        $data[0]['endTime'] = '2026-10-27T21:45:00Z';
        $result = $load($data);
        $assert($result['items'][0]['status'] === 'expired' && count($result['meta']['warnings']) > 0,
            'expired never displayed as current');
        $data = $document;
        $data[0]['sent'] = '2026-10-26T21:59:59Z';
        $result = $load($data);
        $assert($result['items'][0]['stale'] && $result['items'][0]['status'] === 'stale',
            'older than 24h is explicitly stale even without supplied end');
        foreach (['bad_sent', 'missing_sent', 'missing_start', 'bad_end', 'end_before_start', 'future_sent',
            'no_sections', 'empty_text', 'wrong_text', 'wrong_headline', 'root_object', 'bad_date'] as $case) {
            $data = $document;
            if ($case === 'bad_sent') $data[0]['sent'] = 'yesterday';
            if ($case === 'missing_sent') unset($data[0]['sent']);
            if ($case === 'missing_start') unset($data[0]['startTime']);
            if ($case === 'bad_end') $data[0]['endTime'] = 'tomorrow';
            if ($case === 'end_before_start') $data[0]['endTime'] = '2026-10-27T20:00:00Z';
            if ($case === 'future_sent') $data[0]['sent'] = '2026-10-27T23:00:00Z';
            if ($case === 'no_sections') $data[0]['data'] = [];
            if ($case === 'empty_text') $data[0]['data'][0]['displayText'] = ' ';
            if ($case === 'wrong_text') $data[0]['data'][0]['displayText'] = ['text'];
            if ($case === 'wrong_headline') $data[0]['mainHeadline'] = 5;
            if ($case === 'root_object') $data = ['data' => $document];
            if ($case === 'bad_date') $data[0]['sent'] = '2026-02-31T12:00:00Z';
            $failed = false;
            try { $load($data); } catch (RuntimeException $exception) { $failed = true; }
            $assert($failed, 'reject ' . $case);
        }
        $body = '{broken';
        $gateway->invalidate('forecast_today');
        $failed = false;
        try { $service->build($now); } catch (RuntimeException $exception) { $failed = true; }
        $assert($failed, 'reject invalid JSON and evict malformed cache');
        $load($document);
        $requests = 0;
        $offline = new ChmiGateway($directory, static function () use (&$requests): string {
            $requests++;
            throw new RuntimeException('offline');
        }, $now->modify('+31 minutes'));
        $result = (new ForecastService($offline))->build($now->modify('+31 minutes'));
        $assert($requests === 1 && $result['meta']['source']['cacheStatus'] === 'stale'
            && count($result['meta']['warnings']) > 0, '30 minute refresh with visible offline fallback');
        $result = $load([$document[0], $document[0]]);
        $assert(count($result['items']) === 2, 'multiple forecast entries retained independently');
    } finally {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) unlink($file);
        if (is_dir($directory)) rmdir($directory);
    }
    return $count;
};
