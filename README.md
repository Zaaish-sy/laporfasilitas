# LaporFasilitas: fondasi pengembangan (v0.3)

Platform pelaporan fasilitas umum **independen berbasis komunitas**, pilot Kota Samarinda.
**Bukan aplikasi resmi pemerintah**, tidak ada integrasi instansi, dan perbaikan tidak dijamin.

Repositori ini adalah **kontrak bersama**. Semua AI pengkode dan semua bagian aplikasi (Android, backend, web admin)
membangun terhadap berkas yang sama, bukan menafsirkan PRD sendiri-sendiri.

## Isi

| Lokasi | Fungsi | Sumber kebenaran untuk |
|---|---|---|
| `docs/openapi.yaml` | Kontrak API (42 path, 47 operasi) | Bentuk request/response, peran (`x-roles`), transisi (`x-transition`) |
| `db/schema.sql` | Skema PostgreSQL 16 | Struktur data dan constraint (idempotensi, satu klaim aktif, riwayat append-only) |
| `core/src/Domain/` | State machine status T-01..T-15 | Siapa boleh mengubah status apa, dan syaratnya |
| `core/src/Application/` | `AuthService`, `ReportTransitions`, `ReportService`, `AnalysisProcessor`, kontrak AI + stub (PHP murni + PDO) | Aturan akun/token, perubahan status atomik, modul laporan, pipeline analisis AI |
| `core/tests/` | Uji state machine dan uji integrasi ke PostgreSQL asli | Bukti perilaku di atas |
| `backend-overlay/` | Lapisan Laravel tipis (provider, middleware, controller, rute) | **Belum dijalankan.** Disalin ke proyek Laravel Anda |
| `docs/state-machine.mmd` | Diagram status (dihasilkan dari kode) | Jangan diedit tangan |
| `docs/DECISIONS.md` | Keputusan kerja, cakupan, jadwal, serah terima | Apa yang dibangun dan apa yang ditunda |
| `docs/SETUP-LARAVEL.md` | Langkah membuat proyek Laravel dan memasang overlay | |

`core/` sengaja tidak bergantung pada Laravel. Laravel hanya membungkusnya; aturan bisnis tinggal di satu tempat dan bisa diuji tanpa framework.

## Menjalankan pengecekan

```bash
bash tools/run-tests.sh        # semua pengecekan sekaligus
php core/bin/export-mermaid.php > docs/state-machine.mmd   # regenerasi diagram status
```

Uji integrasi memakai PostgreSQL asli dan membuat serta menghapus skema sementara sendiri
(env opsional: `LAPOR_TEST_DSN`, `LAPOR_TEST_USER`, `LAPOR_TEST_PASS`; bawaan `127.0.0.1`, db `lapor_dev`, user/pass `lapor`).

## Status verifikasi (jujur)

**Sudah dijalankan** (sandbox, PostgreSQL 16, PHP 8.3), semuanya lewat `bash tools/run-tests.sh`:

- State machine: 262 pernyataan lulus, termasuk matriks lengkap pasangan (dari, ke) yang tak terdefinisi.
- `AuthService`: 50 lulus (token hanya tersimpan sebagai hash, rotasi refresh, pencabutan sesi, anonimisasi akun).
- `ReportTransitions`: 39 lulus, termasuk **klaim serempak lewat dua proses terpisah** (kunci baris `SELECT ... FOR UPDATE`),
  atomisitas status + riwayat, dan pencegahan pemakaian ulang persetujuan buka kembali.
- `ReportService`: 79 lulus (validasi, draft idempoten, visibilitas, paginasi kursor tanpa duplikat/celah, alasan dan hasil AI hanya untuk pemilik/moderator).
- `AnalysisProcessor`: 28 lulus (AI mati -> Perlu Review, ambang null -> tidak ada yang lolos, auto-publish, area pilot, kirim ulang hanya menganalisis foto baru, balapan saat AI berjalan).
- Skema: dimuat tanpa galat; 11 uji constraint lulus. OpenAPI valid; enum status identik di OpenAPI, SQL, dan PHP.
- **Uji mutasi:** merusak kode inti satu per satu (hapus `FOR UPDATE`, draft terlihat orang lain, alasan moderasi bocor, paginasi `<=`,
  AI gagal diabaikan, lolos tanpa ambang, analisis ulang semua foto, area pilot diabaikan, dll.) membuat uji **gagal**. Uji tidak kosong.
- **Satu cacat desain ditemukan dan diperbaiki saat menulis uji:** dua flag publikasi otomatis yang bisa berbeda membuat laporan nyangkut
  diam-diam di `menunggu_analisis`. Kini flag hanya ada di `ReportTransitions`, dan galat transisi tak terduga tidak lagi ditelan.

**Belum ada / belum diverifikasi:**

