<?php
/**
 * Odesílání JSON odpovědí.
 */
declare(strict_types=1);

namespace OuBrix\Weather\Http;

final class JsonResponse
{
    /**
     * Odešle data jako JSON s daným HTTP kódem.
     *
     * @param mixed $data
     * @param int   $maxAge  > 0 = povolí krátké cachování (s), 0 = no-store
     */
    public static function send($data, int $status = 200, int $maxAge = 0): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header($maxAge > 0 ? 'Cache-Control: public, max-age=' . $maxAge : 'Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Odešle chybu ve sjednoceném tvaru {"error": {"code", "message", ...}}.
     *
     * @param array<string, mixed> $extra doplňující bezpečné údaje (např. diagnostics)
     */
    public static function error(int $status, string $code, string $message, array $extra = []): void
    {
        self::send(['error' => array_merge(['code' => $code, 'message' => $message], $extra)], $status);
    }
}
