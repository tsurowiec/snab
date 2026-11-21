<?php

namespace App\Services\AliorPayees;

use App\Models\User;
use App\Services\CardImport\ExtractionException;

/** Reads Alior transactions from a screenshot. Bound to ClaudePayeeExtractor; tests bind a fake. */
interface PayeeExtractor
{
    /**
     * @return list<AliorRow>
     *
     * @throws ExtractionException
     */
    public function extract(User $user, string $imageBytes, string $mimeType): array;
}
