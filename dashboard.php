<?php
// ============================================================
// ARCHIMEDES DASHBOARD v5.3 — Correction Structure DB
// ============================================================

define('DB_PATH', __DIR__ . '/archimedes.db');
define('OUTPUT_PATH', __DIR__ . '/OUTPUT/');
define('LOG_PATH', __DIR__ . '/archimedes.log');

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Base de données introuvable. Lancez archimedes.php d'abord.");
}

// Stats rapides
$stats = [
    'concepts'   => (int)$db->query("SELECT COUNT(*) FROM concepts")->fetchColumn(),
    'relations'  => (int)$db->query("SELECT COUNT(*) FROM edges")->fetchColumn(),
    'hypotheses' => (int)$db->query("SELECT COUNT(*) FROM hypotheses")->fetchColumn(),
    'preprints'  => is_dir(OUTPUT_PATH) ? count(glob(OUTPUT_PATH . "*.md")) : 0
];

$tab = $_GET['tab'] ?? 'knowledge';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Archimedes v5.3 — Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/water.css@2/out/dark.css">
    <style>
        :root { --accent: #00ffcc; --bg-card: #1a1a1a; }
        body { max-width: 1200px; margin: auto; }
        .grid-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0; }
        .card-stat { background: var(--bg-card); padding: 20px; border-radius: 10px; text-align: center; border-bottom: 3px solid var(--accent); }
        .nav { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 1px solid #444; padding-bottom: 10px; }
        .btn { background: #333; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn.active { background: var(--accent); color: black; font-weight: bold; }
        .hypo-card { background: var(--bg-card); border: 1px solid #333; padding: 20px; margin-bottom: 15px; border-radius: 8px; }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.8em; font-weight: bold; text-transform: uppercase; }
        .validated { background: #28a745; color: white; }
        .failed, .rejected { background: #dc3545; color: white; }
        .pending { background: #ffc107; color: black; }
        .chart-container { background: #000; border-radius: 5px; padding: 5px; border: 1px solid #444; }
        pre { background: #0b0b0b; border: 1px solid #222; max-height: 500px; overflow: auto; padding: 10px; color: #00ff66; font-family: 'Courier New', monospace; }
    </style>
</head>
<body>

    <header style="text-align: center; margin-top: 40px;">
        <h1>🏛️ ARCHIMEDES <small style="color:var(--accent)">Control Panel</small></h1>
        <p>Système de Découverte Scientifique Autonome</p>
    </header>

    <div class="grid-stats">
        <div class="card-stat"><h2><?php echo $stats['concepts']; ?></h2>Concepts</div>
        <div class="card-stat"><h2><?php echo $stats['relations']; ?></h2>Relations</div>
        <div class="card-stat"><h2><?php echo $stats['hypotheses']; ?></h2>Hypothèses</div>
        <div class="card-stat"><h2><?php echo $stats['preprints']; ?></h2>Articles</div>
    </div>

    <div class="nav">
        <a href="?tab=knowledge" class="btn <?php echo $tab=='knowledge'?'active':''; ?>">Graphe</a>
        <a href="?tab=hypotheses" class="btn <?php echo $tab=='hypotheses'?'active':''; ?>">Hypothèses & Tests</a>
        <a href="?tab=preprints" class="btn <?php echo $tab=='preprints'?'active':''; ?>">Preprints</a>
        <a href="?tab=logs" class="btn <?php echo $tab=='logs'?'active':''; ?>">Logs</a>
    </div>

    <?php if ($tab == 'knowledge'): ?>
        <h2>🌐 Graphe de Connaissances (Dernières extractions)</h2>
        <table>
            <thead>
                <tr><th>Source (A)</th><th>Relation</th><th>Cible (B)</th><th>Confiance</th></tr>
            </thead>
            <tbody>
                <?php
                // On tente de joindre via source_id ou concept_a selon ce qui existe
                $stmt = $db->query("SELECT c1.name as s, e.relation_type as r, c2.name as t, e.confidence as c 
                                    FROM edges e 
                                    JOIN concepts c1 ON (e.source_id = c1.id) 
                                    JOIN concepts c2 ON (e.target_id = c2.id) 
                                    ORDER BY e.id DESC LIMIT 50");
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><b><?php echo htmlspecialchars($r['s']); ?></b></td>
                        <td><code><?php echo htmlspecialchars($r['r']); ?></code></td>
                        <td><b><?php echo htmlspecialchars($r['t']); ?></b></td>
                        <td><?php echo round($r['c']*100); ?>%</td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

    <?php elseif ($tab == 'hypotheses'): ?>
        <h2>🧪 Simulations Cinétiques & Validations</h2>
        <?php
        // Correction de la requête pour correspondre aux colonnes réelles (concept_a / concept_c)
        $q = "SELECT h.*, c1.name as name_a, c2.name as name_c 
              FROM hypotheses h
              JOIN concepts c1 ON h.concept_a = c1.id 
              JOIN concepts c2 ON h.concept_c = c2.id 
              ORDER BY h.id DESC";
        
        try {
            $res = $db->query($q)->fetchAll(PDO::FETCH_ASSOC);
            if (!$res) echo "<p>En attente de la Phase 3 (Raisonnement de Graphe)...</p>";
            
            foreach ($res as $h): 
                $st = strtolower($h['status'] ?? 'pending');
            ?>
                <div class="hypo-card">
                    <span class="badge <?php echo $st; ?>"><?php echo $st; ?></span>
                    <strong style="font-size: 1.2em; margin-left: 10px;">
                        <?php echo htmlspecialchars($h['name_a']); ?> ↔ <?php echo htmlspecialchars($h['name_c']); ?>
                    </strong>
                    <p style="color: #bbb; margin: 10px 0; font-style: italic;">
                        <?php echo htmlspecialchars($h['mechanism'] ?? 'Aucun mécanisme décrit.'); ?>
                    </p>
                    
                    <div style="display: flex; gap: 20px; align-items: center; background: #222; padding: 10px; border-radius: 5px;">
                        <div style="flex: 1;">
                            <small><strong>PARAMÈTRES CALCULÉS :</strong></small><br>
                            Vmax: <code><?php echo $h['vmax']; ?></code><br>
                            Km: <code><?php echo $h['km']; ?></code><br>
                            Confiance: <code><?php echo round(($h['confidence']??0)*100); ?>%</code>
                        </div>
                        <div style="flex: 1; text-align: right;">
                            <canvas id="chart-<?php echo $h['id']; ?>" width="220" height="90" class="chart-container"></canvas>
                        </div>
                    </div>

                    <script>
                    (function() {
                        const canvas = document.getElementById('chart-<?php echo $h['id']; ?>');
                        const ctx = canvas.getContext('2d');
                        const Vmax = <?php echo (float)$h['vmax']; ?>;
                        const Km = <?php echo (float)$h['km']; ?>;
                        
                        ctx.strokeStyle = '<?php echo ($st == "validated") ? "#00ffcc" : "#ff4444"; ?>';
                        ctx.lineWidth = 2;
                        ctx.beginPath();
                        
                        // Dessin de la courbe de Michaelis-Menten
                        for(let x=0; x < canvas.width; x++) {
                            let s = x / 1000; // Simule concentration substrat
                            let v = (Vmax * s) / (Km + s);
                            let y = canvas.height - (v / (Vmax * 1.2) * canvas.height); 
                            if(x === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
                        }
                        ctx.stroke();
                        
                        // Ligne de Km (Repère)
                        ctx.setLineDash([2, 2]);
                        ctx.strokeStyle = '#555';
                        ctx.beginPath();
                        ctx.moveTo(Km * 1000, 0); ctx.lineTo(Km * 1000, canvas.height);
                        ctx.stroke();
                    })();
                    </script>
                </div>
            <?php endforeach; 
        } catch (Exception $e) {
            echo "<p class='badge rejected'>Erreur SQL : Les colonnes de votre table 'hypotheses' ne correspondent pas. Vérifiez archimedes.php.</p>";
            echo "<pre>Détails : " . $e->getMessage() . "</pre>";
        }
        ?>

    <?php elseif ($tab == 'preprints'): ?>
        <h2>📜 Articles Scientifiques Validés</h2>
        <?php
        $files = is_dir(OUTPUT_PATH) ? glob(OUTPUT_PATH . "*.md") : [];
        if (!$files) echo "<p>Aucune découverte n'a encore été transformée en Preprint.</p>";
        foreach ($files as $f): ?>
            <details style="background: #1a1a1a; margin-bottom: 10px; border-radius: 5px;">
                <summary style="padding: 15px; cursor: pointer;">📑 <?php echo basename($f); ?> (Généré le <?php echo date("d/m/Y", filemtime($f)); ?>)</summary>
                <pre style="white-space: pre-wrap; color: #eee;"><?php echo htmlspecialchars(file_get_contents($f)); ?></pre>
            </details>
        <?php endforeach; ?>

    <?php elseif ($tab == 'logs'): ?>
        <h2>📟 Terminal de Monitoring</h2>
        <pre id="log-terminal"><?php 
            if (file_exists(LOG_PATH)) {
                $lines = file(LOG_PATH);
                $last_lines = array_slice($lines, -100);
                echo htmlspecialchars(implode("", array_reverse($last_lines)));
            } else {
                echo "En attente de logs...";
            }
        ?></pre>
        <button onclick="window.location.reload()" class="btn">Actualiser les Logs</button>
    <?php endif; ?>

    <footer style="margin-top: 50px; opacity: 0.3; text-align: center; font-size: 0.8em;">
        <hr>
        <p>Archimedes Engine v5.3 | Laragon Local Environment</p>
    </footer>
</body>
</html>
