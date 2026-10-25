-- Enrollment books the visit slot when it receives the handoff and answers with
-- the date, time and office. The answer is kept on the outbox row so the
-- confirmation message to the applicant can say where to go and when.
-- Safe to run twice.
ALTER TABLE enrollment_handoff_outbox
    ADD COLUMN IF NOT EXISTS visit_date       date         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS visit_start_time time(0)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS visit_end_time   time(0)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS visit_office     varchar(100) DEFAULT NULL;
