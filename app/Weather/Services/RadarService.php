<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Support\RadarProduct;

final class RadarService
{
    /** @var ChmiGateway */
    private $gateway;

    public function __construct(ChmiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function build(string $product = 'maxz', ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $definition = RadarProduct::definition($product);
        $indexKey = 'radar_index_' . $product;
        $index = $this->gateway->fetch($indexKey);
        try {
            $stamp = RadarProduct::latest($index['body'], $product, $now);
        } catch (RuntimeException $exception) {
            $this->gateway->invalidate($indexKey);
            throw $exception;
        }
        $imageKey = 'radar_frame_' . $product . '_' . $stamp;
        $image = $this->gateway->fetch($imageKey);
        try {
            $size = RadarProduct::png($image['body'], [680, 460]);
        } catch (RuntimeException $exception) {
            $this->gateway->invalidate($imageKey);
            throw $exception;
        }
        $legendKey = 'radar_legend_' . $product;
        $legend = $this->gateway->fetch($legendKey);
        try {
            RadarProduct::png($legend['body'], [56, 236]);
        } catch (RuntimeException $exception) {
            $this->gateway->invalidate($legendKey);
            throw $exception;
        }
        $time = RadarProduct::time($stamp, $product);
        $age = max(0, $now->getTimestamp() - $time->getTimestamp());
        $stale = $age > ($product === 'maxz' ? 1200 : 3600);
        $warnings = [];
        if ($stale) $warnings[] = 'Radarový snímek je zastaralý; nemusí odpovídat současné situaci.';
        $sources = [];
        foreach (['index' => $index, 'image' => $image, 'legend' => $legend] as $name => $source) {
            unset($source['body']);
            $sources[$name] = $source;
            if ($source['cacheStatus'] === 'stale') {
                $stale = true;
                $warnings[] = $source['upstreamError'];
            }
        }
        return [
            'product' => ['id' => $product, 'name' => $definition['name'], 'unit' => $definition['unit'],
                'refreshSeconds' => $definition['step']],
            'crs' => 'EPSG:3857', 'bounds' => RadarProduct::BOUNDS, 'dataBounds' => RadarProduct::DATA_BOUNDS,
            'frame' => ['filename' => RadarProduct::filename($product, $stamp),
                'start' => $time->modify('-' . $definition['interval'] . ' seconds')->format('Y-m-d\TH:i:s\Z'),
                'end' => $time->format('Y-m-d\TH:i:s\Z'), 'ageSeconds' => $age, 'width' => $size[0], 'height' => $size[1]],
            'image' => 'data:image/png;base64,' . base64_encode($image['body']),
            'legend' => 'data:image/png;base64,' . base64_encode($legend['body']),
            'stations' => [
                ['name' => 'OuBrix', 'latitude' => 50.51039, 'longitude' => 13.64525],
                ['name' => 'ČHMÚ Kopisty', 'latitude' => 50.54417, 'longitude' => 13.62278],
            ],
            'meta' => ['generatedAt' => $now->format('Y-m-d\TH:i:s\Z'), 'stale' => $stale,
                'warnings' => array_values(array_unique($warnings)), 'sources' => $sources],
        ];
    }
}
