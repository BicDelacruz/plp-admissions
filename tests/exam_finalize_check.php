<?php
// Run: php tests/exam_finalize_check.php
// Checks scoring, draft parsing and rank. No database needed.
// ponytail: the pass check reads the course threshold from the database, so it is covered by the manual test (Phase 6.9 and 12).
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/automation.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

$q = fn(int $id, string $type, array $x = []) => $x + [
    'id' => $id, 'question_type' => $type, 'points' => 1,
    'correct_index' => 0, 'correct_answer' => null, 'scale_min' => 1, 'scale_max' => 5,
];

$questions = [
    $q(1, 'multiple_choice', ['correct_index' => 1, 'points' => 2]),
    $q(2, 'checkboxes', ['correct_answer' => '[2,0]', 'points' => 3]),
    $q(3, 'identification', ['correct_answer' => 'Manila']),
    $q(4, 'paragraph', ['points' => 5]),
    $q(5, 'linear_scale'),
];

// All right: 2 + 3 + 1 + 0 (paragraph is manual) + 1
[$score, $saved] = score_exam_answers($questions, [1 => '1', 2 => ['0', '2'], 3 => ' manila ', 4 => 'essay', 5 => '3']);
check($score === 7, 'all correct scores 7');
check($saved[2] === [0, 2], 'checkbox answers sorted and cast');
check($saved[3] === 'manila', 'text trimmed');

// Nothing answered
[$score, $saved] = score_exam_answers($questions, []);
check($score === 0, 'empty answers score 0');
check($saved[1] === -1, 'unanswered choice saved as -1');

// Partial checkbox is wrong, scale out of range gets no credit
[$score] = score_exam_answers($questions, [2 => ['0'], 5 => '9']);
check($score === 0, 'partial checkbox and out of range scale score 0');

// Draft keys as the browser stores them
$draft = exam_answers_from_draft('{"answers[1]":"1","answers[2][]":["0","2"],"7":"x"}');
check($draft[1] === '1' && $draft[2] === ['0', '2'] && $draft[7] === 'x', 'draft keys parse');
check(exam_answers_from_draft('not json') === [], 'bad draft gives empty answers');
[$score] = score_exam_answers($questions, $draft);
check($score === 5, 'draft scores like a normal submit');

// Rank
check(score_to_rank(0, 10) === 1, 'zero is rank 1');
check(score_to_rank(7, 10) === 7, '70% is rank 7');
check(score_to_rank(10, 10) === 10, '100% is rank 10');
check(score_to_rank(5, 0) === 1, 'no items is rank 1');

echo "exam_finalize_check: all passed\n";
