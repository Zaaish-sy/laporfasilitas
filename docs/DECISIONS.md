# Keputusan kerja LaporFasilitas

Status: **usulan bawaan, belum dikonfirmasi pemilik proyek.** Ubah baris mana pun; jika berubah, perbarui
`openapi.yaml` / `schema.sql` / state machine terlebih dahulu (lihat README).

## 1. Fakta (dari PRD v1.0 dan catatan pemilik)

- Independen berbasis komunitas, bukan aplikasi resmi pemerintah; perbaikan tidak dijamin; tidak ada integrasi instansi.
- Pilot Samarinda. Android (Kotlin, Compose, MVVM + Repository, Room) + web admin + backend + layanan AI.
- AI hanya pemeriksaan awal visual; confidence bukan persentase kebenaran; ambang dari pengujian, bukan tebakan.
- Nominatim (geocoding) dan peta dipisah, bisa diganti; Nominatim publik: maks. 1 permintaan/detik, tanpa autocomplete, perlu cache.
- Room tetap dipakai (draft, cache, antrean sinkronisasi).
- Pengunggahan ke Google Play diurus **prodi**. Tenggat 29 November 2026 belum pasti. Ini juga tugas akhir semester 5.

## 2. Keputusan kerja (usulan)

| # | Keputusan | Pilihan | Dampak bila diubah |
|---|---|---|---|
| 1 | Publikasi otomatis | **Mati.** Semua laporan lewat admin. Flag `autoPublishEnabled` dinyalakan hanya setelah model terbukti layak | Hanya konfigurasi (T-02) |
| 2 | Hak volunteer | Hanya rekomendasi (F7) | Tambah transisi bila volunteer boleh memutuskan |
| 3 | Status "Perlu Perbaikan" | Ada (A-03); beda dari "Ditolak" | Skema + state machine |
| 4 | Dipublikasikan dan Menunggu Tindak Lanjut | Berurutan otomatis (T-07) | Hapus T-07 jika digabung |
| 5 | Arti "Menunggu Konfirmasi" | Menunggu pemeriksaan bukti oleh admin (rekomendasi volunteer opsional) | Guard T-12 |
| 6 | Buka kembali | Buka kembali laporan lama; kolom `parent_report_id` disiapkan untuk laporan lanjutan | Alur UC-17 |
| 7 | Klaim ganda | Satu klaim aktif per laporan (indeks unik parsial). Batas waktu klaim **belum diterapkan** | T-09 oleh sistem |
| 9 | Akses publik | Wajib login | Endpoint publik |
| 11 | Komentar | Tidak ada; cukup konfirmasi | Entitas baru |
| 12 | Infrastruktur | Laravel + PostgreSQL (PRD); admin sebagai halaman web di aplikasi yang sama; foto di penyimpanan server; **tanpa PostGIS** (lat/lon + bounding box) | Skema lokasi |
| 13 | Notifikasi | Polling dalam aplikasi; FCM ditunda | Endpoint device |
| 14 | Login | Email + password, token bearer opak (hanya hash disimpan) dengan refresh token yang dirotasi. Masa berlaku usulan: access 1 jam, refresh 30 hari (#18 terbuka) | Auth |
| 15 | Verifikasi email | Tabel dan alur ada; **belum ada penyedia SMTP**. Untuk uji tertutup, syarat verifikasi dimatikan (`LAPOR_REQUIRE_EMAIL_VERIFICATION=false`) agar 12 tester tidak terblokir | Keputusan penyedia email |

Keputusan yang **belum** bisa diputuskan sendiri (butuh data atau pihak lain): penyedia email/SMTP; kategori dan model AI beserta ambangnya;
batas ukuran/jumlah foto dan angka rate limit; retensi foto dan data; ketentuan nama pengembang di toko (prodi).

## 3. Cakupan

- **Tingkat A (wajib demo):** registrasi/login; buat laporan (foto, kategori, GPS atau titik manual); draft Room + antrean kirim;
  hasil AI (nyata atau stub); antrean review dan keputusan admin; daftar publik, peta, detail; konfirmasi komunitas;
  klaim, bukti, Selesai; **flag konten** dan **hapus akun** (syarat umum Google Play untuk aplikasi berkonten pengguna dan berakun; verifikasi di Play Console).
- **Tingkat B (jika waktu ada):** rekomendasi volunteer, pengajuan buka kembali, statistik sederhana.
- **Tingkat C (ditunda, dicatat sebagai batasan):** FCM, PostGIS, deteksi duplikat otomatis, audit log lengkap,
  penyedia tile produksi, laporan lanjutan.

## 4. Jadwal kerja (tenggat kerja 29 November; konfirmasi tenggat asli ke dosen/prodi)

| Tanggal | Target |
|---|---|
| 14-15 Nov | Bekukan keputusan, repo, kontrak (berkas ini), deploy backend publik ber-HTTPS |
| 16-18 Nov | Auth, buat laporan Android + Room, upload foto, stub AI |
| 19-21 Nov | Admin: review dan publikasi; daftar, peta, konfirmasi. **21 Nov: titik uji ujung ke ujung** |
| 22-25 Nov | Klaim, bukti, Selesai; integrasi AI (nyata atau stub); perbaikan sinkronisasi |
| 26 Nov | **Pembekuan fitur** |
| 27-28 Nov | Perbaikan bug, paket serah terima prodi, dokumen |
| 29 Nov | Cadangan; tidak ada pekerjaan baru |

## 5. Pertanyaan untuk prodi (kirim hari ini)

1. Jenis dan tanggal pembuatan akun Play Console prodi (personal/organisasi)? Akun personal yang dibuat setelah 13 Nov 2023 wajib closed test dengan minimal 12 tester selama 14 hari sebelum produksi.
2. Siapa merekrut 12 tester (jika berlaku) dan kapan bisa mulai?
3. Batas terakhir menerima berkas siap unggah?
4. Nama pengembang yang tampil di toko dan kontak resmi untuk kebijakan privasi dan penghapusan data?
5. Siapa yang menanggung server backend setelah serah terima, dan sampai kapan?

## 6. Paket serah terima untuk prodi

AAB bertanda tangan (Play App Signing; kunci unggah jangan dikirim lewat chat) dengan nama paket final, `versionCode`, `versionName`;
akun uji reviewer + langkah singkat; URL kebijakan privasi; lembar jawaban Data Safety; lembar rating konten;
alur dan URL penghapusan akun; aset toko (ikon, feature graphic, tangkapan layar, deskripsi dengan penafian "bukan aplikasi resmi pemerintah");
catatan rilis; dokumen teknis server (alamat, pemulihan, kontak); repositori kode dan dokumen analisis.
