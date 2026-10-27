<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Support;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use RuntimeException;

final class ChmiJson
{
    /** @return Generator<array<string, mixed>> */
    public static function rows(array $document, array $required): Generator
    {
        $data = $document['data']['data'] ?? null;
        if (!is_array($data) || !is_string($data['header'] ?? null) || !is_array($data['values'] ?? null)) {
            throw new RuntimeException('Neočekávaná tabulková struktura ČHMÚ.');
        }
        $header = array_map('trim', explode(',', $data['header']));
        if (array_diff($required, $header) !== [] || count(array_unique($header)) !== count($header)) {
            throw new RuntimeException('Chybějící nebo duplicitní sloupce ČHMÚ.');
        }
        foreach ($data['values'] as $values) {
            if (!is_array($values) || array_keys($values) !== range(0, count($header) - 1)) {
                throw new RuntimeException('Neplatná délka řádku ČHMÚ.');
            }
            yield array_combine($header, $values);
        }
    }

    public static function number($value): float
    {
        $value = is_string($value) ? str_replace(',', '.', $value) : $value;
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new RuntimeException('Neplatná číselná hodnota ČHMÚ.');
        }
        return (float) $value;
    }

    public static function time($value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('Chybějící čas ČHMÚ.');
        }
        foreach (['!Y-m-d\TH:i:s\Z', '!Y-m-d\TH:i:s.v\Z'] as $format) {
            $time = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($time !== false && $time->format(substr($format, 1)) === $value) {
                return $time->format('Y-m-d\TH:i:s\Z');
            }
        }
        throw new RuntimeException('Neplatný UTC čas ČHMÚ.');
    }
}
