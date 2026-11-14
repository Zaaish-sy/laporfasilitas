-- LaporFasilitas: skema PostgreSQL (MVP)
-- Sumber: docs ERD + state machine. Tanpa PostGIS (Keputusan: lat/lon biasa + filter bounding box).
-- Nilai enum report_status HARUS sama dengan App\Domain\ReportStatus.
-- Jalankan: psql -v ON_ERROR_STOP=1 -f db/schema.sql

BEGIN;

-- ---------- Tipe ----------
CREATE TYPE app_role AS ENUM ('pengguna', 'volunteer', 'admin');

CREATE TYPE report_status AS ENUM (
    'draft', 'menunggu_analisis', 'perlu_review', 'perlu_perbaikan', 'ditolak',
    'dipublikasikan', 'menunggu_tindak_lanjut', 'dalam_penanganan',
    'menunggu_konfirmasi', 'selesai', 'dibuka_kembali'
);

CREATE TYPE ai_result AS ENUM ('passed', 'needs_review', 'rejected');
CREATE TYPE confirmation_type AS ENUM ('juga_melihat', 'masih_terjadi', 'tampak_diperbaiki');
CREATE TYPE claim_state AS ENUM ('active', 'released', 'completed');
CREATE TYPE review_state AS ENUM ('pending', 'approved', 'rejected');
CREATE TYPE recommendation_type AS ENUM ('layak', 'perbaikan', 'tidak_valid');
CREATE TYPE moderation_action_type AS ENUM
    ('rekomendasi', 'publikasi', 'tolak', 'minta_perbaikan', 'duplikat', 'takedown');
CREATE TYPE flag_state AS ENUM ('open', 'resolved');
CREATE TYPE location_source AS ENUM ('gps', 'manual');

-- ---------- Akun ----------
CREATE TABLE users (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email           text NOT NULL UNIQUE,            -- saat akun dihapus: dianonimkan, bukan dikosongkan
    password_hash   text NOT NULL,
    display_name    text NOT NULL,                   -- tampilan publik; jangan pakai email (PRD 9.2)
    role            app_role NOT NULL DEFAULT 'pengguna',
    email_verified_at timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    deleted_at      timestamptz                      -- anonimisasi; riwayat laporan tetap utuh
);

CREATE TABLE categories (
    id      serial PRIMARY KEY,
    name    text NOT NULL UNIQUE,
    active  boolean NOT NULL DEFAULT true            -- dinonaktifkan, tidak dihapus
);

