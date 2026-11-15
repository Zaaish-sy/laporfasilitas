# Memasang proyek Laravel + overlay

> **Peringatan jujur:** langkah dan berkas `backend-overlay/` ini **belum pernah dijalankan** (Laravel tidak bisa dipasang di
> lingkungan tempat berkas ini ditulis). Logika bisnisnya sudah diuji di `core/`; yang belum diuji hanya perekat Laravel.
> Jika ada galat, salin pesannya persis; kemungkinan besar perbaikannya kecil (versi Laravel, nama ekstensi, urutan migrasi).

## Prasyarat

- PHP 8.3+ dengan ekstensi **pdo_pgsql**, **pgsql**, mbstring, openssl, fileinfo. Di Windows: buka `php.ini`, hapus tanda `;` di depan
  `extension=pdo_pgsql`, `extension=pgsql`, `extension=mbstring`, `extension=openssl`, `extension=fileinfo`. Cek: `php -m`.
- Composer 2, PostgreSQL 16 (lokal), Git.

## 1. Database

Di `psql` (sebagai superuser postgres):

```sql
CREATE ROLE lapor LOGIN PASSWORD 'ganti-ini';
CREATE DATABASE lapor_dev OWNER lapor;
```

## 2. Buat proyek dan pasang overlay (dari akar repositori, PowerShell)

```powershell
composer create-project laravel/laravel backend
Copy-Item -Recurse -Force backend-overlay\* backend\
```

Folder `backend/` ikut di-commit, tetapi `backend/.env` dan `backend/vendor/` **tidak boleh** (pastikan `.gitignore` di akar repo memuat `.env` dan `vendor/`).

## 3. Hubungkan `core/` ke Laravel (autoload)

Buka `backend/composer.json`, bagian `autoload`, tambahkan dua baris ke `psr-4` (dua baris baru di bawah `"App\\"`):

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "App\\Domain\\": "../core/src/Domain/",
        "App\\Application\\": "../core/src/Application/",
        "Database\\Factories\\": "database/factories/",
        "Database\\Seeders\\": "database/seeders/"
    }
},
```

Lalu: `cd backend; composer dump-autoload`.

## 4. Hapus migrasi bawaan Laravel (bentrok dengan skema kita)

Skema berasal dari `db/schema.sql` (satu sumber kebenaran). Migrasi bawaan membuat tabel `users` sendiri dan akan bentrok.

```powershell
Remove-Item backend\database\migrations\0001_01_01_*
```

Yang tersisa hanya `2026_11_15_000000_load_lapor_schema.php`, yang memuat `db/schema.sql`.

## 5. `.env`

Ubah di `backend/.env`:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lapor_dev
DB_USERNAME=lapor
DB_PASSWORD=ganti-ini

SESSION_DRIVER=array
CACHE_STORE=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log

LAPOR_AUTO_PUBLISH=false
LAPOR_REQUIRE_EMAIL_VERIFICATION=false
# Placeholder (Keputusan #23): LAPOR_MAX_IMAGES=5, LAPOR_MAX_IMAGE_KB=5120
# Ambang AI: biarkan KOSONG sampai ada hasil evaluasi model (LAPOR_AI_PASS_MIN, LAPOR_AI_THRESHOLD_VERSION)
```

`SESSION_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION` diubah agar Laravel tidak mencari tabel `sessions`/`cache`/`jobs` yang tidak kita buat.

## 6. `bootstrap/app.php` dan `bootstrap/providers.php`

`bootstrap/providers.php`: tambahkan `App\Providers\LaporFasilitasServiceProvider::class,` ke dalam array.

`bootstrap/app.php` (struktur Laravel 11 ke atas; sesuaikan bila versi Anda berbeda):

```php
<?php

use App\Application\ApiException;
use App\Domain\TransitionException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/lapor_api.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Bentuk galat seragam sesuai docs/openapi.yaml: { code, message, details }
        $exceptions->render(fn (ApiException $e) => response()->json(
            ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => (object) $e->details], $e->httpStatus));
        $exceptions->render(fn (TransitionException $e) => response()->json(
            ['code' => $e->errorCode, 'message' => $e->getMessage()], $e->httpStatus));
    })->create();
```

## 7. Jalankan

```powershell
cd backend
php artisan migrate        # memuat db/schema.sql
php artisan serve          # http://127.0.0.1:8000
```

Cek cepat (PowerShell, terminal lain):

```powershell
$b = "http://127.0.0.1:8000/api/v1"
Invoke-RestMethod "$b/auth/register" -Method Post -ContentType "application/json" -Body '{"email":"uji@contoh.test","password":"password123","display_name":"Uji"}'
$t = Invoke-RestMethod "$b/auth/login" -Method Post -ContentType "application/json" -Body '{"email":"uji@contoh.test","password":"password123"}'
Invoke-RestMethod "$b/me" -Headers @{ Authorization = "Bearer $($t.access_token)" }
```

Uji laporan (lanjutan, setelah `$t` di atas; butuh satu berkas gambar `foto.jpg`):

```powershell
$h = @{ Authorization = "Bearer $($t.access_token)" }
$r = Invoke-RestMethod "$b/reports" -Method Post -Headers $h -ContentType "application/json" -Body '{"client_uuid":"11111111-1111-4111-8111-111111111111","category_id":1,"title":"Jalan berlubang","location":{"lat":-0.5,"lon":117.15,"source":"gps"}}'
curl.exe -s -H "Authorization: Bearer $($t.access_token)" -F "file=@foto.jpg" "$b/reports/$($r.id)/images"
Invoke-RestMethod "$b/reports/$($r.id)/submit" -Method Post -Headers $h
Invoke-RestMethod "$b/reports/$($r.id)" -Headers $h
```

Hasil yang benar: setelah `submit`, status berubah menjadi `perlu_review` (AI masih stub, jadi tidak ada yang lolos otomatis).
Mengirim `POST /reports` dua kali dengan `client_uuid` yang sama harus menjawab 200 dengan laporan yang sama, bukan membuat laporan baru.

Hasil yang benar untuk uji auth: register menampilkan pengguna dengan `email_verified: False`, login menampilkan `access_token`, dan `/me` menampilkan profil.
Tanpa token, `/me` harus menjawab 401 dengan `{ "code": "unauthorized", ... }`.

## Galat yang mungkin muncul (perkiraan, belum teruji)

| Gejala | Kemungkinan penyebab |
|---|---|
| `could not find driver` | `pdo_pgsql` belum diaktifkan di `php.ini` |
| `Class "App\Application\AuthService" not found` | Langkah 3 belum dilakukan, atau lupa `composer dump-autoload` |
| `relation "users" already exists` saat migrate | Langkah 4 belum dilakukan (migrasi bawaan masih ada) |
| `file_get_contents(...db/schema.sql)` gagal | Folder `backend/` tidak sejajar dengan `db/`; sesuaikan path di migrasi |
| Respons galat berbentuk HTML bukan JSON | Tambahkan header `Accept: application/json` atau cek langkah 6 |
