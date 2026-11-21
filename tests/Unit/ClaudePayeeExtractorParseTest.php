<?php

use App\Services\AliorPayees\AliorRow;
use App\Services\AliorPayees\ClaudePayeeExtractor;

it('parses rows and skips unusable ones', function () {
    $rows = ClaudePayeeExtractor::parse(['transactions' => [
        ['date' => '2026-11-20', 'merchant' => '  Biedronka   1234 ', 'amount' => '-1 234,50'],
        ['date' => '2026-11-19', 'merchant' => 'Pracodawca', 'amount' => '5000.00'],
        ['date' => 'yesterday', 'merchant' => 'X', 'amount' => '-1'],
        ['date' => '2026-11-19', 'merchant' => '', 'amount' => '-1'],
        ['date' => '2026-11-19', 'merchant' => 'X', 'amount' => 'abc'],
        ['date' => '2026-11-19', 'merchant' => 'X', 'amount' => '0.00'],
        'junk',
    ]]);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->merchant)->toBe('Biedronka 1234')
        ->and($rows[0]->amount)->toBe(-1234500)
        ->and($rows[0]->date->toDateString())->toBe('2026-11-20')
        ->and($rows[1]->amount)->toBe(5000000);
});

it('returns nothing for a missing list', function () {
    expect(ClaudePayeeExtractor::parse([]))->toBe([]);
});

it('builds a stable import id within the YNAB limit', function () {
    $a = AliorRow::fromArray(['date' => '2026-11-20', 'merchant' => 'Lidl', 'amount' => -1000]);
    $b = AliorRow::fromArray(['date' => '2026-11-20', 'merchant' => ' LIDL ', 'amount' => -1000]);

    expect($a->importId())->toBe($b->importId())
        ->and($a->importId())->toStartWith('SNAB:A:')
        ->and(strlen($a->importId()))->toBe(36);
});
