# LaporFasilitas: fondasi pengembangan (v0.1)

Platform pelaporan fasilitas umum **independen berbasis komunitas**, pilot Kota Samarinda.
**Bukan aplikasi resmi pemerintah**, tidak ada integrasi instansi, dan perbaikan tidak dijamin.

Berkas ini adalah **kontrak bersama**. Semua AI pengkode dan semua bagian aplikasi (Android, backend, web admin)
harus membangun terhadap berkas yang sama, bukan menafsirkan PRD sendiri-sendiri.

## Isi

| Berkas | Fungsi | Sumber kebenaran untuk |
|---|---|---|
| `docs/openapi.yaml` | Kontrak API (41 path, 46 operasi) | Bentuk request/response, peran (`x-roles`), transisi (`x-transition`) |
| `db/schema.sql` | Skema PostgreSQL 16 | Struktur data, constraint (idempotensi, satu klaim aktif, riwayat append-only) |
| `backend/app/Domain/ReportStateMachine.php` | Aturan transisi status T-01..T-15 | Siapa boleh mengubah status apa, dan syaratnya |
| `docs/state-machine.mmd` | Diagram status (dihasilkan dari kode) | Jangan diedit tangan; regenerasi dengan perintah di bawah |
| `docs/DECISIONS.md` | Keputusan kerja, cakupan, jadwal, paket serah terima | Apa yang dibangun dan apa yang ditunda |

## Menjalankan pengecekan

```bash
php backend/tests/StateMachineTest.php                      # uji state machine (tanpa Composer)
python3 tools/check_parity.py                               # OpenAPI = SQL = PHP (enum status, ID transisi)
psql -v ON_ERROR_STOP=1 -d <db_kosong> -f db/schema.sql     # buat skema
psql -v ON_ERROR_STOP=1 -d <db_kosong> -f db/tests/constraints.sql   # uji constraint (rollback otomatis)
php backend/bin/export-mermaid.php > docs/state-machine.mmd # regenerasi diagram status
```

## Status verifikasi (jujur)

Sudah dijalankan, hasil di sandbox saat dibuat:

- State machine: 262 pernyataan lulus, termasuk matriks lengkap pasangan (dari, ke) yang tak terdefinisi (99 pasangan, semua 409).
  Uji mutasi: melonggarkan guard Selesai atau memberi hak publikasi ke pengguna membuat uji **gagal** (uji tidak kosong).
- Skema: dimuat di PostgreSQL 16 tanpa galat; 11 uji constraint lulus.
- OpenAPI: lolos validator `openapi-spec-validator`; enum status identik di OpenAPI, SQL, dan PHP.

**Belum ada / belum diverifikasi:**

- Proyek Laravel belum dibuat; kelas domain di sini murni PHP dan diharapkan dipakai ulang di dalamnya.
- Belum ada kode Android (Kotlin/Compose/Room) maupun layanan AI. Tidak ada yang dikompilasi.
- `docs/state-machine.mmd` belum dirender; sintaksnya sederhana tetapi belum dicek dengan Mermaid.
- Alamat server di OpenAPI masih placeholder.

## Aturan yang tidak boleh dilanggar siapa pun yang menulis kode

1. Klien **tidak pernah** mengirim status. Klien mengirim aksi; backend menghitung `TransitionContext` dari database
   lalu memanggil `ReportStateMachine::apply()`. Setiap transisi berhasil menulis `status_history` dalam transaksi yang sama.
2. **Selesai hanya lewat T-12** (admin + bukti disetujui). Bukan karena waktu, jumlah konfirmasi, atau hasil AI.
3. **AI tidak mengubah status** dan tidak menghitung `verification_result`. Backend menghitungnya dari respons AI
   dan `threshold_version`. Jika AI gagal, laporan masuk Perlu Review (bukan lolos otomatis).
4. Volunteer **hanya merekomendasikan**. Tidak ada transisi moderasi untuk Volunteer.
5. Konfirmasi komunitas tidak mengubah status; pelapor tidak boleh mengonfirmasi laporannya sendiri.
6. Pencarian alamat hanya lewat tombol cari (bukan autocomplete) via proxy backend dengan cache dan batas 1 permintaan/detik.
   Penyedia peta dan geocoding dipisah dan bisa diganti.
7. Jangan menampilkan angka akurasi AI yang tidak pernah diukur. Jika model belum siap, gunakan stub yang selalu
   mengembalikan Needs Review dan laporkan sebagai keterbatasan.

## Pembagian tugas untuk AI pengkode (satu modul, satu tugas)

Setiap tugas diberi `docs/openapi.yaml` + `db/schema.sql` + berkas ini sebagai konteks. Contoh bentuk perintah:

> Baca `docs/openapi.yaml`, `db/schema.sql`, `backend/app/Domain/ReportStateMachine.php`.
> Implementasikan HANYA endpoint tag "Laporan" (createReport, submitReport, ...) di Laravel.
> Jangan ubah kontrak. Semua perubahan status wajib lewat `ReportStateMachine::apply()` dan menulis
> `status_history`. Tulis uji untuk 403 (peran/kepemilikan) dan 409 (transisi tidak sah) setiap endpoint.
> Jika kontrak tampak salah, berhenti dan laporkan, jangan menyesuaikan sendiri.

Urutan modul yang disarankan: (1) Auth + `/me`, (2) Laporan + gambar + submit + stub AI, (3) Admin keputusan,
(4) Daftar publik + peta + konfirmasi + flag, (5) Klaim + bukti + keputusan bukti, (6) Android: auth, buat laporan + Room,
(7) Android: daftar/peta/detail, (8) Web admin, (9) layanan AI.

**Jika kontrak perlu berubah:** ubah `openapi.yaml` dan/atau `schema.sql` lebih dulu, jalankan
`python3 tools/check_parity.py`, baru ubah kode.
