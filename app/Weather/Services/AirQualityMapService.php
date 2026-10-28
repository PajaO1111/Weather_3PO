<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Support\AirQualityMapProduct;

final class AirQualityMapService
{
    private const MAP_ROOT = 'https://opendata.chmi.cz/air_quality/now/maps/';
    private const MAX_ARCHIVE_BYTES = 1000000;
    private const MAX_JSON_BYTES = 900000;
    private const MAX_PNG_BYTES = 4000000;
    private const MAX_PNG_DIMENSION = 4096;

    /** @var ChmiGateway */
    private $gateway;

    /** @var DateTimeImmutable */
    private $now;

    public function __construct(ChmiGateway $gateway, ?DateTimeImmutable $now = null)
    {
        $this->gateway = $gateway;
        $this->now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    public function build(string $component = 'PM10', string $type = 'hours'): array
    {
        $definition = AirQualityMapProduct::definition($component);
        if (!isset($definition['types'][$type])) {
            throw new RuntimeException('Požadovaný typ mapy ovzduší není pro látku dostupný.');
        }

        $index = $this->gateway->fetch('air_quality_map_index');
        $filename = $this->latestArchive($index['body'], $component, $type);
        $slot = $type === 'hours' ? substr($filename, -6, 2) : 'daily';
        $archiveKey = AirQualityMapProduct::resourceKey($component, $type, $slot);
        $archive = $this->gateway->fetch($archiveKey);
        try {
            $map = $this->readArchive($archive['body'], $filename, $component, $type);
        } catch (Throwable $exception) {
            $this->gateway->invalidate($archiveKey);
            throw new RuntimeException('Archiv operativní mapy ČHMÚ nemá očekávaný formát.', 0, $exception);
        }

        $static = $this->gateway->fetch('air_quality_map_static');
        try {
            $reference = $this->readStaticMetadata($static['body'], $component, $type, $map['width'], $map['height']);
        } catch (Throwable $exception) {
            $this->gateway->invalidate('air_quality_map_static');
            throw new RuntimeException('Statická metadata mapy ČHMÚ nemají očekávaný formát.', 0, $exception);
        }

        $age = max(0, $this->now->getTimestamp() - $map['end']->getTimestamp());
        $stale = $age > ($type === 'hours' ? 10800 : 129600);
        $warnings = [];
        if ($stale) {
            $warnings[] = 'Mapa je starší než očekávaná aktualizační doba; nemusí odpovídat současné situaci.';
        }
        $sources = [];
        foreach ([
            'index' => ['source' => $index, 'url' => self::MAP_ROOT],
            'map' => ['source' => $archive, 'url' => self::MAP_ROOT . $filename],
            'metadata' => ['source' => $static, 'url' => self::MAP_ROOT . 'airquality_NRT-map_static.json'],
        ] as $name => $entry) {
            $source = $entry['source'];
            unset($source['body']);
            $source['url'] = $entry['url'];
            $sources[$name] = $source;
            if ($source['cacheStatus'] === 'stale') {
                $stale = true;
                if ($source['upstreamError'] !== null) {
                    $warnings[] = $source['upstreamError'];
                }
            }
        }

        return [
            'component' => ['code' => $component, 'label' => $definition['label']],
            'type' => ['code' => $type, 'label' => $definition['types'][$type]],
            'period' => [
                'start' => $map['start']->format('Y-m-d\TH:i:s\Z'),
                'end' => $map['end']->format('Y-m-d\TH:i:s\Z'),
                'createdAt' => $map['createdAt']->format('Y-m-d\TH:i:s\Z'),
                'ageSeconds' => $age,
            ],
            'map' => 'data:image/png;base64,' . base64_encode($map['image']),
            'bounds' => $reference['bounds'],
            'raster' => [
                'width' => $map['width'],
                'height' => $map['height'],
                'gridStepMeters' => $reference['gridStepMeters'],
                'pixelWidthMeters' => $reference['pixelWidthMeters'],
                'pixelHeightMeters' => $reference['pixelHeightMeters'],
                'crs' => 'EPSG:3857',
            ],
            'legend' => $reference['legend'],
            'meta' => [
                'stale' => $stale,
                'warnings' => array_values(array_unique($warnings)),
                'sources' => $sources,
            ],
        ];
    }

    private function latestArchive(string $html, string $component, string $type): string
    {
        $definition = AirQualityMapProduct::definition($component);
        if (!isset($definition['filenameType'][$type])) {
            throw new RuntimeException('Požadovaný typ mapy ovzduší není povolen.');
        }
        $suffix = preg_quote($definition['filenameType'][$type], '/');
        $hour = $type === 'hours' ? '_(?:\d{2})' : '';
        $pattern = '/<a\s+href="(airquality_NRT-map_CR_' . preg_quote($component, '/') . '_'
            . $suffix . $hour . '\.zip)">[^<]*<\/a>\s+(\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2})/i';
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);
        $latest = null;
        foreach ($matches as $match) {
            $time = $this->listingDate($match[2]);
            if ($time === null || $time->getTimestamp() > $this->now->getTimestamp() + 300) {
                continue;
            }
            if ($latest === null || $time > $latest['time']) {
                $latest = ['filename' => $match[1], 'time' => $time];
            }
        }
        if ($latest === null) {
            throw new RuntimeException('Index ČHMÚ neobsahuje použitelnou mapu pro zvolenou látku a typ.');
        }
        AirQualityMapProduct::filename(
            $component,
            $type,
            $type === 'hours' ? substr($latest['filename'], -6, 2) : 'daily'
        );
        return $latest['filename'];
    }

