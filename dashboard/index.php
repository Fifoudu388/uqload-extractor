<?php
/**
 * Tableau de bord des extracteurs — etat des services + controle du
 * load balancer (activation / desactivation / choix des backends),
 * via l'API Railway. Voir README.md pour la configuration.
 *
 * Variables d'environnement :
 *   DASHBOARD_PASSWORD  (obligatoire) mot de passe d'acces a cette page
 *   EXTRACTOR_URLS      URL des services extracteurs, separees par des espaces
 *   PUBLIC_URL          entree publique, ex. https://stream.visionary.qzz.io
 *   EXTRACTOR_API_KEY   (optionnel) une cle UQLOAD_API_KEYS, pour les tests
 *   RAILWAY_API_TOKEN, RAILWAY_TOKEN_TYPE (project|account),
 *   RAILWAY_PROJECT_ID, RAILWAY_ENVIRONMENT_ID, LB_SERVICE_ID
 *                       (controle du load balancer ; sans eux, etat seul)
 *   EXTRACTOR_SERVICE_IDS  identifiants Railway des services extracteurs,
 *                       dans le meme ordre que EXTRACTOR_URLS : ils servent
 *                       a activer / desactiver un extracteur partout
 */

require __DIR__ . '/lib/railway.php';
require __DIR__ . '/lib/status.php';

function envList(string $name): array
{
    $raw = trim((string) (getenv($name) ?: ''));
    if ($raw === '') {
        return [];
    }
    return array_values(preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY));
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$PASSWORD = (string) (getenv('DASHBOARD_PASSWORD') ?: '');
$POOL = envList('EXTRACTOR_URLS');
$PUBLIC_URL = trim((string) (getenv('PUBLIC_URL') ?: ''));
$API_KEY = (string) (getenv('EXTRACTOR_API_KEY') ?: '');
$EXTRACTOR_SERVICES = envList('EXTRACTOR_SERVICE_IDS');

// --- Sans mot de passe configure, on n'affiche que la marche a suivre. ---
if ($PASSWORD === '') {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Configuration manquante</title>'
        . '<body style="font-family:system-ui;background:#0f1420;color:#e8edf5;padding:2rem">'
        . '<h1>Configuration incomplete</h1><p>La variable <code>DASHBOARD_PASSWORD</code> '
        . 'n\'est pas definie sur ce service. Ajoute-la dans Railway (Settings &rarr; Variables), '
        . 'puis redeploie. Voir <code>dashboard/README.md</code>.</p></body>';
    exit;
}

// --- Authentification HTTP Basic (le mot de passe fait foi). ---
$given = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
if (!hash_equals($PASSWORD, $given)) {
    header('WWW-Authenticate: Basic realm="Extracteurs"');
    http_response_code(401);
    echo 'Acces refuse.';
    exit;
}

session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$railway = RailwayClient::fromEnv();

$collectStatus = function () use ($railway, $POOL, $PUBLIC_URL, $EXTRACTOR_SERVICES) {
    $backends = [];
    foreach ($POOL as $url) {
        $backends[] = checkBackend($url);
    }
    $lb = null;
    $lbError = null;
    if ($railway !== null) {
        try {
            $lb = $railway->lbState();
        } catch (Throwable $ex) {
            $lbError = $ex->getMessage();
        }
    }
    $disabled = null;
    $disabledError = null;
    if ($railway !== null && $EXTRACTOR_SERVICES !== []) {
        try {
            $disabled = $railway->getDisabledExtractors($EXTRACTOR_SERVICES);
        } catch (Throwable $ex) {
            $disabledError = $ex->getMessage();
        }
    }
    return [
        'disabled' => $disabled,
        'disabled_error' => $disabledError,
        'extractor_control' => $railway !== null && $EXTRACTOR_SERVICES !== [],
        'checked_at' => date('H:i:s'),
        'public' => checkPublic($PUBLIC_URL),
        'public_url' => $PUBLIC_URL,
        'backends' => $backends,
        'lb' => $lb,
        'lb_error' => $lbError,
        'control' => $railway !== null,
    ];
};

