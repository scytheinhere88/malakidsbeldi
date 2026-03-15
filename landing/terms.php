<?php require_once dirname(__DIR__).'/config.php';
$lang = getLang();
$canonicalUrl = APP_URL . '/landing/terms.php';
?>
<!DOCTYPE html><html lang="<?= $lang ?>"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Terms of Service | BulkReplace</title>
<meta name="description" content="BulkReplace Terms of Service. Learn about our usage policy, quota rollover, billing, and refund policy.">
<link rel="canonical" href="<?= $canonicalUrl ?>">
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">

<!-- Open Graph -->
<meta property="og:title" content="BulkReplace Terms of Service">
<meta property="og:type" content="website">
<meta property="og:image" content="https://bulkreplacetool.com/img/og-cover.png">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="BulkReplace Terms of Service">
<meta name="twitter:image" content="https://bulkreplacetool.com/img/og-cover.png">
<meta name="twitter:description" content="BulkReplace usage policy, quota rollover, billing, and refund policy.">
<meta name="robots" content="index, follow">
<meta property="og:description" content="Terms and conditions for using BulkReplace bulk content replacement tool">
<meta property="og:url" content="<?= $canonicalUrl ?>">
<meta property="og:type" content="website">


<script type="application/ld+json">
{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[
  {"@type":"ListItem","position":1,"name":"Home","item":"https://bulkreplacetool.com/"},
  {"@type":"ListItem","position":2,"name":"Terms of Service","item":"https://bulkreplacetool.com/landing/terms.php"}
]}
</script>
</head><body>

<nav><div class="nav-inner">
  <a href="/" class="nav-logo"><img src="/img/logo.png" alt="BulkReplace" class="nav-logo-img"><span class="nav-logo-text">BulkReplace</span></a>
  <div class="nav-links">
    <a href="/"><?= t('nav_home') ?></a>
    <a href="/landing/tutorial.php"><?= t('nav_tutorial') ?></a>
    <a href="/landing/pricing.php"><?= t('nav_pricing') ?></a>
    <a href="/landing/terms.php" style="color:var(--a1);">Terms</a>
    <a href="/landing/privacy.php">Privacy</a>
  </div>
  <div class="nav-cta">
    <div class="lang-switcher" style="display:inline-flex;gap:6px;margin-right:12px;background:var(--dim);border:1px solid var(--border);border-radius:8px;padding:4px;">
      <a href="/lang/switch.php?lang=en&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" style="padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;text-decoration:none;color:var(--muted);transition:all .2s;<?= $lang==='en'?'background:var(--a1);color:#000;font-weight:700;':'' ?>">EN</a>
      <a href="/lang/switch.php?lang=id&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" style="padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;text-decoration:none;color:var(--muted);transition:all .2s;<?= $lang==='id'?'background:var(--a1);color:#000;font-weight:700;':'' ?>">ID</a>
    </div>
    <a href="/auth/login.php" class="btn btn-ghost btn-sm"><?= t('nav_signin') ?></a>
    <a href="/auth/register.php" class="btn btn-amber btn-sm"><?= t('nav_register') ?></a>
  </div>
</div></nav>

