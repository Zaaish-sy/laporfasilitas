-- Uji constraint inti. Jalankan SETELAH schema.sql pada database kosong:
--   psql -v ON_ERROR_STOP=1 -f db/tests/constraints.sql
-- Semua dibungkus transaksi dan di-ROLLBACK; tidak meninggalkan data.
BEGIN;

CREATE TEMP TABLE _t (name text, ok boolean);

-- Data dasar
INSERT INTO users (id, email, password_hash, display_name, role) VALUES
    ('00000000-0000-0000-0000-000000000001', 'a@example.test', 'x', 'A', 'pengguna'),
    ('00000000-0000-0000-0000-000000000002', 'b@example.test', 'x', 'B', 'pengguna');
INSERT INTO reports (id, client_uuid, user_id, category_id, title) VALUES
    ('10000000-0000-0000-0000-000000000001', '20000000-0000-0000-0000-000000000001',
     '00000000-0000-0000-0000-000000000001', 1, 'Jalan berlubang');

-- 1. client_uuid yang sama dari pengguna yang sama ditolak (anti laporan ganda, A-08)
DO $$ BEGIN
    BEGIN
        INSERT INTO reports (client_uuid, user_id, category_id, title)
        VALUES ('20000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', 1, 'Duplikat');
        INSERT INTO _t VALUES ('client_uuid ganda ditolak', false);
    EXCEPTION WHEN unique_violation THEN
        INSERT INTO _t VALUES ('client_uuid ganda ditolak', true);
    END;
END $$;

-- 1b. client_uuid yang sama dari PENGGUNA LAIN boleh (kunci per pengguna)
DO $$ BEGIN
    BEGIN
        INSERT INTO reports (client_uuid, user_id, category_id, title)
        VALUES ('20000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', 1, 'Laporan B');
        INSERT INTO _t VALUES ('client_uuid sama, pengguna lain, diizinkan', true);
    EXCEPTION WHEN unique_violation THEN
        INSERT INTO _t VALUES ('client_uuid sama, pengguna lain, diizinkan', false);
    END;
END $$;

-- 2. Hanya satu klaim aktif per laporan (A-06)
INSERT INTO action_claims (report_id, user_id) VALUES
    ('10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002');
DO $$ BEGIN
    BEGIN
        INSERT INTO action_claims (report_id, user_id)
        VALUES ('10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001');
        INSERT INTO _t VALUES ('klaim aktif kedua ditolak', false);
    EXCEPTION WHEN unique_violation THEN
        INSERT INTO _t VALUES ('klaim aktif kedua ditolak', true);
    END;
END $$;

-- 2b. Setelah klaim dilepas, klaim baru boleh
UPDATE action_claims SET state = 'released', released_at = now()
 WHERE report_id = '10000000-0000-0000-0000-000000000001';
DO $$ BEGIN
    BEGIN
        INSERT INTO action_claims (report_id, user_id)
        VALUES ('10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001');
        INSERT INTO _t VALUES ('klaim baru setelah dilepas diizinkan', true);
    EXCEPTION WHEN unique_violation THEN
        INSERT INTO _t VALUES ('klaim baru setelah dilepas diizinkan', false);
    END;
END $$;

-- 3. status_history append-only
INSERT INTO status_history (report_id, from_status, to_status, transition_id)
VALUES ('10000000-0000-0000-0000-000000000001', NULL, 'draft', NULL);
DO $$ BEGIN
    BEGIN
        UPDATE status_history SET reason = 'ubah';
        INSERT INTO _t VALUES ('UPDATE status_history ditolak', false);
    EXCEPTION WHEN raise_exception THEN
        INSERT INTO _t VALUES ('UPDATE status_history ditolak', true);
    END;
    BEGIN
        DELETE FROM status_history;
        INSERT INTO _t VALUES ('DELETE status_history ditolak', false);
    EXCEPTION WHEN raise_exception THEN
        INSERT INTO _t VALUES ('DELETE status_history ditolak', true);
    END;
END $$;

-- 4. Konfirmasi ganda (laporan, pengguna, jenis) ditolak
INSERT INTO confirmations (report_id, user_id, type)
VALUES ('10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', 'juga_melihat');
DO $$ BEGIN
    BEGIN
        INSERT INTO confirmations (report_id, user_id, type)
        VALUES ('10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', 'juga_melihat');
        INSERT INTO _t VALUES ('konfirmasi ganda ditolak', false);
    EXCEPTION WHEN unique_violation THEN
        INSERT INTO _t VALUES ('konfirmasi ganda ditolak', true);
    END;
END $$;

-- 5. Koordinat di luar rentang ditolak
DO $$ BEGIN
    BEGIN
        INSERT INTO report_locations (report_id, lat, lon, source)
        VALUES ('10000000-0000-0000-0000-000000000001', 95, 117.1, 'gps');
        INSERT INTO _t VALUES ('lat di luar rentang ditolak', false);
    EXCEPTION WHEN check_violation THEN
        INSERT INTO _t VALUES ('lat di luar rentang ditolak', true);
    END;
END $$;

-- 6. Rekomendasi wajib berisi jenis rekomendasi, dan hanya untuk action_type 'rekomendasi'
DO $$ BEGIN
    BEGIN
        INSERT INTO moderation_actions (report_id, actor_id, action_type)
        VALUES ('10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', 'rekomendasi');
        INSERT INTO _t VALUES ('rekomendasi tanpa jenis ditolak', false);
    EXCEPTION WHEN check_violation THEN
        INSERT INTO _t VALUES ('rekomendasi tanpa jenis ditolak', true);
    END;
    BEGIN
        INSERT INTO moderation_actions (report_id, actor_id, action_type, recommendation)
        VALUES ('10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', 'publikasi', 'layak');
        INSERT INTO _t VALUES ('jenis rekomendasi pada aksi non-rekomendasi ditolak', false);
    EXCEPTION WHEN check_violation THEN
        INSERT INTO _t VALUES ('jenis rekomendasi pada aksi non-rekomendasi ditolak', true);
    END;
END $$;

-- 7. Foto yang sama (sha256) pada satu laporan tidak tersimpan dua kali (unggah idempoten)
INSERT INTO report_images (report_id, storage_key, sha256)
VALUES ('10000000-0000-0000-0000-000000000001', 'k1', repeat('a', 64));
DO $$ BEGIN
    BEGIN
        INSERT INTO report_images (report_id, storage_key, sha256)
        VALUES ('10000000-0000-0000-0000-000000000001', 'k2', repeat('a', 64));
        INSERT INTO _t VALUES ('foto ganda pada laporan ditolak', false);
    EXCEPTION WHEN unique_violation THEN
        INSERT INTO _t VALUES ('foto ganda pada laporan ditolak', true);
    END;
END $$;

-- Laporan hasil
SELECT CASE WHEN ok THEN 'LULUS ' ELSE 'GAGAL ' END || name AS hasil FROM _t;
DO $$ DECLARE bad int; BEGIN
    SELECT count(*) INTO bad FROM _t WHERE NOT ok;
    IF bad > 0 THEN RAISE EXCEPTION '% uji constraint gagal', bad; END IF;
END $$;

ROLLBACK;
