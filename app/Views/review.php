<?php
/**
 * Graded question list for a finished attempt.
 * Expects: $reviewQuestions (presented order, snapshot questions incl. answer + explanation),
 * $reviewAnswers (question id => graded answer row), $reviewFlags (correctness, correct_answers, explanations).
 */

use QuizMyClass\Support\Grader;
use QuizMyClass\Support\View;

foreach ($reviewQuestions as $number => $question):
    $row = $reviewAnswers[(int) $question['id']] ?? null;
    $given = $row['answer'] ?? null;
    $correct = $row !== null && (int) ($row['is_correct'] ?? 0) === 1;
    ?>
    <div class="card review-card">
        <div class="card-content card-content-padding">
            <div class="review-head">
                <strong>Question <?= $number + 1 ?></strong>
                <?php if ($reviewFlags['correctness']): ?>
                    <?= $correct ? View::chip('✓ Correct', 'green') : View::chip('✗ Incorrect', 'red') ?>
                    <span class="review-points"><?= rtrim(rtrim(number_format((float) ($row['points_awarded'] ?? 0), 2, '.', ''), '0'), '.') ?: '0' ?> / <?= rtrim(rtrim(number_format((float) $question['points'], 2, '.', ''), '0'), '.') ?> <?= (float) $question['points'] === 1.0 ? 'pt' : 'pts' ?></span>
                <?php endif; ?>
            </div>
            <div class="review-prompt"><?= $question['prompt_html'] ?></div>
            <p class="review-line"><span>Your answer:</span> <strong><?= View::e(Grader::describeResponse($question, $given)) ?></strong></p>
            <?php if ($reviewFlags['correct_answers'] && !$correct): ?>
                <p class="review-line"><span>Correct answer:</span> <strong><?= View::e(Grader::describeCorrect($question)) ?></strong></p>
            <?php endif; ?>
            <?php if ($reviewFlags['explanations'] && trim((string) ($question['explanation_html'] ?? '')) !== ''): ?>
                <div class="review-explanation"><?= $question['explanation_html'] ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
