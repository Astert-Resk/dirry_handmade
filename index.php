<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

$siteTitle   = 'デンビタロウの日記';
$listNote    = 'その日の気分で、少しずつ。';
$listCssHref = 'css/diary-list.css';  // 直下にあるCSSを参照

$entries = [];
$path = __DIR__ . '/data/entries.json';
if (is_file($path)) {
  $json = json_decode(file_get_contents($path), true);
  if (is_array($json)) $entries = $json;
}
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo e($siteTitle); ?> - 目次</title>
  <link rel="stylesheet" href="<?php echo e($listCssHref); ?>" />
  <meta name="description" content="<?php echo e($listNote); ?>">
</head>
<body>
  <main class="diary-list" itemscope itemtype="https://schema.org/Blog">
    <header class="list-header">
      <h1 class="list-title"><?php echo e($siteTitle); ?></h1>
      <p class="list-note"><?php echo e($listNote); ?></p>
    </header>

    <section class="list-section" aria-label="最新の日記">
      <ul class="entry-list">
        <?php foreach ($entries as $it): ?>
        <li class="entry-item" itemscope itemprop="blogPost" itemtype="https://schema.org/BlogPosting">
          <a class="entry-link" href="<?php echo e($it['href']); ?>" itemprop="url">
            <span class="entry-title" itemprop="headline"><?php echo e($it['title']); ?></span>
            <time class="entry-date" datetime="<?php echo e($it['date_iso']); ?>" itemprop="datePublished">
              <?php echo e($it['date_disp']); ?>
            </time>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </main>
</body>
</html>
