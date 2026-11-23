<?php

declare(strict_types=1);

use QuizMyClass\Support\AttemptRepository;
use QuizMyClass\Support\Endpoint;
use QuizMyClass\Support\Http;

$runtime = require dirname(__DIR__, 2) . '/app/bootstrap.php';

// Autosave one answer. JSON in, JSON out; the page keeps working if this fails (answers also go with Submit).
try {
    $user = Endpoint::postUser($runtime);
    $answer = $_POST['answer'] ?? null;
    $attempt = (new AttemptRepository($runtime['database']))->saveAnswer(
        (int) ($_POST['attempt_id'] ?? 0),
        (int) $user['id'],
        (int) ($_POST['question_id'] ?? 0),
        $answer,
    );
    Http::json(['status' => $attempt['status'] === 'in_progress' ? 'saved' : 'finished']);
} catch (Throwable $exception) {
    Http::json(['status' => 'error', 'message' => $exception->getMessage()], 400);
}
