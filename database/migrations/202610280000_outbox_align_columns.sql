-- Aligns enrollment_handoff_outbox with schema.sql on databases that were
-- created from the first version of this table (it had applicant_reference and
-- no last_http_status or enrollment_result). The app writes the new names, and
-- without them the handoff insert fails and the send is never recorded.
-- On a fresh setup schema.sql already has these columns, so this does nothing.
-- Safe to run twice.
ALTER TABLE enrollment_handoff_outbox
    ADD COLUMN IF NOT EXISTS applicant_ref     varchar(40) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_http_status  smallint    DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS enrollment_result text        DEFAULT NULL
        CHECK (enrollment_result IN ('created','updated','unchanged'));

-- Carry over the reference from the old column name, only where it exists.
-- EXECUTE keeps this from being parsed on databases that never had it.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name   = 'enrollment_handoff_outbox'
                  AND column_name  = 'applicant_reference') THEN
        EXECUTE 'UPDATE enrollment_handoff_outbox
                    SET applicant_ref = applicant_reference
                  WHERE applicant_ref IS NULL';
    END IF;
END $$;
