<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Support;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class RadarProduct
{
    public const ROOT = '/meteorology/weather/radar/';
    public const BOUNDS = [[48.047, 11.267], [52.167, 20.770]];
    public const DATA_BOUNDS = [[48.047, 11.267], [51.458, 19.624]];

    public static function definition(string $product): array
    {
        $products = [
            'maxz' => ['name' => 'MAX_Z_MASK', 'path' => 'composite/maxz/png_masked/',
                'prefix' => 'z_max3d', 'suffix' => '0', 'step' => 300, 'interval' => 300,
                'legend' => 'scl/scl-dbz-mmh.png', 'unit' => 'dBZ'],
            'merge' => ['name' => 'MERGE', 'path' => 'composite/merge1h/png/',
                'prefix' => 'merge', 'suffix' => '60', 'step' => 600, 'interval' => 3600,
                'legend' => 'scl/scl-mm.png', 'unit' => 'mm / 1 h'],
        ];
        if (!isset($products[$product])) throw new RuntimeException('Nepovolený radarový produkt.');
        return $products[$product];
    }

    public static function time(string $stamp, string $product): DateTimeImmutable
    {
        $definition = self::definition($product);
        $time = DateTimeImmutable::createFromFormat('!YmdHi', $stamp, new DateTimeZone('UTC'));
        if ($time === false || $time->format('YmdHi') !== $stamp
            || $time->getTimestamp() % $definition['step'] !== 0) {
            throw new RuntimeException('Neplatný čas radarového souboru.');
        }
        return $time;
    }

    public static function filename(string $product, string $stamp): string
    {
        $definition = self::definition($product);
        $time = self::time($stamp, $product);
        return 'pacz2gmaps3.' . $definition['prefix'] . '.' . $time->format('Ymd.Hi') . '.' . $definition['suffix'] . '.png';
    }

    public static function latest(string $html, string $product, DateTimeImmutable $now): string
    {
        $definition = self::definition($product);
        $pattern = '/href=["\']pacz2gmaps3\.' . preg_quote($definition['prefix'], '/') .
            '\.(\d{8})\.(\d{4})\.' . $definition['suffix'] . '\.png["\']/i';
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);
        $latest = null;
        foreach ($matches as $match) {
            $stamp = $match[1] . $match[2];
            $time = self::time($stamp, $product);
            if ($time->getTimestamp() > $now->getTimestamp() + 300) {
                throw new RuntimeException('Radarový index obsahuje budoucí snímek.');
            }
            if ($now->getTimestamp() - $time->getTimestamp() > 8 * 86400) continue;
            if ($latest === null || strcmp($stamp, $latest) > 0) $latest = $stamp;
        }
        if ($latest === null) throw new RuntimeException('Index ČHMÚ neobsahuje použitelný radarový snímek.');
        return $latest;
    }

    public static function png(string $body, ?array $dimensions = null): array
    {
        if (substr($body, 0, 8) !== "\x89PNG\r\n\x1a\n") throw new RuntimeException('ČHMÚ nevrátilo PNG.');
        $size = @getimagesizefromstring($body);
        if ($size === false || $size[2] !== IMAGETYPE_PNG || $size[0] > 2048 || $size[1] > 2048
            || ($dimensions !== null && [$size[0], $size[1]] !== $dimensions)) {
            throw new RuntimeException('Neočekávané rozměry radarového PNG.');
        }
        // Ověříme i úplnost chunků a jejich CRC; useknutý PNG nesmí vypadat jako prázdný radar.
        $position = 8;
        $hasData = false;
        $ended = false;
        while ($position + 12 <= strlen($body)) {
            $length = unpack('N', substr($body, $position, 4))[1];
            if ($length > strlen($body) - $position - 12) break;
            $type = substr($body, $position + 4, 4);
            $chunk = substr($body, $position + 4, $length + 4);
            if (hash('crc32b', $chunk, true) !== substr($body, $position + 8 + $length, 4)) {
                throw new RuntimeException('Chybný kontrolní součet PNG.');
            }
            if ($type === 'IDAT') $hasData = true;
            $position += $length + 12;
            if ($type === 'IEND' && $length === 0) { $ended = true; break; }
        }
        if (!$hasData || !$ended || $position !== strlen($body)) throw new RuntimeException('Neúplný radarový PNG.');
        return [$size[0], $size[1]];
    }
}