- **`backend-overlay/` (Laravel) hanya di-lint, belum dijalankan**: Packagist tidak terjangkau dari sandbox, jadi Laravel tidak bisa dipasang di sini.
  Perlakukan sebagai titik awal; laporkan galat apa pun saat pertama kali dijalankan.
- Endpoint yang sudah punya controller (belum dijalankan): auth, `/me`, kategori, laporan (buat/ubah/foto/kirim/daftar/detail/riwayat/analisis), berkas foto.
  Komunitas, klaim/bukti, moderasi, dan admin masih berupa kontrak di OpenAPI.
- **AI masih stub.** Selama ambang belum ditetapkan dari evaluasi model (Keputusan #8), tidak ada laporan yang lolos otomatis; semuanya ke Perlu Review.
- Unggah foto di controller (deteksi MIME, simpan berkas) belum diuji; logika di balik `addImage` sudah. Metadata EXIF belum dihapus (Keputusan #20).
- Belum ada kode Android, web admin, atau layanan AI. Tidak ada yang dikompilasi.
- `docs/state-machine.mmd` belum dirender. Alamat server di OpenAPI masih placeholder.
- Pengiriman email verifikasi belum punya penyedia SMTP (lihat `docs/DECISIONS.md`).

## Aturan yang tidak boleh dilanggar siapa pun yang menulis kode

1. Klien **tidak pernah** mengirim status. Klien mengirim aksi; backend memanggil `ReportTransitions::transition()`, yang
   menghitung konteks dari database, mengunci baris laporan, mengubah status, dan menulis `status_history` dalam satu transaksi.
   **Jangan** `UPDATE reports SET status` langsung di tempat lain.
2. **Selesai hanya lewat T-12** (admin + bukti disetujui). Bukan karena waktu, jumlah konfirmasi, atau hasil AI.
3. **AI tidak mengubah status** dan tidak menghitung `verification_result`. Backend menghitungnya dari respons AI
   dan `threshold_version`. Jika AI gagal, laporan masuk Perlu Review (bukan lolos otomatis).
4. Volunteer **hanya merekomendasikan**. Tidak ada transisi moderasi untuk Volunteer.
5. Konfirmasi komunitas tidak mengubah status; pelapor tidak boleh mengonfirmasi laporannya sendiri.
6. Pencarian alamat hanya lewat tombol cari (bukan autocomplete) via proxy backend dengan cache dan batas 1 permintaan/detik.
   Penyedia peta dan geocoding dipisah dan bisa diganti.
7. Jangan menampilkan angka akurasi AI yang tidak pernah diukur. Jika model belum siap, gunakan stub yang selalu
   mengembalikan Needs Review dan laporkan sebagai keterbatasan.
8. Jangan menyimpan token atau rahasia mentah di database atau repositori; hanya hash.

## Pola untuk aksi yang juga mengubah tabel turunan (klaim, bukti, pengajuan)

```php
$pdo->beginTransaction();
try {
    $transitions->transition($reportId, ReportStatus::DalamPenanganan, $actor);  // bergabung, tidak commit sendiri
    // ... INSERT action_claims ...                                              // ubah tabel turunan SETELAH transisi
    $pdo->commit();
} catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
```
Untuk keputusan admin atas bukti atau pengajuan: tandai `approved` **dulu**, baru panggil `transition()` (guard membacanya), semuanya dalam satu transaksi.

## Pembagian tugas untuk AI pengkode (satu modul, satu tugas)

Berikan `docs/openapi.yaml`, `db/schema.sql`, dan `core/src/` sebagai konteks. Contoh bentuk perintah:

> Baca `docs/openapi.yaml`, `db/schema.sql`, `core/src/`. Implementasikan HANYA endpoint tag "Laporan" di Laravel
> (controller tipis di `backend-overlay/` sebagai contoh gaya). Jangan ubah kontrak. Semua perubahan status wajib lewat
> `ReportTransitions::transition()`. Tulis uji untuk 403 (peran/kepemilikan) dan 409 (transisi tidak sah) setiap endpoint.
> Jika kontrak tampak salah, berhenti dan laporkan, jangan menyesuaikan sendiri.

Urutan modul: (1) Auth + `/me` [inti teruji, controller belum dijalankan], (2) Laporan + gambar + submit + stub AI [inti teruji, controller belum dijalankan], (3) Admin keputusan,
(4) Daftar publik + peta + konfirmasi + flag, (5) Klaim + bukti + keputusan bukti, (6) Android: auth, buat laporan + Room,
(7) Android: daftar/peta/detail, (8) Web admin, (9) layanan AI.

**Jika kontrak perlu berubah:** ubah `openapi.yaml` dan/atau `schema.sql` lebih dulu, jalankan
`bash tools/run-tests.sh`, baru ubah kode.
