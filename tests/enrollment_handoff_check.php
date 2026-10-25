<?php
// Run: php tests/enrollment_handoff_check.php
// Checks the pure helpers of the Enrollment handoff. No database, no network.
// ponytail: plain asserts, no framework; the send and retry flow is covered by the manual end to end test.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/enrollment_handoff.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

// Every built-in course has a code, and the code is what Enrollment seeds.
$expected = [
    'BS Accountancy (BSA)' => 'BSA',
    'BS Business Administration major in Marketing Management (BSBA)' => 'BSBA',
    'BS Entrepreneurship (BSENT)' => 'BSENT',
    'BS Hospitality Management (BSHM)' => 'BSHM',
    'Bachelor of Elementary Education (BEED)' => 'BEED',
    'Bachelor of Secondary Education Major in English (BSED-ENG)' => 'BSED-ENG',
    'Bachelor of Secondary Education Major in Filipino (BSED-FIL)' => 'BSED-FIL',
    'Bachelor of Secondary Education Major in Mathematics (BSED-MATH)' => 'BSED-MATH',
    'AB Psychology (AB Psych)' => 'AB Psych',
    'BS Computer Science (BSCS)' => 'BSCS',
    'BS Information Technology (BSIT)' => 'BSIT',
    'BS Electronics Engineering (BSECE)' => 'BSECE',
    'BS Nursing (BSN)' => 'BSN',
];
foreach (PLP_COURSES as $course) {
    check(array_key_exists($course, $expected), "course not covered by this test: $course");
}
foreach ($expected as $course => $code) {
    check(enrollment_course_code($course) === $code, "$course gives code $code");
}
check(count($expected) === count(PLP_COURSES), 'test list matches PLP_COURSES');

check(enrollment_course_code('Some Custom Course') === null, 'a course without brackets has no code');
check(enrollment_course_code('Custom Course ()') === null, 'empty brackets have no code');
check(enrollment_course_code('  BS Nursing (BSN)  ') === 'BSN', 'surrounding spaces are ignored');

check(enrollment_applicant_reference('2026-2027', 42) === 'ADM-2026-000042', 'reference is padded to 6');
check(enrollment_applicant_reference('2026-2027', 1234567) === 'ADM-2026-1234567', 'long ids are not cut');
check(enrollment_applicant_reference('next year', 1) === null, 'school year without a year is rejected');
check(preg_match('/^[A-Za-z0-9-]{3,40}$/', enrollment_applicant_reference('2025-2026', 7)) === 1, 'matches the receiver format');

check(enrollment_admission_type('freshman') === 'new', 'freshman is new');
check(enrollment_admission_type('transferee') === 'transferee', 'transferee stays transferee');
check(enrollment_admission_type('foreign') === 'foreign', 'foreign stays foreign');

check(enrollment_full_name(['first_name' => 'Ana', 'middle_name' => 'Cruz', 'last_name' => 'Reyes', 'suffix' => 'Jr.']) === 'Ana Cruz Reyes Jr.', 'name order');
check(enrollment_full_name(['first_name' => '', 'last_name' => '', 'name' => 'Ana Reyes']) === 'Ana Reyes', 'falls back to the combined name');

// Enrollment's answers.
check(enrollment_classify_answer(201, ['status' => 'created', 'applicant_ref' => 'X'])[0] === 'sent', '201 created is sent');
check(enrollment_classify_answer(200, ['status' => 'updated'])[1] === 'updated', '200 updated is kept');
check(enrollment_classify_answer(200, ['status' => 'unchanged'])[0] === 'sent', '200 unchanged is sent');
check(enrollment_classify_answer(200, ['status' => 'weird'])[0] === 'pending', 'unknown 200 body stays pending');
check(enrollment_classify_answer(400, ['error' => 'bad'])[0] === 'rejected', '400 is rejected');
check(enrollment_classify_answer(409, ['error' => 'email taken'])[2] === 'email taken', '409 keeps the reason');
check(enrollment_classify_answer(422, ['error' => 'no program'])[0] === 'rejected', '422 is rejected');
check(enrollment_classify_answer(401, null)[0] === 'pending', '401 stays pending');
check(enrollment_classify_answer(503, ['error' => 'off'])[0] === 'pending', '503 stays pending');
check(enrollment_classify_answer(500, null)[0] === 'pending', '500 stays pending');
check(enrollment_classify_answer(null, null, 'timed out')[0] === 'pending', 'no answer stays pending');

check(enrollment_verified_documents([]) === [], 'no approved documents, none verified');
check(enrollment_verified_documents(['psa_birth_cert', 'alien_cert', 'psa_birth_cert']) === ['acr', 'psa_birth_cert'], 'mapped, unique and sorted');
check(enrollment_verified_documents(['valid_id_1', 'photo_1', 'barangay_cert']) === [], 'papers Enrollment asks for differently are not mapped');

check(enrollment_status_parse(['applicant_ref' => 'ADM-2026-000042', 'stage' => 'enrolled', 'student_number' => '2026-00012']) === [42, 'enrolled', '2026-00012'], 'status call is read');
check(is_string(enrollment_status_parse(['applicant_ref' => 'nope', 'stage' => 'enrolled', 'student_number' => '1'])), 'bad ref is refused');
check(is_string(enrollment_status_parse(['applicant_ref' => 'ADM-2026-000042', 'stage' => 'graduated', 'student_number' => '1'])), 'unknown stage is refused');
check(is_string(enrollment_status_parse(['applicant_ref' => 'ADM-2026-000042', 'stage' => 'student', 'student_number' => ''])), 'missing student number is refused');

echo "enrollment handoff checks passed\n";
