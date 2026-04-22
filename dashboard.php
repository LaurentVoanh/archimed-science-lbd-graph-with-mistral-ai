<?php
/**
 * ARCHIMEDES DASHBOARD v6.5 - ULTIMATE REPAIR
 * Fix : Détection dynamique des colonnes de la table 'hypotheses'
 */

define('ARCH_VERSION', '5.0'); 
define('DASH_VERSION', '6.5');
define('DB_PATH', __DIR__ . '/archimedes.db');
define('OUTPUT_PATH', __DIR__ . '/OUTPUT/');
define('LOG_PATH', __DIR__ . '/archimedes.log');

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- AUTO-DETECTION TABLE EDGES ---
    $qEdges = $db->query("PRAGMA table_info(edges)");
    $colsEdges = $qEdges->fetchAll(PDO::FETCH_COLUMN, 1);
    $colA = in_array('concept_a', $colsEdges) ? 'concept_a' : (in_array('source', $colsEdges) ? 'source' : $colsEdges[0]);
    $colB = in_array('concept_b', $colsEdges) ? 'concept_b' : (in_array('target', $colsEdges) ? 'target' : $colsEdges[1]);

    // --- AUTO-DETECTION TABLE HYPOTHESES (Fix pour l'erreur Title/Abstract) ---
    $qHypo = $db->query("PRAGMA table_info(hypotheses)");
    $colsHypo = $qHypo->fetchAll(PDO::FETCH_COLUMN, 1);
    // On cherche les noms probables, sinon on prend les colonnes par index
    $hTitle = in_array('title', $colsHypo) ? 'title' : (in_array('hypothesis', $colsHypo) ? 'hypothesis' : $colsHypo[min(1, count($colsHypo)-1)]);
    $hDesc  = in_array('abstract', $colsHypo) ? 'abstract' : (in_array('content', $colsHypo) ? 'content' : $colsHypo[min(2, count($colsHypo)-1)]);

} catch (Exception $e) {
    die("Erreur base de données : " . $e->getMessage());
}

// Stats
$stats = [
    'concepts'   => (int)($db->query("SELECT COUNT(*) FROM concepts")->fetchColumn() ?: 0),
    'relations'  => (int)($db->query("SELECT COUNT(*) FROM edges")->fetchColumn() ?: 0),
    'hypotheses' => (int)($db->query("SELECT COUNT(*) FROM hypotheses")->fetchColumn() ?: 0),
    'preprints'  => is_dir(OUTPUT_PATH) ? count(glob(OUTPUT_PATH . "*.md")) : 0
];