    private function listingDate(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^(\d{2})-([A-Za-z]{3})-(\d{4})\s+(\d{2}):(\d{2})$/D', $value, $match)) {
            return null;
        }
        $months = [
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
            'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
        ];
        $month = strtolower($match[2]);
        if (!isset($months[$month])) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $match[3] . '-' . $months[$month] . '-' . $match[1] . ' ' . $match[4] . ':' . $match[5],
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date
            : null;
    }

    /** @return array{image:string,width:int,height:int,start:DateTimeImmutable,end:DateTimeImmutable,createdAt:DateTimeImmutable} */
    private function readArchive(string $body, string $filename, string $component, string $type): array
    {
        if (strlen($body) < 22 || strlen($body) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Archiv mapy překračuje velikostní limit.');
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Rozšíření PHP ZipArchive není na serveru dostupné.');
        }
        $path = tempnam(sys_get_temp_dir(), 'wx-map-');
        if ($path === false) {
            throw new RuntimeException('Dočasný soubor archivu mapy nelze vytvořit.');
        }
        $zip = new \ZipArchive();
        try {
            if (file_put_contents($path, $body, LOCK_EX) !== strlen($body) || $zip->open($path) !== true
                || $zip->numFiles !== 1) {
                throw new RuntimeException('Archiv mapy je neplatný nebo obsahuje neočekávané položky.');
            }
            $entryName = $zip->getNameIndex(0);
            $stat = $zip->statIndex(0);
            $typeToken = AirQualityMapProduct::definition($component)['filenameType'][$type];
            if (!is_string($entryName) || strpos($entryName, '/') !== false || strpos($entryName, '\\') !== false
                || !is_array($stat) || !isset($stat['size']) || $stat['size'] > self::MAX_JSON_BYTES
                || !preg_match('/^airquality_NRT-map_CR_\d{10}_' . preg_quote($component, '/') . '_'
                    . preg_quote($typeToken, '/') . '\.json$/D', $entryName)) {
                throw new RuntimeException('Archiv mapy obsahuje neplatný JSON soubor.');
            }
            $json = $zip->getFromIndex(0, self::MAX_JSON_BYTES + 1);
            if (!is_string($json) || $json === '' || strlen($json) > self::MAX_JSON_BYTES) {
                throw new RuntimeException('JSON mapy překračuje velikostní limit.');
            }
        } finally {
            if ($zip->status === \ZipArchive::ER_OK) {
                $zip->close();
            }
            @unlink($path);
        }

        $document = json_decode($json, true);
        if (!is_array($document) || !isset($document['datovyZdrojID'], $document['data'], $document['datumVytvoreni'])
            || $document['datovyZdrojID'] !== 'ovzdusi.mereni' || !is_array($document['data'])) {
            throw new RuntimeException('JSON mapy neodpovídá očekávanému zdroji ČHMÚ.');
        }
        $data = $document['data'];
        if (($data['componentCode'] ?? null) !== $component || ($data['type'] ?? null) !== $type
            || (isset($data['mapFormat']) && $data['mapFormat'] !== 'png')
            || (isset($data['mapEncoding']) && $data['mapEncoding'] !== 'base64')
            || !isset($data['map'], $data['dt_beg_utc'], $data['dt_end_utc'])
            || !is_string($data['map'])) {
            throw new RuntimeException('JSON mapy obsahuje nesouhlasící látku, interval nebo formát.');
        }
        $start = $this->utcDate($data['dt_beg_utc']);
        $end = $this->utcDate($data['dt_end_utc']);
        $createdAt = $this->utcDate($document['datumVytvoreni']);
        $duration = $end->getTimestamp() - $start->getTimestamp();
        if ($duration <= 0 || $duration > 26 * 3600 || $createdAt->getTimestamp() > $this->now->getTimestamp() + 300
            || $end->getTimestamp() > $this->now->getTimestamp() + 300) {
            throw new RuntimeException('Mapa obsahuje neplatné nebo budoucí časové období.');
        }
        $image = base64_decode($data['map'], true);
        if (!is_string($image) || strlen($image) > self::MAX_PNG_BYTES) {
            throw new RuntimeException('PNG mapa překračuje velikostní limit.');
        }
        [$width, $height] = $this->validatePng($image);
        return ['image' => $image, 'width' => $width, 'height' => $height,
            'start' => $start, 'end' => $end, 'createdAt' => $createdAt];
    }

    /** @return array{bounds:array,legend:array,gridStepMeters:int,pixelWidthMeters:float,pixelHeightMeters:float} */
    private function readStaticMetadata(string $body, string $component, string $type, int $width, int $height): array
    {
        $document = json_decode($body, true);
        $data = is_array($document) && isset($document['data']) && is_array($document['data']) ? $document['data'] : null;
        $extent = is_array($data) && isset($data['extent']) && is_array($data['extent']) ? $data['extent'] : null;
        if ($extent === null || ($extent['EPSG'] ?? null) !== 3857
            || !isset($extent['xSW'], $extent['ySW'], $extent['xNE'], $extent['yNE'], $extent['dx'], $extent['dy'],
                $extent['lonSW'], $extent['latSW'], $extent['lonNE'], $extent['latNE'])
            || !is_array($data['legend'] ?? null)) {
            throw new RuntimeException('Metadata neobsahují očekávaný rozsah EPSG:3857.');
        }
        foreach (['xSW', 'ySW', 'xNE', 'yNE', 'dx', 'dy', 'lonSW', 'latSW', 'lonNE', 'latNE'] as $field) {
            if (!is_numeric($extent[$field]) || !is_finite((float) $extent[$field])) {
                throw new RuntimeException('Metadata obsahují nečíselný rozsah.');
            }
            $extent[$field] = (float) $extent[$field];
        }
        $widthMeters = $extent['xNE'] - $extent['xSW'];
        $heightMeters = $extent['yNE'] - $extent['ySW'];
        if ($widthMeters <= 0 || $heightMeters <= 0 || $extent['dx'] <= 0 || $extent['dy'] <= 0) {
            throw new RuntimeException('Metadata obsahují neplatný rozsah nebo krok gridu.');
        }
        $aspect = $widthMeters / $heightMeters;
        if (abs(($width / $height) - $aspect) / $aspect > 0.01) {
            throw new RuntimeException('Rozměry PNG neodpovídají poměru stran georeferencovaného rozsahu.');
        }
        $scaleX = ($widthMeters / $extent['dx']) / $width;
        $scaleY = ($heightMeters / $extent['dy']) / $height;
        if ($scaleX < 1 || $scaleY < 1 || abs($scaleX - $scaleY) / max($scaleX, $scaleY) > 0.01) {
            throw new RuntimeException('Rozlišení PNG neodpovídá deklarovanému kroku gridu.');
        }
        foreach (['SW' => ['xSW', 'ySW', 'lonSW', 'latSW'], 'NE' => ['xNE', 'yNE', 'lonNE', 'latNE']] as $corner) {
            [$xKey, $yKey, $lonKey, $latKey] = $corner;
            $projected = $this->inverseMercator($extent[$xKey], $extent[$yKey]);
            if (abs($projected[0] - $extent[$lonKey]) > 0.01 || abs($projected[1] - $extent[$latKey]) > 0.01) {
                throw new RuntimeException('Zeměpisné souřadnice nesouhlasí s EPSG:3857.');
            }
        }
        $legendEntry = null;
        foreach ($data['legend'] as $entry) {
            if (is_array($entry) && ($entry['componentCode'] ?? null) === $component && ($entry['type'] ?? null) === $type) {
                $legendEntry = $entry;
                break;
            }
        }
        if ($legendEntry === null || !isset($legendEntry['values']) || !is_array($legendEntry['values'])) {
            throw new RuntimeException('Pro mapu chybí oficiální legenda ČHMÚ.');
        }
        $values = [];
        foreach ($legendEntry['values'] as $value) {
            if (!is_array($value) || !isset($value['color'], $value['label'])
                || !is_string($value['color']) || !preg_match('/^#[0-9A-Fa-f]{6}$/D', $value['color'])
                || !is_string($value['label']) || strlen($value['label']) > 80) {
                throw new RuntimeException('Legenda obsahuje neplatnou barvu nebo popisek.');
            }
            $values[] = ['color' => $value['color'], 'label' => $value['label']];
        }
        if ($values === []) {
            throw new RuntimeException('Legenda mapy je prázdná.');
        }
        return [
            'bounds' => [[$extent['latSW'], $extent['lonSW']], [$extent['latNE'], $extent['lonNE']]],
            'legend' => [
                'description' => isset($legendEntry['desc']) && is_string($legendEntry['desc']) ? $legendEntry['desc'] : '',
                'units' => isset($legendEntry['units']) && is_string($legendEntry['units'])
                    ? trim(html_entity_decode(strip_tags($legendEntry['units']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '',
                'values' => $values,
            ],
            'gridStepMeters' => (int) $extent['dx'],
            'pixelWidthMeters' => $widthMeters / $width,
            'pixelHeightMeters' => $heightMeters / $height,
        ];
    }

    /** @return array{0:float,1:float} longitude, latitude */
    private function inverseMercator(float $x, float $y): array
    {
        $radius = 6378137.0;
        return [
            rad2deg($x / $radius),
            rad2deg(2 * atan(exp($y / $radius)) - M_PI_2),
        ];
    }

    /** @return array{0:int,1:int} */
    private function validatePng(string $image): array
    {
        if (substr($image, 0, 8) !== "\x89PNG\r\n\x1a\n" || strlen($image) < 45) {
            throw new RuntimeException('Mapa neobsahuje úplné PNG.');
        }
        $size = @getimagesizefromstring($image);
        if ($size === false || $size[2] !== IMAGETYPE_PNG || $size[0] < 2 || $size[1] < 2
            || $size[0] > self::MAX_PNG_DIMENSION || $size[1] > self::MAX_PNG_DIMENSION) {
            throw new RuntimeException('PNG má neplatný typ nebo rozměry.');
        }
        $position = 8;
        $hasData = false;
        $ended = false;
        while ($position + 12 <= strlen($image)) {
            $length = unpack('N', substr($image, $position, 4))[1];
            if ($length > strlen($image) - $position - 12) {
                break;
            }
            $type = substr($image, $position + 4, 4);
            $chunk = substr($image, $position + 4, $length + 4);
            if (hash('crc32b', $chunk, true) !== substr($image, $position + 8 + $length, 4)) {
                throw new RuntimeException('PNG mapy má chybný kontrolní součet.');
            }
            if ($type === 'IDAT') {
                $hasData = true;
            }
            $position += $length + 12;
            if ($type === 'IEND' && $length === 0) {
                $ended = true;
                break;
            }
        }
        if (!$hasData || !$ended || $position !== strlen($image)) {
            throw new RuntimeException('PNG mapy je neúplné.');
        }
        return [$size[0], $size[1]];
    }

    private function utcDate($value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D', $value)) {
            throw new RuntimeException('Mapa obsahuje neplatný UTC čas.');
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new RuntimeException('Mapa obsahuje neplatný čas.', 0, $exception);
        }
    }
}
