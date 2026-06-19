# Nota Backup & Restore — Yapılacaklar

## Planlanan Özellikler

#### Yedek Test Et (Backup Verification)

**Amaç:** Backup tamamlandıktan sonra arşivin gerçekten restore edilebilir olduğunu doğrula.
Rakiplerin hepsinde "Backup tamamlandı" yazar. Bizde "Backup tamamlandı ve doğrulandı" yazacak.

**Seviye 1 — ZIP Doğrulama (server-side, saniyeler içinde)**
- ZIP açılabilir mi? (`ZipArchive::CHECKCONS` ile aç)
- Dosya bozuk mu? (CRC hatası var mı?)
- `wp-config.php`, `wp-content/`, `wp-includes/` ZIP içinde mevcut mu?
- `database.sql` ZIP içinde var mı ve ilk satırı `-- MySQL dump` ile başlıyor mu?

**Seviye 2 — İçerik Kontrolü**
- DB dump'ın son satırı düzgün mü? (`-- Dump completed` veya benzeri)
- ZIP içindeki dosya sayısı mantıklı mı? (tek dosyalı backup = muhtemelen bozuk)
- DB dump içinde `wp_options` tablosu ve `siteurl` var mı?

**Sonuç Rozetleri (backup geçmişinde gösterilir)**
- ✅ Doğrulandı — tüm kontroller geçti
- ⚠️ Uyarı — ZIP açıldı ama bazı kritik dosyalar eksik
- ❌ Bozuk — ZIP açılamıyor veya CRC hatası var

**Seviye 3 — WP Playground (isteğe bağlı, kullanıcı tetikler)**
- Backup history'de "Playground'da Test Et" butonu
- WP Playground API: `importWordPressFiles` + `importDatabase`
- Küçük siteler için çalışır (<50MB), büyük siteler için uyarı göster
- Tarayıcıda canlı WordPress çalıştırarak gerçek restore testi

**Yol Haritası**

| Versiyon | Kapsam | Not |
|---|---|---|
| v1 | Seviye 1+2 server-side, her backup sonrası otomatik | İlk implemente edilecek |
| v2 | Backup geçmişinde "Playground'da Test Et" butonu | v1 sonrası |
| v3 | Chunked büyük site desteği Playground için | Uzak gelecek |

**Teknik Notlar**
- Doğrulama sonucu `wpbn_backups` tablosuna yeni `verified` kolonu olarak kaydedilir (`null` = test yapılmadı, `1` = geçti, `0` = başarısız)
- Server-side test AJAX ile tetiklenir, yeni `wpbn_verify_backup` action
- Büyük ZIP'lerde sadece merkezi dizini oku (ZipArchive stream seek), tüm dosyaları açma

#### Tek Dosya / Tek Tablo Restore
Tüm siteyi restore etmeden sadece seçilen dosyayı veya DB tablosunu geri getirir.
Kullanıcılar backup ZIP içinden istediği dosyayı veya tabloyu seçebilir.
(Rakiplerde sadece premium'da mevcut)

#### Olay Bazlı Zamanlama
Saate göre değil olaya göre backup tetikleme:
- Her post/sayfa yayınlandığında DB backup al
- Her eklenti/tema güncellemesinden önce otomatik backup al
- Yüklenen dosya sayısı X'i geçtiğinde backup al

#### WooCommerce Sipariş Koruma
Dosyalara dokunmadan sadece DB'yi saatlik yedekler.
WooCommerce sitelerinde sipariş kaybını önler.
Çok fazla dosya olmadığı için hızlı ve hafif çalışır.

#### SQLite Arşiv Formatı + Artımlı Güncelleme (Update)
Mevcut ZIP formatına ek olarak SQLite tabanlı arşiv formatı seçeneği. Ayarlardan seçilebilir.

**Arşiv Formatı Seçeneği (Settings)**
- ZIP (Standart) — varsayılan, tüm araçlarla açılır
- SQLite (Gelişmiş) — artımlı güncelleme desteği, tek dosya restore, extractor.php ile açılır

**Artımlı Güncelleme — UX**
- Backup geçmişindeki actions dropdown'una "Update" butonu eklenir (yalnızca SQLite formatlı backuplarda aktif, ZIP'te grileşir)
- Tıklanınca modal açılır: son backup tarihi, tahmini güncelleme süresi, açıklama
- Kullanıcı onaylar → mevcut .db dosyası güncellenir (yeni full backup oluşmaz)