$tab = $_GET['tab'] ?? 'network';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>ARCHIMEDES CONTROL v<?php echo DASH_VERSION; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/water.css@2/out/dark.css">
    <style>
        :root { --neon: #00e5ff; --purp: #9d00ff; --bg: #0a0a0b; }
        body { background: var(--bg); font-family: 'Consolas', monospace; max-width: 1200px; }
        header { border-bottom: 1px solid var(--neon); padding: 1rem 0; display: flex; justify-content: space-between; align-items: center; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 2rem 0; }
        .stat-card { background: #16161a; padding: 1.5rem; border-radius: 8px; border: 1px solid #222; text-align: center; }
        .stat-val { font-size: 2rem; color: var(--neon); font-weight: bold; display: block; }
        nav { display: flex; gap: 5px; margin-bottom: 2rem; }
        nav a { flex: 1; text-align: center; padding: 10px; background: #1a1a1d; text-decoration: none; color: #777; border-radius: 4px; }
        nav a.active { background: var(--neon); color: #000; font-weight: bold; }
        .panel { background: #111114; padding: 2rem; border-radius: 12px; border: 1px solid #1f1f23; }
        table { width: 100%; border-collapse: collapse; }
        th { color: var(--purp); border-bottom: 1px solid #333; text-align: left; padding: 10px; }
        td { padding: 12px 10px; border-bottom: 1px solid #1a1a1a; }
        .hypo-box { border-left: 3px solid var(--purp); background: #1a1a20; padding: 15px; margin-bottom: 15px; border-radius: 0 8px 8px 0; }
        #log-term { background: #000; color: #0f0; padding: 15px; height: 400px; overflow-y: auto; font-size: 0.8rem; display: flex; flex-direction: column-reverse; }
    </style>
</head>
<body>

<header>
    <h1>ARCHIMEDES <span style="color:var(--neon)">CORE</span></h1>
    <div style="text-align:right">
        <span style="color:var(--neon); font-weight:bold;"><?php echo date('H:i:s'); ?></span><br>
        <small style="color:#555">v<?php echo ARCH_VERSION; ?> Active</small>
    </div>
</header>

<div class="stats-grid">
    <div class="stat-card"><span class="stat-val"><?php echo $stats['concepts']; ?></span>Concepts</div>
    <div class="stat-card"><span class="stat-val"><?php echo $stats['relations']; ?></span>Liaisons</div>
    <div class="stat-card"><span class="stat-val"><?php echo $stats['hypotheses']; ?></span>Inférences</div>
    <div class="stat-card"><span class="stat-val"><?php echo $stats['preprints']; ?></span>Preprints</div>
</div>

<nav>
    <a href="?tab=network" class="<?php echo $tab == 'network' ? 'active' : ''; ?>">🌐 RÉSEAU</a>
    <a href="?tab=hypotheses" class="<?php echo $tab == 'hypotheses' ? 'active' : ''; ?>">💡 ANALYSE</a>
    <a href="?tab=preprints" class="<?php echo $tab == 'preprints' ? 'active' : ''; ?>">📜 ARCHIVES</a>
    <a href="?tab=logs" class="<?php echo $tab == 'logs' ? 'active' : ''; ?>">📟 LOGS</a>
</nav>

<main class="panel">
    <?php if ($tab == 'network'): ?>
        <h2>🌐 Topologie du Graphe</h2>
        <table>
            <thead><tr><th>Source</th><th>Vecteur</th><th>Cible</th></tr></thead>
            <tbody>
                <?php
                $edges = $db->query("SELECT $colA AS a, $colB AS b FROM edges LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($edges as $e): ?>
                <tr>
                    <td><strong style="color:var(--neon)"><?php echo htmlspecialchars($e['a'] ?? 'N/A'); ?></strong></td>
                    <td style="color:#39ff14">➔</td>
                    <td><strong style="color:var(--purp)"><?php echo htmlspecialchars($e['b'] ?? 'N/A'); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ($tab == 'hypotheses'): ?>
        <h2>💡 Inférences Bayesiennes</h2>
        <?php
        // On utilise les colonnes détectées dynamiquement
        $hypo = $db->query("SELECT $hTitle AS title, $hDesc AS abstract FROM hypotheses ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        if (!$hypo) echo "<p>Aucune donnée.</p>";
        foreach ($hypo as $h): ?>
            <div class="hypo-box">
                <h3 style="margin:0; color:#fff;"><?php echo htmlspecialchars($h['title'] ?? 'Sans titre'); ?></h3>
                <p style="color:#888; font-size:0.9rem; margin-top:10px;">
                    <?php echo nl2br(htmlspecialchars($h['abstract'] ?? 'Aucun contenu disponible.')); ?>
                </p>
            </div>
        <?php endforeach; ?>

    <?php elseif ($tab == 'preprints'): ?>
        <h2>📜 Bibliothèque Preprints</h2>
        <?php
        $files = is_dir(OUTPUT_PATH) ? glob(OUTPUT_PATH . "*.md") : [];
        foreach ($files as $f): 
            $content = file_get_contents($f);
            preg_match('/# (.*)/', $content, $m);
            $title = $m[1] ?? basename($f);
        ?>
            <details style="margin-bottom:10px; border:1px solid #222;">
                <summary style="padding:10px; cursor:pointer;">📄 <?php echo htmlspecialchars($title); ?></summary>
                <pre style="padding:15px; background:#000; font-size:0.8rem; white-space:pre-wrap;"><?php echo htmlspecialchars($content); ?></pre>
            </details>
        <?php endforeach; ?>

    <?php elseif ($tab == 'logs'): ?>
        <h2>📟 Terminal Système</h2>
        <div id="log-term">
            <?php 
            if (file_exists(LOG_PATH)) {
                $logs = array_reverse(array_slice(file(LOG_PATH), -100));
                foreach($logs as $l) echo "<span>> " . htmlspecialchars($l) . "</span>";
            }
            ?>
        </div>
    <?php endif; ?>
</main>

<footer style="text-align:center; padding:2rem; font-size:0.7rem; color:#444;">
    ARCHIMEDES v<?php echo ARCH_VERSION; ?> | DASHBOARD v<?php echo DASH_VERSION; ?>
</footer>

</body>
</html>
