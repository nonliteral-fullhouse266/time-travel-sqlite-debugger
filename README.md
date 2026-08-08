# ⏱️ Time-Travel SQLite Debugger (Veritabanı Zaman Makinesi)

Lokal geliştirme ortamında bozulan veya istenmeyen değişiklik yapılan bir **SQLite** veritabanı dosyasını, tıpkı bir videoyu geri sarar gibi saniyeler içinde eski bir state'ine (örneğin 3 dakika öncesine) döndüren açık kaynaklı, ultra hafif web uygulaması ve CLI dinleyicisi.

> 🇬🇧 **English Documentation:** For English README, please see [README.en.md](README.en.md).

---

## 🚀 Özellikler

- **⚡ Sıfır Bağımlılık (Zero Dependencies):** Herhangi bir ağır PHP framework veya npm paketi gerektirmez. Saf PHP 8+ ve Vanilla JS.
- **🌐 Çoklu Dil Desteği (i18n):** Dil dosyaları tamamen ayrılmıştır. Arayüzden tek tıkla dil değiştirilebilir (Örn: 🇹🇷 Türkçe, 🇬🇧 English). İnsanlar yeni dil dosyaları (`lang/xx.json`) ekleyerek projeyi kolayca çevirebilir.
- **💾 SQLite WAL Modu Desteği:** `PRAGMA journal_mode=WAL;` kullanılan veritabanlarında `.sqlite-wal` ve `.sqlite-shm` dosyalarını anlık kopyalar ve geri yükler.
- **🔍 Görsel Veri & Tablo Farkı İnceleme (Diff Viewer):** Zaman yolculuğu yapmadan önce o geçmiş an ile canlı veritabanı arasındaki tablo ve satır farklarını (`+3 satır (Canlıda)`) gösterir.
- **📌 Snapshot İğneleme & Sabitleme:** Temizlik limitinden etkilenmesini istemediğiniz kritik snapshot'ları tek tıkla iğneleyerek süresiz saklayabilirsiniz.
- **📥 Tek Tıkla Snapshot İndirme:** İstediğiniz geçmiş veritabanı durumunu `.sqlite` dosyası olarak bilgisayarınıza indirebilirsiniz.
- **⌨️ Klavye Yön Tuşları Desteği:** Klavye **Sol Ok (⬅️)** ve **Sağ Ok (➡️)** tuşlarıyla zaman çubuğunda milisaniyelik adımlarla gezinebilirsiniz.
- **🔄 Otomatik Anlık Snapshot (Watcher Daemon):** Arka planda çalışan `watcher.php` scripti `database.sqlite` dosyasındaki her değişikliği algılar ve anında zaman damgalı yedek (`backups/1715000000_database.sqlite`) oluşturur.
- **🧹 Otomatik Temizlik:** Dizinde varsayılan olarak maksimum **50 yedek** tutar, iğnelenmemiş eski snapshot'ları otomatik siler.

---

## 📁 Proje Yapısı

```
time-travel/
├── index.html        # Chrono Console UI (Tailwind CSS + Vanilla JS + i18n Engine)
├── api.php           # Snapshot listeleme, restore, diff, download ve i18n API
├── watcher.php       # CLI arka plan dinleyici daemon scripti (WAL & Signal destekli)
├── lang.php          # Çoklu dil (i18n) kütüphanesi
├── lang/             # Dil JSON dosyaları dizini
│   ├── tr.json       # Türkçe dil dosyası
│   └── en.json       # İngilizce dil dosyası
├── database.sqlite   # Hedef SQLite veritabanı (otomatik oluşur)
├── backups/          # Zaman damgalı yedeklerin tutulduğu klasör (otomatik oluşur)
├── README.md         # Türkçe kullanım ve kurulum rehberi
└── README.en.md      # English documentation & guide
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
   `[+] Created snapshot: 1715000000_database.sqlite`
4. Zaman çubuğunu geriye sürükleyin veya **⬅️ / ➡️** ok tuşlarını kullanın.
5. **"🔍 Fark İncele"** butonuna basarak tablo satır değişikliklerini görün.
6. **"⚡ Bu State'e Geri Dön"** butonuna basarak veritabanınızı geçmişe döndürün!

---

## 🔒 Dosya ve İzin Yönetimi (Linux / Pardus)

```bash
chmod -R 775 .
```

---

## 📄 Lisans

Bu proje **MIT** lisansı altında açık kaynak olarak sunulmuştur.
