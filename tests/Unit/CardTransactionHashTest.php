<?php

use App\Models\CardTransaction;
use Carbon\CarbonImmutable;

it('ignores case and whitespace differences in the description', function () {
    $at = CarbonImmutable::parse('2026-11-15 14:32');

    expect(CardTransaction::hashFor($at, -42500, 'PLN', '  Żabka   Warszawa '))
        ->toBe(CardTransaction::hashFor($at, -42500, 'pln', 'żabka warszawa'));
});

it('ignores seconds but not minutes, amount or currency', function () {
    $base = CardTransaction::hashFor(CarbonImmutable::parse('2026-11-15 14:32:10'), -42500, 'PLN', 'Lidl');

    expect(CardTransaction::hashFor(CarbonImmutable::parse('2026-11-15 14:32:59'), -42500, 'PLN', 'Lidl'))->toBe($base)
        ->and(CardTransaction::hashFor(CarbonImmutable::parse('2026-11-15 14:33'), -42500, 'PLN', 'Lidl'))->not->toBe($base)
        ->and(CardTransaction::hashFor(CarbonImmutable::parse('2026-11-15 14:32'), -42510, 'PLN', 'Lidl'))->not->toBe($base)
        ->and(CardTransaction::hashFor(CarbonImmutable::parse('2026-11-15 14:32'), -42500, 'EUR', 'Lidl'))->not->toBe($base);
});
