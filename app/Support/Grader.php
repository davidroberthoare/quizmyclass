<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

/**
 * Pure grading and seeded ordering for quiz attempts.
 * Questions are the immutable snapshot arrays stored in quiz_revisions.questions_json.
 */
final class Grader
{
    /**
     * Validate and normalize a submitted response. Returns null for "unanswered".
     *
     * @param array<string, mixed> $question
     */
    public static function normalize(array $question, mixed $raw): int|float|string|array|null
    {
        $choiceCount = count($question['choices'] ?? []);
        switch ($question['type'] ?? '') {
            case 'multiple_choice':
                $index = filter_var($raw, FILTER_VALIDATE_INT);
                return ($index !== false && $index >= 0 && $index < $choiceCount) ? $index : null;
            case 'multiple_select':
                $values = is_array($raw) ? $raw : [];
                $indexes = [];
                foreach ($values as $value) {
                    $index = filter_var($value, FILTER_VALIDATE_INT);
                    if ($index !== false && $index >= 0 && $index < $choiceCount) {
                        $indexes[$index] = $index;
                    }
                }
                ksort($indexes);
                return $indexes === [] ? null : array_values($indexes);
            case 'true_false':
                return in_array($raw, ['true', 'false', true, false], true)
                    ? (($raw === true || $raw === 'true') ? 'true' : 'false')
                    : null;
            case 'short_text':
                $text = is_string($raw) ? trim($raw) : '';
                return $text === '' ? null : mb_substr($text, 0, 500);
            case 'numeric':
                if (is_string($raw)) {
                    $raw = trim($raw);
                }
                if ($raw === '' || $raw === null || is_bool($raw) || is_array($raw)) {
                    return null;
                }
                $number = filter_var($raw, FILTER_VALIDATE_FLOAT);
                return ($number === false || abs($number) > 1e12) ? null : (float) $number;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $question
     * @return array{correct: bool, points: float}
     */
    public static function grade(array $question, mixed $response): array
    {
        $response = self::normalize($question, $response);
        $answer = $question['answer'] ?? [];
        $correct = false;
        if ($response !== null) {
            switch ($question['type']) {
                case 'multiple_choice':
                    $correct = $response === (int) ($answer['correct_index'] ?? -1);
                    break;
                case 'multiple_select':
                    $expected = array_map('intval', $answer['correct_indexes'] ?? []);
                    sort($expected);
                    $correct = $response === array_values($expected);
                    break;
                case 'true_false':
                    $correct = ($response === 'true') === (($answer['correct'] ?? null) === true);
                    break;
                case 'short_text':
                    $given = self::fold((string) $response);
                    foreach ($answer['accepted_answers'] ?? [] as $accepted) {
                        if (self::fold((string) $accepted) === $given) {
                            $correct = true;
                            break;
                        }
                    }
                    break;
                case 'numeric':
                    $tolerance = (float) ($answer['tolerance'] ?? 0);
                    $correct = abs((float) $response - (float) ($answer['correct'] ?? NAN)) <= $tolerance + 1e-9;
                    break;
            }
        }
        return ['correct' => $correct, 'points' => $correct ? (float) $question['points'] : 0.0];
    }

    private static function fold(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * Deterministic permutation of 0..$count-1 for a seed (mulberry32), so a refresh
     * shows the same order without touching PHP's global random state.
     *
     * @return list<int>
     */
    public static function order(int $count, int $seed): array
    {
        $order = $count > 0 ? range(0, $count - 1) : [];
        $state = $seed & 0xFFFFFFFF;
        // 32-bit multiply without overflowing PHP's 64-bit ints.
        $imul = static fn (int $a, int $b): int => (
            (($a & 0xFFFF) * $b) + (((($a >> 16) * $b) & 0xFFFF) << 16)
        ) & 0xFFFFFFFF;
        $next = static function () use (&$state, $imul): float {
            $state = ($state + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = $imul($state ^ ($state >> 15), $state | 1);
            $t ^= ($t + $imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;
            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
        for ($i = $count - 1; $i > 0; $i--) {
            $j = (int) floor($next() * ($i + 1));
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }
        return $order;
    }

    /**
     * Questions in the order a given attempt presents them, each with a `choice_order`.
     * Answer keys and explanations are never included, so the result is safe to send to a student.
     *
     * @param list<array<string, mixed>> $questions
     * @param array<string, mixed> $settings
     * @return list<array<string, mixed>>
     */
    public static function present(array $questions, array $settings, int $seed): array
    {
        $indexes = !empty($settings['shuffle_questions'])
            ? self::order(count($questions), $seed)
            : ($questions === [] ? [] : range(0, count($questions) - 1));
        $presented = [];
        foreach ($indexes as $index) {
            $question = $questions[$index];
            $choices = count($question['choices'] ?? []);
            $choiceOrder = ($choices > 0 && !empty($settings['shuffle_choices']) && $question['type'] !== 'true_false')
                ? self::order($choices, $seed + (int) $question['id'])
                : ($choices > 0 ? range(0, $choices - 1) : []);
            unset($question['answer'], $question['explanation_html']);
            $question['choice_order'] = $choiceOrder;
            $presented[] = $question;
        }
        return $presented;
    }

    /**
     * Plain-text description of a student's response (callers escape it).
     *
     * @param array<string, mixed> $question
     */
    public static function describeResponse(array $question, mixed $response): string
    {
        $response = self::normalize($question, $response);
        if ($response === null) {
            return 'No answer';
        }
        $choices = $question['choices'] ?? [];
        return match ($question['type']) {
            'multiple_choice' => (string) ($choices[$response] ?? ''),
            'multiple_select' => implode('; ', array_map(static fn (int $i): string => (string) ($choices[$i] ?? ''), $response)),
            'true_false' => $response === 'true' ? 'True' : 'False',
            default => (string) $response,
        };
    }

    /**
     * Plain-text description of the correct answer (callers escape it).
     *
     * @param array<string, mixed> $question
     */
    public static function describeCorrect(array $question): string
    {
        $answer = $question['answer'] ?? [];
        $choices = $question['choices'] ?? [];
        return match ($question['type']) {
            'multiple_choice' => (string) ($choices[(int) ($answer['correct_index'] ?? -1)] ?? ''),
            'multiple_select' => implode('; ', array_map(
                static fn ($i): string => (string) ($choices[(int) $i] ?? ''),
                $answer['correct_indexes'] ?? [],
            )),
            'true_false' => ($answer['correct'] ?? false) ? 'True' : 'False',
            'short_text' => implode('; ', array_map('strval', $answer['accepted_answers'] ?? [])),
            'numeric' => (string) ($answer['correct'] ?? '')
                . ((float) ($answer['tolerance'] ?? 0) > 0 ? ' ± ' . $answer['tolerance'] : ''),
            default => '',
        };
    }
}
