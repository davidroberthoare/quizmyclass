<?php

declare(strict_types=1);

use QuizMyClass\Auth\Session;
use QuizMyClass\Support\Endpoint;
use QuizMyClass\Support\Http;
use QuizMyClass\Support\ResultsRepository;
use QuizMyClass\Support\RoomRepository;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';

$redirect = '/';
try {
    $user = Endpoint::postUser($runtime);
    $room = (new RoomRepository($runtime['database']))->findBySlug((string) ($_POST['room_slug'] ?? ''));
    if ($room === null) {
        throw new RuntimeException('Quiz not found.');
    }
    $revisionId = (int) ($_POST['revision_id'] ?? 0);
    $redirect = '/results.php?room=' . rawurlencode((string) $room['slug']) . '&rev=' . $revisionId;
    $studentId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $deleted = (new ResultsRepository($runtime['database']))->reset(
        (int) $room['id'],
        $revisionId,
        (int) $user['id'],
        $studentId === false || $studentId === null ? null : $studentId,
    );
    Session::set('flash_success', $deleted . ' attempt' . ($deleted === 1 ? '' : 's') . ' deleted.');
} catch (Throwable $exception) {
    Session::set('flash_error', Http::safeMessage($exception));
}
Http::redirect($redirect);
