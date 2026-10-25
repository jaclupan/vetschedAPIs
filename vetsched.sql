-- ==========================================================
-- VETSCHED PostgreSQL 17 Clean & Validated Schema Script
-- ==========================================================

-- 1. ACCOUNT TABLE
DROP TABLE IF EXISTS "account" CASCADE;
CREATE TABLE "public"."account" (
    "student_id" character varying(50) NOT NULL,
    "email" character varying(255) NOT NULL,
    "password_hash" character varying(255) NOT NULL,
    "first_name" character varying(100) NOT NULL,
    "last_name" character varying(100) NOT NULL,
    "year_level" integer,
    "semester" smallint DEFAULT 1 NOT NULL,
    CONSTRAINT "account_email_key" UNIQUE ("email"),
    CONSTRAINT "account_pkey" PRIMARY KEY ("student_id")
);

-- 2. ADMIN ACCOUNT TABLE & SEQUENCE
DROP TABLE IF EXISTS "admin_account" CASCADE;
DROP SEQUENCE IF EXISTS admin_account_admin_id_seq;
CREATE SEQUENCE admin_account_admin_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 START 5 CACHE 1;

CREATE TABLE "public"."admin_account" (
    "admin_id" integer DEFAULT nextval('admin_account_admin_id_seq') NOT NULL,
    "username" character varying(50) NOT NULL,
    "password_hash" character varying(255) NOT NULL,
    "display_name" character varying(100) NOT NULL,
    "is_active" boolean DEFAULT true NOT NULL,
    "created_at" timestamptz DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "role" character varying(20) DEFAULT 'admin' NOT NULL,
    CONSTRAINT "admin_account_pkey" PRIMARY KEY ("admin_id"),
    CONSTRAINT "admin_account_username_key" UNIQUE ("username")
);

INSERT INTO "admin_account" ("admin_id", "username", "password_hash", "display_name", "is_active", "created_at", "role") VALUES
(3, 'SuperAdmin', '$2y$10$e3.wLFilr1PgL0QUPQ07Nuk/hVjZfHDP5ZujYrC8E8ZWhd/jUWSsK', 'Super Admin', true, '2026-10-14 10:36:59.204263+00', 'super_admin');

-- 3. INSTRUCTOR TABLE & SEQUENCE
DROP TABLE IF EXISTS "instructor" CASCADE;
DROP SEQUENCE IF EXISTS instructor_instructor_id_seq;
CREATE SEQUENCE instructor_instructor_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 START 11 CACHE 1;

CREATE TABLE "public"."instructor" (
    "instructor_id" integer DEFAULT nextval('instructor_instructor_id_seq') NOT NULL,
    "instructor_name" character varying(255) NOT NULL,
    CONSTRAINT "instructor_pkey" PRIMARY KEY ("instructor_id")
);

CREATE INDEX "idx_instructor_name_normalized" ON "public"."instructor" USING btree (lower(TRIM(BOTH FROM instructor_name)));

-- 4. SUBJECT TABLE & SEQUENCE
DROP TABLE IF EXISTS "subject" CASCADE;
DROP SEQUENCE IF EXISTS subject_subject_id_seq;
CREATE SEQUENCE subject_subject_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 START 14 CACHE 1;

CREATE TABLE "public"."subject" (
    "subject_id" integer DEFAULT nextval('subject_subject_id_seq') NOT NULL,
    "subject_code" character varying(50) NOT NULL,
    "subject_name" character varying(255) NOT NULL,
    "year_level" integer,
    "semester" smallint DEFAULT 1 NOT NULL,
    CONSTRAINT "subject_pkey" PRIMARY KEY ("subject_id")
);

-- 5. ROOM TABLE & SEQUENCE
DROP TABLE IF EXISTS "room" CASCADE;
DROP SEQUENCE IF EXISTS room_room_id_seq;
CREATE SEQUENCE room_room_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 START 39 CACHE 1;

CREATE TABLE "public"."room" (
    "room_id" integer DEFAULT nextval('room_room_id_seq') NOT NULL,
    "building" character varying(100) NOT NULL,
    "room_num" character varying(50) NOT NULL,
    CONSTRAINT "room_pkey" PRIMARY KEY ("room_id")
);

