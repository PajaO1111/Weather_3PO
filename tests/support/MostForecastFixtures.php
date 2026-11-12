<?php
declare(strict_types=1);

final class MostForecastFixtures
{
    public static function document(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('2026-10-27T22:00:00Z');
        $start = $now->setTime((int) $now->format('H'), 0)->modify('+1 hour');
        $hours = [];
        for ($index = 0; $index < 4; $index++) {
            $hours[] = [
                'validityTime' => $start->modify('+' . $index . ' hours')->format('Y-m-d\TH:i:sP'),
                't2m' => 6.5 + $index,
                'prec' => $index === 0 ? 0.2 : 0,
                'snow' => 0,
                'windSpeed' => 2 + $index,
                'windGustSpeed' => 3 + $index,
                'windDirection' => 45,
                'mslp' => 1020.5 + $index,
                'cloudsTot' => 50 + $index,
                'icon' => 40,
            ];
        }
        return ['parameters' => [], 'data' => $hours];
    }

    public static function cache(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
            throw new RuntimeException('Cannot create Most forecast fixture cache.');
        }
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'forecast_most.json', json_encode([
            'body' => base64_encode(json_encode(self::document(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'sourceLastModified' => null,
        ], JSON_THROW_ON_ERROR));
    }
}
