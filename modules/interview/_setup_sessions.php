<?php
// _setup_sessions.php — card-grid session list for one college.
// Variables in scope: $college, $slots, $byDate, $staffList,
// $isAdmin, $canManage, $errors, $today.
//
// Layout matches the exam directory style 1:1: a responsive grid
// of cards (one per session) with a dashed "+ Add Session" card as
// the first cell. Setup is purely about creating/editing sessions —
// past sessions, rosters, and evaluation live elsewhere.

function _session_expired(string $date, ?string $endTime, string $today, string $nowTime): bool {
    if ($date < $today) return true;
    if ($date === $today && $endTime !== null && $nowTime >= $endTime) return true;
    return false;
}
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>

<div data-page-actions>
        <?php if ($canManage && !empty($byDate)): ?>
            <?= ui_button('Select', ['variant' => 'ghost', 'attrs' => ['id' => 'sess-select-toggle', 'onclick' => 'toggleSessSelectMode()']]) ?>
        <?php endif; ?>
    </div>

<?php
// Build a date map for the toolbar filter dropdown. Mirrors the
// interview-queue date filter — one entry per distinct session date
// with a count and a "past" flag. Filtering is pure client-side via
// data-date on each card.
$sessDateMap = [];
foreach ($byDate as $_d => $_sessions) {
    $_d = (string)$_d;
    if ($_d === '') continue;
    $sessDateMap[] = [
        'date'    => $_d,
        'count'   => count($_sessions),
        'is_past' => $_d < $today,
    ];
}
usort($sessDateMap, fn($a, $b) => $a['date'] <=> $b['date']);
$totalSessCount = array_sum(array_column($sessDateMap, 'count'));
?>
<?php if (count($sessDateMap) > 1): ?>
<div class="select-toolbar">
    <select id="sess-date-filter" class="form-control" title="Filter by session date">
        <option value="" data-count="<?= (int)$totalSessCount ?>">
            All dates · <?= (int)$totalSessCount ?> session<?= (int)$totalSessCount === 1 ? '' : 's' ?>
        </option>
        <?php foreach ($sessDateMap as $_d): ?>
            <option value="<?= e($_d['date']) ?>" data-count="<?= (int)$_d['count'] ?>">
                <?= format_date($_d['date']) ?><?= $_d['is_past'] ? ' (Past)' : '' ?>
                · <?= (int)$_d['count'] ?> session<?= (int)$_d['count'] === 1 ? '' : 's' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <span id="sess-count">
        <?= (int)$totalSessCount ?> session<?= (int)$totalSessCount === 1 ? '' : 's' ?>
    </span>
</div>
<?php endif; ?>

<style>
/* Card layout comes from the shared selector-card rules in app.css. */

/* Bulk-select checkboxes, highlight and bar come from app.css (.select-*). */
</style>

<?php
$nowTime = date('H:i:s');

// Flatten $byDate back to a single ordered list for the grid.
$flatSlots = [];
foreach ($byDate as $date => $dateSlots) {
    foreach ($dateSlots as $s) { $flatSlots[] = $s; }
}
?>

