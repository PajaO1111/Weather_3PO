<?php
/**
 * Validace parametrů stránky a API statistik:
 *   ?year=2026 (výchozí = letošní rok) nebo ?year=all (celé období měření)
 *
 * Neplatná hodnota vyhodí InvalidArgumentException (API → 400).
 */
declare(strict_types=1);

namespace OuBrix\Weather\Http\Validation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class StatsQuery
{
    /** Hodnota parametru year pro celé období měření. */
    public const ALL = 'all';

    /** Nejstarší přípustný rok (stanice měří od 2020+, rezerva). */
    public const MIN_YEAR = 2000;

    /**
     * @param array<string, mixed> $query    obvykle $_GET
     * @param array<string, mixed> $settings config/app.php
     * @return array{period: string} period = "all" nebo rok "RRRR"
     */
    public static function parse(array $query, DateTimeImmutable $now, array $settings): array
    {
        $currentYear = (int) $now->setTimezone(new DateTimeZone((string) $settings['timezone']))->format('Y');
        if (!array_key_exists('year', $query)) {
            return ['period' => (string) $currentYear];
        }
        $raw = $query['year'];
        if ($raw === self::ALL) {
            return ['period' => self::ALL];
        }
        if (!is_string($raw) || preg_match('/^\d{4}$/D', $raw) !== 1
            || (int) $raw < self::MIN_YEAR || (int) $raw > $currentYear) {
            throw new InvalidArgumentException(
                'Parametr year musí být rok ' . self::MIN_YEAR . '–' . $currentYear . ' nebo „all“ (celé období).'
            );
        }
        return ['period' => $raw];
    }
}
