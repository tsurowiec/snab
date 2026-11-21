<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\AliorPayees\AliorRow;
use App\Services\AliorPayees\PayeeExtractor;
use App\Services\CardImport\ExtractionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** @param list<AliorRow>|ExtractionException $result */
function fakePayeeExtractor(array|ExtractionException $result): void
{
    app()->instance(PayeeExtractor::class, new class($result) implements PayeeExtractor
    {
        /** @param list<AliorRow>|ExtractionException $result */
        public function __construct(private array|ExtractionException $result) {}

        public function extract(User $user, string $imageBytes, string $mimeType): array
        {
            $user->llmUsages()->create(['feature' => 'alior_payees', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 3000, 'output_tokens' => 1000, 'cost_micros' => 16000]);
            if ($this->result instanceof ExtractionException) {
                throw $this->result;
            }

            return $this->result;
        }
    });
}

beforeEach(function () {
    $this->user = User::factory()->withAnthropicKey()->withYnabToken()->create();
    $this->user->setSetting(Setting::ALIOR_YNAB_ACCOUNT, 'acc-alior');
});

it('redirects guests to login', function () {
    $this->get(route('alior-payees.index'))->assertRedirect(route('login'));
});

it('asks for the missing configuration', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::tools.alior-payees.index')
        ->assertSee('No Anthropic API key set')
        ->assertSee('No Alior account chosen');
});

it('reads, reviews and applies renames and additions', function () {
    fakeApis(transactions: [
        ynabTx(['id' => 'a', 'date' => '2026-11-20', 'amount' => -42500, 'payee_name' => 'Transakcja kartą']),
        ynabTx(['id' => 'b', 'date' => '2026-11-19', 'amount' => -12990, 'payee_name' => 'Żabka']),
    ]);
    $rows = [
        AliorRow::fromArray(['date' => '2026-11-20', 'merchant' => 'Lidl', 'amount' => -42500]),
        AliorRow::fromArray(['date' => '2026-11-19', 'merchant' => 'Żabka', 'amount' => -12990]),
        AliorRow::fromArray(['date' => '2026-11-18', 'merchant' => 'Orlen', 'amount' => -250000]),
    ];
    fakePayeeExtractor($rows);

    $page = Livewire::actingAs($this->user)
        ->test('pages::tools.alior-payees.index')
        ->set('screenshots', [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')])
        ->call('extract')
        ->assertHasNoErrors()
        ->assertSee('rename: Transakcja kartą')
        ->assertSee('not in YNAB — add')
        ->assertSee('Apply 2 changes in YNAB')
        ->assertSee('Claude read 2 screenshots');

    // The same rows in two overlapping screenshots are shown once.
    expect(array_column($page->get('rows'), 'selected'))->toBe([true, false, true]);

    $page->call('apply')->assertSet('rows', [])->assertSet('ynabError', null);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['transactions'][0]['payee_name'] === 'Lidl');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['transactions'][0]['payee_name'] === 'Orlen');
});

it('shows YNAB errors inline', function () {
    Http::fake(['*' => Http::response(['error' => ['detail' => 'Unauthorized']], 401)]);
    fakePayeeExtractor([AliorRow::fromArray(['date' => '2026-11-20', 'merchant' => 'Lidl', 'amount' => -1000])]);

    Livewire::actingAs($this->user)
        ->test('pages::tools.alior-payees.index')
        ->set('screenshots', [UploadedFile::fake()->image('a.png')])
        ->call('extract')
        ->assertSet('rows', [])
        ->assertSee('YNAB API error (401)');
});

it('shows extraction errors', function () {
    fakePayeeExtractor(new ExtractionException('Claude declined to read this image.'));

    Livewire::actingAs($this->user)
        ->test('pages::tools.alior-payees.index')
        ->set('screenshots', [UploadedFile::fake()->image('a.png')])
        ->call('extract')
        ->assertSee('a.png: Claude declined to read this image.');
});
