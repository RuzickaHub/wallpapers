<?php
// index.php
// AI-morph galerie — neuromorphic UI, ratio-aware modal, aggregated upload progress, spinner placeholder, light/dark mode
declare(strict_types=1);

// -----------------------------------------------------------------------------
// CONFIG
// -----------------------------------------------------------------------------
$UPLOAD_DIR = __DIR__ . '/uploads';
$MAX_SIZE = 50 * 1024 * 1024; // 50 MB per file

if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0755, true);
}

// -----------------------------------------------------------------------------
// HELPERS
// -----------------------------------------------------------------------------
function scan_images(string $dir): array {
    $files = array_values(array_filter(scandir($dir), function($f) use ($dir) {
        return !in_array($f, ['.','..']) && preg_match('/\.(jpe?g|png|webp|gif)$/i', $f) && is_file($dir . '/' . $f);
    }));
    return $files;
}

function base_url(): string {
    $scheme = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === '1')) ||
              (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
              ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
    return $scheme . '://' . $host . ($path === '/' ? '' : $path);
}

// -----------------------------------------------------------------------------
// API: GET = list, POST = upload (no delete exposed)
// -----------------------------------------------------------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $files = scan_images($UPLOAD_DIR);
            $out = [];
            foreach ($files as $f) {
                $p = $UPLOAD_DIR . '/' . $f;
                $out[] = [
                    'id' => $f,
                    'name' => $f,
                    'url' => base_url() . '/uploads/' . rawurlencode($f),
                    'size' => filesize($p),
                    'mime' => mime_content_type($p),
                ];
            }
            echo json_encode($out);
            exit;
        } elseif ($method === 'POST') {
            if (empty($_FILES['file'])) throw new Exception('Soubor chybí');
            // possibly handle multiple uploads with same POST but we expect one per request from XHR
            $file = $_FILES['file'];
            if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) throw new Exception('Chyba při nahrávání');
            if ($file['size'] > $MAX_SIZE) throw new Exception('Soubor překročil maximální velikost 50 MB');
            $tmpMime = @mime_content_type($file['tmp_name']) ?: '';
            if (!str_starts_with($tmpMime, 'image/')) throw new Exception('Neplatný typ souboru');
            $safe = preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', basename($file['name']));
            $uniq = bin2hex(random_bytes(6)) . '_' . $safe;
            $target = $UPLOAD_DIR . '/' . $uniq;
            if (!move_uploaded_file($file['tmp_name'], $target)) throw new Exception('Nepodařilo se uložit soubor');
            echo json_encode(['success' => true, 'id' => $uniq, 'url' => base_url() . '/uploads/' . rawurlencode($uniq), 'size' => filesize($target)]);
            exit;
        } else {
            throw new Exception('Nepodporovaná metoda');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// -----------------------------------------------------------------------------
// FRONTEND: prepare list and bg pool
// -----------------------------------------------------------------------------
$images = scan_images($UPLOAD_DIR);
shuffle($images);
$bgPool = array_slice($images, 0, min(6, max(1, count($images))));
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>AI-Morph Galerie — Neuromorphic</title>

<!-- Tailwind CDN for utilities -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- Feather icons CDN -->
<script src="https://unpkg.com/feather-icons"></script>

<style>
  /* Basic variables */
  :root {
    --bg-dark: #0b1020;
    --bg-light: #f3f6fb;
    --card-dark: rgba(255,255,255,0.03);
    --card-light: #e6eef8;
    --text-dark: #e6eef8;
    --text-light: #0b1020;
    --neu-shadow: 18px 18px 36px rgba(2,6,23,0.55), -10px -8px 24px rgba(255,255,255,0.03);
  }
  html,body { height:100%; }
  body { margin:0; font-family:Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; transition: background .25s ease, color .25s ease; }

  /* Theme classes (JS toggles body.light / body.dark) */
  body.dark { background: var(--bg-dark); color: var(--text-dark); }
  body.dark .card { background: var(--card-dark); box-shadow: var(--neu-shadow); }
  body.light { background: var(--bg-light); color: var(--text-light); }
  body.light .card { background: var(--card-light); box-shadow: 12px 12px 24px rgba(163,177,198,0.4), -8px -6px 18px rgba(255,255,255,0.8); }

  /* Background crossfade */
  #bg-wrap { position:fixed; inset:0; z-index:-2; overflow:hidden; pointer-events:none; }
  .bg-layer { position:absolute; inset:0; background-size:cover; background-position:center; filter: blur(28px) brightness(0.6) saturate(0.85); transform: scale(1.06); opacity:0; transition: opacity 1.2s ease-in-out, transform 8s ease-in-out; }
  .bg-layer.show { opacity:1; transform: scale(1); }

  /* Header / container */
  header { backdrop-filter: blur(6px); background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); border-bottom: 1px solid rgba(255,255,255,0.04); }
  .container { max-width:1200px; margin:0 auto; padding:20px; }

  /* Grid + thumbs */
  .grid { display:grid; gap:18px; grid-template-columns: repeat(2,1fr); }
  @media(min-width:640px){ .grid{ grid-template-columns: repeat(3,1fr); } }
  @media(min-width:1024px){ .grid{ grid-template-columns: repeat(4,1fr); } }
  @media(min-width:1280px){ .grid{ grid-template-columns: repeat(5,1fr); } }

  .thumb { border-radius: 14px; overflow:hidden; transition: transform .28s cubic-bezier(.16,1,.3,1), box-shadow .28s; display:block; height:180px; position:relative; }
  .thumb img { width:100%; height:100%; object-fit:cover; display:block; transform-origin:center; transition: transform .5s ease, opacity .45s ease; border-radius:10px; }
  .thumb:hover { transform: translateY(-6px) scale(1.02); }

  .thumb .meta { position:absolute; left:12px; bottom:10px; padding:6px 10px; border-radius:999px; background:rgba(0,0,0,0.36); color:#fff; font-size:12px; backdrop-filter: blur(6px); }

  /* Modal */
  #modal { position:fixed; inset:0; display:flex; align-items:center; justify-content:center; z-index:60; opacity:0; pointer-events:none; transition:opacity .28s ease; }
  #modal.show { opacity:1; pointer-events:auto; }
  #modal-overlay { position:absolute; inset:0; background:rgba(2,6,23,0.45); backdrop-filter: blur(6px) saturate(0.9); }

  #modal-body { position:relative; z-index:5; display:flex; align-items:center; justify-content:center; max-width:94vw; max-height:92vh; width:auto; height:auto; transition: width .25s ease, height .25s ease, transform .35s cubic-bezier(.16,1,.3,1); border-radius:18px; box-shadow: 0 30px 70px rgba(2,6,23,0.7); background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(0,0,0,0.02)); overflow:hidden; }

  /* morph layers & spinner */
  .morph-layer { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; opacity:0; transition: opacity .65s ease, filter .65s ease, transform .65s cubic-bezier(.16,1,.3,1); }
  .morph-layer.show { opacity:1; }
  .morph-layer img { max-width:100%; max-height:100%; object-fit:contain; border-radius:12px; user-select:none; -webkit-user-drag:none; }

  .layer-spinner { position:absolute; width:72px; height:72px; border-radius:50%; display:grid; place-items:center; background:rgba(0,0,0,0.36); color:#fff; font-weight:700; opacity:0; transform:scale(.98); transition:opacity .18s ease, transform .18s ease; }
  .layer-spinner.show { opacity:1; transform:scale(1); }

  /* controls */
  .ctrl { position:absolute; display:inline-grid; place-items:center; width:46px; height:46px; border-radius:999px; transition: transform .15s ease, background .15s; box-shadow: 6px 8px 18px rgba(2,6,23,0.45), -4px -3px 10px rgba(255,255,255,0.02); }
  .ctrl:hover { transform:scale(1.06); cursor:pointer; }
  #btn-close { top:16px; right:16px; }
  #btn-prev { left:12px; top:50%; transform:translateY(-50%); }
  #btn-next { right:12px; top:50%; transform:translateY(-50%); }
  #btn-download { bottom:16px; right:24px; }

  /* Upload modal (aggregated progress) */
  #upload-modal { position:fixed; inset:0; display:flex; align-items:center; justify-content:center; z-index:80; opacity:0; pointer-events:none; transition:opacity .18s ease; }
  #upload-modal.show { opacity:1; pointer-events:auto; }
  .upload-card { width: min(560px, 94vw); border-radius:14px; padding:18px; background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(0,0,0,0.02)); box-shadow: var(--neu-shadow); display:flex; gap:12px; align-items:center; justify-content:space-between; }

  /* aggregated circular progress */
  .progress-wrap { width:108px; height:108px; display:grid; place-items:center; position:relative; }
  .progress-svg { transform: rotate(-90deg); }
  .progress-text { position:absolute; font-weight:700; font-size:16px; color:inherit; }

  .toast { position:fixed; right:18px; bottom:18px; padding:10px 14px; border-radius:10px; background:rgba(0,0,0,0.7); color:#fff; z-index:90; }

  .skeleton { background: linear-gradient(90deg, rgba(255,255,255,0.02), rgba(255,255,255,0.04)); height:160px; border-radius:12px; }

  @media(max-width:768px){ .thumb { height:140px; } .upload-card { padding:14px; } .progress-wrap { width:78px; height:78px } }
</style>
</head>
<body class="dark"> <!-- výchozí: dark; lze přepnout -->

<!-- BG crossfade layers -->
<div id="bg-wrap" aria-hidden="true">
  <?php foreach ($bgPool as $i => $b):
    $url = 'uploads/' . rawurlencode($b);
  ?>
    <div class="bg-layer" id="bg-<?php echo $i; ?>" style="background-image:url('<?php echo $url; ?>')"></div>
  <?php endforeach; ?>
</div>

<header class="w-full">
  <div class="container flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-semibold">AI-Morph Galerie</h1>
      <p class="text-sm text-gray-300/70">Neuromorphic UI · ratio-aware modal · AI-like morph</p>
    </div>

    <div class="flex items-center gap-3">
      <label id="upload-label" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white/6 hover:bg-white/8 cursor-pointer card">
        <svg data-feather="upload" class="w-4 h-4"></svg>
        <span class="text-sm">Nahrát</span>
        <input id="upload" type="file" accept="image/*" multiple class="sr-only" />
      </label>

      <button id="toggle-theme" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white/6 hover:bg-white/8 card" title="Přepnout motiv">
        <svg data-feather="moon" class="w-4 h-4"></svg>
      </button>

      <button id="refresh" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white/6 hover:bg-white/8 card">
        <svg data-feather="refresh-cw" class="w-4 h-4"></svg>
        <span class="text-sm">Obnovit</span>
      </button>
    </div>
  </div>
</header>

<main class="container mt-6">
  <div id="grid" class="grid"></div>
</main>

<footer class="container mt-10 mb-12 text-sm text-gray-300/70">
  © <?php echo date('Y'); ?> — Until Design Fluent
</footer>

<!-- Image modal -->
<div id="modal" aria-hidden="true" role="dialog">
  <div id="modal-overlay" onclick="closeModal()"></div>
  <div id="modal-body" role="document" aria-label="Image preview">
    <div id="layer1" class="morph-layer">
      <img src="" alt="">
      <div class="layer-spinner" id="spinner1">⌛</div>
    </div>
    <div id="layer2" class="morph-layer">
      <img src="" alt="">
      <div class="layer-spinner" id="spinner2">⌛</div>
    </div>

    <div id="btn-close" class="ctrl" title="Zavřít" onclick="closeModal()"><svg data-feather="x" class="w-5 h-5"></svg></div>
    <div id="btn-prev" class="ctrl" title="Předchozí" onclick="prevImage()"><svg data-feather="chevron-left" class="w-5 h-5"></svg></div>
    <div id="btn-next" class="ctrl" title="Další" onclick="nextImage()"><svg data-feather="chevron-right" class="w-5 h-5"></svg></div>
    <div id="btn-download" class="ctrl" title="Stáhnout" onclick="downloadCurrent()"><svg data-feather="download" class="w-5 h-5"></svg></div>
  </div>
</div>

<!-- Upload modal (aggregated progress) -->
<div id="upload-modal" aria-hidden="true">
  <div class="upload-card card">
    <div>
      <div style="font-weight:700">Nahrávání souborů</div>
      <div id="upload-fname" style="opacity:.85;margin-top:6px;font-size:13px">Vyber soubory...</div>
      <div id="upload-status" style="opacity:.8;margin-top:8px;font-size:13px">0 / 0 • 0 B / 0 B</div>
    </div>

    <div style="display:flex;align-items:center;gap:12px">
      <div class="progress-wrap" aria-hidden="true">
        <svg class="progress-svg" width="108" height="108" viewBox="0 0 100 100">
          <circle cx="50" cy="50" r="40" stroke="rgba(255,255,255,0.12)" stroke-width="10" fill="none"></circle>
          <circle id="progress-circle" cx="50" cy="50" r="40" stroke="#ffffff" stroke-width="10" stroke-linecap="round" fill="none" stroke-dasharray="251.2" stroke-dashoffset="251.2"></circle>
        </svg>
        <div class="progress-text" id="progress-text">0%</div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  'use strict';
  const API = '?api=1';
  const grid = document.getElementById('grid');
  const uploadInput = document.getElementById('upload');
  const uploadModal = document.getElementById('upload-modal');
  const progressCircle = document.getElementById('progress-circle');
  const progressText = document.getElementById('progress-text');
  const uploadFname = document.getElementById('upload-fname');
  const uploadStatus = document.getElementById('upload-status');
  const refreshBtn = document.getElementById('refresh');
  const toggleThemeBtn = document.getElementById('toggle-theme');

  // modal elements
  const modal = document.getElementById('modal');
  const modalBody = document.getElementById('modal-body');
  const layer1 = document.getElementById('layer1');
  const layer2 = document.getElementById('layer2');
  const spinner1 = document.getElementById('spinner1');
  const spinner2 = document.getElementById('spinner2');
  const imgA = layer1.querySelector('img');
  const imgB = layer2.querySelector('img');

  let items = [];
  let currentIndex = 0;
  let activeImg = imgA;
  let bufferImg = imgB;
  let activeSpinner = spinner1;
  let bufferSpinner = spinner2;
  let isShown = false;
  let bgLayers = Array.from(document.querySelectorAll('.bg-layer'));

  // feather icons
  if (window.feather) feather.replace();

  // THEME: light/dark saved to localStorage
  function applyTheme(theme) {
    document.body.classList.remove('light','dark');
    document.body.classList.add(theme === 'light' ? 'light' : 'dark');
    const icon = theme === 'light' ? 'sun' : 'moon';
    toggleThemeBtn.innerHTML = `<svg data-feather="${icon}" class="w-4 h-4"></svg>`;
    if (window.feather) feather.replace();
    localStorage.setItem('ui-theme', theme);
  }
  toggleThemeBtn.addEventListener('click', () => {
    const cur = localStorage.getItem('ui-theme') || 'dark';
    applyTheme(cur === 'dark' ? 'light' : 'dark');
  });
  const savedTheme = localStorage.getItem('ui-theme') || 'dark';
  applyTheme(savedTheme);

  // ---------- Load list ----------
  async function loadList() {
    grid.innerHTML = Array.from({length:8}).map(()=>'<div class="skeleton"></div>').join('');
    try {
      const res = await fetch(API);
      items = await res.json();
      renderGrid();
      startBgLoop();
    } catch (e) {
      console.error(e);
      grid.innerHTML = '<div class="p-8 rounded bg-red-600/10">Chyba při načítání</div>';
    }
  }

  function renderGrid() {
    if (!items.length) {
      grid.innerHTML = `<div class="p-8 rounded bg-white/4 text-center">Žádné obrázky — nahraj první.</div>`;
      return;
    }
    grid.innerHTML = '';
    items.forEach((it, i) => {
      const el = document.createElement('div');
      el.className = 'thumb card';
      el.innerHTML = `<img data-src="${it.url}" alt="${escapeHtml(it.name)}" loading="lazy" class="not-loaded">` +
                     `<div class="meta">${formatSize(it.size)}</div>`;
      el.addEventListener('click', () => openModal(i));
      grid.appendChild(el);
    });
    lazyLoadThumbnails();
  }

  function lazyLoadThumbnails() {
    const imgs = document.querySelectorAll('.thumb img');
    const io = new IntersectionObserver((entries, obs) => {
      for (const e of entries) {
        if (!e.isIntersecting) continue;
        const im = e.target;
        const src = im.getAttribute('data-src');
        if (!src) { obs.unobserve(im); continue; }
        im.src = src;
        im.onload = () => { im.classList.remove('not-loaded'); im.classList.add('loaded'); };
        im.onerror = () => { im.classList.remove('not-loaded'); im.classList.add('loaded'); im.alt = 'Nelze načíst'; };
        obs.unobserve(im);
      }
    }, { rootMargin: '120px' });
    imgs.forEach(i => io.observe(i));
  }

  // ---------- Modal open/close & morph ----------
  function openModal(index) {
    if (!items[index]) return;
    currentIndex = index;
    setLayer(activeImg, items[index].url, true);
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    isShown = true;
    preload((currentIndex + 1) % items.length);
    preload((currentIndex - 1 + items.length) % items.length);
  }

  function closeModal() {
    modal.classList.remove('show');
    document.body.style.overflow = '';
    isShown = false;
    setTimeout(()=> {
      layer1.classList.remove('show');
      layer2.classList.remove('show');
      activeImg.src = '';
      bufferImg.src = '';
      spinner1.classList.remove('show');
      spinner2.classList.remove('show');
    }, 360);
  }

  function setLayer(imgEl, src, makeActive=false) {
    const parent = imgEl.parentElement;
    const spinner = (imgEl === imgA) ? spinner1 : spinner2;
    parent.classList.remove('show');
    spinner.classList.add('show');
    imgEl.src = src;
    imgEl.style.transform = 'scale(1.02)';
    imgEl.onload = () => {
      adjustModalSize(imgEl.naturalWidth || 1, imgEl.naturalHeight || 1);
      parent.classList.add('show');
      spinner.classList.remove('show');
      imgEl.style.transform = 'scale(1)';
      if (makeActive) {
        activeImg = imgEl;
        bufferImg = (imgEl === imgA) ? imgB : imgA;
        activeSpinner = spinner;
        bufferSpinner = (spinner === spinner1) ? spinner2 : spinner1;
      }
    };
    imgEl.onerror = () => {
      parent.classList.add('show');
      spinner.classList.remove('show');
      imgEl.alt = 'Chyba načtení';
    };
  }

  function morphTo(newIndex) {
    if (!items[newIndex]) return;
    setLayer(bufferImg, items[newIndex].url, false);
    const buffSpinner = (bufferImg === imgA) ? spinner1 : spinner2;
    bufferImg.onload = () => {
      bufferImg.parentElement.classList.add('show');
      activeImg.parentElement.classList.remove('show');
      buffSpinner.classList.remove('show');
      bufferImg.style.transform = 'scale(1.02)';
      setTimeout(()=> bufferImg.style.transform = 'scale(1)', 40);
      [activeImg, bufferImg] = [bufferImg, activeImg];
      currentIndex = newIndex;
      preload((currentIndex + 1) % items.length);
      preload((currentIndex - 1 + items.length) % items.length);
    };
  }

  function nextImage() { morphTo((currentIndex + 1) % items.length); }
  function prevImage() { morphTo((currentIndex - 1 + items.length) % items.length); }

  function downloadCurrent() {
    if (!items[currentIndex]) return;
    const a = document.createElement('a');
    a.href = items[currentIndex].url;
    a.download = items[currentIndex].name || '';
    document.body.appendChild(a); a.click(); a.remove();
  }

  function preload(idx) { if (!items[idx]) return; const i = new Image(); i.src = items[idx].url; }

  function adjustModalSize(nw, nh) {
    const vw = Math.min(window.innerWidth * 0.94, 1600);
    const vh = Math.min(window.innerHeight * 0.9, 1000);
    const ratio = nw / nh;
    let w = vw, h = Math.round(w / ratio);
    if (h > vh) { h = vh; w = Math.round(h * ratio); }
    modalBody.style.width = w + 'px';
    modalBody.style.height = h + 'px';
  }

  // ---------- UPLOAD with aggregated progress ----------
  // plan: compute total bytes of all files; upload files sequentially via XHR or parallel,
  // we'll upload sequentially but compute aggregated progress = (uploadedBytesSoFar + currentLoaded)/totalBytes
  uploadInput.addEventListener('change', (e) => {
    const files = Array.from(e.target.files || []);
    if (!files.length) return;
    const totalBytes = files.reduce((s, f) => s + (f.size || 0), 0);
    let uploadedBytesSoFar = 0;
    let currentIndexUpload = 0;

    uploadFname.textContent = files[0].name || 'Nahrávání...';
    uploadStatus.textContent = `0 / ${files.length} • 0 B / ${formatSize(totalBytes)}`;
    uploadModal.classList.add('show');

    (async function seqUpload() {
      for (let i = 0; i < files.length; i++) {
        const file = files[i];
        uploadFname.textContent = file.name;
        try {
          await uploadSingle(file, (loaded) => {
            // loaded = bytes loaded for current file
            const percent = Math.min(100, ((uploadedBytesSoFar + loaded) / totalBytes) * 100);
            setProgress(percent);
            uploadStatus.textContent = `${i} / ${files.length} • ${formatSize(Math.round(uploadedBytesSoFar + loaded))} / ${formatSize(totalBytes)}`;
          });
          // after file completed
          uploadedBytesSoFar += file.size;
          setProgress(Math.min(100, (uploadedBytesSoFar / totalBytes) * 100));
          uploadStatus.textContent = `${i+1} / ${files.length} • ${formatSize(uploadedBytesSoFar)} / ${formatSize(totalBytes)}`;
        } catch (err) {
          console.error(err);
          showToast('Chyba nahrávání: ' + (err.message || err), true);
        }
      }
      // finalize
      setProgress(100);
      setTimeout(()=> {
        uploadModal.classList.remove('show');
        setProgress(0);
        uploadInput.value = '';
        loadList();
      }, 600);
    })();
  });

  function uploadSingle(file, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', API, true);
      const fd = new FormData();
      fd.append('file', file);
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          onProgress(e.loaded);
        }
      });
      xhr.onload = () => {
        try {
          const res = JSON.parse(xhr.responseText || '{}');
          if (xhr.status >= 200 && xhr.status < 300 && res.success) {
            onProgress(file.size);
            resolve(res);
          } else {
            reject(new Error(res.error || 'Chyba při uploadu'));
          }
        } catch (err) {
          reject(err);
        }
      };
      xhr.onerror = () => reject(new Error('Síťová chyba'));
      xhr.send(fd);
    });
  }

  function setProgress(percent) {
    progressText.textContent = Math.round(percent) + '%';
    const r = 40;
    const circumference = 2 * Math.PI * r;
    const offset = circumference - (percent / 100) * circumference;
    progressCircle.style.strokeDasharray = circumference;
    progressCircle.style.strokeDashoffset = offset;
  }

  // ---------- bg rotation ----------
  function startBgLoop() {
    if (!bgLayers.length) return;
    bgLayers.forEach((b)=> b.classList.remove('show'));
    let idx = 0;
    bgLayers[idx].classList.add('show');
    setInterval(()=> {
      const prev = idx;
      idx = (idx + 1) % bgLayers.length;
      bgLayers[prev].classList.remove('show');
      bgLayers[idx].classList.add('show');
    }, 6000 + Math.floor(Math.random()*3000));
  }

  // ---------- helpers ----------
  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024*1024) return Math.round(bytes/1024) + ' KB';
    return Math.round(bytes/(1024*1024)) + ' MB';
  }

  function escapeHtml(s){ return String(s).replace(/[&<>"'`=\/]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c] || c; }); }

  function showToast(msg, err=false) {
    const t = document.createElement('div'); t.className='toast'; t.textContent = msg;
    if (err) t.style.background = 'rgba(200,40,40,0.95)';
    document.body.appendChild(t);
    setTimeout(()=> t.style.opacity = '0', 2400);
    setTimeout(()=> t.remove(), 3000);
  }

  // ---------- keyboard & swipe ----------
  document.addEventListener('keydown', (e) => {
    if (!isShown) return;
    if (e.key === 'Escape') closeModal();
    if (e.key === 'ArrowRight') nextImage();
    if (e.key === 'ArrowLeft') prevImage();
    if (e.key === 'd') downloadCurrent();
  });

  (function attachSwipe(){
    let sx=0, dx=0, sy=0, dy=0;
    modalBody.addEventListener('touchstart', (e) => { if (e.touches.length === 1) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; dx=dy=0; } }, {passive:true});
    modalBody.addEventListener('touchmove', (e) => { if (e.touches.length === 1) { dx = e.touches[0].clientX - sx; dy = e.touches[0].clientY - sy; } }, {passive:true});
    modalBody.addEventListener('touchend', () => { if (Math.abs(dx) > 60 && Math.abs(dy) < 120) { if (dx < 0) nextImage(); else prevImage(); } sx=sy=dx=dy=0; }, {passive:true});
  })();

  // refresh button
  refreshBtn.addEventListener('click', loadList);

  // expose globals
  window.nextImage = nextImage;
  window.prevImage = prevImage;
  window.downloadCurrent = downloadCurrent;
  window.closeModal = closeModal;

  // init
  loadList();
  if (window.feather) feather.replace();
})();
</script>
</body>
</html>