CREATE INDEX "idx_room_location_normalized" ON "public"."room" USING btree (lower(TRIM(BOTH FROM building)), lower(TRIM(BOTH FROM room_num)));

-- 6. SECTION TABLE & SEQUENCE
DROP TABLE IF EXISTS "section" CASCADE;
DROP SEQUENCE IF EXISTS section_section_id_seq;
CREATE SEQUENCE section_section_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 START 28 CACHE 1;

CREATE TABLE "public"."section" (
    "section_id" integer DEFAULT nextval('section_section_id_seq') NOT NULL,
    "section_name" character varying(50) NOT NULL,
    "is_open" boolean DEFAULT false NOT NULL,
    "subject_id" integer,
    "max_capacity" integer DEFAULT 0 NOT NULL,
    CONSTRAINT "section_pkey" PRIMARY KEY ("section_id"),
    CONSTRAINT "uq_section_subject_name" UNIQUE ("subject_id", "section_name")
);

CREATE INDEX "idx_section_subject" ON "public"."section" USING btree ("subject_id");

-- 7. SCHEDULE TABLE & SEQUENCE
DROP TABLE IF EXISTS "schedule" CASCADE;
DROP SEQUENCE IF EXISTS schedule_schedule_id_seq;
CREATE SEQUENCE schedule_schedule_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 START 48 CACHE 1;

CREATE TABLE "public"."schedule" (
    "schedule_id" integer DEFAULT nextval('schedule_schedule_id_seq') NOT NULL,
    "day_of_the_week" character varying(20) NOT NULL,
    "start_time" time without time zone NOT NULL,
    "end_time" time without time zone NOT NULL,
    "section_id" integer,
    CONSTRAINT "schedule_pkey" PRIMARY KEY ("schedule_id")
);

CREATE INDEX "idx_schedule_section_id" ON "public"."schedule" USING btree ("section_id");

-- 8. OFFERING TABLE & SEQUENCE
DROP TABLE IF EXISTS "offering" CASCADE;
DROP SEQUENCE IF EXISTS offering_offering_id_seq;
CREATE SEQUENCE offering_offering_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 START 48 CACHE 1;

CREATE TABLE "public"."offering" (
    "offering_id" integer DEFAULT nextval('offering_offering_id_seq') NOT NULL,
    "subject_id" integer,
    "room_id" integer,
    "instructor_id" integer,
    "max_capacity" integer NOT NULL,
    "schedule_id" integer,
    "section_id" integer,
    "class_type" character varying(20) DEFAULT 'Lecture' NOT NULL,
    CONSTRAINT "offering_pkey" PRIMARY KEY ("offering_id")
);

CREATE INDEX "idx_offering_schedule_id" ON "public"."offering" USING btree ("schedule_id");
CREATE INDEX "idx_offering_subject_id" ON "public"."offering" USING btree ("subject_id");

-- 9. PASSWORD RESET OTPS TABLE
DROP TABLE IF EXISTS "password_reset_otps" CASCADE;
CREATE TABLE "public"."password_reset_otps" (
    "email" text NOT NULL,
    "code_hash" text NOT NULL,
    "expires_at" timestamptz NOT NULL,
    "attempts" integer DEFAULT 0 NOT NULL,
    "created_at" timestamptz DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT "password_reset_otps_pkey" PRIMARY KEY ("email")
);

-- 10. ACCOUNT REGISTRATION OTPS TABLE
DROP TABLE IF EXISTS "account_otps" CASCADE;
CREATE TABLE "public"."account_otps" (
    "email" text NOT NULL,
    "student_id" text NOT NULL,
    "code_hash" text NOT NULL,
    "registration_data" jsonb NOT NULL,
    "expires_at" timestamptz NOT NULL,
    "attempts" integer DEFAULT 0 NOT NULL,
    "created_at" timestamptz DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT "account_otps_pkey" PRIMARY KEY ("email")
);

-- 11. PLOTTING TABLE & SEQUENCE
DROP TABLE IF EXISTS "plotting" CASCADE;
DROP SEQUENCE IF EXISTS plotting_plot_id_seq;
CREATE SEQUENCE plotting_plot_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 START 93 CACHE 1;

