<?php
declare(strict_types=1);

use OuBrix\Weather\Http\Validation\ObservationQuery;

return static function (): int {
    check(ObservationQuery::limit([], 288, 500) === 288, 'default limit');
    check(ObservationQuery::limit(['limit' => '500'], 288, 500) === 500, 'max limit');
    $rejected = 0;
    foreach (['0', '501', '-1', '1.5', '01', 'abc', '', ['1']] as $value) {
        try {
            ObservationQuery::limit(['limit' => $value], 288, 500);
        } catch (InvalidArgumentException $exception) {
            $rejected++;
        }
    }
    check($rejected === 8, 'invalid limits rejected');
    check(ObservationQuery::hours([], 24, 168) === 24 && ObservationQuery::hours(['hours' => '168'], 24, 168) === 168, 'hours default/max');
    $rejectedHours = 0;
    foreach (['0', '169', '-1', '1.5', 'x', ['1']] as $value) {
        try {
            ObservationQuery::hours(['hours' => $value], 24, 168);
        } catch (InvalidArgumentException $exception) {
            $rejectedHours++;
        }
    }
    check($rejectedHours === 6, 'invalid hours rejected');
    return 5;
};
