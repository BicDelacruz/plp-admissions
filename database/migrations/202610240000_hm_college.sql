-- Add the College of Hospitality Management and move BS Hospitality
-- Management (BSHM) from the College of Business and Accountancy to it.
-- Safe to run twice. Existing exam and interview slots keep the college
-- name they were created with; new BSHM applicants route to the HM college.

BEGIN;

INSERT INTO departments (code, name)
VALUES ('HM', 'College of Hospitality Management')
ON CONFLICT (code) DO NOTHING;

UPDATE course_departments
   SET department_id = (SELECT id FROM departments WHERE code = 'HM')
 WHERE course_name = 'BS Hospitality Management (BSHM)';

COMMIT;
