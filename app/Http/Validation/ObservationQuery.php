<?php
/**
 * Validace parametrů ?hours= a ?limit= pro časovou řadu.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Http\Validation;

use InvalidArgumentException;

final class ObservationQuery
{
    /**
     * Vrátí počet požadovaných řádků.
     *
     * Chybějící parametr = výchozí hodnota; cokoli jiného než celé číslo
     * v rozsahu 1..max je chyba (400), nic se „tiše“ neopravuje.
     *
     * @param array<string, mixed> $query obvykle $_GET
     */
    public static function limit(array $query, int $default, int $max): int
    {
        if (!array_key_exists('limit', $query)) {
            return $default;
        }
        $raw = $query['limit'];
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,3}$/D', $raw) !== 1 || (int) $raw > $max) {
            throw new InvalidArgumentException('Parametr limit musí být celé číslo 1–' . $max . '.');
        }
        return (int) $raw;
    }

    /**
     * Vrátí délku časového okna v hodinách z parametru ?hours=.
     *
     * Stejná pravidla jako limit(): chybějící = výchozí, jinak celé číslo 1..max.
     *
     * @param array<string, mixed> $query obvykle $_GET
     */
    public static function hours(array $query, int $default, int $max): int
    {
        if (!array_key_exists('hours', $query)) {
            return $default;
        }
        $raw = $query['hours'];
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,3}$/D', $raw) !== 1 || (int) $raw > $max) {
            throw new InvalidArgumentException('Parametr hours musí být celé číslo 1–' . $max . '.');
        }
        return (int) $raw;
    }
}
