<?php

namespace App\Services\CardImport;

use App\Models\User;

/** Reads card transactions from a screenshot. Bound to ClaudeTransactionExtractor; tests bind a fake. */
interface TransactionExtractor
{
    /**
     * @return list<ExtractedTransaction>
     *
     * @throws ExtractionException
     */
    public function extract(User $user, string $imageBytes, string $mimeType): array;
}
