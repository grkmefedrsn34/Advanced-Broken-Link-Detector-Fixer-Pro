# Advanced-Broken-Link-Detector-Fixer-Pro
Arka planda sunucuyu yormadan kırık linkleri tarar, e-posta ile raporlar ve panelden tek tıkla güncelleme/silme imkanı sunar.

Dosayayı indirdikten sonra zip haline getirin ve wordpress içine öyle yükleyin .

ÖNEMLİ : Önceki sürüm sitelerin tüm HTML içeriğini indiriyordu (wp_remote_get). Bu sürüm sadece sitenin başlığını kontrol eder (wp_remote_head). Sitenin çalışıp çalışmadığını anlamak için tüm sayfayı indirmek yerine sadece sunucudan durum kodunu ister. Bu sayede tarama hızı %80 oranında artar ve sunucu bant genişliği korunur.

En can alıcı ticari optimizasyon budur. Sistem 10 yazıyı tarar. Eğer arkada taranacak başka yazı kalmışsa, sonraki güne bırakmaz; hemen 5 saniye sonrasına tek seferlik yeni bir mikro görev yazar. Sunucu dinlenir, 5 saniye sonra sonraki 10 yazıyı tarar. Sistem bu sayede sunucuyu hiç şişirmeden tüm siteyi saatler içinde bitirir.

Rapordaki form alanı sayesinde site sahibi direkt yeni URL'i yazıp "Güncelle" dediğinde str_replace mantığıyla veritabanındaki eski kırık adresi saniyeler içinde yenisiyle değiştirir.

Kodların tamamı tek bir sınıf (KLB_Broken_Link_Detector_Pro) altında toplandı. Bu, başka eklentilerle çakışmayı (isim benzerliğinden dolayı sitenin çökmesini) tamamen engeller. Resmi WordPress standartlarına tam uyumludur.
