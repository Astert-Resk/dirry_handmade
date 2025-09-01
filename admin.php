<?php
// admin.php : 日記CMS（ログイン → 作成/編集/削除 → entries.json 更新 → 全記事の前後リンク自動更新）
// 想定: 一覧は index.php が data/entries.json を読み込んで表示（＝一覧HTMLは生成しない）

declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');
session_start();

/* ===== 設定 ===== */
$PASSWORD       = '0000';               // ← 必ず変更
$baseDir        = __DIR__;
$contentsDir    = $baseDir . '/diary-contents';  // 記事の出力先
$contentsHref   = 'diary-contents';              // 一覧・管理から見た記事への相対リンク
$dataDir        = $baseDir . '/data';
$detailCssHref  = '../css/diary-detail.css';         // 記事HTMLから見た詳細CSSの相対パス（例: ../css/diary-detail.css）
$siteTitle      = 'デンビタロウの日記';
$listLinkHref   = './index.php';                 // 管理画面から「一覧を開く」で飛ぶ先（動的一覧）

if (!is_dir($dataDir))     { mkdir($dataDir, 0775, true); }
if (!is_dir($contentsDir)) { mkdir($contentsDir, 0775, true); }
$entriesJson = $dataDir . '/entries.json';

/* ===== ユーティリティ ===== */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function h2t(string $s): string { return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function ymd_to_jp(string $ymd): string { [$y,$m,$d] = explode('-', $ymd); return sprintf('%d年%d月%d日', (int)$y, (int)$m, (int)$d); }
function load_entries(string $path): array {
  if (!file_exists($path)) return [];
  $a = json_decode(file_get_contents($path), true);
  return is_array($a) ? $a : [];
}
function save_entries(string $path, array $entries): void {
  // 新しい順: date_iso DESC, created_at DESC
  usort($entries, fn($a,$b) => ($b['date_iso'] <=> $a['date_iso']) ?: ($b['created_at'] <=> $a['created_at']));
  file_put_contents($path, json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
function generate_detail_html(string $title, string $dateIso, string $dateDisp, string $bodyHtml, string $detailCssHref, string $siteTitle, ?string $prevHref, ?string $nextHref): string {
  $prev = $prevHref ? '<a class="nav-link" rel="prev" href="'.e($prevHref).'">← まえのにっき</a>' : '<a class="nav-link" rel="prev" href="#" data-disabled="true" aria-disabled="true">← まえのにっき</a>';
  $next = $nextHref ? '<a class="nav-link" rel="next" href="'.e($nextHref).'">つぎのにっき →</a>' : '<a class="nav-link" rel="next" href="#" data-disabled="true" aria-disabled="true">つぎのにっき →</a>';
  return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{$title} - {$siteTitle}</title>
  <link rel="stylesheet" href="{$detailCssHref}" />
</head>
<body>
  <article class="diary" itemscope itemtype="https://schema.org/BlogPosting">
    <header class="diary-header">
      <h1 class="diary-title" itemprop="headline">{$title}</h1>
      <time class="diary-date" datetime="{$dateIso}" itemprop="datePublished">{$dateDisp}</time>
    </header>

    <main class="diary-body" itemprop="articleBody">
      {$bodyHtml}
    </main>

    <footer class="diary-footer">
      <nav class="nav-links" aria-label="前後の日記">
        {$prev}
        {$next}
      </nav>
    </footer>
  </article>
</body>
</html>
HTML;
}
/** 既存記事の <main class="diary-body">…</main> からテキストを抽出（編集フォーム用） */
function extract_text_from_detail(string $absPath): string {
  if (!is_file($absPath)) return '';
  $html = file_get_contents($absPath);
  if (!preg_match('/<main[^>]*class="[^"]*\bdiary-body\b[^"]*"[^>]*>(.*?)<\/main>/is', $html, $m)) return '';
  $inner = $m[1];
  $inner = preg_replace('/<br\s*\/?>/i', "\n", $inner); // <br> → 改行
  $inner = strip_tags($inner);                           // タグ除去
  return h2t(trim($inner));
}
/** 全記事の <nav class="nav-links">…</nav> を並び順に合わせて更新 */
function rebuild_all_navs(array $entries, string $baseDir): void {
  $n = count($entries);
  for ($i=0; $i<$n; $i++) {
    // 前後の “一覧用 href” を取得
    $prevHref = ($i+1 < $n) ? $entries[$i+1]['href'] : null; // older
    $nextHref = ($i-1 >= 0) ? $entries[$i-1]['href'] : null; // newer

    // 記事ページ内から見た相対パス（同じフォルダなのでファイル名のみ）
    $prevRel = $prevHref ? basename($prevHref) : null;
    $nextRel = $nextHref ? basename($nextHref) : null;

    // 対象HTMLを読み込み
    $abs = $baseDir . '/' . $entries[$i]['href'];
    if (!is_file($abs)) continue;
    $html = file_get_contents($abs);

    // ナビHTMLを組み立て
    $prev = $prevRel ? '<a class="nav-link" rel="prev" href="'.e($prevRel).'">← まえのにっき</a>'
                     : '<a class="nav-link" rel="prev" href="#" data-disabled="true" aria-disabled="true">← まえのにっき</a>';
    $next = $nextRel ? '<a class="nav-link" rel="next" href="'.e($nextRel).'">つぎのにっき →</a>'
                     : '<a class="nav-link" rel="next" href="#" data-disabled="true" aria-disabled="true">つぎのにっき →</a>';
    $nav  = "<nav class=\"nav-links\" aria-label=\"前後の日記\">\n        {$prev}\n        {$next}\n      </nav>";

    // 置換して保存
    $html = preg_replace('/<nav\s+class="nav-links"[^>]*>.*?<\/nav>/is', $nav, $html, 1);
    file_put_contents($abs, $html, LOCK_EX);
  }
}


/* ===== 認証 ===== */
if (isset($_GET['logout'])) {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
  header('Location: '.$_SERVER['PHP_SELF']);
  exit;
}
$loginError = '';
if (($_POST['mode'] ?? '') === 'login') {
  $pass = (string)($_POST['password'] ?? '');
  if ($PASSWORD === '' || hash_equals($PASSWORD, $pass)) {
    $_SESSION['authed'] = true;
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
  } else {
    $loginError = 'パスワードが違います。';
  }
}
$authed = !empty($_SESSION['authed']);

/* ===== ルーティング: 作成 / 更新 / 削除 ===== */
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $mode    = (string)($_POST['mode'] ?? '');
  $entries = load_entries($entriesJson);

  if ($mode === 'create') {
    $title   = trim((string)($_POST['title'] ?? ''));
    $dateIso = trim((string)($_POST['date']  ?? date('Y-m-d')));
    $bodyRaw = (string)($_POST['body'] ?? '');

    if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIso)) { http_response_code(400); echo 'タイトルと日付は必須です。'; exit; }

    $bodyHtml = '<p>' . nl2br(e($bodyRaw)) . '</p>';
    $dateDisp = ymd_to_jp($dateIso);
    $ymd      = str_replace('-', '', $dateIso);

    // ファイル名（同日に既存があれば時刻サフィックス）
    $fileName = "{$ymd}.html";
    $target   = $contentsDir . '/' . $fileName;
    if (file_exists($target)) {
      $fileName = "{$ymd}-" . date('His') . '.html';
      $target   = $contentsDir . '/' . $fileName;
    }
    $href = $contentsHref . '/' . $fileName;

    // まず entries に追加 → 保存（並び替え確定）
    $entries[] = [
      'title'      => e($title),
      'date_iso'   => $dateIso,
      'date_disp'  => $dateDisp,
      'href'       => $href,
      'created_at' => date('c'),
    ];
    save_entries($entriesJson, $entries);
    $entries = load_entries($entriesJson); // ソート後を再読込

    // 自分の位置から前後リンクを決定
    $idx = array_search($href, array_column($entries, 'href'), true);
    $prevHref = ($idx !== false && $idx+1 < count($entries)) ? $entries[$idx+1]['href'] : null; // older
    $nextHref = ($idx !== false && $idx-1 >= 0)            ? $entries[$idx-1]['href'] : null;   // newer

    // 詳細HTMLを書き出し
    $detail = generate_detail_html(e($title), $dateIso, $dateDisp, $bodyHtml, $detailCssHref, $siteTitle, $prevHref, $nextHref);
    file_put_contents($target, $detail, LOCK_EX);

    // 全記事のナビ更新
    rebuild_all_navs($entries, $baseDir);

    // 完了表示
    echo <<<DONE
<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,"Meiryo","Hiragino Sans","Yu Gothic",sans-serif;line-height:1.7;padding:24px;background:#fffdf7}
  a{color:#3a6f6f;text-decoration:none;border-bottom:1px dashed #cde4e4}
  a:hover{text-decoration:underline}
  .ok{display:inline-block;background:#f5fbfb;border:1px dashed #cde4e4;padding:.4em .6em;border-radius:10px}
</style>
<p class="ok">生成完了！</p>
<ul>
  <li>記事：<a href="./{$href}" target="_blank" rel="noopener">{$href}</a></li>
  <li>一覧：<a href="{$listLinkHref}" target="_blank" rel="noopener">index</a></li>
</ul>
<p><a href="{$_SERVER['PHP_SELF']}">← 続けて投稿する</a>／<a href="{$_SERVER['PHP_SELF']}?logout=1">ログアウト</a></p>
DONE;
    exit;
  }

  if ($mode === 'update') {
    $href    = (string)($_POST['href'] ?? '');
    $title   = trim((string)($_POST['title'] ?? ''));
    $dateIso = trim((string)($_POST['date']  ?? date('Y-m-d')));
    $bodyRaw = (string)($_POST['body'] ?? '');

    if ($href === '' || !preg_match('#^diary-contents/.+\.html$#', $href)) { http_response_code(400); echo '不正な記事パスです。'; exit; }
    if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIso)) { http_response_code(400); echo 'タイトルと日付は必須です。'; exit; }

    $abs     = $baseDir . '/' . $href;
    if (!is_file($abs)) { http_response_code(404); echo '記事ファイルが見つかりません。'; exit; }

    $bodyHtml = '<p>' . nl2br(e($bodyRaw)) . '</p>';
    $dateDisp = ymd_to_jp($dateIso);

    // entries.json を更新
    $changed = false;
    foreach ($entries as &$it) {
      if ($it['href'] === $href) {
        $it['title']     = e($title);
        $it['date_iso']  = $dateIso;
        $it['date_disp'] = $dateDisp;
        $changed = true; break;
      }
    }
    unset($it);
    if (!$changed) { http_response_code(404); echo 'entries.json に対象が見つかりません。'; exit; }
    save_entries($entriesJson, $entries);
    $entries = load_entries($entriesJson);

    // 自分の前後
    $idx = array_search($href, array_column($entries, 'href'), true);
    $prevHref = ($idx !== false && $idx+1 < count($entries)) ? $entries[$idx+1]['href'] : null;
    $nextHref = ($idx !== false && $idx-1 >= 0)            ? $entries[$idx-1]['href'] : null;

    // 詳細HTMLを上書き再生成
    $detail = generate_detail_html(e($title), $dateIso, $dateDisp, $bodyHtml, $detailCssHref, $siteTitle, $prevHref, $nextHref);
    file_put_contents($abs, $detail, LOCK_EX);

    // 全記事のナビ更新
    rebuild_all_navs($entries, $baseDir);

    header('Location: '.$_SERVER['PHP_SELF'].'?edited=1'); exit;
  }

  if ($mode === 'delete') {
    $href = (string)($_POST['href'] ?? '');
    if ($href === '' || !preg_match('#^diary-contents/.+\.html$#', $href)) { http_response_code(400); echo '不正な記事パスです。'; exit; }
    $abs  = $baseDir . '/' . $href;

    // entries.json から除去
    $entries = array_values(array_filter($entries, fn($it) => $it['href'] !== $href));
    save_entries($entriesJson, $entries);

    // ファイル削除（存在すれば）
    if (is_file($abs)) { @unlink($abs); }

    // 全記事のナビ更新
    rebuild_all_navs($entries, $baseDir);

    header('Location: '.$_SERVER['PHP_SELF'].'?deleted=1'); exit;
  }
}

