<?php
// Run: php tests/enrollment_handoff_check.php
// Checks the program map and reference format used by the enrollment handoff. No database needed.
// ponytail: plain asserts, no framework; the send and retry flow is covered by the manual end to end test.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/enrollment_handoff.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
}

// Every built-in course either maps to an enrollment program or is BSENT (known gap).
$expected = [
    'BS Accountancy (BSA)' => 'BSA',
    'BS Business Administration major in Marketing Management (BSBA)' => 'BSBA',
    'BS Hospitality Management (BSHM)' => 'BSHM',
    'Bachelor of Elementary Education (BEED)' => 'BEED',
    'Bachelor of Secondary Education Major in English (BSED-ENG)' => 'BSED',
    'Bachelor of Secondary Education Major in Filipino (BSED-FIL)' => 'BSED',
    'Bachelor of Secondary Education Major in Mathematics (BSED-MATH)' => 'BSED',
    'AB Psychology (AB Psych)' => 'BSP',
    'BS Computer Science (BSCS)' => 'BSCS',
    'BS Information Technology (BSIT)' => 'BSIT',
    'BS Electronics Engineering (BSECE)' => 'BSECE',
    'BS Nursing (BSN)' => 'BSN',
    'BS Entrepreneurship (BSENT)' => null,
];
foreach (PLP_COURSES as $course) {
    check(array_key_exists($course, $expected), "course not covered by this test: $course");
}
foreach ($expected as $course => $program) {
    check(enrollment_program_code($course) === $program, "$course maps to " . var_export($program, true));
}
check(count($expected) === count(PLP_COURSES), 'test list matches PLP_COURSES');

check(enrollment_program_code('Some Custom Course') === null, 'a course without brackets is rejected');
check(enrollment_program_code('Custom Course (XYZ)') === null, 'an unknown code is rejected');
check(enrollment_program_code('  BS Nursing (BSN)  ') === 'BSN', 'surrounding spaces are ignored');

check(enrollment_applicant_reference('2026-2027', 42) === 'ADM-2026-000042', 'reference is padded to 6');
check(enrollment_applicant_reference('2026-2027', 1234567) === 'ADM-2026-1234567', 'long ids are not cut');
check(enrollment_applicant_reference('next year', 1) === null, 'school year without a year is rejected');
check(preg_match('/^ADM-[0-9]{4}-[0-9]{6}$/', enrollment_applicant_reference('2025-2026', 7)) === 1, 'matches the receiver format');

echo "enrollment handoff checks passed\n";
