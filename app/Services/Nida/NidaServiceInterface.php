<?php

namespace App\Services\Nida;

use App\Models\NidaMockRecord;

interface NidaServiceInterface
{
    /**
     * Find a NIDA record by its 20-digit national ID number.
     */
    public function findByNidaNumber(string $nidaNumber): ?NidaMockRecord;

    /**
     * Loosely compare registered name with NIDA record name.
     */
    public function looseNameMatches(string $registeredFirstName, string $registeredLastName, NidaMockRecord $record): bool;

    /**
     * Generate 3 random challenge questions from the allowed set.
     *
     * @return array<int, array{key: string, question: string}>
     */
    public function generateQuestions(): array;

    /**
     * Verify submitted answers against the NIDA record.
     *
     * @param NidaMockRecord $record
     * @param array<int, array{question_key: string, answer: string}> $submittedAnswers
     * @param array<int, string> $expectedQuestionKeys
     * @return bool
     */
    public function verifyAnswers(NidaMockRecord $record, array $submittedAnswers, array $expectedQuestionKeys): bool;
}
