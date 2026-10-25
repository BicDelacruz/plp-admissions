-- Enrollment -> Admissions status callback. Enrollment tells Admissions when
-- an applicant becomes a student and when the COR is issued, so staff see how
-- far each confirmed applicant has come. The stage only moves forward.
-- Safe to run twice.
ALTER TABLE enrollment_handoff_outbox
    ADD COLUMN IF NOT EXISTS enrollment_stage          text         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS enrollment_student_number varchar(20)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS enrollment_stage_at       timestamp(0) DEFAULT NULL;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_handoff_outbox_stage') THEN
        ALTER TABLE enrollment_handoff_outbox
            ADD CONSTRAINT chk_handoff_outbox_stage CHECK (enrollment_stage IN ('student','enrolled'));
    END IF;
END $$;
