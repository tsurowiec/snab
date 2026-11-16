<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\LlmUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Claude API call: tokens and estimated cost (from App\Services\Anthropic\Pricing).
 *
 * @property int $id
 * @property int $user_id
 * @property string $feature
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_creation_input_tokens
 * @property int $cache_read_input_tokens
 * @property int|null $cost_micros
 * @property string|null $stop_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'feature', 'model', 'input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'cost_micros', 'stop_reason'])]
class LlmUsage extends Model
{
    use BelongsToUser;

    /** @use HasFactory<LlmUsageFactory> */
    use HasFactory;

    public const FEATURE_CARD_IMPORT = 'card_import';

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_creation_input_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
            'cost_micros' => 'integer',
        ];
    }

    /** "$0.0123" — micro-dollars formatted for display. */
    public static function formatCost(?int $micros): string
    {
        return $micros === null ? '—' : '$'.number_format($micros / 1_000_000, 4);
    }
}