<div class="select-grid sess-dir-grid">

    <?php /* ── Existing sessions (upcoming only) ────────────────── */ ?>
    <?php foreach ($flatSlots as $slot):
        $date      = $slot['slot_date'];
        $booked    = (int)$slot['booked'];
        $capacity  = (int)$slot['capacity'];
        $isExpired = _session_expired($date, $slot['end_time'] ?? null, $today, $nowTime);
        $isClosed  = $slot['status'] === 'closed' || $isExpired;
        $isFull    = $booked >= $capacity;

        $timeLabel = 'All day';
        if ($slot['slot_time']) {
            $timeLabel = format_time($slot['slot_time']);
            if ($slot['end_time']) $timeLabel .= ' – ' . format_time($slot['end_time']);
        }

        $cardClasses = 'select-card sess-dir-card';
        if ($date === $today)             $cardClasses .= ' is-today';
        if ($slot['status'] === 'closed' || $isExpired) $cardClasses .= ' is-muted';

        $editPayload = json_encode([
            'id'             => (int)$slot['id'],
            'slot_date'      => $slot['slot_date'],
            'slot_time'      => substr($slot['slot_time'] ?? '', 0, 5),
            'end_time'       => substr($slot['end_time']  ?? '', 0, 5),
            'capacity'       => (int)$slot['capacity'],
            'booked'         => $booked,
            'status'         => $slot['status'],
            'assigned_to'    => (int)($slot['assigned_to'] ?? 0),
            'location_label' => $slot['location_label'] ?? '',
            'location_notes' => $slot['location_notes'] ?? '',
        ], JSON_HEX_APOS | JSON_HEX_QUOT);
        // Sessions with bookings can't be deleted via bulk select either —
        // dim the card and disable its checkbox to match the single-delete rule.
        $undeletable = $booked > 0;
        if ($undeletable) $cardClasses .= ' is-undeletable';
    ?>
        <div class="<?= $cardClasses ?>" data-session-id="<?= (int)$slot['id'] ?>"
             data-date="<?= e($date) ?>"
             onclick='handleSessCardClick(event, <?= $editPayload ?>)'>
            <?php if ($canManage): ?>
                <input type="checkbox" class="select-card-check sess-select-checkbox"
                       value="<?= (int)$slot['id'] ?>"
                       <?= $undeletable ? 'disabled title="Has bookings — cannot remove"' : '' ?>
                       onclick="event.stopPropagation();onSessCheckboxChange(this)">
            <?php endif; ?>

            <!-- Status badge top-right -->
            <div class="select-card-badge">
                <?php if ($isExpired && $slot['status'] !== 'closed'): ?>
                    <span class="badge badge-neutral">Ended</span>
                <?php elseif ($isClosed): ?>
                    <span class="badge badge-neutral">Closed</span>
                <?php elseif ($isFull): ?>
                    <span class="badge badge-review">Full</span>
                <?php elseif ($date === $today): ?>
                    <span class="badge badge-info">Today</span>
                <?php else: ?>
                    <span class="badge badge-approved">Open</span>
                <?php endif; ?>
            </div>


            <!-- Title: interviewer name -->
            <div class="select-card-title"><?= e($slot['interviewer_name'] ?? 'Unassigned') ?></div>

            <!-- Meta: date · time · location · capacity -->
            <div class="select-card-meta">
                <div class="select-card-meta-row">
                    <?= e(format_date($date, 'D, M j')) ?>
                </div>
                <div class="select-card-meta-row">
                    <?= e($timeLabel) ?>
                </div>
                <?php if (!empty($slot['location_label'])): ?>
                    <div class="select-card-meta-row">
                        <?= e($slot['location_label']) ?>
                    </div>
                <?php endif; ?>
                <div class="select-card-meta-row">
                    <?= $booked ?> / <?= $capacity ?> booked
                </div>
            </div>

            <!-- Footer: notes (or empty) on left, "Edit →" on right -->
            <div class="select-card-footer">
                <span>
                    <?= !empty($slot['location_notes'])
                        ? e($slot['location_notes'])
                        : '<span style="opacity:.5">No notes</span>' ?>
                </span>
                <span class="select-card-action">
                    Edit
                </span>
            </div>
        </div>
    <?php endforeach; ?>

    <?php /* ── Add Session card (always last in grid; opens the modal) ─── */ ?>
    <?php if ($canManage): ?>
    <div class="select-card select-card--add sess-dir-card sess-add-card"
         onclick="document.getElementById('add-session-modal').style.display='flex'"
         role="button" tabindex="0"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
        <div class="select-card-title">Add Session</div>
        <div class="select-card-desc">Schedule a new interview session</div>
    </div>
    <?php endif; ?>

</div>

<?php if ($canManage && !empty($byDate)): ?>
<!-- Floating bar for bulk delete (visible while in select mode). -->
<div id="sess-bulk-bar" class="select-bulk-bar sess-bulk-bar">
    <span id="sess-bulk-count" style="font-weight:var(--weight-medium)">0 selected</span>
    <form method="POST" action="<?= url('/staff/interviews/setup') ?>" id="sess-bulk-form"
          style="display:flex;gap:var(--space-2);align-items:center;margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_sessions_bulk">
        <input type="hidden" name="college" value="<?= e($college) ?>">
        <input type="hidden" name="ids"     id="sess-bulk-ids" value="">
        <button type="button" class="btn btn-ghost btn-sm"
                onclick="cancelSessSelectMode()">Cancel</button>
        <button type="submit" class="btn btn-sm" id="sess-bulk-delete-btn"
                style="background:var(--error);color:#fff;border-color:var(--error)"
                onclick="return confirmSessBulkDelete()">
            Delete Selected
        </button>
    </form>
</div>
<?php endif; ?>

<!-- ─────────────────────────────────────────────────────────
     ADD SESSION MODAL — with Single / Recurring toggle.
     The toggle swaps which fields are visible and rewrites
     the hidden `action` so one form submits as either
     `add_session` or `batch_create`.