CREATE TABLE "public"."plotting" (
    "plot_id" integer DEFAULT nextval('plotting_plot_id_seq') NOT NULL,
    "student_id" character varying(50),
    "offering_id" integer,
    "status" character varying(50) NOT NULL,
    CONSTRAINT "plotting_pkey" PRIMARY KEY ("plot_id")
);

CREATE INDEX "idx_plotting_offering_id" ON "public"."plotting" USING btree ("offering_id");

-- 12. SCHEDULE SUBMISSION TABLE
DROP TABLE IF EXISTS "schedule_submission" CASCADE;
CREATE TABLE "public"."schedule_submission" (
    "student_id" character varying(50) NOT NULL,
    "is_submitted" boolean DEFAULT false NOT NULL,
    "submitted_at" timestamptz,
    CONSTRAINT "schedule_submission_pkey" PRIMARY KEY ("student_id")
);

-- ==========================================================
-- TRIGGER FUNCTION & TRIGGER
-- ==========================================================
CREATE OR REPLACE FUNCTION public.close_full_section() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    enrolled_count INTEGER;
    section_capacity INTEGER;
    target_section_id INTEGER;
BEGIN
    SELECT o.max_capacity, sc.section_id
    INTO section_capacity, target_section_id
    FROM offering o
    JOIN schedule sc ON sc.schedule_id = o.schedule_id
    WHERE o.offering_id = NEW.offering_id;

    SELECT COUNT(*)
    INTO enrolled_count
    FROM plotting
    WHERE offering_id = NEW.offering_id;

    IF section_capacity IS NOT NULL
       AND enrolled_count >= section_capacity THEN
        UPDATE section
        SET is_open = FALSE
        WHERE section_id = target_section_id;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER "trg_close_full_section" 
    AFTER INSERT ON "public"."plotting" 
    FOR EACH ROW 
    EXECUTE FUNCTION public.close_full_section();

-- ==========================================================
-- FOREIGN KEY CONSTRAINTS
-- ==========================================================
ALTER TABLE ONLY "public"."offering" ADD CONSTRAINT "offering_instructor_id_fkey" FOREIGN KEY (instructor_id) REFERENCES instructor(instructor_id) ON DELETE CASCADE;
ALTER TABLE ONLY "public"."offering" ADD CONSTRAINT "offering_room_id_fkey" FOREIGN KEY (room_id) REFERENCES room(room_id) ON DELETE CASCADE;
ALTER TABLE ONLY "public"."offering" ADD CONSTRAINT "offering_schedule_id_fkey" FOREIGN KEY (schedule_id) REFERENCES schedule(schedule_id) ON DELETE CASCADE;
ALTER TABLE ONLY "public"."offering" ADD CONSTRAINT "offering_section_id_fkey" FOREIGN KEY (section_id) REFERENCES section(section_id) ON DELETE CASCADE;
ALTER TABLE ONLY "public"."offering" ADD CONSTRAINT "offering_subject_id_fkey" FOREIGN KEY (subject_id) REFERENCES subject(subject_id) ON DELETE CASCADE;

ALTER TABLE ONLY "public"."plotting" ADD CONSTRAINT "plotting_offering_id_fkey" FOREIGN KEY (offering_id) REFERENCES offering(offering_id) ON DELETE CASCADE;
ALTER TABLE ONLY "public"."plotting" ADD CONSTRAINT "plotting_student_id_fkey" FOREIGN KEY (student_id) REFERENCES account(student_id) ON DELETE CASCADE;

ALTER TABLE ONLY "public"."schedule" ADD CONSTRAINT "schedule_section_id_fkey" FOREIGN KEY (section_id) REFERENCES section(section_id) ON DELETE CASCADE;

ALTER TABLE ONLY "public"."schedule_submission" ADD CONSTRAINT "schedule_submission_student_id_fkey" FOREIGN KEY (student_id) REFERENCES account(student_id) ON DELETE CASCADE;

ALTER TABLE ONLY "public"."section" ADD CONSTRAINT "section_subject_id_fkey" FOREIGN KEY (subject_id) REFERENCES subject(subject_id) ON DELETE CASCADE;