-- Offline-safe exam saving.
--   exam_drafts  : one draft per (applicant, exam, slot).
--   exam_results : submit_note label + one result per (applicant, exam),
--                  so two page loads cannot double-submit.

-- 1. exam_drafts.slot_id -------------------------------------------------
ALTER TABLE exam_drafts ADD COLUMN IF NOT EXISTS slot_id integer;

-- Backfill from the applicant's assigned slot (applicant_exam_slots is 1:1).
UPDATE exam_drafts d
   SET slot_id = a.slot_id
  FROM applicant_exam_slots a
 WHERE a.applicant_id = d.applicant_id
   AND d.slot_id IS NULL;

-- Stale drafts with no slot cannot be restored or finalized: drop them.
DELETE FROM exam_drafts WHERE slot_id IS NULL;

ALTER TABLE exam_drafts ALTER COLUMN slot_id SET NOT NULL;

ALTER TABLE exam_drafts DROP CONSTRAINT IF EXISTS fk_draft_slot;
ALTER TABLE exam_drafts
    ADD CONSTRAINT fk_draft_slot
    FOREIGN KEY (slot_id) REFERENCES exam_slot_schedule (id) ON DELETE CASCADE;

ALTER TABLE exam_drafts DROP CONSTRAINT IF EXISTS uq_draft;
ALTER TABLE exam_drafts
    ADD CONSTRAINT uq_draft UNIQUE (applicant_id, exam_id, slot_id);

-- 2. exam_results.submit_note + unique result ------------------------------
ALTER TABLE exam_results ADD COLUMN IF NOT EXISTS submit_note varchar(100) DEFAULT NULL;

-- Remove duplicate (applicant, exam) rows first. Keep the earliest.
DO $$
DECLARE removed integer;
BEGIN
    WITH ranked AS (
        SELECT id,
               row_number() OVER (PARTITION BY applicant_id, exam_id
                                  ORDER BY submitted_at, id) AS rn
        FROM exam_results
    ), gone AS (
        DELETE FROM exam_results r
        USING ranked
        WHERE r.id = ranked.id AND ranked.rn > 1
        RETURNING r.id
    )
    SELECT count(*) INTO removed FROM gone;
    RAISE NOTICE 'exam_results: removed % duplicate row(s)', removed;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS uq_exam_results_applicant_exam
    ON exam_results (applicant_id, exam_id);

COMMENT ON COLUMN exam_results.submit_note IS 'Set when the result was auto-submitted, e.g. Auto-submitted, connection lost';
