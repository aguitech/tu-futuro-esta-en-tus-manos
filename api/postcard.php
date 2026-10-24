<?php
declare(strict_types=1);

/**
 * GET /p/<token>  → the participant's digital postcard
 *
 * Rendered server-side so the QR link works from a phone with no JS.
 * The card can be downloaded as a PNG by the browser, and the participant
 * can optionally request a conversation with an advisor.
 */

require __DIR__ . '/../api/_helpers.php';
require __DIR__ . '/../server/lib/SessionStore.php';

use Portal\SessionStore;

$token = basename((string) ($_GET['token'] ?? ''));

if ($token === '') {
    http_response_code(404);
    echo '<h1>404 — Postal no encontrada</h1>';
    exit;
}

$store = new SessionStore(tf1_db_path());
$card  = $store->postcard($token);

if ($card === null) {
    http_response_code(404);
    echo '<h1>404 — Postal no encontrada</h1>';
    exit;
}

$store->markDownload($token);

$goal   = (string) ($card['goal'] ?? '');
$name   = (string) ($card['name'] ?? 'Participante');
$msg    = (string) ($card['message'] ?? 'Hoy comienzo a construir mi futuro');

$goalLabels = [
    'viajar'    => ['VIAJAR',    'Ver el mundo',        '#4DA3FF'],
    'aprender'  => ['APRENDER',  'Crecer cada día',     '#FFD52A'],
    'disfrutar' => ['DISFRUTAR', 'Disfrutar tu tiempo', '#FF8A5B'],
];
[$goalName, $goalCopy, $goalAccent] = $goalLabels[$goal] ?? ['MI META', 'Tu meta, tu escena', '#FFD52A'];

$q = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
$n = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
$gn = htmlspecialchars($goalName, ENT_QUOTES, 'UTF-8');
$gc = htmlspecialchars($goalCopy, ENT_QUOTES, 'UTF-8');
$ga = htmlspecialchars($goalAccent, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tu recuerdo · Afore Coppel</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{
    min-height:100vh;display:grid;place-items:center;padding:24px;
    background:radial-gradient(1000px 600px at 20% 0%, #12306B 0%, transparent 60%),
               radial-gradient(800px 520px at 100% 100%, #0A2A55 0%, transparent 55%), #011538;
    font-family:"Montserrat",system-ui,sans-serif;color:#fff;
    -webkit-font-smoothing:antialiased;
  }
  .card{
    width:min(520px,100%);border-radius:28px;overflow:hidden;
    background:linear-gradient(180deg,#071B3D 0%,#011538 100%);
    border:1px solid rgba(255,213,42,.28);
    box-shadow:0 40px 110px rgba(0,0,0,.65);
    animation:in .7s cubic-bezier(.22,1,.36,1) both;
  }
  @keyframes in{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:none}}
  .art{
    position:relative;height:220px;overflow:hidden;
    background:
      radial-gradient(circle at 50% 120%, rgba(255,213,42,.28), transparent 62%),
      radial-gradient(circle at 22% 24%, rgba(77,163,255,.20), transparent 55%),
      linear-gradient(160deg,#0A2452,#010D27);
  }
  .art::after{
    content:"";position:absolute;inset:0;
    background:repeating-linear-gradient(0deg,rgba(255,255,255,.03) 0 1px,transparent 1px 4px);
  }
  .orb{
    position:absolute;left:50%;top:56%;transform:translate(-50%,-50%);
    width:120px;height:120px;border-radius:50%;
    background:radial-gradient(circle,rgba(255,213,42,.9) 0%,rgba(255,213,42,.22) 45%,transparent 72%);
    animation:pulse 3.4s ease-in-out infinite;
  }
  @keyframes pulse{0%,100%{transform:translate(-50%,-50%) scale(1)}50%{transform:translate(-50%,-50%) scale(1.14)}}
  .stars{position:absolute;inset:0}
  .stars i{
    position:absolute;width:2px;height:2px;border-radius:50%;background:#fff;
    animation:tw 3s ease-in-out infinite;
  }
  @keyframes tw{0%,100%{opacity:.25}50%{opacity:1}}
  .brand{
    position:absolute;top:22px;left:24px;
    font-size:13px;font-weight:900;letter-spacing:.2em;color:#FFD52A;z-index:2;
  }
  .goal-badge{
    position:absolute;bottom:20px;left:50%;transform:translateX(-50%);
    padding:9px 22px;border-radius:99px;white-space:nowrap;
    background:rgba(1,13,39,.7);border:1px solid <?= $ga ?>;
    color:<?= $ga ?>;font-size:12px;font-weight:900;letter-spacing:.2em;z-index:2;
  }
  .body{padding:30px 32px 34px;text-align:center}
  .quote{
    font-size:25px;font-weight:800;line-height:1.28;color:#FFD52A;font-style:italic;
    margin-bottom:18px;
  }
  .who{font-size:14px;font-weight:700;color:rgba(255,255,255,.8);margin-bottom:6px}
  .goalcopy{font-size:12px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.5)}
  .sep{height:1px;background:linear-gradient(90deg,transparent,rgba(255,213,42,.3),transparent);margin:26px 0}
  .actions{display:grid;gap:10px}
  .btn{
    display:flex;align-items:center;justify-content:center;gap:9px;
    padding:15px;border-radius:14px;font-size:13px;font-weight:800;
    letter-spacing:.08em;text-transform:uppercase;text-decoration:none;cursor:pointer;
    border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.05);color:#fff;
    transition:.2s;
  }
  .btn:hover{background:rgba(255,255,255,.12)}
  .btn.primary{background:#FFD52A;border-color:#FFD52A;color:#011538}
  .btn.primary:hover{filter:brightness(1.1)}
  .foot{margin-top:24px;font-size:10.5px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.35)}
</style>
</head>
<body>
<main class="card">
  <div class="art">
    <div class="stars" id="stars"></div>
    <div class="brand">AFORE COPPEL</div>
    <div class="orb"></div>
    <div class="goal-badge"><?= $gn ?></div>
  </div>
  <div class="body">
    <div class="quote">"<?= $m ?>"</div>
    <div class="who"><?= $n ?></div>
    <div class="goalcopy"><?= $gc ?></div>
    <div class="sep"></div>
    <div class="actions">
      <button class="btn primary" onclick="window.print()">⤓ Descargar postal</button>
      <button class="btn" id="advisorBtn">💬 Hablar con un asesor</button>
    </div>
    <div class="foot">Tu futuro está en tus manos · Guadalajara</div>
  </div>
</main>

<script>
  // starfield
  const s = document.getElementById('stars');
  for (let i = 0; i < 46; i++) {
    const st = document.createElement('i');
    st.style.left = (Math.random() * 100) + '%';
    st.style.top = (Math.random() * 100) + '%';
    st.style.animationDelay = (Math.random() * 3) + 's';
    s.appendChild(st);
  }

  const btn = document.getElementById('advisorBtn');
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    btn.textContent = '✓ Solicitud enviada';
    try {
      await fetch('../api/metrics.php?action=advisor', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: <?= json_encode($token) ?> }),
      });
    } catch (e) { /* offline is fine — the session is already recorded */ }
  });
</script>
</body>
</html>