-- ---------- Laporan ----------
CREATE TABLE reports (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    client_uuid      uuid NOT NULL,                  -- kunci idempotensi dari klien (A-08)
    user_id          uuid NOT NULL REFERENCES users(id),
    category_id      integer NOT NULL REFERENCES categories(id),
    title            text NOT NULL CHECK (char_length(title) BETWEEN 3 AND 150),
    description      text NOT NULL DEFAULT '',
    status           report_status NOT NULL DEFAULT 'draft',
    parent_report_id uuid REFERENCES reports(id),    -- laporan lanjutan (cadangan Keputusan #6)
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_reports_client UNIQUE (user_id, client_uuid)
);
CREATE INDEX ix_reports_status_created ON reports (status, created_at DESC);
CREATE INDEX ix_reports_category ON reports (category_id);
CREATE INDEX ix_reports_user ON reports (user_id, created_at DESC);

CREATE TABLE report_locations (
    report_id  uuid PRIMARY KEY REFERENCES reports(id) ON DELETE CASCADE,
    lat        double precision NOT NULL CHECK (lat BETWEEN -90 AND 90),
    lon        double precision NOT NULL CHECK (lon BETWEEN -180 AND 180),
    address    text,                                 -- hasil geocoding; boleh kosong
    source     location_source NOT NULL
);
CREATE INDEX ix_locations_lat_lon ON report_locations (lat, lon);   -- filter bounding box

CREATE TABLE report_images (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    report_id   uuid NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    storage_key text NOT NULL,
    sha256      char(64) NOT NULL,                   -- idempotensi unggah + bantu deteksi duplikat
    taken_at    timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_image_per_report UNIQUE (report_id, sha256)
);

CREATE TABLE ai_analyses (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    image_id            uuid NOT NULL REFERENCES report_images(id) ON DELETE CASCADE,
    predicted_class     text,
    confidence          double precision CHECK (confidence BETWEEN 0 AND 1),
    category_match      boolean,
    image_quality       text,
    verification_result ai_result NOT NULL,          -- DIHITUNG backend dari respons AI + ambang
    model_version       text NOT NULL,
    threshold_version   text NOT NULL,
    created_at          timestamptz NOT NULL DEFAULT now()
);

-- ---------- Riwayat status (append-only) ----------
CREATE TABLE status_history (
    id          bigserial PRIMARY KEY,
    report_id   uuid NOT NULL REFERENCES reports(id),
    from_status report_status,                        -- NULL hanya untuk pembuatan
    to_status   report_status NOT NULL,
    transition_id text,                               -- mis. 'T-12'
    actor_id    uuid REFERENCES users(id),            -- NULL = sistem
    reason      text,
    changed_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ix_history_report ON status_history (report_id, changed_at);

CREATE FUNCTION forbid_history_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'status_history bersifat append-only';
END $$;

CREATE TRIGGER trg_history_immutable
    BEFORE UPDATE OR DELETE ON status_history
    FOR EACH ROW EXECUTE FUNCTION forbid_history_change();

-- ---------- Komunitas ----------
CREATE TABLE confirmations (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    report_id  uuid NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    user_id    uuid NOT NULL REFERENCES users(id),
    type       confirmation_type NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_confirmation UNIQUE (report_id, user_id, type)
    -- Pelapor tidak dihitung konfirmasi independen (A-12): ditegakkan di aplikasi.
);

CREATE TABLE content_flags (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    report_id  uuid NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    user_id    uuid NOT NULL REFERENCES users(id),
    reason     text NOT NULL CHECK (char_length(reason) > 0),
    state      flag_state NOT NULL DEFAULT 'open',
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_flag_once UNIQUE (report_id, user_id)
);

-- ---------- Tindak lanjut ----------
CREATE TABLE action_claims (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    report_id  uuid NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    user_id    uuid NOT NULL REFERENCES users(id),
    state      claim_state NOT NULL DEFAULT 'active',
    claimed_at timestamptz NOT NULL DEFAULT now(),
    released_at timestamptz
);
-- Satu klaim aktif per laporan (A-06), ditegakkan di tingkat database.
CREATE UNIQUE INDEX uq_active_claim ON action_claims (report_id) WHERE state = 'active';

CREATE TABLE resolution_evidence (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    claim_id         uuid NOT NULL REFERENCES action_claims(id) ON DELETE CASCADE,
    photo_before_key text,
    photo_after_key  text NOT NULL,
    note             text,
    lat              double precision CHECK (lat BETWEEN -90 AND 90),
    lon              double precision CHECK (lon BETWEEN -180 AND 180),
    review_state     review_state NOT NULL DEFAULT 'pending',
    created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE reopen_requests (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    report_id    uuid NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    user_id      uuid NOT NULL REFERENCES users(id),
    photo_key    text NOT NULL,
    note         text,
    review_state review_state NOT NULL DEFAULT 'pending',
    created_at   timestamptz NOT NULL DEFAULT now()
);

-- ---------- Moderasi, notifikasi, audit ----------
CREATE TABLE moderation_actions (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    report_id      uuid NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    evidence_id    uuid REFERENCES resolution_evidence(id) ON DELETE CASCADE,  -- rekomendasi atas bukti
    actor_id       uuid NOT NULL REFERENCES users(id),
    action_type    moderation_action_type NOT NULL,
    recommendation recommendation_type,
    note           text,
    created_at     timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ck_recommendation CHECK ((action_type = 'rekomendasi') = (recommendation IS NOT NULL))
);

CREATE TABLE notifications (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type       text NOT NULL,
    report_id  uuid REFERENCES reports(id) ON DELETE CASCADE,
    body       text NOT NULL,
    read_at    timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ix_notifications_user ON notifications (user_id, created_at DESC);

CREATE TABLE audit_logs (
    id          bigserial PRIMARY KEY,
    actor_id    uuid REFERENCES users(id),
    action      text NOT NULL,
    target_type text NOT NULL,
    target_id   text NOT NULL,
    detail      jsonb,
    at          timestamptz NOT NULL DEFAULT now()
);

-- ---------- Data awal ----------
INSERT INTO categories (name) VALUES
    ('Kerusakan jalan'),
    ('Drainase'),
    ('Fasilitas umum');

COMMIT;
