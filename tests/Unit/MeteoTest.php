<?php
declare(strict_types=1);

use OuBrix\Weather\Weather\Support\Meteo;

return static function (): int {
    check(Meteo::compass(0.0) === 'S', 'compass 0');
    check(Meteo::compass(225.0) === 'JZ', 'compass 225');
    check(Meteo::compass(359.0) === 'S', 'compass 359');
    check(Meteo::compass(null) === null, 'compass null');
    check(Meteo::feelsLike(5.0, 20.0, 1.0, 5.0) === 1.0, 'wind chill used');
    check(Meteo::feelsLike(30.0, 5.0, 30.0, 33.0) === 33.0, 'heat index used');
    check(Meteo::feelsLike(18.0, 5.0, 18.0, 18.0) === 18.0, 'plain temperature');
    check(Meteo::pressureTendency(0.3) === 'ustálený', 'steady');
    check(Meteo::pressureTendency(-1.2) === 'mírně klesá', 'falling');
    check(Meteo::pressureTendency(3.0) === 'výrazně stoupá', 'rising fast');
    check(Meteo::uvCategory(6.5) === 'vysoký', 'uv high');
    check(Meteo::toFloat('12.50') === 12.5 && Meteo::toFloat(null) === null && Meteo::toFloat('x') === null, 'toFloat');

    // Beaufort a intenzita srážek
    check(Meteo::beaufort(0.0) === ['force' => 0, 'label' => 'bezvětří'] && Meteo::beaufort(null) === null, 'beaufort 0');
    check(Meteo::beaufort(5.9)['force'] === 1 && Meteo::beaufort(6.0)['force'] === 2 && Meteo::beaufort(38.9)['force'] === 5, 'beaufort limits');
    check(Meteo::beaufort(120.0) === ['force' => 12, 'label' => 'orkán'], 'beaufort 12');
    check(Meteo::precipIntensity(0.0) === 'bez srážek' && Meteo::precipIntensity(1.0) === 'slabé' && Meteo::precipIntensity(60.0) === 'přívalové' && Meteo::precipIntensity(null) === null, 'precip intensity');

    // Odhad stavu: (srážky, teplota, vlhkost, rosný bod, den, záření, jasno, výška Slunce)
    check(Meteo::condition(1.0, 10.0, 90.0, 8.0, true, 100.0, 500.0, 30.0)['code'] === 'rain', 'rain');
    check(Meteo::condition(12.0, 10.0, 90.0, 8.0, false, null, null, null)['code'] === 'heavy-rain', 'heavy rain');
    check(Meteo::condition(0.5, 0.5, 95.0, 0.0, true, 50.0, 300.0, 20.0)['code'] === 'snow', 'snow');
    check(Meteo::condition(0.0, 5.0, 98.0, 4.5, true, 50.0, 300.0, 20.0)['code'] === 'fog', 'fog');
    check(Meteo::condition(0.0, 5.0, 80.0, 1.0, false, 0.0, 0.0, -10.0)['code'] === 'night', 'night');
    check(Meteo::condition(0.0, 20.0, 50.0, 9.0, true, 800.0, 900.0, 50.0)['code'] === 'clear', 'clear');
    check(Meteo::condition(0.0, 20.0, 50.0, 9.0, true, 450.0, 900.0, 50.0)['code'] === 'partly', 'partly');
    check(Meteo::condition(0.0, 20.0, 50.0, 9.0, true, 100.0, 900.0, 50.0)['code'] === 'cloudy', 'cloudy');
    check(Meteo::condition(0.0, 20.0, 50.0, 9.0, true, 10.0, 100.0, 5.0)['code'] === 'day', 'low sun = unknown cloudiness');
    return 25;
};
