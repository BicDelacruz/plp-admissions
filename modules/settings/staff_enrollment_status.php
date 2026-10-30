<?php
// Read-only SSO visibility into accepted students already transferred to Enrollment.
// Uses the existing authenticated REST bridge, not database access to Enrollment.
require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);
$db = db();
$refresh = enrollment_handoff_refresh_status($db);

$stmt = $db->query("SELECT a.id, a.course_applied, a.school_year,
    u.name AS applicant_name, ar.result, ar.enrollment_intent,
    h.status AS handoff_status, h.enrollment_student_number, h.enrollment_stage
    FROM applicants a
    JOIN users u ON u.id=a.user_id
    JOIN admission_results ar ON ar.applicant_id=a.id AND ar.result='accepted'
    LEFT JOIN enrollment_handoff_outbox h ON h.applicant_id=a.id
    WHERE a.overall_status <> 'withdrawn'
    ORDER BY ar.released_at DESC NULLS LAST, a.id DESC
    LIMIT 250");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<section class="card" style="padding:var(--space-5)">
    <h2>Student Enrollment Status</h2>
    <p style="color:var(--text-secondary);margin-top:var(--space-2)">
        This table shows Admissions applicants accepted by PLP and their current
        Registrar enrollment status. Status is checked automatically when the page opens.
        Only the Enrollment Registrar can confirm official enrollment.
    </p>
    <?php if (!$refresh['ok']): ?>
        <div class="notice warning" style="margin-top:var(--space-3)">
            Enrollment status could not be refreshed right now. Saved status may be outdated.
        </div>
    <?php endif; ?>
    <div style="overflow-x:auto;margin-top:var(--space-4)">
        <table class="auto-table">
            <thead><tr>
                <th>Applicant</th>
                <th>Program</th>
                <th>Admission</th>
                <th>Enrollment Status</th>
                <th>Student Number</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row):
                [$statusLabel,$statusBadge] = enrollment_display_status(
                    $row,
                    ($row['enrollment_intent'] ?? null) === 'confirmed',
                    $refresh['ok']
                );
            ?>
                <tr>
                    <td><?= e($row['applicant_name']) ?></td>
                    <td><?= e($row['course_applied']) ?></td>
                    <td><span class="badge badge-approved">Accepted</span></td>
                    <td><span class="badge <?= e($statusBadge) ?>"><?= e($statusLabel) ?></span></td>
                    <td><?= e($row['enrollment_student_number'] ?: 'Not assigned') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$rows): ?>
            <p style="color:var(--text-secondary);padding:var(--space-4)">No accepted applicants were found.</p>
        <?php endif; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
$pageTitle = 'Student Enrollment Status';
$activeNav = 'student-enrollment-status';
$pageWide = true;
include VIEWS_PATH . '/layouts/app.php';
