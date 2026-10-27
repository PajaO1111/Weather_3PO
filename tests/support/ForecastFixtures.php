<?php
declare(strict_types=1);

final class ForecastFixtures
{
    public static function document(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return [[
            'mainHeadline' => 'Předpověď na noc',
            'sent' => $now->modify('-1 hour')->format('Y-m-d\TH:i:s\Z'),
            'startTime' => $now->modify('-30 minutes')->format('Y-m-d\TH:i:s\Z'),
            'data' => [
                ['displayText' => 'Místy mlhy.'],
                ['headline' => 'Počasí:', 'displayText' => 'Jasno nebo skoro jasno.'],
                ['displayText' => 'Nejnižší teploty 6 až 1 °C.'],
            ],
        ]];
    }

    public static function cache(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
            throw new RuntimeException('Cannot create forecast fixture cache.');
        }
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'forecast_today.json', json_encode([
            'body' => base64_encode(json_encode(self::document(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'sourceLastModified' => null,
        ], JSON_THROW_ON_ERROR));
    }
}
