<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Validation and defaults for the per-quiz delivery settings stored in rooms.settings_json. */
final class QuizSettings
{
    public const FEEDBACK_WHEN = ['immediate', 'after_close', 'never'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'min_approved' => 1,
            'open_at' => null,
            'close_at' => null,
            'duration_minutes' => null,
            'max_attempts' => 1,
            'shuffle_questions' => false,
            'shuffle_choices' => false,
            'feedback_when' => 'immediate',
            'show_score' => true,
            'show_correctness' => true,
            'show_correct_answers' => false,
            'show_explanations' => false,
        ];
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public static function fromStored(string $json): array
    {
        $decoded = json_decode($json, true);
        return array_replace(self::defaults(), is_array($decoded) ? $decoded : []);
    }

    /**
     * Validate untrusted form input. Dates arrive as local "Y-m-d\TH:i" strings
     * in the deployment timezone and are stored as UTC.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validate(array $input, string $timezone): array
    {
        $settings = self::defaults();

        $min = filter_var($input['min_approved'] ?? 1, FILTER_VALIDATE_INT);
        if ($min === false || $min < 1 || $min > 500) {
            throw new RuntimeException('Minimum approved questions must be between 1 and 500.');
        }
        $settings['min_approved'] = $min;

        foreach (['open_at', 'close_at'] as $field) {
            $settings[$field] = self::parseLocalDate((string) ($input[$field] ?? ''), $timezone);
        }
        if ($settings['open_at'] !== null && $settings['close_at'] !== null
            && $settings['close_at'] <= $settings['open_at']
        ) {
            throw new RuntimeException('The close time must be after the open time.');
        }

        $duration = trim((string) ($input['duration_minutes'] ?? ''));
        if ($duration !== '') {
            $minutes = filter_var($duration, FILTER_VALIDATE_INT);
            if ($minutes === false || $minutes < 1 || $minutes > 600) {
                throw new RuntimeException('Time limit must be between 1 and 600 minutes, or blank for no limit.');
            }
            $settings['duration_minutes'] = $minutes;
        }

        $attempts = filter_var($input['max_attempts'] ?? 1, FILTER_VALIDATE_INT);
        if ($attempts === false || $attempts < 0 || $attempts > 100) {
            throw new RuntimeException('Attempts must be between 1 and 100, or 0 for unlimited.');
        }
        $settings['max_attempts'] = $attempts;

        foreach (['shuffle_questions', 'shuffle_choices', 'show_score', 'show_correctness',
            'show_correct_answers', 'show_explanations'] as $flag) {
            $settings[$flag] = !empty($input[$flag]);
        }

        $when = (string) ($input['feedback_when'] ?? 'immediate');
        if (!in_array($when, self::FEEDBACK_WHEN, true)) {
            throw new RuntimeException('Feedback timing is invalid.');
        }
        $settings['feedback_when'] = $when;

        return $settings;
    }

    /**
     * What a student may see about a finished attempt, given the revision's feedback rules.
     * "After close" feedback opens once the quiz is no longer active or its close time has passed.
     *
     * @param array<string, mixed> $settings
     * @return array{score: bool, correctness: bool, correct_answers: bool, explanations: bool}
     */
    public static function feedback(array $settings, string $roomState, string $nowUtc): array
    {
        $when = (string) ($settings['feedback_when'] ?? 'immediate');
        $closed = $roomState !== 'active'
            || (!empty($settings['close_at']) && $nowUtc >= (string) $settings['close_at']);
        $visible = $when === 'immediate' || ($when === 'after_close' && $closed);
        return [
            'score' => $visible && !empty($settings['show_score']),
            'correctness' => $visible && !empty($settings['show_correctness']),
            'correct_answers' => $visible && !empty($settings['show_correct_answers']),
            'explanations' => $visible && !empty($settings['show_explanations']),
        ];
    }

    private static function parseLocalDate(string $value, string $timezone): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone($timezone));
        if ($date === false) {
            throw new RuntimeException('A date or time is invalid.');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** Format a stored UTC timestamp for a datetime-local input, or for display. */
    public static function toLocal(?string $utc, string $timezone, string $format = 'Y-m-d\TH:i'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone($timezone))->format($format);
    }
}
