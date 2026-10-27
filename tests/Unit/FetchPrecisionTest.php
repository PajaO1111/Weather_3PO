<?php
declare(strict_types=1);

return static function (): int {
    foreach (['fetch_weather2.php', 'fetch_weather.php'] as $script) {
        // Požadavek kontrolujeme bez spuštění CRONu, načtení hesel nebo zápisu do DB.
        $source = file_get_contents(WEATHER_ROOT . '/' . $script);
        check(is_string($source), $script . ': readable source');
        $query = null;
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
                && strpos($token[1], '?stationId=') !== false) {
                $literal = substr($token[1], 1, -1);
                $query = substr($literal, strpos($literal, '?') + 1);
                break;
            }
        }
        check($query !== null, $script . ': current observation request found');
        parse_str($query, $parameters);
        check(($parameters['numericPrecision'] ?? null) === 'decimal', $script . ': request decimal values at source');
        check(($parameters['units'] ?? null) === 'm' && ($parameters['format'] ?? null) === 'json',
            $script . ': preserve metric JSON request');
    }
    return 8;
};
