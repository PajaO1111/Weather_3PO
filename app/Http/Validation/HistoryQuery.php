<?php
/**
 * Validace parametrů stránky a API historie:
 *   ?from=2026-10-01&to=2026-10-23&resolution=auto|raw|hour|day&format=json|csv
 *
 * - from/to jsou MÍSTNÍ data (Europe/Prague) včetně obou krajních dnů,
 * - chybějící from/to = posledních 7 dní včetně dneška,
 * - resolution=auto vybere podrobnost podle délky období (viz config/app.php),
 * - neplatné hodnoty vyhodí InvalidArgumentException (API → 400),
 *   nic se „tiše“ neopravuje.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Http\Validation;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class HistoryQuery
{
    /** Podrobnosti dat: surová měření, hodinové a denní agregace. */
    public const RESOLUTIONS = ['auto', 'raw', 'hour', 'day'];

    /** Výchozí délka období ve dnech (včetně dneška). */
    public const DEFAULT_DAYS = 7;

    /**
     * @param array<string, mixed> $query    obvykle $_GET
     * @param DateTimeImmutable    $now      aktuální čas (kvůli testům předáván zvenku)
     * @param array<string, mixed> $settings config/app.php
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable, days: int, resolution: string, requested: string, format: string}
     *         from/to = místní půlnoc prvního a posledního dne
     */
    public static function parse(array $query, DateTimeImmutable $now, array $settings): array
    {
        $timezone = new DateTimeZone((string) $settings['timezone']);
        $today = $now->setTimezone($timezone)->setTime(0, 0, 0);

        $to = array_key_exists('to', $query) ? self::date($query['to'], 'to', $timezone) : $today;
        $from = array_key_exists('from', $query)
            ? self::date($query['from'], 'from', $timezone)
            : $to->sub(new DateInterval('P' . (self::DEFAULT_DAYS - 1) . 'D'));

        if ($to > $today) {
            throw new InvalidArgumentException('Datum „do“ nesmí být v budoucnosti.');
        }
        if ($from > $to) {
            throw new InvalidArgumentException('Datum „od“ musí být nejpozději stejné jako „do“.');
        }
        $days = self::days($from, $to);
        $maxDays = (int) $settings['history_max_days'];
        if ($days > $maxDays) {
            throw new InvalidArgumentException('Období může mít nejvýš ' . $maxDays . ' dní.');
        }

        $requested = self::choice($query, 'resolution', self::RESOLUTIONS, 'auto');
        $resolution = $requested;
        if ($requested === 'auto') {
            if ($days <= (int) $settings['history_auto_raw_days']) {
                $resolution = 'raw';
            } elseif ($days <= (int) $settings['history_auto_hour_days']) {
                $resolution = 'hour';
            } else {
                $resolution = 'day';
            }
        } elseif ($requested === 'raw' && $days > (int) $settings['history_raw_max_days']) {
            throw new InvalidArgumentException('Data po 5 min lze zobrazit nejvýš za ' . (int) $settings['history_raw_max_days'] . ' dní.');
        } elseif ($requested === 'hour' && $days > (int) $settings['history_hour_max_days']) {
            throw new InvalidArgumentException('Hodinová data lze zobrazit nejvýš za ' . (int) $settings['history_hour_max_days'] . ' dní.');
        }

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'resolution' => $resolution,
            'requested' => $requested,
            'format' => self::choice($query, 'format', ['json', 'csv'], 'json'),
        ];
    }

    /** Počet kalendářních dní včetně obou krajních (správně i přes změnu času). */
    public static function days(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->setTime(12, 0)->diff($to->setTime(12, 0))->days + 1;
    }

    /**
     * Místní datum RRRR-MM-DD → půlnoc v daném pásmu.
     *
     * @param mixed $raw
     */
    private static function date($raw, string $name, DateTimeZone $timezone): DateTimeImmutable
    {
        if (!is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $raw, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 2000) {
            throw new InvalidArgumentException('Parametr ' . $name . ' musí být platné datum ve tvaru RRRR-MM-DD.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timezone);
        if ($date === false) {
            throw new InvalidArgumentException('Parametr ' . $name . ' musí být platné datum ve tvaru RRRR-MM-DD.');
        }
        return $date;
    }

    /**
     * Hodnota z výčtu povolených; chybějící parametr = výchozí.
     *
     * @param array<string, mixed> $query
     * @param list<string>         $allowed
     */
    private static function choice(array $query, string $name, array $allowed, string $default): string
    {
        if (!array_key_exists($name, $query)) {
            return $default;
        }
        $raw = $query[$name];
        if (!is_string($raw) || !in_array($raw, $allowed, true)) {
            throw new InvalidArgumentException('Parametr ' . $name . ' musí být jedna z hodnot: ' . implode(', ', $allowed) . '.');
        }
        return $raw;
    }
}