**Teknik Akış**
- Mevcut backup.db açılır, `files` tablosundaki path+hash listesi çekilir
- Şu anki dosya sistemi taranır, hash karşılaştırması yapılır
- Değişen → `UPDATE`, yeni → `INSERT`, siteden silinen → `DELETE`
- `meta` tablosu güncellenir (updated_at, wp_version, active_plugins)
- SQLite transaction ile sarılır — güncelleme yarıda kesilirse orijinal backup bozulmaz
- Backup listesinde iki tarih gösterilir: "Oluşturuldu: 1 Ocak | Güncellendi: 18 Haziran"

**SQLite Şeması**
```sql
CREATE TABLE files (
    path        TEXT PRIMARY KEY,
    hash        TEXT NOT NULL,
    size        INTEGER,
    compressed  BLOB,         -- gzdeflate() ile sıkıştırılmış
    is_compressed INTEGER DEFAULT 1,  -- jpg/png gibi zaten sıkıştırılmışlar için 0
    modified_at INTEGER,
    action      TEXT DEFAULT 'add'   -- 'add' | 'deleted'
);
CREATE TABLE db_dump (
    chunk_index INTEGER PRIMARY KEY,
    data        BLOB
);
CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT);
```

**Sıkıştırma**
- `gzdeflate($data, 6)` — ZIP ile aynı algoritma (DEFLATE), extension gerektirmez
- jpg, png, gif, webp, mp4, zip, gz gibi zaten sıkıştırılmış dosyalar ham saklanır (`is_compressed = 0`)

**Şifreleme (AES-256-CBC, BLOB Seviyesinde)**
- Her dosyanın compressed BLOB'u ayrı ayrı şifrelenir — tüm dosyayı şifrelemek artımlı güncellemeyi bozar
- `openssl_encrypt($compressed, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv)` — OpenSSL, shared hosting'de standarт
- Her dosya için rastgele IV üretilir, `iv` kolonunda saklanır
- Anahtar türetme mevcut ZIP şifrelemeyle aynı — kullanıcı tek şifre girer, her iki formatta çalışır
- Şema: `files` tablosuna `iv TEXT` kolonu, `meta` tablosuna `encrypted INTEGER DEFAULT 0`
- Restore: `openssl_decrypt()` → `gzinflate()` → diske yaz
- SQLCipher/SEE (tüm dosya şifreleme) elendi — shared hosting'de PHP extension kurulumu gerektirir

**Installer Entegrasyonu**
- Ayrı extractor.php yok — mevcut installer.php SQLite formatını da destekler
- Installer açılışta arşiv tipini otomatik tespit eder (backup.db mi, backup.zip mi)
- PDO_SQLite varsa PDO ile okur, yoksa pure PHP binary reader ile okur (~300-400 satır, extension gerektirmez)
- Settings'te PDO_SQLite yoksa SQLite format seçeneği grileşir — kullanıcı hiç SQLite backup alamaz, installer sorunu yaşanmaz
- Mevcut backup'ların erişilebilirliği için pure PHP reader zorunlu: host PHP güncellemesiyle extension'ı devre dışı bırakabilir

#### Premium — SQLite Uyum Güncellemeleri
SQLite arşiv formatı eklendikten sonra premium tarafında güncellenmesi gereken bölümler.

**Emergency Recovery**
- Standalone sayfa, WordPress gerektirmez — ZIP'in yanı sıra .db dosyasını da okuyabilmeli
- PDO_SQLite kontrolü yapılır, yoksa pure PHP binary reader devreye girer
- Format otomatik tespit edilir (backup.db mi, backup.zip mi)

**Admin Panel Restore (`class-wpbn-restore-engine.php`)**
- Mevcut restore engine sadece ZIP destekliyor
- SQLite için ayrı akış: .db aç → dosyaları ABSPATH'e yaz → db_dump tablosunu içe aktar
- Format tespiti otomatik — kullanıcı seçim yapmasın

**Cloud Sync (GDrive, S3, Dropbox vb.)**
- Yükleme: .db dosyası mevcut ZIP akışıyla paralel olarak cloud'a yüklenebilmeli
- Cloud Pull (`class-wpbn-cloud-pull.php`): .db dosyası indirilip restore edilebilmeli
- Format tespiti otomatik

#### WP-CLI Komutları
Terminal üzerinden backup ve yönetim işlemleri yapmak için WP-CLI entegrasyonu.
Sunucu erişimi olan geliştiriciler ve otomasyon script'leri için.

**Planlanan komutlar:**
- `wp nota backup create` — Yeni backup başlatır (type: full/db/files)
- `wp nota backup list` — Backup geçmişini listeler
- `wp nota backup delete <id>` — Belirtilen backup'ı siler
- `wp nota backup verify <id>` — Backup ZIP'ini doğrular
- `wp nota settings get <key>` — Ayar değeri okur
- `wp nota settings set <key> <value>` — Ayar değeri yazar