<div class="wrap"><section class="section" style="max-width:740px;margin:0 auto;">
  <div class="section-tag"><?= $lang==='id'?'Legal':'Legal' ?></div>
  <div class="section-title"><?= $lang==='id'?'Syarat dan Ketentuan':'Terms of Service' ?></div>
  <p style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:40px;">
    <?= $lang==='id'?'Terakhir diperbarui: ':'Last updated: ' ?><?= date('F j, Y') ?>
  </p>

  <?php
  $sections = $lang === 'id' ? [
    ['title'=>'1. Penerimaan Syarat','body'=>'Dengan mengakses atau menggunakan BulkReplace ("Layanan"), Anda setuju untuk terikat dengan Syarat Layanan ini. Jika Anda tidak setuju, jangan gunakan Layanan.'],
    ['title'=>'2. Deskripsi Layanan','body'=>'BulkReplace adalah tool berbasis web yang melakukan penggantian konten client-side di berbagai file. Semua pemrosesan file terjadi di browser Anda. Tidak ada file yang diupload ke server kami.'],
    ['title'=>'3. Akun Pengguna','body'=>'Anda harus mendaftar akun untuk menggunakan Layanan. Anda bertanggung jawab untuk menjaga kerahasiaan kredensial Anda dan untuk semua aktivitas di bawah akun Anda.'],
    ['title'=>'4. Paket dan Pembayaran','body'=>'Paket gratis menyediakan alokasi satu kali 20 baris CSV tanpa reset bulanan. Paket berbayar (Pro, Platinum) menyediakan alokasi CSV bulanan yang reset setiap bulan. Kuota yang tidak terpakai di paket berbayar rollover ke bulan berikutnya. Paket Lifetime menyediakan akses unlimited dengan pembayaran satu kali. Semua pembayaran diproses via Lemon Squeezy. Refund ditangani sesuai kebijakan Lemon Squeezy.'],
    ['title'=>'5. Kebijakan Rollover Kuota','body'=>'Pada paket Pro dan Platinum, baris CSV yang tidak terpakai dari setiap bulan rollover ke bulan berikutnya. Saldo rollover terakumulasi hingga maksimum 3x limit paket bulanan Anda. Kuota rollover kedaluwarsa jika langganan Anda berakhir.'],
    ['title'=>'6. Penggunaan yang Dapat Diterima','body'=>'Anda tidak boleh menggunakan Layanan untuk memproses konten ilegal, berbahaya, atau malicious. Anda tidak boleh mencoba reverse-engineer, decompile, atau mengekstrak source code Layanan. Anda tidak boleh membagikan kredensial akun Anda dengan orang lain.'],
    ['title'=>'7. Privasi','body'=>'BulkReplace tidak menyimpan file Anda. Semua pemrosesan file bersifat client-side. Kami hanya menyimpan informasi akun Anda (nama, email, status paket) dan statistik penggunaan (baris diproses, file diupdate) di database kami. Kami tidak menjual data Anda ke pihak ketiga.'],
    ['title'=>'8. Ketersediaan Layanan','body'=>'Kami berusaha untuk ketersediaan tinggi tetapi tidak menjamin akses tanpa gangguan. Kami berhak untuk memodifikasi, menangguhkan, atau menghentikan Layanan kapan saja dengan pemberitahuan yang wajar.'],
    ['title'=>'9. Batasan Tanggung Jawab','body'=>'BulkReplace disediakan "apa adanya" tanpa jaminan apapun. Kami tidak bertanggung jawab atas kehilangan data, kerusakan file, atau kerusakan yang diakibatkan dari penggunaan Layanan. Selalu backup file Anda sebelum memproses.'],
    ['title'=>'10. Dukungan','body'=>'Dukungan disediakan via Telegram di '.SUPPORT_TELEGRAM.'. Waktu respons bervariasi berdasarkan paket. Kami berusaha merespons dalam 24 jam untuk paket berbayar.'],
    ['title'=>'11. Perubahan Syarat','body'=>'Kami dapat memperbarui Syarat ini kapan saja. Penggunaan Layanan yang berkelanjutan setelah perubahan merupakan penerimaan terhadap Syarat baru.'],
    ['title'=>'12. Kontak','body'=>'Untuk pertanyaan tentang Syarat ini, hubungi kami via Telegram: '.SUPPORT_TELEGRAM],
  ] : [
    ['title'=>'1. Acceptance of Terms','body'=>'By accessing or using BulkReplace ("the Service"), you agree to be bound by these Terms of Service. If you do not agree, do not use the Service.'],
    ['title'=>'2. Description of Service','body'=>'BulkReplace is a web-based tool that performs client-side content replacement across files. All file processing occurs in your browser. No files are uploaded to our servers.'],
    ['title'=>'3. User Accounts','body'=>'You must register for an account to use the Service. You are responsible for maintaining the confidentiality of your credentials and for all activities under your account.'],
    ['title'=>'4. Plans and Billing','body'=>'Free plan provides a one-time allocation of 20 CSV rows with no monthly reset. Paid plans (Pro, Platinum) provide monthly CSV allocations that reset each month. Unused quota on paid plans rolls over to the next month. Lifetime plan provides unlimited access with a one-time payment. All payments are processed via Lemon Squeezy. Refunds are handled per Lemon Squeezy policy.'],
    ['title'=>'5. Quota Rollover Policy','body'=>'On Pro and Platinum plans, unused CSV rows from each month roll over to the following month. Rollover balance accumulates up to a maximum of 3x your monthly plan limit. Rolled-over quota expires if your subscription lapses.'],
    ['title'=>'6. Acceptable Use','body'=>'You may not use the Service to process illegal, harmful, or malicious content. You may not attempt to reverse-engineer, decompile, or extract the Service source code. You may not share your account credentials with others.'],
    ['title'=>'7. Privacy','body'=>'BulkReplace does not store your files. All file processing is client-side. We store only your account information (name, email, plan status) and usage statistics (rows processed, files updated) in our database. We do not sell your data to third parties.'],
    ['title'=>'8. Service Availability','body'=>'We strive for high availability but do not guarantee uninterrupted access. We reserve the right to modify, suspend, or discontinue the Service at any time with reasonable notice.'],
    ['title'=>'9. Limitation of Liability','body'=>'BulkReplace is provided as-is without warranty of any kind. We are not liable for any data loss, file corruption, or damages resulting from use of the Service. Always maintain backups of your files before processing.'],
    ['title'=>'10. Support','body'=>'Support is provided via Telegram at '.SUPPORT_TELEGRAM.'. Response times vary by plan. We aim to respond within 24 hours for paid plans.'],
    ['title'=>'11. Changes to Terms','body'=>'We may update these Terms at any time. Continued use of the Service after changes constitutes acceptance of the new Terms.'],
    ['title'=>'12. Contact','body'=>'For questions about these Terms, contact us via Telegram: '.SUPPORT_TELEGRAM],
  ];

  foreach($sections as $sec): ?>
  <div style="margin-bottom:32px;">
    <div style="font-family:'Syne',sans-serif;font-size:17px;font-weight:700;color:#fff;margin-bottom:10px;"><?= $sec['title'] ?></div>
    <p style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);line-height:2;"><?= htmlspecialchars($sec['body']) ?></p>
  </div>
  <?php endforeach; ?>
</section></div>

<footer><div class="footer-grid">
  <div class="footer-brand"><a href="/" class="nav-logo"><img src="/img/logo.png" alt="BulkReplace" class="nav-logo-img"><span class="nav-logo-text" style="margin-left:10px;">BulkReplace</span></a><p><?= t('footer_tagline') ?></p></div>
  <div class="footer-col"><h4><?= t('footer_product') ?></h4><a href="/"><?= t('nav_home') ?></a><a href="/landing/tutorial.php"><?= t('nav_tutorial') ?></a><a href="/landing/pricing.php"><?= t('nav_pricing') ?></a></div>
  <div class="footer-col"><h4><?= t('footer_support') ?></h4><a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank">Telegram</a><a href="/landing/terms.php">Terms</a><a href="/landing/privacy.php">Privacy</a></div>
  <div class="footer-col"><h4><?= t('footer_account') ?></h4><a href="/auth/login.php"><?= t('nav_signin') ?></a><a href="/auth/register.php"><?= t('nav_register') ?></a></div>
</div><div class="footer-bottom"><span>© <?= date('Y') ?> BulkReplace</span></div></footer>

</body></html>
