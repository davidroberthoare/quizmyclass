<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Endpoint;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\QuizRepository;
use QuizMyClass\Support\ResultsRepository;
use QuizMyClass\Support\RoomRepository;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = Endpoint::pageUser($runtime);
$db = $runtime['database'];

$room = (new RoomRepository($db))->findBySlug((string) ($_GET['room'] ?? ''));
$revision = (new QuizRepository($db))->revision((int) ($_GET['rev'] ?? 0));
if ($room === null || (int) $room['owner_id'] !== (int) $user['id']
    || $revision === null || (int) $revision['room_id'] !== (int) $room['id']
) {
    Session::set('flash_error', 'Only the quiz owner can export results.');
    Http::redirect('/');
}

/** Neutralize spreadsheet formulas in untrusted cells (student names, emails). */
$safe = static function (mixed $value): string {
    $text = (string) $value;
    return $text !== '' && strpos("=+-@\t\r", $text[0]) !== false ? "'" . $text : $text;
};

$results = new ResultsRepository($db);
$type = ($_GET['type'] ?? '') === 'attempts' ? 'attempts' : 'students';
$name = preg_replace('/[^a-z0-9-]+/', '', (string) $room['slug']) . '-v' . (int) $revision['revision_number'] . '-' . $type . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly

if ($type === 'students') {
    fputcsv($out, ['Student', 'Email', 'Attempts', 'Best score', 'Max score', 'Best %', 'Last submitted (UTC)'], ',', '"', '');
    foreach ($results->students((int) $revision['id'], (int) $user['id']) as $row) {
        $percent = (float) $row['max_score'] > 0 ? round(100 * (float) $row['best_score'] / (float) $row['max_score'], 1) : 0;
        fputcsv($out, [$safe($row['display_name']), $safe($row['email']), $row['attempts'], $row['best_score'], $row['max_score'], $percent, $row['last_at']], ',', '"', '');
    }
} else {
    fputcsv($out, ['Student', 'Email', 'Attempt', 'Status', 'Score', 'Max score', 'Submitted (UTC)'], ',', '"', '');
    foreach ($results->students((int) $revision['id'], (int) $user['id']) as $student) {
        foreach ($results->attemptsForStudent((int) $revision['id'], (int) $student['user_id']) as $attempt) {
            if ($attempt['status'] === 'in_progress') {
                continue;
            }
            fputcsv($out, [$safe($student['display_name']), $safe($student['email']), $attempt['attempt_number'], $attempt['status'], $attempt['score'], $attempt['max_score'], $attempt['submitted_at']], ',', '"', '');
        }
    }
}
fclose($out);
