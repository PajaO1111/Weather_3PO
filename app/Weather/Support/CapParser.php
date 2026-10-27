<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Support;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class CapParser
{
    private const NS = 'urn:oasis:names:tc:emergency:cap:1.2';

    public function parse(string $xml): array
    {
        if (strlen($xml) > 4000000 || strpos($xml, "\0") !== false || preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $xml)) {
            throw new RuntimeException('CAP obsahuje zakázanou deklaraci nebo přesahuje limit.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || $document->doctype !== null
                || $document->documentElement === null || $document->documentElement->localName !== 'alert'
                || $document->documentElement->namespaceURI !== self::NS) {
                throw new RuntimeException('Neplatné XML CAP 1.2.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('c', self::NS);
        $root = $document->documentElement;
        $text = function (DOMElement $node, string $name, bool $required = false) use ($xpath): ?string {
            $nodes = $xpath->query('c:' . $name, $node);
            if ($nodes->length > 1 || ($required && $nodes->length !== 1)) {
                throw new RuntimeException('Chybějící nebo duplicitní CAP pole ' . $name . '.');
            }
            if ($nodes->length === 0) return null;
            $value = trim($nodes->item(0)->textContent);
            if ($required && $value === '') throw new RuntimeException('Prázdné CAP pole ' . $name . '.');
            return $value;
        };
        $many = static function (DOMElement $node, string $name) use ($xpath): array {
            $result = [];
            foreach ($xpath->query('c:' . $name, $node) as $child) $result[] = trim($child->textContent);
            return $result;
        };
        $pairs = static function (DOMElement $node, string $name) use ($xpath, $text): array {
            $result = [];
            foreach ($xpath->query('c:' . $name, $node) as $child) {
                $value = $text($child, 'value', $name !== 'parameter');
                if ($value === null) throw new RuntimeException('Chybějící hodnota CAP parametru.');
                $result[] = ['name' => $text($child, 'valueName', true), 'value' => $value];
            }
            return $result;
        };
        $message = [
            'identifier' => $text($root, 'identifier', true), 'sender' => $text($root, 'sender', true),
            'sent' => self::time($text($root, 'sent', true)),
            'status' => $text($root, 'status', true), 'msgType' => $text($root, 'msgType', true),
            'scope' => $text($root, 'scope', true), 'codes' => $many($root, 'code'),
            'note' => $text($root, 'note'), 'references' => [], 'infos' => [],
        ];
        $this->allowed($message['status'], ['Actual', 'Exercise', 'System', 'Test', 'Draft']);
        $this->allowed($message['msgType'], ['Alert', 'Update', 'Cancel', 'Ack', 'Error']);
        $this->allowed($message['scope'], ['Public', 'Restricted', 'Private']);
        $references = $text($root, 'references');
        if ($references !== null && $references !== '') {
            foreach (preg_split('/\s+/', $references) as $reference) {
                $parts = explode(',', $reference);
                if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '') {
                    throw new RuntimeException('Neplatná CAP reference.');
                }
                $message['references'][] = ['sender' => $parts[0], 'identifier' => $parts[1], 'sent' => self::time($parts[2])];
            }
        }
        if (in_array($message['msgType'], ['Update', 'Cancel'], true) && $message['references'] === []) {
            throw new RuntimeException('Aktualizace nebo odvolání CAP postrádá reference.');
        }
        foreach ($xpath->query('c:info', $root) as $node) {
            $info = [
                'language' => $text($node, 'language') ?: 'en-US',
                'categories' => $many($node, 'category'), 'event' => $text($node, 'event', true),
                'responseTypes' => $many($node, 'responseType'),
                'urgency' => $text($node, 'urgency', true), 'severity' => $text($node, 'severity', true),
                'certainty' => $text($node, 'certainty', true),
                'headline' => $text($node, 'headline'), 'description' => $text($node, 'description'),
                'instruction' => $text($node, 'instruction'), 'web' => $text($node, 'web'),
                'effective' => $this->optionalTime($text($node, 'effective')),
                'onset' => $this->optionalTime($text($node, 'onset')),
                'expires' => $this->optionalTime($text($node, 'expires')),
                'eventCodes' => $pairs($node, 'eventCode'), 'parameters' => $pairs($node, 'parameter'),
                'areas' => [],
            ];
            $eventEnds = array_values(array_filter($info['parameters'], static function (array $pair): bool {
                return $pair['name'] === 'eventEndingTime';
            }));
            if (count($eventEnds) > 1) throw new RuntimeException('Duplicitní konec výskytu CAP jevu.');
            $info['eventEndingTime'] = $eventEnds === [] ? null : self::time($eventEnds[0]['value']);
            $this->allowed($info['urgency'], ['Immediate', 'Expected', 'Future', 'Past', 'Unknown']);
            $this->allowed($info['severity'], ['Extreme', 'Severe', 'Moderate', 'Minor', 'Unknown']);
            $this->allowed($info['certainty'], ['Observed', 'Likely', 'Possible', 'Unlikely', 'Unknown']);
            foreach ($xpath->query('c:area', $node) as $area) {
                $info['areas'][] = [
                    'description' => $text($area, 'areaDesc', true), 'geocodes' => $pairs($area, 'geocode'),
                    'polygons' => $many($area, 'polygon'), 'circles' => $many($area, 'circle'),
                    'altitude' => $text($area, 'altitude'), 'ceiling' => $text($area, 'ceiling'),
                ];
            }
            $message['infos'][] = $info;
            if (count($message['infos']) > 500) throw new RuntimeException('Příliš mnoho CAP info bloků.');
        }
        if ($message['infos'] === [] && in_array($message['msgType'], ['Alert', 'Update'], true)) {
            throw new RuntimeException('CAP výstraha neobsahuje žádné info bloky.');
        }
        return $message;
    }

    public static function time(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value)) {
            throw new RuntimeException('Neplatný CAP čas včetně pásma.');
        }
        $time = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
            throw new RuntimeException('Neplatné CAP datum.');
        }
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function optionalTime(?string $value): ?string
    {
        return $value === null || $value === '' ? null : self::time($value);
    }

    private function allowed(string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) throw new RuntimeException('Neznámá hodnota číselníku CAP.');
    }
}
