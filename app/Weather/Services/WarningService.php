<?php
declare(strict_types=1);

namespace OuBrix\Weather\Weather\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use OuBrix\Weather\Weather\Gateways\ChmiGateway;
use OuBrix\Weather\Weather\Support\CapParser;

final class WarningService
{
    public const MOST = '4209';

    /** @var ChmiGateway */
    private $gateway;

    public function __construct(ChmiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function build(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $source = $this->gateway->fetch('warnings_current');
        try {
            $message = (new CapParser())->parse($source['body']);
            $result = $this->select([$message], $now);
        } catch (RuntimeException $exception) {
            $this->gateway->invalidate('warnings_current');
            throw $exception;
        }
        unset($source['body']);
        $result['meta']['source'] = $source;
        if ($source['cacheStatus'] === 'stale') {
            $result['meta']['warnings'][] = $source['upstreamError'];
            $result['summary']['state'] = 'unverified';
            $result['summary']['active'] = 0;
            $result['summary']['upcoming'] = 0;
            foreach ($result['items'] as &$item) {
                if (in_array($item['state'], ['active', 'upcoming', 'no_warning'], true)) {
                    $item['state'] = 'stale';
                    $item['stale'] = true;
                }
            }
            unset($item);
        }
        return $result;
    }

    /**
     * Kompletní snapshot se vyhodnocuje samostatně, nepřimícháváme staré soubory.
     * Při předání více obálek se nejdřív uplatní reference bez ohledu na území.
     */
    public function select(array $messages, DateTimeImmutable $now): array
    {
        $superseded = [];
        $byKey = [];
        $warnings = [];
        $latest = null;
        foreach ($messages as $message) {
            if (strtotime($message['sent']) > $now->getTimestamp() + 300) {
                throw new RuntimeException('CAP vydání je v budoucnosti.');
            }
            $key = $this->key($message);
            if (isset($byKey[$key])) throw new RuntimeException('Duplicitní CAP identita zprávy.');
            $byKey[$key] = $message;
            if ($latest === null || strcmp($message['sent'], $latest) > 0) $latest = $message['sent'];
        }
        foreach ($byKey as $key => $message) {
            if (!in_array($message['msgType'], ['Update', 'Cancel'], true)) continue;
            foreach ($message['references'] as $reference) {
                $target = $this->key($reference);
                if ($target === $key || strtotime($reference['sent']) >= strtotime($message['sent'])) {
                    throw new RuntimeException('Neplatné pořadí referencí CAP.');
                }
                // Testovací nebo soukromá zpráva nesmí zrušit skutečnou veřejnou výstrahu.
                if (isset($byKey[$target]) && ($byKey[$target]['status'] !== $message['status']
                    || $byKey[$target]['scope'] !== $message['scope'])) continue;
                $superseded[$target] = true;
            }
        }
        $items = [];
        $notices = [];
        $noWarning = 0;
        $uncertain = 0;
        $active = 0;
        $upcoming = 0;
        $publicActual = false;
        foreach ($byKey as $key => $message) {
            if (isset($superseded[$key])) continue;
            if (in_array($message['msgType'], ['Cancel', 'Ack', 'Error'], true)) {
                $header = $message;
                unset($header['infos']);
                $notices[] = $header;
                continue;
            }
            $isPublic = $message['status'] === 'Actual' && $message['scope'] === 'Public';
            $stale = $now->getTimestamp() - strtotime($message['sent']) > 86400;
            if ($isPublic && $stale) $warnings[] = 'Vydání CAP je starší než 24 hodin; aktuálnost stavu nelze potvrdit.';
            $czech = [];
            foreach ($message['infos'] as $info) {
                if (preg_match('/^cs(?:-|$)/i', $info['language'])) $czech[$this->infoKey($info)] = true;
            }
            foreach ($message['infos'] as $index => $info) {
                // Překlady párujeme podle kódů, území a platnosti, nikoli přeloženého názvu.
                $isCzech = preg_match('/^cs(?:-|$)/i', $info['language']) === 1;
                if (!$isCzech && isset($czech[$this->infoKey($info)])) continue;
                $matches = false;
                $unknownArea = $info['areas'] === [];
                foreach ($info['areas'] as $area) {
                    $hasCode = false;
                    foreach ($area['geocodes'] as $code) {
                        if ($code['name'] !== 'CISORP') continue;
                        $hasCode = true;
                        if ($code['value'] === self::MOST) $matches = true;
                    }
                    if (!$hasCode) $unknownArea = true;
                }
                if ($isPublic && $unknownArea) {
                    $uncertain++;
                    $warnings[] = 'Část CAP zprávy nemá CISORP; vztah k Mostu nelze určit.';
                }
                if (!$matches) {
                    continue;
                }
                $publicActual = $publicActual || $isPublic;
                if ($isPublic && !$isCzech) {
                    $warnings[] = 'Část hlášení nemá český protějšek; zobrazujeme původní text (' . $info['language'] . ').';
                }
                $start = $info['onset'] ?? $info['effective'];
                $end = $info['expires'];
                if ($start !== null && $end !== null && strcmp($end, $start) <= 0) {
                    throw new RuntimeException('Neplatný interval CAP výstrahy.');
                }
                if ($info['effective'] !== null && $end !== null && strcmp($end, $info['effective']) <= 0) {
                    throw new RuntimeException('Neplatná účinnost CAP zprávy.');
                }
                $eventEnd = $info['eventEndingTime'];
                if ($info['onset'] !== null && $eventEnd !== null && strcmp($eventEnd, $info['onset']) <= 0) {
                    throw new RuntimeException('Neplatný interval výskytu CAP jevu.');
                }
                $isNone = $info['severity'] === 'Minor' && $info['certainty'] === 'Unlikely';
                $startsLater = ($start !== null && strtotime($start) > $now->getTimestamp())
                    || ($info['effective'] !== null && strtotime($info['effective']) > $now->getTimestamp());
                if (!$isPublic) {
                    $state = 'non_actual';
                } elseif ($stale) {
                    $state = 'stale';
                } elseif (($end !== null && strtotime($end) <= $now->getTimestamp())
                    || ($eventEnd !== null && strtotime($eventEnd) <= $now->getTimestamp())) {
                    $state = 'expired';
                } elseif ($start === null) {
                    $state = 'unknown_time';
                } elseif ($startsLater) {
                    $state = 'upcoming';
                } else {
                    $state = $isNone ? 'no_warning' : 'active';
                }
                if ($state === 'active') $active++;
                if ($state === 'upcoming' && !$isNone) $upcoming++;
                if ($state === 'no_warning') $noWarning++;
                if ($isPublic && (in_array($state, ['unknown_time', 'expired'], true) || ($state === 'upcoming' && $isNone)
                    || $info['severity'] === 'Unknown'
                    || $info['certainty'] === 'Unknown' || $info['urgency'] === 'Unknown')) $uncertain++;
                $header = $message;
                unset($header['infos']);
                $items[] = ['message' => $header, 'infoIndex' => $index, 'info' => $info,
                    'state' => $state, 'noWarning' => $isNone, 'stale' => $stale,
                    'ageSeconds' => max(0, $now->getTimestamp() - strtotime($message['sent']))];
            }
        }
        if ($uncertain > 0) $warnings[] = 'Část časové platnosti, území nebo závažnosti není úplná; stav není potvrzen jako bez výstrah.';
        $state = $active > 0 || $upcoming > 0 ? 'warnings'
            : ($publicActual && $noWarning > 0 && $uncertain === 0 && $warnings === [] ? 'no_warning_reported' : 'unverified');
        return [
            'area' => ['name' => 'ORP Most', 'code' => self::MOST, 'codeSystem' => 'CISORP'],
            'summary' => ['state' => $state, 'active' => $active, 'upcoming' => $upcoming,
                'noWarningReports' => $noWarning, 'uncertainBlocks' => $uncertain],
            'items' => $items, 'notices' => $notices,
            'meta' => ['generatedAt' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'latestSent' => $latest, 'warnings' => array_values(array_unique($warnings)),
                'supersededMessages' => count($superseded)],
        ];
    }

    private function key(array $message): string
    {
        return $message['sender'] . "\n" . $message['identifier'] . "\n" . $message['sent'];
    }

    private function infoKey(array $info): string
    {
        $codes = array_map(static function (array $pair): string {
            return $pair['name'] . ':' . $pair['value'];
        }, $info['eventCodes']);
        sort($codes);
        $areas = [];
        foreach ($info['areas'] as $area) {
            $geocodes = array_map(static function (array $pair): string {
                return $pair['name'] . ':' . $pair['value'];
            }, $area['geocodes']);
            sort($geocodes);
            $areas[] = [$geocodes, $area['polygons'], $area['circles'], $area['altitude'], $area['ceiling']];
        }
        sort($areas);
        $categories = $info['categories'];
        sort($categories);
        return json_encode([$codes, $areas, $categories, $info['severity'], $info['certainty'], $info['urgency'],
            $info['effective'], $info['onset'], $info['expires'], $info['eventEndingTime']], JSON_THROW_ON_ERROR);
    }
}
