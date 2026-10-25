<?php
/**
 * Poslední platné koncentrace UMOM nezávislé na přepisovaném CSV snímku.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Repositories;

use RuntimeException;

final class AirQualityLastValidRepository
{
    /** @var string */
    private $directory;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    /**
     * Zámek chrání souběžná načtení; starší odpověď nesmí přepsat novější měření.
     *
     * @param array<string, array<string, mixed>> $measurements
     * @return array<string, array<string, mixed>>
     */
    public function merge(array $measurements): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Složku posledních platných měření nelze vytvořit.');
        }
        $file = $this->directory . DIRECTORY_SEPARATOR . 'air_last_valid_umom.json';
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Úložiště posledních platných měření nelze otevřít.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Úložiště posledních platných měření nelze zamknout.');
            }
            $contents = stream_get_contents($handle, 65537);
            if ($contents === false || strlen($contents) > 65536) {
                throw new RuntimeException('Úložiště posledních platných měření nelze přečíst.');
            }
            $saved = $contents === '' ? [] : json_decode($contents, true);
            if (!is_array($saved)) {
                throw new RuntimeException('Úložiště posledních platných měření je poškozené.');
            }
            $latest = [];
            foreach ($measurements as $component => $measurement) {
                $previous = $saved[$component] ?? null;
                if (is_array($previous) && $this->isValid($previous)
                    && $previous['registrationId'] === $measurement['registrationId']
                    && $previous['unit'] === $measurement['unit']) {
                    $previous['value'] = (float) $previous['value'];
                    $latest[$component] = $previous;
                }
                if ($this->isValid($measurement)
                    && (!isset($latest[$component]) || $measurement['observedAt'] >= $latest[$component]['observedAt'])) {
                    $latest[$component] = $measurement;
                }
            }
            $json = json_encode($latest, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            if ($latest !== $saved) {
                rewind($handle);
                if (fwrite($handle, $json) !== strlen($json) || !ftruncate($handle, strlen($json)) || !fflush($handle)) {
                    throw new RuntimeException('Poslední platná měření se nepodařilo uložit.');
                }
            }
            return $latest;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $measurement */
    private function isValid(array $measurement): bool
    {
        return isset($measurement['value'], $measurement['observedAt'], $measurement['registrationId'], $measurement['unit'])
            && is_numeric($measurement['value'])
            && is_finite((float) $measurement['value'])
            && (float) $measurement['value'] >= 0
            && in_array($measurement['status'] ?? null, ['available', 'substituted'], true)
            && in_array($measurement['valueTypeId'] ?? null, [8, 9, 10, 11], true)
            && is_string($measurement['observedAt'])
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $measurement['observedAt']) === 1;
    }
}
