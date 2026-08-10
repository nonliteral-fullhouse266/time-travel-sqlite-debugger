# ⏱️ Time-Travel SQLite Debugger (Veritabanı Zaman Makinesi)

Lokal geliştirme ortamında bozulan veya istenmeyen değişiklik yapılan bir **SQLite** veritabanı dosyasını, tıpkı bir videoyu geri sarar gibi saniyeler içinde eski bir state'ine (örneğin 3 dakika öncesine) döndüren açık kaynaklı, ultra hafif web uygulaması ve CLI dinleyicisi.

> 🇬🇧 **English Documentation:** For English README, please see [README.md](README.md).

---

**⚠️ Mimari ve Yerel Geliştirme Üzerine Önemli Not**

- **Sadece Yerel Geliştirme (Local Development) İçindir:** Bu araç, sıfır bağımlılığa sahip (zero-dependency) yerel geliştirme ve hata ayıklama süreçleri için tasarlanmıştır.
- **WAL Checkpoint & İptal Koruması (Abort):** Herhangi bir snapshot alınmadan veya restore yapılmadan hemen önce araç PDO üzerinden `PRAGMA wal_checkpoint(TRUNCATE);` komutunu çalıştırır. WAL checkpoint başarısız olursa (örneğin veritabanı kilitliyse), tutarsız veri saklamamak veya yüklememek için snapshot alma ya da restore işlemi derhal iptal edilir (abort). Checkpoint başarılı olduğunda tüm WAL verileri ana `.sqlite` dosyasına yazılır ve ayrı `-wal`/`-shm` kopyalarına ihtiyaç duyulmaz.
- **Güvenlik Ağı ve İptal Mekanizması:** Restore öncesinde aktif veritabanının otomatik `pre-restore-safety-snapshot` yedeği alınır. Güvenlik yedeği alınamazsa aktif veriyi korumak adına restore işlemi derhal iptal edilir (abort). Restore sonrasında ise `PRAGMA integrity_check;` ile veritabanı bütünlüğü doğrulanır.
- **Lokal IP Güvenliği ve Limitleme:** API erişimi istemcinin doğrudan soket IP adresi (`127.0.0.1` / `::1`) üzerinden doğrulanır. Klasör depolaması 50 dosya limiti ve 1 GB üst sınırı ile otomatik yönetilir.

---

## 🚀 Özellikler

- **⚡ Sıfır Bağımlılık (Zero Dependencies):** Herhangi bir ağır PHP framework veya npm paketi gerektirmez. Saf PHP 8+ ve Vanilla JS.
- **💾 WAL Checkpoint & Veri Bütünlüğü (Core Integrity):** Snapshot alınmadan hemen önce `PRAGMA wal_checkpoint(TRUNCATE);` çalıştırarak WAL verisini ana dosyaya yazar. Checkpoint başarısız olursa snapshot işlemini derhal iptal eder (abort).
- **⏱️ Microtime & Benzersiz ID Kimliklendirme:** Snapshot isimlerinde `microtime(true)` ve `uniqid()` (`{timestamp}_{uniqid}_database.sqlite`) kullanarak aynı saniye içindeki çakışmaları engeller.
- **🛡️ Otomatik Güvenlik Ağı (Pre-Restore Safety Snapshot):** Restore yapmadan HEMEN ÖNCE aktif veritabanının otomatik yedeğini alır. Güvenlik yedeği alınamazsa restore işlemini iptal eder (abort).
- **✅ Restore Sonrası Doğrulama (PRAGMA integrity_check):** Restore bittiğinde PDO ile veritabanına bağlanıp `PRAGMA integrity_check;` komutunu çalıştırır ve sonucu arayüze döner.
- **🔍 Görsel Veri & Tablo Farkı İnceleme (Diff Viewer):** Zaman yolculuğu yapmadan önce o geçmiş an ile canlı veritabanı arasındaki tablo ve satır farklarını (`+3 satır (Canlıda)`) gösterir.
- **⚠️ Uygulama Worker Yeniden Başlatma Uyarısı:** Restore sonrasında kullanıcıya çalışan uygulamasını (örn. Laravel/PHP worker) yeniden başlatması gerektiğini belirgin bir uyarı mesajıyla bildirir.
- **📌 Snapshot İğneleme & Sabitleme:** Temizlik limitinden etkilenmesini istemediğiniz kritik snapshot'ları tek tıkla iğneleyerek süresiz saklayabilirsiniz. İğnelenmiş (pinned) snapshot'lar otomatik silinmeden muaf tutulduğu için toplam dosya sayısı nominal 50 limitini bilinçli olarak aşabilir.
- **📥 Tek Tıkla Snapshot İndirme:** İstediğiniz geçmiş veritabanı durumunu `.sqlite` dosyası olarak bilgisayarınıza indirebilirsiniz.
- **🧹 İki Kademeli Depolama Limiti:** Maksimum **50 iğnelenmemiş yedek sayısı** VE **1 GB toplam klasör boyutu** limitlerini uygulayarak en eski iğnelenmemiş snapshot'ları otomatik temizler.
- **🔒 Lokal IP Güvenlik Kontrolü:** `api.php` isteklerini istemcinin doğrudan soket IP'si (`REMOTE_ADDR`: `127.0.0.1` veya `::1`) üzerinden doğrular; manipüle edilebilen proxy header'larını (`X-Forwarded-For`, `X-Real-IP`) kasıtlı olarak dikkate almaz.
- **🌐 Çoklu Dil Desteği (i18n):** Dil dosyaları `lang/` altında bağımsızdır (🇹🇷 Türkçe, 🇬🇧 English). Arayüzden dinamik dil değiştirilebilir.

