-- Add applicant-entered GWA and a single staff verification action.
-- Apply this migration to the Admissions PostgreSQL database before using the new pages.
BEGIN;
ALTER TABLE public.applicants
  ADD COLUMN IF NOT EXISTS inputted_gwa numeric(5,2),
  ADD COLUMN IF NOT EXISTS gwa_verified_at timestamptz,
  ADD COLUMN IF NOT EXISTS gwa_verified_by integer REFERENCES public.users(id) ON DELETE SET NULL;
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='applicants_inputted_gwa_range'
                AND conrelid='public.applicants'::regclass) THEN
    ALTER TABLE public.applicants
      ADD CONSTRAINT applicants_inputted_gwa_range
      CHECK (inputted_gwa IS NULL OR (inputted_gwa >= 75 AND inputted_gwa <= 100));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='applicants_gwa_verification_requires_value'
                AND conrelid='public.applicants'::regclass) THEN
    ALTER TABLE public.applicants
      ADD CONSTRAINT applicants_gwa_verification_requires_value
      CHECK (gwa_verified_at IS NULL OR (inputted_gwa IS NOT NULL AND gwa_verified_by IS NOT NULL));
  END IF;
END $$;
CREATE INDEX IF NOT EXISTS idx_applicants_gwa_unverified
  ON public.applicants (id)
  WHERE inputted_gwa IS NOT NULL AND gwa_verified_at IS NULL;
COMMIT;