───────────────────────────────────────────────────────── -->
<div id="add-session-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:560px">
        <div class="modal-header">
            <div class="modal-title">Add Session</div>
            <button class="btn-icon" onclick="document.getElementById('add-session-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>" id="add-session-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="add-action" value="add_session">
            <input type="hidden" name="department" value="<?= e($college) ?>">

            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">

                <!-- Mode toggle -->
                <div class="seg-control" style="align-self:flex-start">
                    <button type="button" id="add-mode-single"
                            class="seg-control-item active"
                            onclick="setAddMode('single')">Single session</button>
                    <button type="button" id="add-mode-batch"
                            class="seg-control-item"
                            onclick="setAddMode('batch')">Recurring (multiple)</button>
                </div>

                <!-- Shared fields: interviewer + location -->
                <div>
                    <label class="form-label">Assigned Interviewer <span style="color:var(--error)">*</span></label>
                    <select name="assigned_to" class="form-control" required>
                        <option value="">— Select staff —</option>
                        <?php foreach ($staffList as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Location Label <span style="color:var(--error)">*</span></label>
                    <input type="text" name="location_label" class="form-control" required
                           placeholder="e.g. Room 201, Desk A">
                </div>
                <div>
                    <label class="form-label">
                        Directions / Notes
                        <span style="color:var(--text-tertiary);font-weight:400"> — optional</span>
                    </label>
                    <input type="text" name="location_notes" class="form-control"
                           placeholder="e.g. 2nd floor, turn left at the bulletin board">
                </div>

                <!-- ── Single-session fields ─────────────────────── -->
                <div id="add-single-fields" style="display:flex;flex-direction:column;gap:var(--space-4)">
                    <div>
                        <label class="form-label">Date <span style="color:var(--error)">*</span></label>
                        <input type="date" name="slot_date" class="form-control"
                               value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Start Time <span style="color:var(--error)">*</span></label>
                            <input type="time" name="slot_time" class="form-control" value="09:00">
                        </div>
                        <div>
                            <label class="form-label">End Time <span style="color:var(--error)">*</span></label>
                            <input type="time" name="slot_end_time" class="form-control" value="16:00">
                        </div>
                        <div>
                            <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                            <input type="number" name="capacity" class="form-control"
                                   value="30" min="1" max="500">
                        </div>
                    </div>
                </div>

                <!-- ── Recurring (batch) fields ──────────────────── -->
                <div id="add-batch-fields" style="display:none;flex-direction:column;gap:var(--space-4)">
                    <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
                        One session is created per selected weekday in the date range.
                    </p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Start Date <span style="color:var(--error)">*</span></label>
                            <input type="date" name="start_date" class="form-control"
                                   value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                        </div>
                        <div>
                            <label class="form-label">End Date <span style="color:var(--error)">*</span></label>
                            <input type="date" name="end_date" class="form-control"
                                   value="<?= date('Y-m-d', strtotime('+14 days')) ?>">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Start Time</label>
                            <input type="time" name="start_time" class="form-control" value="09:00">
                        </div>
                        <div>
                            <label class="form-label">End Time</label>
                            <input type="time" name="end_time" class="form-control" value="16:00">
                        </div>
                        <div>
                            <label class="form-label">Capacity</label>
                            <input type="number" name="capacity_batch" class="form-control" value="30" min="1" max="500">
                        </div>
                    </div>
                    <div>
                        <label class="form-label" style="margin-bottom:var(--space-2)">Days of Week</label>
                        <div style="display:flex;flex-wrap:wrap;gap:var(--space-3)">
                            <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri'] as $dn => $dl): ?>
                                <label class="form-check" style="gap:var(--space-1)">
                                    <input type="checkbox" name="days[]" value="<?= $dn ?>" checked>
                                    <?= $dl ?>
                                </label>
                            <?php endforeach; ?>
                            <label class="form-check" style="gap:var(--space-1)">
                                <input type="checkbox" name="days[]" value="6"> Sat
                            </label>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('add-session-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary" id="add-submit">Add Session</button>
            </div>
        </form>
    </div>
</div>

<!-- ─────────────────────────────────────────────────────────
     EDIT SESSION MODAL — also hosts Close/Reopen and Delete
     so the cards themselves stay clean.
───────────────────────────────────────────────────────── -->
<div id="edit-session-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:560px">
        <div class="modal-header">
            <div class="modal-title">Edit Session</div>
            <button class="btn-icon" onclick="document.getElementById('edit-session-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>" id="edit-session-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="edit_session">
            <input type="hidden" name="slot_id" id="edit-sess-id">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Assigned Interviewer <span style="color:var(--error)">*</span></label>
                    <select name="assigned_to" id="edit-sess-assigned" class="form-control" required>
                        <option value="">— Select staff —</option>
                        <?php foreach ($staffList as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Location Label <span style="color:var(--error)">*</span></label>
                    <input type="text" name="location_label" id="edit-sess-label" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Directions / Notes</label>
                    <input type="text" name="location_notes" id="edit-sess-notes" class="form-control">
                </div>
                <div>
                    <label class="form-label">Date <span style="color:var(--error)">*</span></label>
                    <input type="date" name="slot_date" id="edit-sess-date" class="form-control" required>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Start Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_time" id="edit-sess-time" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">End Time <span style="color:var(--error)">*</span></label>
                        <input type="time" name="slot_end_time" id="edit-sess-end" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                        <input type="number" name="capacity" id="edit-sess-cap" class="form-control"
                               min="1" max="500" required>
                    </div>
                </div>

                <div id="edit-sess-bookinfo"
                     style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:-4px"></div>
            </div>
            <div class="modal-footer" style="justify-content:space-between">
                <!-- Destructive actions on the left -->
                <div style="display:flex;gap:var(--space-2)">
                    <button type="button" id="edit-sess-toggle"
                            class="btn btn-ghost"
                            style="font-size:var(--text-xs)"
                            onclick="submitSessionAction(this.dataset.next)">
                        Close session
                    </button>
                    <button type="button" id="edit-sess-delete"
                            class="btn btn-ghost"
                            style="font-size:var(--text-xs);color:var(--error)"
                            onclick="if(confirm('Remove this session?'))submitSessionAction('delete_session')">
                        Remove
                    </button>
                </div>
                <div style="display:flex;gap:var(--space-2)">
                    <button type="button" class="btn btn-ghost"
                            onclick="document.getElementById('edit-session-modal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>

        <!-- Hidden form used to submit Close/Reopen/Delete actions -->
        <form method="POST" action="<?= url('/staff/interviews/setup') ?>" id="edit-action-form" style="display:none">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  id="edit-action-name">
            <input type="hidden" name="slot_id" id="edit-action-id">
        </form>
    </div>
</div>

<script>
// ── Date filter on the session card grid ─────────────────────
// Mirrors the interview queue's date dropdown. Pure client-side:
// hide cards whose data-date doesn't match the selected value, then
// rebuild the "N sessions" badge so it always reflects what's visible.
(function() {
    var dateEl  = document.getElementById('sess-date-filter');
    var countEl = document.getElementById('sess-count');
    if (!dateEl) return;

    function applySessDateFilter() {
        var picked  = dateEl.value;
        var visible = 0;
        document.querySelectorAll('.sess-dir-grid .sess-dir-card').forEach(function(card) {
            var d = card.getAttribute('data-date') || '';
            var match = !picked || d === picked;
            card.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (countEl) {
            countEl.textContent = visible + ' session' + (visible === 1 ? '' : 's');
        }
    }
    dateEl.addEventListener('change', applySessDateFilter);
})();

// ── Bulk select mode for session cards ───────────────────────
// ── Bulk select mode for interview session cards ──────────────
// Shared "select mode" logic lives in createCardBulkSelector()
// (public/assets/js/app.js), which loads after this inline block.
// _sessSelector() builds it lazily on first call instead of at parse
// time, so load order doesn't matter. These wrappers keep the same
// global names the markup already calls via onclick=, so no HTML
// changed.
var __sessSelectorInstance = null;
function _sessSelector() {
    if (!__sessSelectorInstance) {
        __sessSelectorInstance = createCardBulkSelector({
            gridSel:     '.sess-dir-grid',
            cardSel:     '.sess-dir-card',
            checkboxSel: '.sess-select-checkbox',
            barId:       'sess-bulk-bar',
            toggleBtnId: 'sess-select-toggle',
            countId:     'sess-bulk-count',
            deleteBtnId: 'sess-bulk-delete-btn',
            idsInputId:  'sess-bulk-ids',
            confirmMsg:  function (n) { return 'Remove ' + n + ' session(s)? This cannot be undone.'; },
        });
    }
    return __sessSelectorInstance;
}
function _sessGrid()              { return document.querySelector('.sess-dir-grid'); }
function toggleSessSelectMode()   { _sessSelector().toggleSelectMode(); }
function cancelSessSelectMode()   { _sessSelector().cancelSelectMode(); }
function onSessCheckboxChange(cb) { _sessSelector().onCheckboxChange(cb); }
function updateSessBulkCount()    { _sessSelector().updateCount(); }
function confirmSessBulkDelete()  { return _sessSelector().confirmBulkDelete(); }
function handleSessCardClick(event, payload) {
    var grid = _sessGrid();
    if (grid && grid.classList.contains('is-selecting')) {
        // In select mode the card toggles its own checkbox instead of opening
        // the edit modal. Disabled (booked) cards do nothing.
        var cb = event.currentTarget.querySelector('.sess-select-checkbox');
        if (cb && !cb.disabled) {
            cb.checked = !cb.checked;
            onSessCheckboxChange(cb);
        }
        return;
    }
    openEditSession(payload);
}

// ── Add modal: switch between Single and Recurring fields ─────
function setAddMode(mode) {
    var single   = document.getElementById('add-single-fields');
    var batch    = document.getElementById('add-batch-fields');
    var btnS     = document.getElementById('add-mode-single');
    var btnB     = document.getElementById('add-mode-batch');
    var action   = document.getElementById('add-action');
    var submit   = document.getElementById('add-submit');

    var isBatch = mode === 'batch';
    single.style.display = isBatch ? 'none' : 'flex';
    batch.style.display  = isBatch ? 'flex' : 'none';
    btnS.classList.toggle('active', !isBatch);
    btnB.classList.toggle('active',  isBatch);
    action.value = isBatch ? 'batch_create' : 'add_session';
    submit.textContent = isBatch ? 'Create Sessions' : 'Add Session';

    // Disable inputs in the hidden block so they don't block submission
    // (and so capacity from the wrong block doesn't get sent).
    document.querySelectorAll('#add-single-fields [name]').forEach(function(el){ el.disabled = isBatch; });
    document.querySelectorAll('#add-batch-fields [name]').forEach(function(el){ el.disabled = !isBatch; });

    // Capacity in the batch block is named `capacity_batch` to avoid
    // colliding with the single field; rewrite it on submit so the
    // server still receives `capacity`.
    var capBatch = document.querySelector('#add-batch-fields [name="capacity_batch"], #add-batch-fields [name="capacity"]');
    if (capBatch) capBatch.name = isBatch ? 'capacity' : 'capacity_batch';
}
// Initialize on first render
document.addEventListener('DOMContentLoaded', function(){ setAddMode('single'); });

// ── Edit modal: open + prefill ────────────────────────────────
function openEditSession(data) {
    document.getElementById('edit-sess-id').value      = data.id;
    document.getElementById('edit-sess-date').value    = data.slot_date;
    document.getElementById('edit-sess-time').value    = data.slot_time;
    document.getElementById('edit-sess-end').value     = data.end_time;
    document.getElementById('edit-sess-cap').value     = data.capacity;
    document.getElementById('edit-sess-label').value   = data.location_label || '';
    document.getElementById('edit-sess-notes').value   = data.location_notes || '';
    var sel = document.getElementById('edit-sess-assigned');
    if (sel) sel.value = data.assigned_to || '';

    // Close vs Reopen label
    var toggle = document.getElementById('edit-sess-toggle');
    if (data.status === 'closed') {
        toggle.textContent = 'Reopen session';
        toggle.dataset.next = 'open_slot';
    } else {
        toggle.textContent = 'Close session';
        toggle.dataset.next = 'close_slot';
    }

    // Disable Remove if there are bookings
    var del = document.getElementById('edit-sess-delete');
    var info = document.getElementById('edit-sess-bookinfo');
    if (data.booked > 0) {
        del.disabled = true;
        del.style.opacity = '.45';
        del.title = 'Cannot remove a session with bookings';
        info.textContent = data.booked + ' applicant(s) booked — Remove disabled.';
    } else {
        del.disabled = false;
        del.style.opacity = '';
        del.title = '';
        info.textContent = '';
    }

    document.getElementById('edit-session-modal').style.display = 'flex';
}

function submitSessionAction(actionName) {
    var slotId = document.getElementById('edit-sess-id').value;
    if (!slotId || !actionName) return;
    document.getElementById('edit-action-name').value = actionName;
    document.getElementById('edit-action-id').value   = slotId;
    document.getElementById('edit-action-form').submit();
}

// Click outside the modal closes it
['add-session-modal','edit-session-modal'].forEach(function(id){
    var m = document.getElementById(id);
    if(m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});
</script>