---

## 📁 Proje Yapısı

```
time-travel/
├── index.html        # Chrono Console UI (Tailwind CSS + Vanilla JS + i18n Engine)
├── api.php           # Snapshot listeleme, restore, WAL checkpoint, integrity check ve API
├── watcher.php       # CLI arka plan dinleyici daemon scripti (WAL Checkpoint & Depolama Quota destekli)
├── lang.php          # Çoklu dil (i18n) kütüphanesi
├── lang/             # Dil JSON dosyaları dizini
│   ├── tr.json       # Türkçe dil dosyası
│   └── en.json       # İngilizce dil dosyası
├── database.sqlite   # Hedef SQLite veritabanı (otomatik oluşur)
├── backups/          # Zaman damgalı yedeklerin tutulduğu klasör (otomatik oluşur)
├── README.md         # Orijinal İngilizce dokümantasyon
└── README.tr.md      # Türkçe dokümantasyon & rehber
```

---

## 🌍 Dil Dosyalarını Güncelleme ve Yeni Dil Ekleme Rehberi

### 1. Yeni Dil Ekleme (Creating a New Translation)
Projeyi farklı bir dile çevirmek için `lang/` klasörü içerisine hedef dil kodunda bir JSON dosyası oluşturun (Örn: `lang/es.json`, `lang/de.json`):

```json
{
  "code": "es",
  "name": "Español",
  "flag": "🇪🇸",
  "cli": { ... },
  "api": { ... },
  "ui":  { ... }
}
```

### 2. Mevcut Dil Dosyalarını Güncelleme
Mevcut bir dil dosyasında (`lang/tr.json` veya `lang/en.json`) metin düzenlemek için dosyayı açıp ilgili key altındaki metinleri değiştirin. Değişiklikler anında geçerli olur (sunucu yeniden başlatma gerekmez).

---

## 💻 Kurulum ve Çalıştırma

### 1. Watcher (Arka Plan Dinleyicisi) Başlatma
```bash
php watcher.php
```
*Farklı bir dil seçeneğiyle (Örn: İngilizce) çalıştırmak isterseniz:*
```bash
php watcher.php database.sqlite en
```

### 2. Web Arayüzünü Başlatma
```bash
php -S 127.0.0.1:8000
```

### 3. Arayüze Erişme
Tarayıcınızda açın: **`http://127.0.0.1:8000`**

---

## 🧪 Nasıl Test Edilir?

1. Web arayüzünü açın.
2. **"✍️ Test Verisi Ekle (DB Write)"** butonuna tıklayın.
3. Terminalde `watcher.php` çıktısını gözlemleyin:
   `[+] Created snapshot: 1770752711.1234_65c3b1a209e8f_database.sqlite`
4. Zaman çubuğunu geriye sürükleyin veya **⬅️ / ➡️** ok tuşlarını kullanın.
5. **"🔍 Fark İncele"** butonuna basarak tablo satır değişikliklerini görün.
6. **"⚡ Bu State'e Geri Dön"** butonuna basarak veritabanınızı geçmişe döndürün! Otomatik pre-restore yedeğini, `PRAGMA integrity_check` doğrulama sonucunu ve worker uyarısını gözlemleyin.

---

## 🔒 Dosya ve İzin Yönetimi (Linux / Unix)

Veritabanı ve kaynak kod dosyalarına gereksiz çalıştırma (`+x`) yetkisi vermeden lokal ortamda temiz dosya izinlerini tanımlamak için:

```bash
# Klasör izinleri (775)
find . -type d -exec chmod 775 {} +

# Dosya izinleri (664)
find . -type f -exec chmod 664 {} +
```

---

## 📄 Lisans

Bu proje **MIT** lisansı altında açık kaynak olarak sunulmuştur.

