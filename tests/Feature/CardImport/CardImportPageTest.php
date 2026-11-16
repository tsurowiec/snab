<?php

use App\Models\User;
use App\Services\CardImport\ExtractedTransaction;
use App\Services\CardImport\ExtractionException;
use App\Services\CardImport\TransactionExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/** @param list<ExtractedTransaction>|ExtractionException $result */
function fakeExtractor(array|ExtractionException $result): void
{
    app()->instance(TransactionExtractor::class, new class($result) implements TransactionExtractor
    {
        public int $calls = 0;

        /** @param list<ExtractedTransaction>|ExtractionException $result */
        public function __construct(private array|ExtractionException $result) {}

        public function extract(User $user, string $imageBytes, string $mimeType): array
        {
            $this->calls++;
            if ($this->result instanceof ExtractionException) {
                throw $this->result;
            }

            return $this->result;
        }
    });
}

function screenshotRows(): array
{
    return [
        new ExtractedTransaction(CarbonImmutable::parse('2026-11-15 14:32'), 'Lidl', -42500, 'PLN'),
        new ExtractedTransaction(CarbonImmutable::parse('2026-11-15 09:10'), 'Booking.com', -1234560, 'PLN', -285000, 'EUR'),
        new ExtractedTransaction(CarbonImmutable::parse('2026-11-14 20:00'), 'Netflix', -60000, 'PLN', status: 'declined'),
    ];
}

beforeEach(fn () => $this->user = User::factory()->withAnthropicKey()->create());

it('redirects guests to login', function () {
    $this->get(route('card-import.index'))->assertRedirect(route('login'));
});

it('warns when no API key is set', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::tools.card-import.index')
        ->assertSee('No Anthropic API key set');
});

it('extracts, reviews and saves only the selected new rows', function () {
    fakeExtractor(screenshotRows());

    $page = Livewire::actingAs($this->user)
        ->test('pages::tools.card-import.index')
        ->set('screenshots', [UploadedFile::fake()->image('revolut.png')])
        ->call('extract')
        ->assertHasNoErrors()
        ->assertSee('Lidl')
        ->assertSee('-1 234.56 PLN')
        ->assertSee('(-285.00 EUR)')
        ->assertSee('declined')
        ->assertSee('Save 2 transactions');

    expect(array_column($page->get('rows'), 'selected'))->toBe([true, true, false]);

    $page->call('save')->assertSet('rows', []);

    expect($this->user->cardTransactions()->pluck('description')->sort()->values()->all())->toBe(['Booking.com', 'Lidl']);
});

it('marks rows that were already imported', function () {
    fakeExtractor(screenshotRows());

    $upload = fn () => Livewire::actingAs($this->user)
        ->test('pages::tools.card-import.index')
        ->set('screenshots', [UploadedFile::fake()->image('revolut.png')])
        ->call('extract');

    $upload()->call('save');

    $second = $upload()->assertSee('already imported')->assertSee('Nothing to save');
    expect(array_column($second->get('rows'), 'duplicate'))->toBe([true, true, false]);

    $second->call('save');
    expect($this->user->cardTransactions()->count())->toBe(2);
});

it('shows extraction errors', function () {
    fakeExtractor(new ExtractionException('Claude declined to read this image.'));

    Livewire::actingAs($this->user)
        ->test('pages::tools.card-import.index')
        ->set('screenshots', [UploadedFile::fake()->image('revolut.png')])
        ->call('extract')
        ->assertSee('revolut.png: Claude declined to read this image.')
        ->assertSet('rows', []);
});

it('rejects non-image uploads', function () {
    fakeExtractor([]);

    Livewire::actingAs($this->user)
        ->test('pages::tools.card-import.index')
        ->set('screenshots', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
        ->call('extract')
        ->assertHasErrors('screenshots.0');
});

it('lists imported transactions', function () {
    $this->user->cardTransactions()->create([
        'occurred_at' => '2026-11-10 12:00', 'description' => 'Old coffee', 'amount' => -12000, 'currency' => 'PLN', 'hash' => str_repeat('a', 64),
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::tools.card-import.index')
        ->assertSee('Paste a screenshot')
        ->assertSeeHtml('x-data="cardImportPaste"')
        ->assertSee('Old coffee')
        ->assertSee('-12.00 PLN');
});

it('shows each transaction once when screenshots overlap', function () {
    fakeExtractor(screenshotRows());

    $page = Livewire::actingAs($this->user)
        ->test('pages::tools.card-import.index')
        ->set('screenshots', [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')])
        ->call('extract');

    expect($page->get('rows'))->toHaveCount(3)
        ->and(array_column($page->get('rows'), 'duplicate'))->toBe([false, false, false]);
});

it('starts each extraction with no leftover screenshots', function () {
    fakeExtractor(screenshotRows());

    Livewire::actingAs($this->user)
        ->test('pages::tools.card-import.index')
        ->set('screenshots', [UploadedFile::fake()->image('a.png')])
        ->call('extract')
        ->assertSet('screenshots', [])
        ->call('save')
        ->set('screenshots', [UploadedFile::fake()->image('b.png')])
        ->call('extract')
        ->assertSee('already imported')
        ->tap(fn ($page) => expect($page->get('rows'))->toHaveCount(3));
});
