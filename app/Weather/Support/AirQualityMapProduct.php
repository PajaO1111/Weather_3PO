<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Support;

use RuntimeException;

final class AirQualityMapProduct
{
    /** @return array{label:string,types:array<string,string>,filenameType:array<string,string>} */
    public static function definition(string $component): array
    {
        $products = [
            'PM10' => ['label' => 'PM10', 'types' => ['hours' => 'Hodinový průměr', 'days' => 'Denní průměr'],
                'filenameType' => ['hours' => 'h', 'days' => 'd']],
            'PM2_5' => ['label' => 'PM2,5', 'types' => ['hours' => 'Hodinový průměr', 'days' => 'Denní průměr'],
                'filenameType' => ['hours' => 'h', 'days' => 'd']],
            'NO2' => ['label' => 'NO₂', 'types' => ['hours' => 'Hodinový průměr', 'max1h' => 'Maximální hodinový průměr za den'],
                'filenameType' => ['hours' => 'h', 'max1h' => 'max1h']],
            'O3' => ['label' => 'O₃', 'types' => ['hours' => 'Hodinový průměr', 'max1h' => 'Maximální hodinový průměr za den',
                    'max8h' => 'Maximální 8hodinový průměr za den'],
                'filenameType' => ['hours' => 'h', 'max1h' => 'max1h', 'max8h' => 'max8h']],
            'SO2' => ['label' => 'SO₂', 'types' => ['hours' => 'Hodinový průměr', 'days' => 'Denní průměr'],
                'filenameType' => ['hours' => 'h', 'days' => 'd']],
        ];
        if (!isset($products[$component])) {
            throw new RuntimeException('Požadovaná látka pro mapu ovzduší není povolena.');
        }
        return $products[$component];
    }

    public static function filename(string $component, string $type, string $slot): string
    {
        $definition = self::definition($component);
        if (!isset($definition['filenameType'][$type])) {
            throw new RuntimeException('Požadovaný typ mapy ovzduší není pro látku dostupný.');
        }
        if ($type === 'hours') {
            if (!preg_match('/^(?:[01]\d|2[0-3])$/D', $slot)) {
                throw new RuntimeException('Neplatný hodinový slot mapy ovzduší.');
            }
            return 'airquality_NRT-map_CR_' . $component . '_h_' . $slot . '.zip';
        }
        if ($slot !== 'daily') {
            throw new RuntimeException('Neplatný denní slot mapy ovzduší.');
        }
        return 'airquality_NRT-map_CR_' . $component . '_' . $definition['filenameType'][$type] . '.zip';
    }

    public static function resourceKey(string $component, string $type, string $slot): string
    {
        self::filename($component, $type, $slot);
        return 'air_map_archive_' . $component . '_' . $type . '_' . $slot;
    }
}
