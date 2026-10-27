<?php
declare(strict_types=1);

final class RadarFixtures
{
    public static function png(int $width = 680, int $height = 460): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . hash('crc32b', $type . $data, true);
        };
        $row = "\0" . str_repeat("\0\0\0\0", $width);
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            . $chunk('IDAT', gzcompress(str_repeat($row, $height))) . $chunk('IEND', '');
    }

    public static function index(string $product, string $stamp): string
    {
        $filename = OuBrix\Weather\Weather\Support\RadarProduct::filename($product, $stamp);
        return '<a href="' . $filename . '">' . $filename . '</a>';
    }

    public static function cache(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) throw new RuntimeException('Cannot create radar fixture cache.');
        foreach (['maxz', 'merge'] as $product) {
            $step = $product === 'maxz' ? 300 : 600;
            $stamp = gmdate('YmdHi', (int) (floor(time() / $step) * $step));
            foreach (['radar_index_' . $product => self::index($product, $stamp),
                'radar_frame_' . $product . '_' . $stamp => self::png(),
                'radar_legend_' . $product => self::png(56, 236)] as $key => $body) {
                file_put_contents($directory . DIRECTORY_SEPARATOR . $key . '.json', json_encode([
                    'body' => base64_encode($body), 'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'sourceLastModified' => null,
                ], JSON_THROW_ON_ERROR));
            }
        }
    }
}
