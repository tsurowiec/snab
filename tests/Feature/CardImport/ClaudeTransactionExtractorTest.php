<?php

use App\Models\User;
use App\Services\CardImport\ClaudeTransactionExtractor;
use App\Services\CardImport\ExtractionException;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Psr\Http\Message\RequestInterface;

/**
 * @param  list<Response>  $responses
 * @param  list<array{request: RequestInterface}>  $history
 */
function claudeWithResponses(array $responses, array &$history): ClaudeTransactionExtractor
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new ClaudeTransactionExtractor(new Guzzle(['handler' => $stack, 'http_errors' => false]));
}

function claudeMessage(string $text, string $stopReason = 'end_turn'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
        'content' => [['type' => 'text', 'text' => $text, 'citations' => null]],
        'stop_reason' => $stopReason, 'stop_sequence' => null, 'stop_details' => null, 'container' => null,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
    ]));
}

beforeEach(fn () => Carbon::setTestNow('2026-11-16 12:00'));

it('sends the screenshot with a strict JSON schema and parses the reply', function () {
    $history = [];
    $extractor = claudeWithResponses([claudeMessage(json_encode(['transactions' => [
        ['date' => '2026-11-16', 'time' => '08:15', 'merchant' => 'Żabka', 'amount' => '-12.99', 'currency' => 'PLN', 'original_amount' => null, 'original_currency' => null, 'status' => 'completed'],
    ]]))], $history);

    $rows = $extractor->extract(User::factory()->withAnthropicKey('sk-ant-key')->create(), 'PNGBYTES', 'image/png');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->description)->toBe('Żabka')
        ->and($rows[0]->amount)->toBe(-12990);

    $request = $history[0]['request'];
    $body = json_decode((string) $request->getBody(), true);

    expect((string) $request->getUri())->toEndWith('/v1/messages')
        ->and($request->getHeaderLine('Authorization').$request->getHeaderLine('x-api-key'))->toContain('sk-ant-key')
        ->and($body['model'])->toBe('claude-opus-5-5')
        ->and($body['messages'][0]['content'][0])->toBe(['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('PNGBYTES')]])
        ->and($body['messages'][0]['content'][1]['text'])->toContain('2026-11-16')
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['format']['schema']['additionalProperties'])->toBeFalse();
});

it('reports refusals, truncation and auth errors', function (Response $response, string $message) {
    $history = [];

    expect(fn () => claudeWithResponses([$response], $history)
        ->extract(User::factory()->withAnthropicKey()->create(), 'x', 'image/png'))
        ->toThrow(ExtractionException::class, $message);
})->with([
    'refusal' => [fn () => claudeMessage('', 'refusal'), 'declined'],
    'max tokens' => [fn () => claudeMessage('{"transactions": [', 'max_tokens'), 'cut off'],
    'bad key' => [fn () => new Response(401, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}'), 'rejected the API key'],
]);

it('requires an API key', function () {
    $history = [];

    expect(fn () => claudeWithResponses([], $history)->extract(User::factory()->create(), 'x', 'image/png'))
        ->toThrow(ExtractionException::class, 'No Anthropic API key');
});