// --- API JSON pour le rafraichissement automatique de la page. ---
if (($_GET['api'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($collectStatus(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Actions (POST, protegees par le jeton CSRF). ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        echo 'Jeton CSRF invalide.';
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($railway === null && $action !== 'test') {
            throw new Exception('Controle Railway non configure (jeton ou identifiants manquants).');
        }
        if ($action === 'activate') {
            $selected = array_values(array_intersect($POOL, (array) ($_POST['backends'] ?? [])));
            if ($selected === []) {
                throw new Exception('Coche au moins un serveur a mettre derriere le load balancer.');
            }
            $railway->setBackends($selected);
            $_SESSION['flash'] = ['ok', 'Load balancer active avec ' . count($selected)
                . ' serveur(s). Redeploiement en cours, laisse-lui environ une minute.'];
        } elseif ($action === 'disable') {
            $railway->disable();
            $_SESSION['flash'] = ['ok', 'Load balancer desactive. Redeploiement en cours, environ une minute.'];
        } elseif ($action === 'toggle_extractor') {
            $name = (string) ($_POST['name'] ?? '');
            if (!in_array($name, EXTRACTOR_NAMES_ALL, true)) {
                throw new Exception('Extracteur inconnu.');
            }
            if ($EXTRACTOR_SERVICES === []) {
                throw new Exception('EXTRACTOR_SERVICE_IDS non configure : impossible de piloter les serveurs extracteurs.');
            }
            $current = $railway->getDisabledExtractors($EXTRACTOR_SERVICES);
            if (in_array($name, $current, true)) {
                $new = array_values(array_diff($current, [$name]));
                $verb = 'reactive';
            } else {
                $new = array_merge($current, [$name]);
                $verb = 'desactive';
            }
            $railway->setDisabledExtractors($EXTRACTOR_SERVICES, $new);
            $_SESSION['flash'] = ['ok', 'Extracteur ' . $name . ' ' . $verb . ' sur '
                . count($EXTRACTOR_SERVICES) . ' serveur(s). Redeploiement en cours, effet d\'ici environ une minute.'];
        } elseif ($action === 'test') {
            $base = $PUBLIC_URL !== '' ? $PUBLIC_URL : ($POOL[0] ?? '');
            if ($base === '') {
                throw new Exception('Ni PUBLIC_URL ni EXTRACTOR_URLS ne sont definis : impossible de tester.');
            }
            $results = [];
            foreach (array_keys(TEST_CASES) as $name) {
                $results[] = testExtractor($base, $name, $API_KEY);
            }
            $_SESSION['tests'] = $results;
            $_SESSION['flash'] = ['ok', 'Tests d\'extraction termines (base : ' . $base . ').'];
        } else {
            throw new Exception('Action inconnue.');
        }
    } catch (Throwable $ex) {
        $_SESSION['flash'] = ['err', $ex->getMessage()];
    }
    header('Location: ./');
    exit;
}

$status = $collectStatus();
$tests = $_SESSION['tests'] ?? null;
unset($_SESSION['tests']);

$publicLabel = [
    'online' => 'En ligne',
    'lb_disabled' => 'Load balancer desactive',
    'down' => 'Injoignable',
    'unexpected' => 'Reponse inattendue',
    'not_configured' => 'Non configuree',
][$status['public']['state']] ?? $status['public']['state'];
if (($status['public']['state'] ?? '') === 'online') {
    $publicLabel .= !empty($status['public']['via_lb']) ? ' (via le load balancer)' : ' (en direct)';
}
$publicClass = ($status['public']['state'] ?? '') === 'online' ? 'ok'
    : (($status['public']['state'] ?? '') === 'lb_disabled' ? 'warn' : 'bad');

$lbBackends = $status['lb']['backends'] ?? [];
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Extracteurs — Tableau de bord</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #0f1420; color: #e8edf5; }
  main { max-width: 880px; margin: 0 auto; padding: 1.2rem 1rem 3rem; }
  h1 { font-size: 1.35rem; margin: .2rem 0 .1rem; }
  h2 { font-size: 1.05rem; margin: 0 0 .6rem; }
  .sub { color: #93a1b5; font-size: .88rem; margin-bottom: 1.1rem; }
  section { background: #171e2e; border: 1px solid #26314a; border-radius: 12px; padding: 1rem 1.1rem; margin-bottom: 1rem; }
  .badge { display: inline-block; padding: .12rem .55rem; border-radius: 999px; font-size: .8rem; font-weight: 600; }
  .badge.ok { background: #14532d; color: #bbf7d0; }
  .badge.warn { background: #713f12; color: #fde68a; }
  .badge.bad { background: #7f1d1d; color: #fecaca; }
  .badge.off { background: #26314a; color: #93a1b5; }
  table { width: 100%; border-collapse: collapse; font-size: .92rem; }
  td, th { padding: .45rem .3rem; border-bottom: 1px solid #26314a; text-align: left; vertical-align: top; }
  tr:last-child td { border-bottom: none; }
  .mono { font-family: ui-monospace, monospace; font-size: .85rem; word-break: break-all; }
  .muted { color: #93a1b5; font-size: .86rem; }
  .flash { border-radius: 10px; padding: .7rem .9rem; margin-bottom: 1rem; font-size: .93rem; }
  .flash.ok { background: #14532d; color: #dcfce7; }
  .flash.err { background: #7f1d1d; color: #fee2e2; }
  button { background: #2563eb; color: #fff; border: 0; border-radius: 9px; padding: .55rem 1rem; font-size: .95rem; font-weight: 600; cursor: pointer; }
  button.danger { background: #b91c1c; }
  button:disabled { opacity: .45; cursor: not-allowed; }
  label.check { display: block; padding: .4rem 0; font-size: .95rem; }
  label.check input { transform: scale(1.2); margin-right: .5rem; }
  .row { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; }
  code { background: #0f1420; padding: .05rem .35rem; border-radius: 5px; }
</style>
</head>
<body>
<main>
  <h1>Extracteurs — Tableau de bord</h1>
  <div class="sub">Etat verifie a <span id="checked-at"><?= e($status['checked_at']) ?></span> · rafraichissement automatique toutes les 30 s</div>

  <?php if ($flash): ?>
    <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
  <?php endif; ?>

  <section>
    <h2>Entree publique</h2>
    <div class="row">
      <span class="badge <?= $publicClass ?>" id="public-badge"><?= e($publicLabel) ?></span>
      <?php if ($PUBLIC_URL !== ''): ?><span class="mono"><?= e($PUBLIC_URL) ?></span><?php endif; ?>
      <?php if (isset($status['public']['ms'])): ?><span class="muted" id="public-ms"><?= (int) $status['public']['ms'] ?> ms</span><?php endif; ?>
    </div>
    <?php if (($status['public']['state'] ?? '') === 'down'): ?>
      <p class="muted">Erreur : <?= e((string) ($status['public']['error'] ?? '')) ?></p>
    <?php endif; ?>
  </section>

  <section>
    <h2>Load balancer</h2>
    <?php if ($railway === null): ?>
      <p><span class="badge off">Controle non configure</span></p>
      <p class="muted">Pour piloter le load balancer d'ici, il manque le jeton Railway et les
      identifiants du service LB (<code>RAILWAY_API_TOKEN</code>, <code>RAILWAY_PROJECT_ID</code>,
      <code>RAILWAY_ENVIRONMENT_ID</code>, <code>LB_SERVICE_ID</code>). Voir le README du dashboard.</p>
    <?php elseif ($status['lb_error'] !== null): ?>
      <p><span class="badge bad">Erreur API Railway</span></p>
      <p class="muted"><?= e((string) $status['lb_error']) ?></p>
    <?php elseif ($status['lb'] !== null && $status['lb']['active']): ?>
      <p><span class="badge ok" id="lb-badge">Actif — <?= count($lbBackends) ?> serveur(s)</span></p>
      <table>
        <?php foreach ($lbBackends as $b): ?>
          <tr><td class="mono"><?= e($b) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p><span class="badge off" id="lb-badge">Desactive</span></p>
      <p class="muted">Aucun backend configure : le service LB repond sa page « desactive » et ne relaie rien.</p>
    <?php endif; ?>

    <?php if ($railway !== null): ?>
      <form method="post" style="margin-top:.8rem">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="activate">
        <div class="muted" style="margin-bottom:.3rem">Serveurs a mettre derriere le load balancer :</div>
        <?php foreach ($POOL as $url): ?>
          <label class="check">
            <input type="checkbox" name="backends[]" value="<?= e($url) ?>" <?= in_array($url, $lbBackends, true) ? 'checked' : '' ?>>
            <span class="mono"><?= e($url) ?></span>
          </label>
        <?php endforeach; ?>
        <?php if ($POOL === []): ?>
          <p class="muted">Aucun serveur dans <code>EXTRACTOR_URLS</code> : rien a activer.</p>
        <?php endif; ?>
        <div class="row" style="margin-top:.6rem">
          <button type="submit" <?= $POOL === [] ? 'disabled' : '' ?>>Activer / mettre a jour</button>
        </div>
      </form>
      <form method="post" style="margin-top:.6rem" onsubmit="return confirm('Desactiver le load balancer ? Le domaine public ne repondra plus via le LB.');">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="disable">
        <button type="submit" class="danger">Desactiver le load balancer</button>
      </form>
    <?php endif; ?>
  </section>

  <section>
    <h2>Serveurs extracteurs</h2>
    <?php if ($POOL === []): ?>
      <p class="muted">Aucun serveur liste : definis <code>EXTRACTOR_URLS</code> sur ce service.</p>
    <?php else: ?>
      <table>
        <tr><th>Serveur</th><th>Etat</th><th>Reponse</th></tr>
        <?php foreach ($status['backends'] as $i => $b): ?>
          <tr>
            <td class="mono"><?= e($b['url']) ?></td>
            <td><span class="badge <?= $b['online'] ? 'ok' : 'bad' ?>" data-backend-badge="<?= $i ?>"><?= $b['online'] ? 'En ligne' : 'Hors ligne' ?></span></td>
            <td class="muted"><span data-backend-code="<?= $i ?>"><?= $b['code'] > 0 ? 'HTTP ' . (int) $b['code'] : e((string) $b['error']) ?></span> · <span data-backend-ms="<?= $i ?>"><?= (int) $b['ms'] ?></span> ms</td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </section>

  <section>
    <h2>Extracteurs (activation)</h2>
    <?php if (!$status['extractor_control']): ?>
      <p><span class="badge off">Controle par extracteur non configure</span></p>
      <p class="muted">Pour activer ou couper un extracteur d'ici, ajoute <code>EXTRACTOR_SERVICE_IDS</code>
      aux variables de ce service : les identifiants Railway de tes services extracteurs, dans le meme
      ordre que <code>EXTRACTOR_URLS</code>. Voir le README du dashboard.</p>
    <?php elseif ($status['disabled_error'] !== null): ?>
      <p><span class="badge bad">Erreur API Railway</span></p>
      <p class="muted"><?= e((string) $status['disabled_error']) ?></p>
    <?php else: ?>
      <table>
        <?php foreach (EXTRACTOR_NAMES_ALL as $name): $off = in_array($name, $status['disabled'] ?? [], true); ?>
          <tr>
            <td><?= e($name) ?></td>
            <td><span class="badge <?= $off ? 'bad' : 'ok' ?>"><?= $off ? 'Desactive' : 'Actif' ?></span></td>
            <td>
              <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="toggle_extractor">
                <input type="hidden" name="name" value="<?= e($name) ?>">
                <button type="submit" class="<?= $off ? '' : 'danger' ?>"><?= $off ? 'Reactiver' : 'Desactiver' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      <p class="muted">Un extracteur desactive repond a tout appel par une erreur <strong>HTTP 503</strong>
      (<code>extracteur_desactive</code>) avec un message explicite. La bascule pose la variable
      <code>DISABLED_EXTRACTORS</code> sur chaque serveur puis le redeploie : effet d'ici environ une minute.</p>
    <?php endif; ?>
  </section>

  <section>
    <h2>Tests d'extraction</h2>
    <p class="muted">Lance une vraie extraction de test (vidmoly, streamtape, lulustream) via
    <?= $PUBLIC_URL !== '' ? 'l\'entree publique' : 'le premier serveur' ?>. Cela verifie que les
    extracteurs trouvent bien un flux ; la lecture via le proxy depend ensuite de l'IP du serveur.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="test">
      <button type="submit">Lancer les tests</button>
    </form>
    <?php if ($tests !== null): ?>
      <table style="margin-top:.6rem">
        <?php foreach ($tests as $t): ?>
          <tr>
            <td><?= e($t['name']) ?></td>
            <td><span class="badge <?= $t['ok'] ? 'ok' : 'bad' ?>"><?= $t['ok'] ? 'OK' : 'Echec' ?></span></td>
            <td class="muted"><?= e($t['detail']) ?> · <?= (int) $t['ms'] ?> ms</td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </section>

  <p class="muted">Astuce : apres une activation ou une desactivation, le service LB met environ
  une minute a redemarrer avec la nouvelle configuration.</p>
</main>
<script>
setInterval(async () => {
  try {
    const r = await fetch('?api=status');
    const s = await r.json();
    document.getElementById('checked-at').textContent = s.checked_at;
    s.backends.forEach((b, i) => {
      const badge = document.querySelector('[data-backend-badge="' + i + '"]');
      if (badge) { badge.textContent = b.online ? 'En ligne' : 'Hors ligne'; badge.className = 'badge ' + (b.online ? 'ok' : 'bad'); }
      const code = document.querySelector('[data-backend-code="' + i + '"]');
      if (code) code.textContent = b.code > 0 ? 'HTTP ' + b.code : (b.error || '');
      const ms = document.querySelector('[data-backend-ms="' + i + '"]');
      if (ms) ms.textContent = b.ms;
    });
  } catch (e) { /* on reessaiera au prochain cycle */ }
}, 30000);
</script>
</body>
</html>