/* ===== 画面（ログイン or 管理UI） ===== */
$today   = date('Y-m-d');
$entries = load_entries($entriesJson);
$editing = $authed && isset($_GET['edit'], $_GET['href']) && $_GET['edit'] === '1' && preg_match('#^diary-contents/.+\.html$#', (string)$_GET['href']);
$editData = null;
if ($editing) {
  $href = (string)$_GET['href'];
  $abs  = $baseDir . '/' . $href;
  foreach ($entries as $it) {
    if ($it['href'] === $href) {
      $editData = [
        'href'     => $href,
        'title'    => h2t($it['title']),
        'date_iso' => $it['date_iso'],
        'body'     => extract_text_from_detail($abs),
      ];
      break;
    }
  }
  if ($editData === null) { $editing = false; }
}
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>日記管理</title>
<style>
  body{background:#fffdf7;color:#333;margin:0;font-family:system-ui,-apple-system,"Meiryo","Hiragino Sans","Yu Gothic",sans-serif}
  .wrap{max-width:980px;margin:32px auto;padding:20px;background:#ffffffcc;border:1px dashed #e5dccb;border-radius:16px}
  h1{margin:0 0 .75em;font-size:1.6rem;padding:0 .4em;background-image:linear-gradient(#fff3c4,#fff3c4);background-size:100% .6em;background-repeat:no-repeat;background-position:0 80%;border-radius:6px}
  h2{margin:1.5em 0 .5em;font-size:1.2rem}
  label{font-weight:700;display:block;margin-top:12px}
  input[type="text"],input[type="date"],input[type="password"],textarea{width:100%;padding:.6em;border:1px solid #cde4e4;border-radius:8px;background:#fff}
  textarea{min-height:220px}
  .note{color:#666;font-size:.95rem;margin:.2em 0 0}
  .row{display:flex;gap:12px;flex-wrap:wrap}
  .row .cell{flex:1}
  .submit{text-align: center;}
  button{background:#f5fbfb;border:1px dashed #cde4e4;color:#3a6f6f;padding:.6em 1em;border-radius:10px;cursor:pointer}
  button:hover{text-decoration:underline}
  .error{color:#b00020;margin:.4em 0 0}
  .topnav{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
  .topnav a{color:#3a6f6f;text-decoration:none;border-bottom:1px dashed #cde4e4}
  table{width:100%;border-collapse:collapse;margin-top:8px}
  th,td{padding:.5em;border-bottom:1px dashed #e5dccb;text-align:left;font-size:.95rem}
  .actions form{display:inline}
  .ok{display:inline-block;background:#f5fbfb;border:1px dashed #cde4e4;padding:.2em .5em;border-radius:10px;margin-left:.5em}
</style>
<div class="wrap">
<?php if(!$authed): ?>
  <div class="topnav"><div></div></div>
  <h1>ログイン</h1>
  <form method="post" action="">
    <input type="hidden" name="mode" value="login">
    <label for="password">パスワード</label>
    <input id="password" name="password" type="password" required placeholder="合言葉を入力">
    <?php if($loginError): ?><p class="error"><?php echo e($loginError); ?></p><?php endif; ?>
    <div class="submit"><button type="submit">入室する</button></div>
  </form>
<?php else: ?>
  <div class="topnav">
    <strong>管理</strong>
    <div>
      <a href="<?php echo e($listLinkHref); ?>" target="_blank" rel="noopener">一覧を開く</a>
      &nbsp;|&nbsp;
      <a href="<?php echo $_SERVER['PHP_SELF']; ?>?logout=1">ログアウト</a>
    </div>
  </div>

  <?php if(isset($_GET['edited'])): ?><span class="ok">更新しました</span><?php endif; ?>
  <?php if(isset($_GET['deleted'])): ?><span class="ok">削除しました</span><?php endif; ?>

  <?php if($editing && $editData): ?>
  <h1>記事を編集</h1>
  <form method="post" action="">
    <input type="hidden" name="mode" value="update">
    <input type="hidden" name="href" value="<?php echo e($editData['href']); ?>">
    <label for="title">タイトル</label>
    <input id="title" name="title" type="text" required value="<?php echo e($editData['title']); ?>">
    <div class="row">
      <div class="cell">
        <label for="date">日付</label>
        <input id="date" name="date" type="date" required value="<?php echo e($editData['date_iso']); ?>">
      </div>
    </div>
    <label for="body">本文</label>
    <textarea id="body" name="body"><?php echo e($editData['body']); ?></textarea>
    <div class="submit">
      <button type="submit">更新する</button>
      <a href="<?php echo $_SERVER['PHP_SELF']; ?>" style="margin-left:12px">← 作成フォームに戻る</a>
    </div>
  </form>
  <?php else: ?>
  <h1>日記を作成</h1>
  <form method="post" action="">
    <input type="hidden" name="mode" value="create">
    <label for="title">タイトル</label>
    <input id="title" name="title" type="text" required placeholder="きょうのこと">
    <p class="note">※ タイトルのすぐ横に小さく日付を表示します。</p>
    <div class="row">
      <div class="cell">
        <label for="date">日付</label>
        <input id="date" name="date" type="date" value="<?php echo e($today); ?>" required>
      </div>
    </div>
    <label for="body">本文</label>
    <textarea id="body" name="body" placeholder="ここに本文を書いてください。やわらかいトーンで、思ったことをそのまま。"></textarea>
    <div class="submit">
      <button type="submit">記事を生成する</button>
      <a href="<?php echo e($listLinkHref); ?>" style="margin-left:12px">一覧を開く</a>
    </div>
  </form>
  <?php endif; ?>

  <h2>記事一覧（新しい順）</h2>
  <table>
    <thead><tr><th>日付</th><th>タイトル</th><th>リンク</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach($entries as $it): ?>
      <tr>
        <td><?php echo e($it['date_disp']); ?></td>
        <td><?php echo h2t($it['title']); ?></td>
        <td><a href="./<?php echo e($it['href']); ?>" target="_blank" rel="noopener"><?php echo e($it['href']); ?></a></td>
        <td class="actions">
          <a href="<?php echo $_SERVER['PHP_SELF']; ?>?edit=1&amp;href=<?php echo e($it['href']); ?>">編集</a>
          |
          <form method="post" action="" onsubmit="return confirm('削除してよろしいですか？ この操作は取り消せません。');" style="display:inline">
            <input type="hidden" name="mode" value="delete">
            <input type="hidden" name="href" value="<?php echo e($it['href']); ?>">
            <button type="submit">削除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
