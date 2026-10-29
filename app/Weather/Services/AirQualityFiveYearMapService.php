<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use RuntimeException;

final class AirQualityFiveYearMapService
{
    private const PARAMETERS = [
        'NO2' => ['label' => 'NO₂ · roční průměr', 'layer' => 'annual', 'field' => 'NO2'],
        'BZN' => ['label' => 'Benzen · roční průměr', 'layer' => 'annual', 'field' => 'BZN'],
        'BaP' => ['label' => 'Benzo[a]pyren · roční průměr', 'layer' => 'annual', 'field' => 'BaP'],
        'PM10' => ['label' => 'PM10 · roční průměr', 'layer' => 'annual', 'field' => 'PM10'],
        'PM2_5' => ['label' => 'PM2,5 · roční průměr', 'layer' => 'annual', 'field' => 'PM2_5'],
        'As' => ['label' => 'Arsen · roční průměr', 'layer' => 'annual', 'field' => 'As'],
        'Cd' => ['label' => 'Kadmium · roční průměr', 'layer' => 'annual', 'field' => 'Cd'],
        'Ni' => ['label' => 'Nikl · roční průměr', 'layer' => 'annual', 'field' => 'Ni'],
        'Pb' => ['label' => 'Olovo · roční průměr', 'layer' => 'annual', 'field' => 'Pb'],
        'SO2_24h' => ['label' => 'SO₂ · 24hodinová vrstva', 'layer' => '24h', 'field' => 'SO2'],
        'PM10_24h' => ['label' => 'PM10 · 24hodinová vrstva', 'layer' => '24h', 'field' => 'PM10'],
    ];

    private const COLORS = ['#440154', '#3b528b', '#21918c', '#5ec962', '#fde725'];

    /** @var string */
    private $dataPath;

    public function __construct(string $dataPath)
    {
        $this->dataPath = $dataPath;
    }

    public static function supports(string $component): bool
    {
        return isset(self::PARAMETERS[$component]);
    }

    /** @return array<string, mixed> */
    public function build(string $component): array
    {
        if (!self::supports($component)) {
            throw new RuntimeException('Požadovaná látka pro pětiletou mapu není povolena.');
        }
        $contents = @file_get_contents($this->dataPath);
        if (!is_string($contents) || $contents === '' || strlen($contents) > 2000000) {
            throw new RuntimeException('Předzpracovaný výřez kvality ovzduší není dostupný nebo překračuje limit.');
        }
        $data = json_decode($contents, true);
        if (!is_array($data) || !isset($data['metadata'], $data['boundary'], $data['features'])
            || !is_array($data['metadata']) || !is_array($data['features'])
            || ($data['metadata']['period'] ?? null) !== '2020–2024'
            || ($data['metadata']['district']['ruianCode'] ?? null) !== 3508
            || ($data['metadata']['crs'] ?? null) !== 'EPSG:4326'
        ) {
            throw new RuntimeException('Předzpracovaný výřez kvality ovzduší nemá očekávané schéma.');
        }

        $parameter = self::PARAMETERS[$component];
        $field = $parameter['field'];
        $layer = $parameter['layer'];
        $features = [];
        $values = [];
        foreach ($data['features'] as $feature) {
            if (!is_array($feature) || !isset($feature['geometry'], $feature['properties']['layers'][$layer])
                || !is_array($feature['geometry']) || ($feature['geometry']['type'] ?? null) !== 'Polygon'
                || !isset($feature['geometry']['coordinates']) || !is_array($feature['geometry']['coordinates'])
                || $feature['geometry']['coordinates'] === []
                || !is_array($feature['properties']['layers'][$layer])
                || !array_key_exists($field, $feature['properties']['layers'][$layer])
            ) {
                throw new RuntimeException('Předzpracovaný výřez obsahuje neplatnou buňku gridu.');
            }
            $value = $feature['properties']['layers'][$layer][$field];
            if ($value !== null && (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0)) {
                throw new RuntimeException('Předzpracovaný výřez obsahuje neplatnou koncentraci.');
            }
            $value = $value === null ? null : (float) $value;
            if ($value !== null) {
                $values[] = $value;
            }
            $features[] = [
                'type' => 'Feature',
                'id' => $feature['id'] ?? null,
                'geometry' => $feature['geometry'],
                'properties' => [
                    'gridId' => $feature['properties']['gridId'] ?? null,
                    'value' => $value,
                ],
            ];
        }
        if ($features === [] || $values === []) {
            throw new RuntimeException('Předzpracovaný výřez neobsahuje zobrazitelné hodnoty.');
        }

        $minimum = min($values);
        $maximum = max($values);
        $intervalCount = $maximum === $minimum ? 1 : count(self::COLORS);
        $intervals = [];
        for ($index = 0; $index < $intervalCount; $index++) {
            $intervals[] = [
                'min' => $minimum + ($maximum - $minimum) * $index / $intervalCount,
                'max' => $index === $intervalCount - 1
                    ? $maximum
                    : $minimum + ($maximum - $minimum) * ($index + 1) / $intervalCount,
                'color' => self::COLORS[$index],
            ];
        }

        $product = $data['metadata']['products'][$layer] ?? null;
        if (!is_array($product) || !isset($product['unit'], $product['label'])) {
            throw new RuntimeException('Metadata pětileté mapy nejsou úplná.');
        }
        $source = $data['metadata'];
        unset($source['products']);
        return [
            'component' => ['code' => $component, 'label' => $parameter['label']],
            'period' => $data['metadata']['period'],
            'layer' => ['code' => $layer, 'label' => $product['label']],
            'unit' => $product['unit'],
            'decimals' => $layer === 'annual' ? 1 : 0,
            'map' => ['type' => 'FeatureCollection', 'features' => $features],
            'boundary' => $data['boundary'],
            'legend' => [
                'method' => 'Pět stejně širokých intervalů v rozsahu zobrazených hodnot pro tuto látku; nejde o stupnici limitů.',
                'minimum' => $minimum,
                'maximum' => $maximum,
                'intervals' => $intervals,
            ],
            'statistics' => [
                'gridCells' => count($features),
                'values' => count($values),
                'missing' => count($features) - count($values),
            ],
            'meta' => $source,
        ];
    }
}
