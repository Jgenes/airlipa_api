<?php

namespace App\Services\Nida;

use App\Models\NidaMockRecord;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class MockNidaService implements NidaServiceInterface
{
    /**
     * Map of question keys to challenge prompts.
     *
     * @var array<string, string>
     */
    protected array $questionCatalog = [
        'date_of_birth' => 'What is your date of birth? (YYYY-MM-DD)',
        'place_of_birth' => 'What is your place of birth?',
        'mother_first_name' => "What is your mother's first name?",
        'region' => 'What is your region of birth?',
        'district' => 'What is your district?',
    ];

    public function findByNidaNumber(string $nidaNumber): ?NidaMockRecord
    {
        return NidaMockRecord::where('nida_number', trim($nidaNumber))->first();
    }

    public function looseNameMatches(string $registeredFirstName, string $registeredLastName, NidaMockRecord $record): bool
    {
        $regFirst = mb_strtolower(trim($registeredFirstName));
        $regLast = mb_strtolower(trim($registeredLastName));
        $recFirst = mb_strtolower(trim($record->first_name));
        $recLast = mb_strtolower(trim($record->last_name));

        // Direct equality
        if ($regFirst === $recFirst && $regLast === $recLast) {
            return true;
        }

        // Swapped first/last name
        if ($regFirst === $recLast && $regLast === $recFirst) {
            return true;
        }

        // Levenshtein / Similarity check
        similar_text($regFirst, $recFirst, $firstPercent);
        similar_text($regLast, $recLast, $lastPercent);

        if ($firstPercent >= 80.0 && $lastPercent >= 80.0) {
            return true;
        }

        // Full name similarity check
        $regFull = "{$regFirst} {$regLast}";
        $recFull = "{$recFirst} {$recLast}";
        similar_text($regFull, $recFull, $fullPercent);

        return $fullPercent >= 80.0;
    }

    public function generateQuestions(): array
    {
        // Pick exactly 3 random keys
        $keys = array_keys($this->questionCatalog);
        shuffle($keys);
        $selectedKeys = array_slice($keys, 0, 3);

        $questions = [];
        foreach ($selectedKeys as $key) {
            $questions[] = [
                'key' => $key,
                'question' => $this->questionCatalog[$key],
            ];
        }

        return $questions;
    }

    public function verifyAnswers(NidaMockRecord $record, array $submittedAnswers, array $expectedQuestionKeys): bool
    {
        // Must contain answers for all 3 expected keys
        $answersByKey = [];
        foreach ($submittedAnswers as $entry) {
            if (isset($entry['question_key']) && isset($entry['answer'])) {
                $answersByKey[trim((string) $entry['question_key'])] = trim((string) $entry['answer']);
            }
        }

        if (count($answersByKey) < count($expectedQuestionKeys)) {
            return false;
        }

        foreach ($expectedQuestionKeys as $key) {
            if (!isset($answersByKey[$key])) {
                return false;
            }

            $submitted = mb_strtolower(trim($answersByKey[$key]));
            $expected = $this->getExpectedAnswer($record, $key);

            if ($submitted !== $expected) {
                return false;
            }
        }

        return true;
    }

    protected function getExpectedAnswer(NidaMockRecord $record, string $key): string
    {
        return match ($key) {
            'date_of_birth' => $record->date_of_birth instanceof Carbon
                ? $record->date_of_birth->format('Y-m-d')
                : (string) Carbon::parse($record->date_of_birth)->format('Y-m-d'),
            'place_of_birth' => mb_strtolower(trim($record->place_of_birth)),
            'mother_first_name' => mb_strtolower(trim($record->mother_first_name)),
            'region' => mb_strtolower(trim($record->region)),
            'district' => mb_strtolower(trim($record->district)),
            default => '',
        };
    }
}
