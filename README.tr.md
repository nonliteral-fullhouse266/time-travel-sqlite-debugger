# ⏱️ Time-Travel SQLite Debugger (Veritabanı Zaman Makinesi)

Lokal geliştirme ortamında bozulan veya istenmeyen değişiklik yapılan bir **SQLite** veritabanı dosyasını, tıpkı bir videoyu geri sarar gibi saniyeler içinde eski bir state'ine (örneğin 3 dakika öncesine) döndüren açık kaynaklı, ultra hafif web uygulaması ve CLI dinleyicisi.

> 🇬🇧 **English Documentation:** For English README, please see [README.md](README.md).

---

**​⚠️ Mimari ve SQLite Üzerine Önemli Not**

**​Sadece Yerel Geliştirme (Local Development) İçindir:** Bu araç, tek kullanıcılı hata ayıklama (debugging) süreçleri için sıfır bağımlılığa sahip (zero-dependency), tak-çalıştır bir görsel zaman kaydırıcısı olarak tasarlanmıştır. Özel PHP eklentilerine veya karmaşık .backup komutlarına ihtiyaç duymadan anında "zamanda yolculuk" yapabilmek için doğrudan standart dosya kopyalama/yeniden adlandırma mekanizmasını kullanır.
​Yerel bir hata ayıklama ortamında eşzamanlı yazma işlemleri (concurrent writes), yarış durumları (race conditions) veya aktif trafik yaşanmadığı için, bu dosya kopyalama yöntemi bu kullanım senaryosunda tamamen güvenlidir.
**​Bu aracı veya sahip olduğu dosya kopyalama mantığını kesinlikle canlı sunucu (production) ortamında kullanmayın.** Özellikle SQLite veritabanınız WAL (Write-Ahead Logging) modunda çalışıyorsa, aktif işlemler sırasında canlı veritabanı dosyalarını kopyalamak veri bozulmasına (corruption) yol açacaktır.

---

## 🚀 Özellikler

- **⚡ Sıfır Bağımlılık (Zero Dependencies):** Herhangi bir ağır PHP framework veya npm paketi gerektirmez. Saf PHP 8+ ve Vanilla JS.
- **💾 WAL Checkpoint & Veri Bütünlüğü (Core Integrity):** Snapshot alınmadan hemen önce `PRAGMA wal_checkpoint(TRUNCATE);` komutunu çalıştırarak tüm WAL verisini ana `.sqlite` dosyasına yazar. Race condition riskini sıfırlar, tekil ve tutarlı `.sqlite` dosyası yedekler.
- **⏱️ Microtime & Benzersiz ID Kimliklendirme:** Snapshot isimlerinde `microtime(true)` ve `uniqid()` (`{timestamp}_{uniqid}_database.sqlite`) kullanarak aynı saniye içindeki çakışmaları engeller.
- **🛡️ Otomatik Güvenlik Ağı (Pre-Restore Safety Snapshot):** Kullanıcı restore işlemi yapmadan HEMEN ÖNCE mevcut aktif veritabanının otomatik yedeğini alır.
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

```bash
chmod -R 775 .
```

---

## 📄 Lisans

Bu proje **MIT** lisansı altında açık kaynak olarak sunulmuştur.

