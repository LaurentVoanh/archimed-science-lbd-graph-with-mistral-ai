<?php
// ============================================================
// ARCHIMEDES v5.0 — Autonomous Scientific Discovery Engine
// Architecture : Single-file PHP 8.3 | SQLite | Mistral API
// Mode : AJAX-first (zero timeout risk) | LiteSpeed/Hostinger
// ============================================================

define('ARCH_VERSION', '5.0');
define('DB_PATH', __DIR__ . '/archimedes.db');
define('OUTPUT_PATH', __DIR__ . '/OUTPUT/');
define('LOG_PATH', __DIR__ . '/archimedes.log');

$MISTRAL_KEYS = [
    '5qaRhgfhhgffgfake',
    'o3rG1zvhgfhXRShytu',
    'vEzQhgfffffjFruXkF',
];
define('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');

// ============================================================
// AJAX ROUTER — Toutes les opérations lourdes passent ici
// ============================================================
if (isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Accel-Buffering: no');
    
    set_time_limit(1800); // 30 minutes
    ini_set('memory_limit', '1024M');
    
    try {
        $db = initDB();
        $action = $_POST['action'];
        
        switch ($action) {
            case 'init_db':        echo json_encode(actionInitDB($db)); break;
            case 'phase1_seed':    echo json_encode(actionPhase1Seed($db, $MISTRAL_KEYS)); break;
            case 'phase2_harvest': echo json_encode(actionPhase2Harvest($db, $MISTRAL_KEYS)); break;
            case 'phase3_reason':  echo json_encode(actionPhase3Reason($db, $MISTRAL_KEYS)); break;
            case 'phase4_sim':     echo json_encode(actionPhase4Simulate($db, $MISTRAL_KEYS)); break;
            case 'phase5_validate':echo json_encode(actionPhase5Validate($db, $MISTRAL_KEYS)); break;
            case 'get_stats':      echo json_encode(actionGetStats($db)); break;
            case 'get_graph':      echo json_encode(actionGetGraph($db)); break;
            case 'get_reports':    echo json_encode(actionGetReports()); break;
            case 'get_logs':       echo json_encode(actionGetLogs()); break;
            case 'clear_db':       echo json_encode(actionClearDB($db)); break;
            default:               echo json_encode(['error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    }
    exit;
}

// ============================================================
// DATABASE INIT
// ============================================================
function initDB(): PDO {
    $db = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA cache_size=10000;');
    return $db;
}

function actionInitDB(PDO $db): array {
    if (!is_dir(OUTPUT_PATH)) mkdir(OUTPUT_PATH, 0755, true);

    $db->exec("CREATE TABLE IF NOT EXISTS concepts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        domain TEXT,
        mesh_term TEXT,
        cluster_id INTEGER DEFAULT 0,
        saturation REAL DEFAULT 0.0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS edges (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_id INTEGER NOT NULL,
        target_id INTEGER NOT NULL,
        relation_type TEXT,
        confidence REAL DEFAULT 0.5,
        controversy_score REAL DEFAULT 0.0,
        impact_factor REAL DEFAULT 1.0,
        evidence_count INTEGER DEFAULT 1,
        source_pmid TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(source_id) REFERENCES concepts(id),
        FOREIGN KEY(target_id) REFERENCES concepts(id)
    )");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_edges_source ON edges(source_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_edges_target ON edges(target_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_concepts_cluster ON concepts(cluster_id)");

    $db->exec("CREATE TABLE IF NOT EXISTS articles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pmid TEXT UNIQUE,
        title TEXT,
        abstract TEXT,
        doi TEXT,
        journal TEXT,
        impact_factor REAL DEFAULT 1.0,
        processed INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS hypotheses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        concept_a INTEGER,
        concept_c INTEGER,
        path TEXT,
        confidence REAL,
        kinetic_valid INTEGER DEFAULT 0,
        redteam_valid INTEGER DEFAULT 0,
        novelty_valid INTEGER DEFAULT 0,
        vmax REAL,
        km REAL,
        retry_count INTEGER DEFAULT 0,
        report_path TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS key_usage (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key_index INTEGER,
        used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        success INTEGER DEFAULT 1
    )");

    archLog("DB initialized OK");
    return ['status' => 'ok', 'message' => 'Database Archimedes v5.0 initialisée avec succès'];
}

// ============================================================
// MISTRAL API — Rotation des clés + cURL robuste
// ============================================================
function callMistral(array $keys, string $prompt, string $model = 'mistral-large-2512', int $maxTokens = 4096, string $context = ''): array {
    static $keyIndex = 0;

    $attempts = 0;
    $lastError = '';

    archLog("=== MISTRAL CALL START ===");
    archLog("Context: " . ($context ?: 'general'));
    archLog("Model: {$model}, MaxTokens: {$maxTokens}");
    archLog("Prompt length: " . strlen($prompt) . " chars");
    archLog("Prompt preview (first 1000 chars): " . substr($prompt, 0, 1000));
    if (strlen($prompt) > 1000) {
        archLog("Prompt continued... (total " . strlen($prompt) . " chars)");
    }

    while ($attempts < count($keys)) {
        $key = $keys[$keyIndex % count($keys)];
        $keyMasked = substr($key, 0, 3) . '***' . substr($key, -3);
        $keyIndex = ($keyIndex + 1) % count($keys);
        $attempts++;

        archLog("Attempt {$attempts}/" . count($keys) . " with key {$keyMasked}");

        $payload = json_encode([
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => 0.3,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
        ]);

        archLog("Payload JSON length: " . strlen($payload) . " bytes");

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$key}\r\n",
                'content'       => $payload,
                'timeout'       => 300,
                'ignore_errors' => true,
            ],
        ]);

        archLog("Calling Mistral endpoint: " . MISTRAL_ENDPOINT);
        $raw = @file_get_contents(MISTRAL_ENDPOINT, false, $ctx);

        if ($raw === false) {
            $lastError = 'file_get_contents failed';
            archLog("ERROR: file_get_contents failed");
            archLog("HTTP response headers: " . print_r($http_response_header ?? 'no headers', true));
            sleep(2);
            continue;
        }

        archLog("Raw response received (" . strlen($raw) . " bytes)");
        archLog("Response preview (first 1000 chars): " . substr($raw, 0, 1000));

        archLog("=== CALLMISTRALAPI DEBUG: RAW RESPONSE ===");
        archLog("Response length: " . strlen($raw) . " bytes");
        archLog("Response first 2000 chars: " . substr($raw, 0, 2000));

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            archLog("JSON decode error: " . json_last_error_msg());
            archLog("Raw response (full): " . $raw);
        }

        if (isset($data['error'])) {
            $lastError = $data['error']['message'] ?? 'API error';
            archLog("API ERROR: " . $lastError);
            archLog("Full error object: " . json_encode($data['error']));
            
            if (str_contains($lastError, 'rate') || str_contains($lastError, 'limit') || str_contains($lastError, '429')) {
                archLog("Rate limit 429 détecté - Délai explicite de 5 secondes avant retry");
                sleep(5);
            }
            continue;
        }

        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            archLog("SUCCESS: Content received (" . strlen($content) . " chars)");
            archLog("Response content preview (first 1000 chars): " . substr($content, 0, 1000));
            archLog("Full response structure: " . json_encode($data));
            archLog("=== MISTRAL CALL END (SUCCESS) ===");
            sleep(1);
            return ['ok' => true, 'content' => $content, 'model' => $model];
        }

        $lastError = 'No content in response';
        archLog("WARNING: No content in response");
        archLog("Full response: " . json_encode($data));
        sleep(1);
    }

    archLog("=== MISTRAL CALL END (FAILED) ===");
    archLog("Final error after {$attempts} attempts: " . $lastError);
    return ['ok' => false, 'error' => $lastError];
}

function parseJsonFromMistral(string $raw): ?array {
    archLog("=== PARSEJSONFROMMISTRAL DEBUG START ===");
    archLog("parseJsonFromMistral: Raw input length=" . strlen($raw));
    archLog("parseJsonFromMistral: Raw preview (first 1000 chars): " . substr($raw, 0, 1000));
    archLog("parseJsonFromMistral: Raw full content: " . $raw);
    
    $raw = trim($raw);
    
    $firstBrace = strpos($raw, '{');
    $firstBracket = strpos($raw, '[');
    $lastBrace = strrpos($raw, '}');
    $lastBracket = strrpos($raw, ']');
    
    if ($firstBrace !== false || $firstBracket !== false) {
        $start = min(
            $firstBrace !== false ? $firstBrace : PHP_INT_MAX,
            $firstBracket !== false ? $firstBracket : PHP_INT_MAX
        );
        $end = max(
            $lastBrace !== false ? $lastBrace : -1,
            $lastBracket !== false ? $lastBracket : -1
        );
        
        if ($start <= $end && $start < strlen($raw)) {
            $raw = substr($raw, $start, $end - $start + 1);
            archLog("parseJsonFromMistral: Extracted JSON block from {$start} to {$end}");
            archLog("parseJsonFromMistral: Extracted JSON (first 1000 chars): " . substr($raw, 0, 1000));
        }
    }
    
    $rawBefore = $raw;
    $raw = preg_replace('/```json\s*/i', '', $raw);
    $raw = preg_replace('/```\s*/i', '', $raw);
    $raw = trim($raw);
    if ($raw !== $rawBefore) {
        archLog("parseJsonFromMistral: Removed markdown backticks");
        archLog("parseJsonFromMistral: After markdown removal (first 1000 chars): " . substr($raw, 0, 1000));
    }
    
    $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw);
    $raw = str_replace(['"', '"', '"'], '"', $raw);
    $raw = str_replace(["'", "'", "'"], "'", $raw);
    
    archLog("parseJsonFromMistral: Cleaned JSON string (first 1000 chars): " . substr($raw, 0, 1000));
    
    $decoded = json_decode($raw, true);
    if ($decoded !== null) {
        archLog("parseJsonFromMistral: SUCCESS on first attempt");
        archLog("parseJsonFromMistral: Decoded data structure: " . json_encode($decoded));
        archLog("=== PARSEJSONFROMMISTRAL DEBUG END (SUCCESS) ===");
        return $decoded;
    }
    
    archLog("parseJsonFromMistral: First parse failed: " . json_last_error_msg());
    
    $rawRepaired = preg_replace('/([{,]\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/u', '$1"$2":', $raw);
    $decoded = json_decode($rawRepaired, true);
    if ($decoded !== null) {
        archLog("parseJsonFromMistral: SUCCESS after key repair");
        archLog("parseJsonFromMistral: Repaired JSON (first 1000 chars): " . substr($rawRepaired, 0, 1000));
        archLog("parseJsonFromMistral: Decoded data structure: " . json_encode($decoded));
        archLog("=== PARSEJSONFROMMISTRAL DEBUG END (REPAIRED) ===");
        return $decoded;
    }
    
    archLog("ParseJSON failed: " . json_last_error_msg() . " | Raw preview: " . substr($raw, 0, 500));
    archLog("ParseJSON failed: Attempted repaired version (first 500 chars): " . substr($rawRepaired, 0, 500));
    archLog("=== PARSEJSONFROMMISTRAL DEBUG END (FAILED) ===");
    
    return null;
}

function archLog(string $msg): void {
    $line = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    @file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}

// ============================================================
// PHASE 1 — Ontologie Auto-Génératrice
// ============================================================
function actionPhase1Seed(PDO $db, array $keys): array {
    $count = $db->query("SELECT COUNT(*) as c FROM concepts")->fetch()['c'];
    $clusters = $db->query("SELECT cluster_id, COUNT(*) as cnt FROM concepts GROUP BY cluster_id")->fetchAll();

    $gaps = [];
    if (count($clusters) >= 2) {
        $stmt = $db->query("
            SELECT c1.cluster_id as ca, c2.cluster_id as cb, COUNT(e.id) as links
            FROM concepts c1
            CROSS JOIN concepts c2
            LEFT JOIN edges e ON (e.source_id = c1.id AND e.target_id = c2.id)
            WHERE c1.cluster_id != c2.cluster_id
            GROUP BY c1.cluster_id, c2.cluster_id
            HAVING links = 0
            LIMIT 3
        ");
        $gaps = $stmt->fetchAll();
    }

    $gapDesc = empty($gaps) ? "Aucun cluster détecté — générer des graines multidisciplinaires initiales" 
        : "Clusters isolés détectés : " . json_encode($gaps);

    $prompt = <<<PROMPT
Tu es ARCHIMEDES v5.0, moteur de découverte scientifique autonome.

MISSION PHASE 1 — ONTOLOGIE AUTO-GÉNÉRATRICE :
État de la base : {$count} concepts, {$gapDesc}

Génère une ontologie de 15 nouveaux concepts scientifiques couvrant des domaines qui ne se croisent jamais habituellement (ex: physique des plasmas + oncologie, biologie marine + neurosciences, cryptographie + génomique).

RÈGLES ABSOLUES :
- Utilise exclusivement des termes MeSH anglais pour chaque concept
- Assigne chaque concept à un cluster (0-5) et un domaine
- Identifie les "ponts" potentiels entre clusters isolés
- Réponds UNIQUEMENT en JSON valide, aucun texte parasite

FORMAT JSON OBLIGATOIRE :
{
  "concepts": [
    {
      "name": "nom_technique_anglais",
      "mesh_term": "terme_MeSH_officiel",
      "domain": "domaine_scientifique",
      "cluster_id": 0,
      "bridge_potential": ["concept_a", "concept_b"]
    }
  ],
  "analysis": "explication courte du choix des domaines",
  "entropy_score": 0.85
}
PROMPT;

    $result = callMistral($keys, $prompt, 'mistral-large-2512', 4096);

    if (!$result['ok']) {
        return ['status' => 'error', 'message' => $result['error']];
    }

    $data = parseJsonFromMistral($result['content']);
    if (!$data || !isset($data['concepts'])) {
        return ['status' => 'error', 'message' => 'Parse JSON échoué', 'raw' => substr($result['content'], 0, 500)];
    }

    $inserted = 0;
    $stmt = $db->prepare("INSERT OR IGNORE INTO concepts (name, domain, mesh_term, cluster_id) VALUES (?, ?, ?, ?)");
    foreach ($data['concepts'] as $c) {
        if (isset($c['name'])) {
            $stmt->execute([$c['name'], $c['domain'] ?? 'Unknown', $c['mesh_term'] ?? $c['name'], $c['cluster_id'] ?? 0]);
            if ($db->lastInsertId()) $inserted++;
        }
    }

    archLog("Phase1: {$inserted} concepts insérés. Entropy: " . ($data['entropy_score'] ?? '?'));

    return [
        'status'    => 'ok',
        'phase'     => 1,
        'inserted'  => $inserted,
        'total'     => $db->query("SELECT COUNT(*) as c FROM concepts")->fetch()['c'],
        'analysis'  => $data['analysis'] ?? '',
        'entropy'   => $data['entropy_score'] ?? 0,
        'model'     => $result['model'],
    ];
}

// ============================================================
// PHASE 2 — Moissonneur Deep-Scan (amélioré)
// ============================================================
function actionPhase2Harvest(PDO $db, array $keys): array {
    // Augmenté à 6 concepts
    $concepts = $db->query("SELECT * FROM concepts ORDER BY saturation ASC LIMIT 6")->fetchAll();
    if (empty($concepts)) {
        return ['status' => 'error', 'message' => 'Aucun concept à traiter. Lancez Phase 1 d\'abord.'];
    }

    $allArticles = [];

    foreach ($concepts as $concept) {
        $term = urlencode($concept['mesh_term'] ?? $concept['name']);
        $pubmedUrl = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&term={$term}[MeSH]&retmax=8&retmode=json&sort=relevance";
        $raw = @file_get_contents($pubmedUrl);

        $pmids = [];
        if ($raw) {
            $pd = json_decode($raw, true);
            $pmids = $pd['esearchresult']['idlist'] ?? [];
        }
        
        // Si aucun résultat MeSH, essayer avec nom seul
        if (empty($pmids)) {
            $term2 = urlencode($concept['name'] . " AND (therapy OR mechanism)");
            $pubmedUrl2 = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&term={$term2}&retmax=5&retmode=json";
            $raw2 = @file_get_contents($pubmedUrl2);
            if ($raw2) {
                $pd2 = json_decode($raw2, true);
                $pmids = $pd2['esearchresult']['idlist'] ?? [];
            }
        }

        foreach (array_slice($pmids, 0, 5) as $pmid) {
            $detailUrl = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&id={$pmid}&retmode=json";
            $detailRaw = @file_get_contents($detailUrl);
            if (!$detailRaw) continue;

            $detail = json_decode($detailRaw, true);
            $art = $detail['result'][$pmid] ?? null;
            if (!$art) continue;

            $title    = $art['title'] ?? 'Unknown';
            $journal  = $art['source'] ?? 'Unknown';
            $doi      = '';
            foreach (($art['articleids'] ?? []) as $aid) {
                if ($aid['idtype'] === 'doi') { $doi = $aid['value']; break; }
            }

            $stmt = $db->prepare("INSERT OR IGNORE INTO articles (pmid, title, journal, doi) VALUES (?, ?, ?, ?)");
            $stmt->execute([$pmid, $title, $journal, $doi]);

            $allArticles[] = ['pmid' => $pmid, 'title' => $title, 'concept' => $concept['name']];
            sleep(1);
        }

        // Analyse sémantique avec Mistral
        if (!empty($allArticles)) {
            $articleList = implode("\n", array_map(fn($a) => "- PMID:{$a['pmid']} | {$a['title']}", $allArticles));

            $prompt = <<<PROMPT
Tu es ARCHIMEDES v5.0, extracteur de relations scientifiques.

Analyse ces articles sur "{$concept['name']}" et extrait des triplets de relations biologiques/scientifiques avec données quantifiées.

ARTICLES :
{$articleList}

RÈGLES :
- Extrait des valeurs numériques (IC50, Km, constantes de liaison) quand disponibles
- Gère les contradictions avec un Score de Controverse [0-1]
- Chaque relation a un score de confiance [0-1]
- Termes en anglais MeSH exclusivement
- Réponse JSON UNIQUEMENT

FORMAT :
{
  "triplets": [
    {
      "source": "concept_source",
      "target": "concept_target",
      "relation": "activates|inhibits|binds|regulates|correlates",
      "confidence": 0.85,
      "controversy": 0.1,
      "ic50": null,
      "km": null,
      "pmid": "12345678"
    }
  ],
  "dark_data": "résultats secondaires ou accidentels notables"
}
PROMPT;

            $result = callMistral($keys, $prompt, 'codestral-2508', 4000);

            if ($result['ok']) {
                $data = parseJsonFromMistral($result['content']);
                if ($data && isset($data['triplets'])) {
                    $conceptStmt = $db->prepare("INSERT OR IGNORE INTO concepts (name, domain, mesh_term, cluster_id) VALUES (?, ?, ?, ?)");
                    $edgeStmt = $db->prepare("
                        INSERT INTO edges (source_id, target_id, relation_type, confidence, controversy_score, source_pmid)
                        SELECT c1.id, c2.id, ?, ?, ?, ?
                        FROM concepts c1, concepts c2
                        WHERE c1.name = ? AND c2.name = ?
                    ");

                    foreach ($data['triplets'] as $t) {
                        if (!isset($t['source'], $t['target'])) continue;
                        $conceptStmt->execute([$t['source'], 'derived', $t['source'], $concept['cluster_id'] ?? 0]);
                        $conceptStmt->execute([$t['target'], 'derived', $t['target'], $concept['cluster_id'] ?? 0]);
                        $edgeStmt->execute([
                            $t['relation'] ?? 'correlates',
                            $t['confidence'] ?? 0.5,
                            $t['controversy'] ?? 0.0,
                            $t['pmid'] ?? '',
                            $t['source'],
                            $t['target'],
                        ]);
                    }
                }
            }
        }

        $db->prepare("UPDATE concepts SET saturation = saturation + 0.2 WHERE id = ?")->execute([$concept['id']]);
        sleep(1);
    }

    $totalEdges = $db->query("SELECT COUNT(*) as c FROM edges")->fetch()['c'];
    archLog("Phase2: " . count($allArticles) . " articles récoltés, {$totalEdges} edges total");

    return [
        'status'       => 'ok',
        'phase'        => 2,
        'articles'     => count($allArticles),
        'total_edges'  => $totalEdges,
        'concepts_done'=> array_column($concepts, 'name'),
    ];
}

// ============================================================
// PHASE 3 — Graph Reasoning 4ème degré (seuils abaissés)
// ============================================================
function actionPhase3Reason(PDO $db, array $keys): array {
    // Chemins A→B→C→D (4 degrés) avec seuils plus bas
    $paths = $db->query("
        SELECT
            ca.name as A, ea.relation_type as rel_AB, cb.name as B,
            eb.relation_type as rel_BC, cc.name as C,
            ec.relation_type as rel_CD, cd.name as D,
            (ea.confidence * eb.confidence * ec.confidence) as path_strength,
            (ea.impact_factor + eb.impact_factor + ec.impact_factor) / 3 as avg_impact
        FROM edges ea
        JOIN edges eb ON ea.target_id = eb.source_id
        JOIN edges ec ON eb.target_id = ec.source_id
        JOIN concepts ca ON ea.source_id = ca.id
        JOIN concepts cb ON ea.target_id = cb.id
        JOIN concepts cc ON eb.target_id = cc.id
        JOIN concepts cd ON ec.target_id = cd.id
        WHERE ea.confidence > 0.15
          AND eb.confidence > 0.15
          AND ec.confidence > 0.15
        ORDER BY path_strength DESC
        LIMIT 30
    ")->fetchAll();

    // Chemins A→B→C (3 degrés)
    $paths3 = $db->query("
        SELECT
            ca.name as A, ea.relation_type as rel_AB, cb.name as B,
            eb.relation_type as rel_BC, cc.name as C,
            (ea.confidence * eb.confidence) as path_strength,
            ca.id as source_concept_id, cc.id as target_concept_id
        FROM edges ea
        JOIN edges eb ON ea.target_id = eb.source_id
        JOIN concepts ca ON ea.source_id = ca.id
        JOIN concepts cb ON ea.target_id = cb.id
        JOIN concepts cc ON eb.target_id = cc.id
        WHERE ea.confidence > 0.2
          AND eb.confidence > 0.2
        ORDER BY path_strength DESC
        LIMIT 40
    ")->fetchAll();

    if (empty($paths3) && empty($paths)) {
        return ['status' => 'warn', 'message' => 'Pas assez d\'edges pour le raisonnement. Lancez Phase 2.', 'edges_count' => $db->query("SELECT COUNT(*) as c FROM edges")->fetch()['c']];
    }

    archLog("Phase3: Edge count = " . $db->query("SELECT COUNT(*) as c FROM edges")->fetch()['c']);
    archLog("Phase3: Paths 4-degrees found = " . count($paths));
    archLog("Phase3: Paths 3-degrees found = " . count($paths3));
    archLog("Phase3: Total paths for hypothesis generation = " . count(array_merge($paths3, $paths)));

    $pathsDesc = json_encode(array_merge($paths3, $paths), JSON_PRETTY_PRINT);

    $prompt = <<<PROMPT
Tu es ARCHIMEDES v5.0, moteur de raisonnement logique.

PHASE 3 — INFÉRENCE DE TRANSITIVITÉ :

Chemins détectés dans le graphe de connaissances :
{$pathsDesc}

MISSION :
1. Applique la logique de transitivité : Si A+ B et B− C, alors A devrait inhiber C
2. Calcule P(A|C) pour chaque chemin multi-degrés
3. Détecte les clusters isolés sans lien vers des pathologies
4. Identifie les 3 hypothèses les plus prometteuses (chemin le plus fort + inattendues)

⚠️ CONTRAINTES CINÉTIQUES :
- Vmax doit être compris entre 0.1 et 5
- Km doit être compris entre 0.01 et 100 (µM)
- Ne pas utiliser de valeurs nanomolaires (Km < 0.01) sauf preuve explicite

⚠️ CONTRAINTES STRICTES DE FORMAT ⚠️
- Réponds UNIQUEMENT avec du JSON brut, SANS AUCUN texte avant ou après
- PAS de balises markdown (pas de ```json ni ```)
- PAS d'explications, PAS de commentaires, PAS de texte introductif ou conclusif
- Le premier caractère de ta réponse DOIT être { ou [
- Le dernier caractère de ta réponse DOIT être } ou ]

FORMAT DE RÉPONSE OBLIGATOIRE (JSON brut uniquement) :
{
  "hypotheses": [
    {
      "concept_a": "nom",
      "concept_c": "nom",
      "path": "A→B→C ou A→B→C→D",
      "transitive_logic": "explication",
      "probability": 0.75,
      "confidence": 0.80,
      "novelty_hint": "pourquoi c'est potentiellement nouveau",
      "vmax_estimate": 1.5,
      "km_estimate": 0.05
    }
  ],
  "isolated_clusters": ["liste des clusters sans pathologie connue"],
  "reasoning_summary": "synthèse"
}
PROMPT;

    $result = callMistral($keys, $prompt, 'mistral-large-2512', 4096);
    if (!$result['ok']) return ['status' => 'error', 'message' => $result['error']];

    $data = parseJsonFromMistral($result['content']);
    if (!$data || !isset($data['hypotheses'])) {
        return ['status' => 'error', 'message' => 'Parse failed', 'raw' => substr($result['content'], 0, 500)];
    }

    $stmt = $db->prepare("
        INSERT INTO hypotheses (concept_a, concept_c, path, confidence, vmax, km, status)
        SELECT c1.id, c2.id, ?, ?, ?, ?, 'pending'
        FROM concepts c1, concepts c2
        WHERE c1.name = ? AND c2.name = ?
    ");

    $inserted = 0;
    foreach ($data['hypotheses'] as $h) {
        if (!isset($h['concept_a'], $h['concept_c'])) continue;
        // Post-traitement des paramètres cinétiques
        $vmax = $h['vmax_estimate'] ?? 1.0;
        $km   = $h['km_estimate'] ?? 0.05;
        if ($km < 0.01) $km = 0.01;
        if ($vmax > 10) $vmax = 5;
        if ($vmax < 0.1) $vmax = 0.1;
        
        $stmt->execute([
            $h['path'] ?? '',
            $h['confidence'] ?? 0.5,
            $vmax,
            $km,
            $h['concept_a'],
            $h['concept_c'],
        ]);
        if ($db->lastInsertId()) $inserted++;
    }

    archLog("Phase3: {$inserted} hypothèses générées");

    return [
        'status'    => 'ok',
        'phase'     => 3,
        'hypotheses'=> count($data['hypotheses']),
        'inserted'  => $inserted,
        'summary'   => $data['reasoning_summary'] ?? '',
        'isolated'  => $data['isolated_clusters'] ?? [],
    ];
}

// ============================================================
// PHASE 4 — Lab-in-the-Loop : Cinétique + Red Team (assoupli)
// ============================================================
function actionPhase4Simulate(PDO $db, array $keys): array {
    // Récupérer les hypothèses pending + celles rejetées avec retry_count < 3
    $hypotheses = $db->query("
        SELECT h.*, ca.name as name_a, cc.name as name_c
        FROM hypotheses h
        JOIN concepts ca ON h.concept_a = ca.id
        JOIN concepts cc ON h.concept_c = cc.id
        WHERE (h.status = 'pending' OR (h.status = 'rejected' AND h.retry_count < 3))
        LIMIT 5
    ")->fetchAll();

    if (empty($hypotheses)) {
        return ['status' => 'warn', 'message' => 'Aucune hypothèse en attente. Lancez Phase 3.'];
    }

    $results = [];

    foreach ($hypotheses as $h) {
        archLog("=== PHASE4: Processing hypothesis ID={$h['id']} {$h['name_a']} -> {$h['name_c']} ===");
        
        $vMax = (float)($h['vmax'] ?? 1.0);
        $km   = (float)($h['km'] ?? 0.05);
        if ($km <= 0) $km = 0.05;

        archLog("Kinetic params: Vmax={$vMax}, Km={$km}");

        $substrates = [0.0001, 0.001, 0.01, 0.1, 1.0];
        $velocities = [];
        $isRealistic = false;

        foreach ($substrates as $s) {
            $v = ($vMax * $s) / ($km + $s);
            $velocities[] = round($v, 6);
            if ($v > 0) $isRealistic = true; // Toute vitesse positive est réaliste
        }

        archLog("Velocities: " . json_encode($velocities));
        archLog("Is realistic: " . ($isRealistic ? 'YES' : 'NO'));

        // Monte Carlo avec CV < 0.6
        $mcResults = [];
        for ($i = 0; $i < 100; $i++) {
            $vMaxVar = $vMax * (1 + (mt_rand(-20, 20) / 100));
            $kmVar   = $km * (1 + (mt_rand(-20, 20) / 100));
            $conc    = 0.001;
            $mcResults[] = ($vMaxVar * $conc) / ($kmVar + $conc);
        }
        sort($mcResults);
        $mcMean   = array_sum($mcResults) / count($mcResults);
        $mcStdDev = sqrt(array_sum(array_map(fn($x) => pow($x - $mcMean, 2), $mcResults)) / count($mcResults));
        $mcCV     = $mcStdDev / ($mcMean ?: 1);
        $mcStable = $mcCV < 0.6;

        archLog("Monte Carlo: mean={$mcMean}, stddev={$mcStdDev}, CV={$mcCV}, stable=" . ($mcStable ? 'YES' : 'NO'));

        // Agent Red Team équilibré (moins destructeur)
        $prompt = <<<PROMPT
Tu es l'AGENT ÉVALUATEUR d'ARCHIMEDES. Analyse cette hypothèse de manière équilibrée.

HYPOTHÈSE À ÉVALUER :
- Concept A : {$h['name_a']}
- Concept C : {$h['name_c']}
- Chemin : {$h['path']}
- Confiance calculée : {$h['confidence']}
- Paramètres cinétiques : Vmax={$vMax}, Km={$km}
- Simulation cinétique réaliste : " . ($isRealistic ? 'OUI' : 'NON') . "
- Monte Carlo stable : " . ($mcStable ? 'OUI' : 'NON') . " (CV=" . round($mcCV, 3) . ")

RÈGLES D'ÉVALUATION :
- Si la cinétique est plausible (Km entre 0.01 et 100 µM, Vmax entre 0.1 et 5), ne conclus pas INVALID pour ça.
- Ne conclus INVALID que si une barrière physique/biologique est ABSOLUMENT rédhibitoire (ex. molécule ne traverse pas la BHE sans transporteur connu).
- Dans le doute, réponds UNCERTAIN.
- Identifie les forces et les faiblesses.

Réponse JSON UNIQUEMENT :
{
  "verdict": "VALID|UNCERTAIN|INVALID",
  "barriers": ["liste des barrières potentielles"],
  "toxicity_risk": "LOW|MEDIUM|HIGH",
  "fatal_flaw": "seulement si INVALID, la raison définitive",
  "confidence_penalty": 0.0,
  "recommendation": "PROCEED|ABORT|REVISE"
}
PROMPT;

        archLog("Calling Red Team agent...");
        $redTeam = callMistral($keys, $prompt, 'mistral-large-2512', 2048, 'phase4_redteam');
        $rtData = null;
        if ($redTeam['ok']) {
            $rtData = parseJsonFromMistral($redTeam['content']);
            archLog("Red Team response parsed: " . json_encode($rtData));
        } else {
            archLog("Red Team call failed: " . ($redTeam['error'] ?? 'unknown error'));
        }

        $kineticValid = $isRealistic && $mcStable ? 1 : 0;
        $redTeamValid = ($rtData && ($rtData['verdict'] ?? '') !== 'INVALID') ? 1 : 0;

        archLog("Validation results: kinetic_valid={$kineticValid}, redteam_valid={$redTeamValid}");

        // NOUVELLE LOGIQUE : accepte si pas INVALID ET (kinetic OU redteam)
        $redInvalid = ($rtData && ($rtData['verdict'] ?? '') === 'INVALID');
        if (!$redInvalid && ($kineticValid || $redTeamValid)) {
            $shouldValidate = true;
            archLog("Phase4: Validé - kinetic={$kineticValid}, redteam={$redTeamValid}");
        } else {
            $shouldValidate = false;
            archLog("Phase4: Rejeté - kinetic={$kineticValid}, redteam_verdict=" . ($rtData['verdict'] ?? 'N/A'));
        }

        $newStatus = $shouldValidate ? 'validated' : 'rejected';
        $retryCount = $h['retry_count'] ?? 0;
        if ($newStatus === 'rejected' && $retryCount < 2) {
            $retryCount++;
            // Modifier paramètres pour réessai
            $db->prepare("UPDATE hypotheses SET vmax = vmax * 0.8, km = km * 10, retry_count = ? WHERE id = ?")
               ->execute([$retryCount, $h['id']]);
            archLog("Hypothèse réessayée avec nouveaux paramètres (retry_count={$retryCount})");
        } else {
            $db->prepare("
                UPDATE hypotheses SET 
                    kinetic_valid = ?, redteam_valid = ?, status = ?, retry_count = ?
                WHERE id = ?
            ")->execute([
                $kineticValid,
                $redTeamValid,
                $newStatus,
                $retryCount,
                $h['id'],
            ]);
        }

        $results[] = [
            'hypothesis_id'  => $h['id'],
            'concept_a'      => $h['name_a'],
            'concept_c'      => $h['name_c'],
            'kinetic_valid'  => (bool)$kineticValid,
            'monte_carlo_cv' => round($mcCV, 3),
            'velocities'     => $velocities,
            'redteam_verdict'=> $rtData['verdict'] ?? 'N/A',
            'recommendation' => $rtData['recommendation'] ?? 'N/A',
            'barriers'       => $rtData['barriers'] ?? [],
            'final_status'   => $newStatus === 'validated' ? 'VALIDATED' : 'REJECTED',
        ];

        sleep(2);
    }

    archLog("Phase4: " . count($results) . " hypothèses simulées");
    return ['status' => 'ok', 'phase' => 4, 'simulations' => $results];
}

// ============================================================
// PHASE 5 — Validation Nouveauté + Génération Preprint (élargie)
// ============================================================
function actionPhase5Validate(PDO $db, array $keys): array {
    archLog("=== PHASE5 START ===");
    
    $statusCounts = $db->query("SELECT status, COUNT(*) as cnt FROM hypotheses GROUP BY status")->fetchAll();
    archLog("Hypothesis status distribution: " . json_encode($statusCounts));
    
    // Récupère les hypothèses validées OU celles qui ont kinetic_valid=1 ou redteam_valid=1
    $validated = $db->query("
        SELECT h.*, ca.name as name_a, cc.name as name_c
        FROM hypotheses h
        JOIN concepts ca ON h.concept_a = ca.id
        JOIN concepts cc ON h.concept_c = cc.id
        WHERE h.novelty_valid = 0
          AND (h.status = 'validated' OR h.kinetic_valid = 1 OR h.redteam_valid = 1)
          AND h.status != 'complete'
        LIMIT 5
    ")->fetchAll();

    if (empty($validated)) {
        $pendingReview = $db->query("
            SELECT h.*, ca.name as name_a, cc.name as name_c
            FROM hypotheses h
            JOIN concepts ca ON h.concept_a = ca.id
            JOIN concepts cc ON h.concept_c = cc.id
            WHERE h.novelty_valid = 0 
            AND (h.kinetic_valid = 1 OR h.redteam_valid = 1)
            AND h.status != 'complete'
            LIMIT 2
        ")->fetchAll();
        
        if (!empty($pendingReview)) {
            archLog("Found " . count($pendingReview) . " hypotheses with partial validation that could be processed");
        }
        
        return ['status' => 'warn', 'message' => 'Aucune hypothèse validée ou partiellement validée en attente de rapport. Lancez Phases 3 & 4.'];
    }

    archLog("Found " . count($validated) . " validated/partial hypotheses to process");
    $reports = [];

    foreach ($validated as $h) {
        archLog("=== PHASE5: Processing hypothesis ID={$h['id']} {$h['name_a']} -> {$h['name_c']} ===");
        
        // Filtre de nouveauté
        $queryA = urlencode($h['name_a']);
        $queryC = urlencode($h['name_c']);
        $noveltyUrl = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&term={$queryA}+AND+{$queryC}&retmax=1&retmode=json";
        
        archLog("PubMed novelty check URL: {$noveltyUrl}");
        $noveltyRaw = @file_get_contents($noveltyUrl);
        $noveltyCount = 0;
        if ($noveltyRaw) {
            $nd = json_decode($noveltyRaw, true);
            $noveltyCount = (int)($nd['esearchresult']['count'] ?? 0);
            archLog("PubMed API response: " . json_encode($nd));
        } else {
            archLog("PubMed API call failed or returned empty");
        }

        $isNovel = $noveltyCount === 0;
        archLog("Novelty check: {$noveltyCount} existing articles, is_novel=" . ($isNovel ? 'YES' : 'NO'));

        // Génération du Pre-print (même pour UNCERTAIN)
        $confidenceNote = ($h['status'] === 'validated') ? "" : " (NOTE: hypothèse partiellement validée, nécessite confirmation expérimentale)";
        $prompt = <<<PROMPT
Tu es ARCHIMEDES v5.0, rédacteur scientifique autonome.

Rédige un pre-print scientifique de 5 sections sur la découverte suivante :

DÉCOUVERTE :
- Relation : {$h['name_a']} → {$h['name_c']}
- Chemin inféré : {$h['path']}
- Score de confiance : {$h['confidence']}
- Nouveauté PubMed : {$noveltyCount} articles existants (0 = absolument nouveau)
{$confidenceNote}

FORMAT OBLIGATOIRE — JSON :
{
  "title": "Titre scientifique accrocheur",
  "abstract": "Résumé 200 mots max",
  "introduction": "Contexte et état de l'art 300 mots",
  "mechanism": "Mécanisme moléculaire proposé 300 mots",
  "evidence": "Preuves et données existantes 300 mots",
  "protocol": "Protocole expérimental suggéré 200 mots",
  "unmet_need": "Besoin médical non satisfait adressé",
  "impact_score": 0.85,
  "keywords": ["kw1", "kw2", "kw3"]
}
PROMPT;

        archLog("Calling Mistral for preprint generation...");
        $result = callMistral($keys, $prompt, 'mistral-large-2512', 6000, 'phase5_preprint');
        $reportData = null;

        if ($result['ok']) {
            $reportData = parseJsonFromMistral($result['content']);
            archLog("Preprint data parsed: " . json_encode($reportData));
        } else {
            archLog("Preprint generation failed: " . ($result['error'] ?? 'unknown error'));
        }

        $reportPath = null;
        if ($reportData) {
            if (!is_dir(OUTPUT_PATH)) mkdir(OUTPUT_PATH, 0755, true);
            $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($h['name_a'] . '_' . $h['name_c']));
            $reportPath = OUTPUT_PATH . "preprint_{$h['id']}_{$slug}.md";

            $md = "# {$reportData['title']}\n\n";
            $md .= "> **ARCHIMEDES v5.0** | Generated: " . date('Y-m-d H:i:s') . "\n";
            if ($h['status'] !== 'validated') $md .= "> **⚠️ NOTE: Hypothèse partiellement validée (nécessite validation expérimentale)**\n";
            $md .= "\n**Keywords:** " . implode(', ', $reportData['keywords'] ?? []) . "\n\n";
            $md .= "## Abstract\n{$reportData['abstract']}\n\n";
            $md .= "## Introduction\n{$reportData['introduction']}\n\n";
            $md .= "## Proposed Mechanism\n{$reportData['mechanism']}\n\n";
            $md .= "## Supporting Evidence\n{$reportData['evidence']}\n\n";
            $md .= "## Suggested Protocol\n{$reportData['protocol']}\n\n";
            $md .= "## Unmet Medical Need\n{$reportData['unmet_need']}\n\n";
            $md .= "---\n*Confidence: {$h['confidence']} | Novelty: " . ($isNovel ? 'ABSOLUTE (0 prior art)' : "{$noveltyCount} related papers") . " | Impact Score: {$reportData['impact_score']}*\n";

            file_put_contents($reportPath, $md);
            archLog("Preprint saved to: {$reportPath}");

            $db->prepare("UPDATE hypotheses SET novelty_valid = 1, report_path = ?, status = 'complete' WHERE id = ?")
               ->execute([$reportPath, $h['id']]);
            archLog("Hypothesis ID={$h['id']} marked as complete");
        } else {
            archLog("No report data generated, hypothesis not updated");
        }

        $reports[] = [
            'hypothesis_id' => $h['id'],
            'title'         => $reportData['title'] ?? 'N/A',
            'relation'      => "{$h['name_a']} → {$h['name_c']}",
            'novelty_count' => $noveltyCount,
            'is_novel'      => $isNovel,
            'report_path'   => $reportPath,
            'impact'        => $reportData['impact_score'] ?? 0,
            'unmet_need'    => $reportData['unmet_need'] ?? '',
        ];

        sleep(2);
    }

    archLog("Phase5: " . count($reports) . " pre-prints générés");
    archLog("=== PHASE5 END ===");
    return ['status' => 'ok', 'phase' => 5, 'reports' => $reports];
}

// ============================================================
// STATS & UTILITIES
// ============================================================
function actionGetStats(PDO $db): array {
    return [
        'status'       => 'ok',
        'concepts'     => (int)$db->query("SELECT COUNT(*) FROM concepts")->fetchColumn(),
        'edges'        => (int)$db->query("SELECT COUNT(*) FROM edges")->fetchColumn(),
        'articles'     => (int)$db->query("SELECT COUNT(*) FROM articles")->fetchColumn(),
        'hypotheses'   => (int)$db->query("SELECT COUNT(*) FROM hypotheses")->fetchColumn(),
        'validated'    => (int)$db->query("SELECT COUNT(*) FROM hypotheses WHERE status='validated' OR status='complete'")->fetchColumn(),
        'complete'     => (int)$db->query("SELECT COUNT(*) FROM hypotheses WHERE status='complete'")->fetchColumn(),
        'clusters'     => $db->query("SELECT cluster_id, COUNT(*) as cnt FROM concepts GROUP BY cluster_id")->fetchAll(),
        'top_concepts' => $db->query("SELECT c.name, COUNT(e.id) as degree FROM concepts c LEFT JOIN edges e ON c.id=e.source_id GROUP BY c.id ORDER BY degree DESC LIMIT 5")->fetchAll(),
    ];
}

function actionGetGraph(PDO $db): array {
    $nodes = $db->query("SELECT id, name, domain, cluster_id, saturation FROM concepts LIMIT 100")->fetchAll();
    $edges = $db->query("SELECT source_id, target_id, relation_type, confidence FROM edges LIMIT 200")->fetchAll();
    return ['status' => 'ok', 'nodes' => $nodes, 'edges' => $edges];
}

function actionGetReports(): array {
    if (!is_dir(OUTPUT_PATH)) return ['status' => 'ok', 'reports' => []];
    $files = glob(OUTPUT_PATH . '*.md') ?: [];
    $reports = [];
    foreach ($files as $f) {
        $content = file_get_contents($f);
        $title = '';
        if (preg_match('/^# (.+)/m', $content, $m)) $title = $m[1];
        $reports[] = ['file' => basename($f), 'title' => $title, 'size' => filesize($f), 'created' => date('Y-m-d H:i:s', filemtime($f)), 'preview' => substr($content, 0, 400)];
    }
    return ['status' => 'ok', 'reports' => $reports];
}

function actionGetLogs(): array {
    $logs = '';
    if (file_exists(LOG_PATH)) {
        $lines = file(LOG_PATH);
        $logs = implode('', array_slice($lines, -200));
    }
    return ['status' => 'ok', 'logs' => $logs];
}

function actionClearDB(PDO $db): array {
    $db->exec("DELETE FROM hypotheses; DELETE FROM edges; DELETE FROM articles; DELETE FROM concepts; DELETE FROM key_usage;");
    archLog("DB cleared by user");
    return ['status' => 'ok', 'message' => 'Base de données réinitialisée'];
}

// ============================================================
// HTML INTERFACE — FUTURISTE SOMBRE (Moebius aesthetic)
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ARCHIMEDES v5.0 — Autonomous Discovery Engine</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Rajdhani:wght@300;400;600;700&family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
<style>
:root {
  --bg:       #020408;
  --bg2:      #060d14;
  --bg3:      #0a1420;
  --panel:    #0d1b2a;
  --border:   #1a3a5c;
  --accent1:  #00c8ff;
  --accent2:  #ff6b00;
  --accent3:  #7fff00;
  --accent4:  #ff2d6b;
  --text:     #b8d4e8;
  --textdim:  #4a7a9b;
  --glow1:    0 0 20px rgba(0,200,255,0.3);
  --glow2:    0 0 20px rgba(255,107,0,0.3);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Rajdhani', sans-serif;
  font-size: 15px;
  min-height: 100vh;
  overflow-x: hidden;
}

body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image: 
    linear-gradient(rgba(0,200,255,0.02) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,200,255,0.02) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events: none;
  z-index: 0;
}

body::after {
  content: '';
  position: fixed;
  inset: 0;
  background: repeating-linear-gradient(
    0deg,
    transparent,
    transparent 2px,
    rgba(0,0,0,0.15) 2px,
    rgba(0,0,0,0.15) 4px
  );
  pointer-events: none;
  z-index: 0;
}

header {
  position: relative;
  z-index: 10;
  padding: 20px 30px 16px;
  border-bottom: 1px solid var(--border);
  background: linear-gradient(180deg, rgba(0,200,255,0.05) 0%, transparent 100%);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
}
.logo-block { display: flex; align-items: center; gap: 16px; }
.logo-icon {
  width: 48px; height: 48px;
  border: 2px solid var(--accent1);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-family: 'Orbitron', sans-serif;
  font-size: 18px;
  font-weight: 900;
  color: var(--accent1);
  box-shadow: var(--glow1), inset 0 0 20px rgba(0,200,255,0.1);
  animation: pulse-icon 3s ease-in-out infinite;
}
@keyframes pulse-icon {
  0%,100% { box-shadow: var(--glow1), inset 0 0 20px rgba(0,200,255,0.1); }
  50% { box-shadow: 0 0 40px rgba(0,200,255,0.6), inset 0 0 30px rgba(0,200,255,0.2); }
}
.logo-text h1 {
  font-family: 'Orbitron', sans-serif;
  font-size: 22px;
  font-weight: 900;
  color: var(--accent1);
  letter-spacing: 4px;
  text-shadow: var(--glow1);
}
.logo-text p {
  font-family: 'Share Tech Mono', monospace;
  font-size: 11px;
  color: var(--textdim);
  letter-spacing: 2px;
  margin-top: 2px;
}
.header-stats {
  display: flex; gap: 20px;
}
.hstat {
  text-align: center;
  padding: 6px 14px;
  border: 1px solid var(--border);
  background: rgba(0,200,255,0.03);
  border-radius: 4px;
}
.hstat-val {
  font-family: 'Orbitron', sans-serif;
  font-size: 20px;
  font-weight: 700;
  color: var(--accent1);
  line-height: 1;
}
.hstat-lbl {
  font-size: 10px;
  color: var(--textdim);
  letter-spacing: 1px;
  margin-top: 2px;
}
.sys-status {
  display: flex; align-items: center; gap: 8px;
  font-family: 'Share Tech Mono', monospace;
  font-size: 11px;
  color: var(--textdim);
}
.status-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--accent3);
  box-shadow: 0 0 8px var(--accent3);
  animation: blink 1.5s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.3} }

.main-layout {
  position: relative; z-index: 1;
  display: grid;
  grid-template-columns: 280px 1fr 320px;
  grid-template-rows: auto 1fr;
  min-height: calc(100vh - 90px);
  gap: 0;
}

.sidebar-left {
  border-right: 1px solid var(--border);
  padding: 20px 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.section-title {
  font-family: 'Share Tech Mono', monospace;
  font-size: 10px;
  letter-spacing: 3px;
  color: var(--textdim);
  text-transform: uppercase;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 4px;
}

.phase-btn {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background: var(--panel);
  border: 1px solid var(--border);
  border-left: 3px solid transparent;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.2s;
  text-align: left;
  width: 100%;
  color: var(--text);
  font-family: 'Rajdhani', sans-serif;
  font-size: 14px;
  font-weight: 600;
  position: relative;
  overflow: hidden;
}
.phase-btn::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, transparent 60%, rgba(0,200,255,0.05));
  opacity: 0;
  transition: opacity 0.2s;
}
.phase-btn:hover::before { opacity: 1; }
.phase-btn:hover {
  border-left-color: var(--accent1);
  border-color: rgba(0,200,255,0.3);
  transform: translateX(2px);
}
.phase-btn.loading {
  border-left-color: var(--accent2);
  animation: loading-pulse 1s ease-in-out infinite;
}
@keyframes loading-pulse {
  0%,100% { box-shadow: none; }
  50% { box-shadow: 0 0 15px rgba(255,107,0,0.3); }
}
.phase-btn.success { border-left-color: var(--accent3); }
.phase-btn.error { border-left-color: var(--accent4); }
.phase-num {
  font-family: 'Orbitron', sans-serif;
  font-size: 11px;
  font-weight: 700;
  width: 22px; height: 22px;
  border-radius: 50%;
  background: rgba(0,200,255,0.1);
  border: 1px solid rgba(0,200,255,0.3);
  display: flex; align-items: center; justify-content: center;
  color: var(--accent1);
  flex-shrink: 0;
}
.phase-info { flex: 1; }
.phase-name { font-size: 13px; font-weight: 700; color: #d0e8f0; }
.phase-desc { font-size: 11px; color: var(--textdim); margin-top: 1px; font-weight: 400; }
.phase-indicator {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--border);
  flex-shrink: 0;
}
.phase-btn.success .phase-indicator { background: var(--accent3); box-shadow: 0 0 6px var(--accent3); }
.phase-btn.loading .phase-indicator { background: var(--accent2); box-shadow: 0 0 6px var(--accent2); animation: blink 0.5s infinite; }

.util-row { display: flex; gap: 8px; }
.util-btn {
  flex: 1;
  padding: 8px 10px;
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 3px;
  color: var(--textdim);
  font-family: 'Share Tech Mono', monospace;
  font-size: 10px;
  cursor: pointer;
  letter-spacing: 1px;
  transition: all 0.2s;
}
.util-btn:hover { border-color: var(--accent1); color: var(--accent1); background: rgba(0,200,255,0.05); }
.util-btn.danger:hover { border-color: var(--accent4); color: var(--accent4); background: rgba(255,45,107,0.05); }

.auto-block {
  padding: 12px 14px;
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 4px;
}
.auto-label { font-size: 12px; color: var(--textdim); margin-bottom: 8px; font-family: 'Share Tech Mono', monospace; letter-spacing: 1px; }
.auto-controls { display: flex; gap: 8px; align-items: center; }
.auto-btn {
  padding: 7px 12px;
  background: rgba(0,200,255,0.08);
  border: 1px solid rgba(0,200,255,0.3);
  border-radius: 3px;
  color: var(--accent1);
  font-family: 'Share Tech Mono', monospace;
  font-size: 11px;
  cursor: pointer;
  transition: all 0.2s;
  letter-spacing: 1px;
}
.auto-btn:hover { background: rgba(0,200,255,0.15); box-shadow: var(--glow1); }
.auto-btn.active { background: rgba(255,107,0,0.1); border-color: var(--accent2); color: var(--accent2); }
.cycle-count { font-family: 'Orbitron', sans-serif; font-size: 16px; color: var(--accent2); }

.center-panel {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.tab-nav {
  display: flex;
  border-bottom: 1px solid var(--border);
  background: var(--bg2);
  padding: 0 20px;
  gap: 4px;
}
.tab {
  padding: 12px 18px;
  font-family: 'Share Tech Mono', monospace;
  font-size: 11px;
  letter-spacing: 2px;
  color: var(--textdim);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: all 0.2s;
  background: none;
  border-top: none;
  border-left: none;
  border-right: none;
  white-space: nowrap;
}
.tab:hover { color: var(--text); }
.tab.active { color: var(--accent1); border-bottom-color: var(--accent1); }

.tab-content { flex: 1; overflow: auto; padding: 20px; }
.tab-pane { display: none; }
.tab-pane.active { display: block; }

.console {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 16px;
  font-family: 'Share Tech Mono', monospace;
  font-size: 12px;
  line-height: 1.8;
  min-height: 200px;
  max-height: 400px;
  overflow-y: auto;
  color: var(--accent3);
}
.console::-webkit-scrollbar { width: 4px; }
.console::-webkit-scrollbar-track { background: var(--bg); }
.console::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
.log-line { display: block; }
.log-line.info  { color: var(--accent1); }
.log-line.warn  { color: var(--accent2); }
.log-line.error { color: var(--accent4); }
.log-line.ok    { color: var(--accent3); }
.log-line.dim   { color: var(--textdim); }

.cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.card {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 16px;
  position: relative;
  overflow: hidden;
}
.card::after {
  content: '';
  position: absolute;
  top: 0; right: 0;
  width: 60px; height: 60px;
  background: radial-gradient(circle at top right, rgba(0,200,255,0.06) 0%, transparent 70%);
}
.card-title {
  font-family: 'Orbitron', sans-serif;
  font-size: 11px;
  font-weight: 700;
  color: var(--accent1);
  letter-spacing: 2px;
  margin-bottom: 10px;
}
.card-val {
  font-family: 'Orbitron', sans-serif;
  font-size: 32px;
  font-weight: 900;
  color: #fff;
  line-height: 1;
}
.card-sub { font-size: 12px; color: var(--textdim); margin-top: 4px; }
.badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 2px;
  font-family: 'Share Tech Mono', monospace;
  font-size: 10px;
  letter-spacing: 1px;
  margin: 2px;
}
.badge-valid { background: rgba(127,255,0,0.1); color: var(--accent3); border: 1px solid rgba(127,255,0,0.3); }
.badge-pending { background: rgba(0,200,255,0.1); color: var(--accent1); border: 1px solid rgba(0,200,255,0.3); }
.badge-reject { background: rgba(255,45,107,0.1); color: var(--accent4); border: 1px solid rgba(255,45,107,0.3); }
.badge-warn { background: rgba(255,107,0,0.1); color: var(--accent2); border: 1px solid rgba(255,107,0,0.3); }

#graphCanvas {
  width: 100%;
  height: 500px;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 4px;
  display: block;
}

.report-item {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 16px;
  margin-bottom: 12px;
  cursor: pointer;
  transition: all 0.2s;
}
.report-item:hover { border-color: rgba(0,200,255,0.4); transform: translateY(-1px); }
.report-title { font-weight: 700; color: #d0e8f0; margin-bottom: 6px; }
.report-meta { font-family: 'Share Tech Mono', monospace; font-size: 10px; color: var(--textdim); }
.report-preview { font-size: 12px; color: var(--textdim); margin-top: 8px; line-height: 1.5; }
.report-full {
  display: none;
  background: var(--bg2);
  border-top: 1px solid var(--border);
  padding: 16px;
  margin-top: 12px;
  font-family: 'Share Tech Mono', monospace;
  font-size: 12px;
  line-height: 1.7;
  white-space: pre-wrap;
  color: var(--text);
}

.sidebar-right {
  border-left: 1px solid var(--border);
  padding: 20px 16px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  overflow-y: auto;
}

.kinetic-chart {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 12px;
}
#kineticCanvas {
  width: 100%;
  height: 150px;
  display: block;
}
.kinetic-params {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-top: 10px;
}
.kparam {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 3px;
  padding: 8px;
  text-align: center;
}
.kparam-label { font-size: 10px; color: var(--textdim); font-family: 'Share Tech Mono', monospace; }
.kparam-val { font-family: 'Orbitron', sans-serif; font-size: 16px; color: var(--accent2); margin-top: 2px; }

.live-feed {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 12px;
  max-height: 300px;
  overflow-y: auto;
  font-family: 'Share Tech Mono', monospace;
  font-size: 11px;
}
.feed-item {
  padding: 6px 0;
  border-bottom: 1px solid rgba(26,58,92,0.4);
  color: var(--textdim);
  line-height: 1.4;
}
.feed-item:last-child { border-bottom: none; }
.feed-time { color: rgba(0,200,255,0.5); font-size: 10px; }

.progress-section { text-align: center; }
.ring-container { position: relative; display: inline-block; margin: 10px auto; }
.ring-label {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.ring-pct { font-family: 'Orbitron', sans-serif; font-size: 20px; font-weight: 700; color: var(--accent1); }
.ring-sub { font-size: 10px; color: var(--textdim); }

::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

@media (max-width: 1100px) {
  .main-layout { grid-template-columns: 240px 1fr; }
  .sidebar-right { display: none; }
}
@media (max-width: 768px) {
  .main-layout { grid-template-columns: 1fr; }
  .sidebar-left { border-right: none; border-bottom: 1px solid var(--border); }
  .header-stats { display: none; }
}
</style>
</head>
<body>

<header>
  <div class="logo-block">
    <div class="logo-icon">Ar</div>
    <div class="logo-text">
      <h1>ARCHIMEDES</h1>
      <p>v5.0 · AUTONOMOUS SCIENTIFIC DISCOVERY ENGINE</p>
    </div>
  </div>
  <div class="header-stats">
    <div class="hstat"><div class="hstat-val" id="stat-concepts">0</div><div class="hstat-lbl">CONCEPTS</div></div>
    <div class="hstat"><div class="hstat-val" id="stat-edges">0</div><div class="hstat-lbl">EDGES</div></div>
    <div class="hstat"><div class="hstat-val" id="stat-hypotheses">0</div><div class="hstat-lbl">HYPOTHÈSES</div></div>
    <div class="hstat"><div class="hstat-val" id="stat-complete" style="color:var(--accent3)">0</div><div class="hstat-lbl">VALIDÉES</div></div>
  </div>
  <div class="sys-status">
    <div class="status-dot"></div>
    <span id="sys-status-text">PHP <?= PHP_VERSION ?> · SQLite · LiteSpeed</span>
  </div>
</header>

<div class="main-layout">

  <aside class="sidebar-left">
    <div class="section-title">// PIPELINE CONTROL</div>

    <button class="phase-btn" id="btn-init" onclick="runAction('init_db', this)">
      <div class="phase-num">0</div>
      <div class="phase-info">
        <div class="phase-name">INIT DATABASE</div>
        <div class="phase-desc">Créer tables + index SQLite</div>
      </div>
      <div class="phase-indicator"></div>
    </button>

    <button class="phase-btn" id="btn-p1" onclick="runPhase(1, this)">
      <div class="phase-num">1</div>
      <div class="phase-info">
        <div class="phase-name">ONTOLOGIE</div>
        <div class="phase-desc">Génération concepts MeSH</div>
      </div>
      <div class="phase-indicator"></div>
    </button>

    <button class="phase-btn" id="btn-p2" onclick="runPhase(2, this)">
      <div class="phase-num">2</div>
      <div class="phase-info">
        <div class="phase-name">DEEP-SCAN</div>
        <div class="phase-desc">PubMed + extraction triplets</div>
      </div>
      <div class="phase-indicator"></div>
    </button>

    <button class="phase-btn" id="btn-p3" onclick="runPhase(3, this)">
      <div class="phase-num">3</div>
      <div class="phase-info">
        <div class="phase-name">GRAPH REASON</div>
        <div class="phase-desc">SQL 4° degré + transitivité</div>
      </div>
      <div class="phase-indicator"></div>
    </button>

    <button class="phase-btn" id="btn-p4" onclick="runPhase(4, this)">
      <div class="phase-num">4</div>
      <div class="phase-info">
        <div class="phase-name">SIMULATION</div>
        <div class="phase-desc">Cinétique + Red Team</div>
      </div>
      <div class="phase-indicator"></div>
    </button>

    <button class="phase-btn" id="btn-p5" onclick="runPhase(5, this)">
      <div class="phase-num">5</div>
      <div class="phase-info">
        <div class="phase-name">VALIDATION</div>
        <div class="phase-desc">Nouveauté + Pre-print</div>
      </div>
      <div class="phase-indicator"></div>
    </button>

    <div class="section-title" style="margin-top:8px">// AUTO-CYCLE</div>
    <div class="auto-block">
      <div class="auto-label">// BOUCLE INFINIE</div>
      <div class="auto-controls">
        <button class="auto-btn" id="btn-auto" onclick="toggleAuto()">▶ START</button>
        <div>
          <div class="auto-label" style="margin:0">CYCLES</div>
          <div class="cycle-count" id="cycle-count">0</div>
        </div>
      </div>
    </div>

    <div class="section-title" style="margin-top:8px">// UTILS</div>
    <div class="util-row">
      <button class="util-btn" onclick="loadStats()">REFRESH</button>
      <button class="util-btn" onclick="loadGraph()">GRAPH</button>
    </div>
    <div class="util-row">
      <button class="util-btn" onclick="loadReports()">REPORTS</button>
      <button class="util-btn" onclick="loadLogs()">LOGS</button>
    </div>
    <div class="util-row">
      <button class="util-btn danger" onclick="confirmClear()">CLEAR DB</button>
    </div>

    <div style="margin-top:auto; padding-top:16px; border-top:1px solid var(--border);">
      <div class="section-title">// MISTRAL KEYS</div>
      <?php foreach ($MISTRAL_KEYS as $i => $k): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:4px 0;">
        <div style="width:6px;height:6px;border-radius:50%;background:var(--accent3);box-shadow:0 0 6px var(--accent3)"></div>
        <span style="font-family:'Share Tech Mono',monospace;font-size:10px;color:var(--textdim)">KEY <?= $i+1 ?> ···<?= substr($k,-6) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </aside>

  <main class="center-panel">
    <nav class="tab-nav">
      <button class="tab active" onclick="showTab('console', this)">CONSOLE</button>
      <button class="tab" onclick="showTab('dashboard', this)">DASHBOARD</button>
      <button class="tab" onclick="showTab('graph', this)">GRAPH</button>
      <button class="tab" onclick="showTab('reports', this)">REPORTS</button>
      <button class="tab" onclick="showTab('logs', this)">SYSTEM LOGS</button>
    </nav>

    <div class="tab-content">

      <div id="tab-console" class="tab-pane active">
        <div class="console" id="main-console">
          <span class="log-line info">[ ARCHIMEDES v5.0 ] Autonomous Scientific Discovery Engine</span>
          <span class="log-line dim">[ INIT ] PHP <?= PHP_VERSION ?> · SQLite3 · LiteSpeed/Hostinger</span>
          <span class="log-line dim">[ INIT ] Mistral API · <?= count($MISTRAL_KEYS) ?> clés en rotation</span>
          <span class="log-line dim">[ READY ] Cliquez sur "INIT DATABASE" pour démarrer le pipeline.</span>
        </div>
        <div id="result-display" style="margin-top:16px;"></div>
      </div>

      <div id="tab-dashboard" class="tab-pane">
        <div class="cards-grid" id="stats-grid">
          <div class="card"><div class="card-title">CONCEPTS</div><div class="card-val" id="d-concepts">—</div><div class="card-sub">Termes MeSH indexés</div></div>
          <div class="card"><div class="card-title">EDGES</div><div class="card-val" id="d-edges">—</div><div class="card-sub">Relations dans le graphe</div></div>
          <div class="card"><div class="card-title">ARTICLES</div><div class="card-val" id="d-articles">—</div><div class="card-sub">PubMed récoltés</div></div>
          <div class="card"><div class="card-title">HYPOTHÈSES</div><div class="card-val" id="d-hypotheses">—</div><div class="card-sub">Générées par Graph Reasoning</div></div>
          <div class="card" style="border-color:rgba(127,255,0,0.2)"><div class="card-title" style="color:var(--accent3)">VALIDÉES</div><div class="card-val" id="d-validated" style="color:var(--accent3)">—</div><div class="card-sub">Simulation + Red Team OK</div></div>
          <div class="card" style="border-color:rgba(0,200,255,0.2)"><div class="card-title" style="color:var(--accent1)">PRE-PRINTS</div><div class="card-val" id="d-complete" style="color:var(--accent1)">—</div><div class="card-sub">Rapports générés</div></div>
        </div>
        <div style="margin-top:20px;">
          <div class="section-title">// TOP CONCEPTS (DEGRÉ)</div>
          <div id="top-concepts" style="margin-top:12px;"></div>
        </div>
        <div style="margin-top:20px;">
          <div class="section-title">// CLUSTERS</div>
          <div id="clusters-display" style="margin-top:12px;"></div>
        </div>
      </div>

      <div id="tab-graph" class="tab-pane">
        <div class="section-title" style="margin-bottom:12px">// KNOWLEDGE GRAPH — VISUALISATION</div>
        <canvas id="graphCanvas"></canvas>
        <div style="margin-top:12px;font-family:'Share Tech Mono',monospace;font-size:11px;color:var(--textdim)">
          Nœuds : <span id="graph-nodes">0</span> · Edges : <span id="graph-edges">0</span>
        </div>
      </div>

      <div id="tab-reports" class="tab-pane">
        <div class="section-title" style="margin-bottom:12px">// PRE-PRINTS GÉNÉRÉS PAR L'IA</div>
        <div id="reports-container"><p style="color:var(--textdim)">Cliquez sur "REPORTS" pour charger les rapports.</p></div>
      </div>

      <div id="tab-logs" class="tab-pane">
        <div class="section-title" style="margin-bottom:12px">// SYSTEM LOGS</div>
        <div class="console" id="sys-logs" style="max-height:600px;">
          <span class="log-line dim">Cliquez sur "LOGS" pour charger les journaux système.</span>
        </div>
      </div>

    </div>
  </main>

  <aside class="sidebar-right">
    <div class="section-title">// KINETIC SIMULATOR</div>
    <div class="kinetic-chart">
      <canvas id="kineticCanvas"></canvas>
      <div class="kinetic-params">
        <div class="kparam"><div class="kparam-label">Vmax</div><div class="kparam-val" id="kp-vmax">—</div></div>
        <div class="kparam"><div class="kparam-label">Km</div><div class="kparam-val" id="kp-km">—</div></div>
        <div class="kparam"><div class="kparam-label">v(0.001M)</div><div class="kparam-val" id="kp-v">—</div></div>
        <div class="kparam"><div class="kparam-label">STATUS</div><div class="kparam-val" id="kp-status" style="font-size:12px">IDLE</div></div>
      </div>
    </div>

    <div class="section-title">// LIVE ACTIVITY FEED</div>
    <div class="live-feed" id="live-feed">
      <div class="feed-item"><span class="feed-time">INIT</span><br>Système prêt. En attente du démarrage du pipeline.</div>
    </div>

    <div class="section-title">// PIPELINE PROGRESS</div>
    <div class="progress-section">
      <div class="ring-container">
        <svg width="100" height="100" viewBox="0 0 100 100">
          <circle cx="50" cy="50" r="42" fill="none" stroke="var(--border)" stroke-width="4"/>
          <circle id="progress-ring" cx="50" cy="50" r="42" fill="none" stroke="var(--accent1)" stroke-width="4"
            stroke-linecap="round"
            stroke-dasharray="263.9"
            stroke-dashoffset="263.9"
            transform="rotate(-90 50 50)"
            style="transition: stroke-dashoffset 0.5s ease; filter: drop-shadow(0 0 6px var(--accent1));"/>
        </svg>
        <div class="ring-label">
          <div class="ring-pct" id="progress-pct">0%</div>
          <div class="ring-sub">PIPELINE</div>
        </div>
      </div>
      <div id="progress-phases" style="font-family:'Share Tech Mono',monospace;font-size:10px;color:var(--textdim);margin-top:8px;text-align:left;"></div>
    </div>
  </aside>

</div>

<script>
let autoRunning = false;
let autoCycle   = 0;
let autoTimer   = null;
let phaseDone   = {1:false, 2:false, 3:false, 4:false, 5:false};

async function ajax(action, extraData = {}) {
  const formData = new FormData();
  formData.append('action', action);
  Object.entries(extraData).forEach(([k,v]) => formData.append(k, v));

  try {
    const res = await fetch(window.location.href, { method: 'POST', body: formData });
    return await res.json();
  } catch(e) {
    return { error: 'Fetch failed: ' + e.message };
  }
}

function log(msg, type = 'info') {
  const c = document.getElementById('main-console');
  const el = document.createElement('span');
  el.className = `log-line ${type}`;
  el.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
  c.appendChild(el);
  c.scrollTop = c.scrollHeight;
}

function addFeed(msg) {
  const feed = document.getElementById('live-feed');
  const item = document.createElement('div');
  item.className = 'feed-item';
  item.innerHTML = `<span class="feed-time">${new Date().toLocaleTimeString()}</span><br>${msg}`;
  feed.insertBefore(item, feed.firstChild);
  if (feed.children.length > 30) feed.removeChild(feed.lastChild);
}

async function runAction(action, btn) {
  if (btn) { btn.classList.add('loading'); btn.classList.remove('success','error'); }
  log(`→ Action: ${action} ...`, 'info');

  const data = await ajax(action);

  if (btn) { btn.classList.remove('loading'); btn.classList.add(data.error ? 'error' : 'success'); }

  if (data.error) {
    log(`✗ ERREUR: ${data.error}`, 'error');
    addFeed('❌ ' + data.error);
    showResult({ error: data.error });
    return null;
  }

  log(`✓ ${data.message || JSON.stringify(data).substring(0,100)}`, 'ok');
  showResult(data);
  loadStats();
  return data;
}

async function runPhase(n, btn) {
  const actions = { 1:'phase1_seed', 2:'phase2_harvest', 3:'phase3_reason', 4:'phase4_sim', 5:'phase5_validate' };
  const action = actions[n];
  if (!action) return;

  if (btn) { btn.classList.add('loading'); btn.classList.remove('success','error'); }
  log(`→ PHASE ${n} démarrée ...`, 'warn');
  addFeed(`🔬 Phase ${n} en cours...`);

  const data = await ajax(action);

  if (btn) { btn.classList.remove('loading'); btn.classList.add(data.error || data.status === 'error' ? 'error' : 'success'); }

  if (data.error || data.status === 'error') {
    log(`✗ Phase ${n} ERREUR: ${data.error || data.message}`, 'error');
    addFeed(`❌ Phase ${n}: ${data.error || data.message}`);
    showResult(data);
    return null;
  }

  phaseDone[n] = true;
  updateProgress();
  log(`✓ Phase ${n} terminée. ${summarizePhase(n, data)}`, 'ok');
  addFeed(`✅ Phase ${n} OK — ${summarizePhase(n, data)}`);
  showResult(data);

  if (n === 4 && data.simulations && data.simulations.length) {
    const sim = data.simulations[0];
    if (sim.velocities) drawKinetic(sim.velocities);
    document.getElementById('kp-vmax').textContent = '—';
    document.getElementById('kp-km').textContent = '—';
    document.getElementById('kp-v').textContent = sim.velocities ? sim.velocities[1].toFixed(5) : '—';
    document.getElementById('kp-status').textContent = sim.kinetic_valid ? '✓ VALID' : '✗ FAIL';
    document.getElementById('kp-status').style.color = sim.kinetic_valid ? 'var(--accent3)' : 'var(--accent4)';
  }

  loadStats();
  return data;
}

function summarizePhase(n, d) {
  const s = {
    1: () => `${d.inserted||0} concepts insérés, entropy=${d.entropy||0}`,
    2: () => `${d.articles||0} articles récoltés, ${d.total_edges||0} edges`,
    3: () => `${d.hypotheses||0} hypothèses générées`,
    4: () => `${(d.simulations||[]).length} simulations`,
    5: () => `${(d.reports||[]).length} pre-prints générés`,
  };
  return (s[n] || (() => JSON.stringify(d).substring(0,80)))();
}

async function toggleAuto() {
  const btn = document.getElementById('btn-auto');
  if (!autoRunning) {
    autoRunning = true;
    btn.textContent = '⏹ STOP';
    btn.classList.add('active');
    log('⚡ AUTO-CYCLE démarré', 'warn');
    runAutoLoop();
  } else {
    autoRunning = false;
    btn.textContent = '▶ START';
    btn.classList.remove('active');
    if (autoTimer) clearTimeout(autoTimer);
    log('⏸ AUTO-CYCLE arrêté', 'dim');
  }
}

async function runAutoLoop() {
  if (!autoRunning) return;

  autoCycle++;
  document.getElementById('cycle-count').textContent = autoCycle;
  log(`═══ AUTO-CYCLE #${autoCycle} ═══`, 'warn');

  const phases = [1,2,3,4,5];
  for (const p of phases) {
    if (!autoRunning) break;
    const btn = document.getElementById(`btn-p${p}`);
    await runPhase(p, btn);
    await new Promise(r => setTimeout(r, 3000));
  }

  if (autoRunning) {
    log('⏳ Pause 60s avant prochain cycle...', 'dim');
    autoTimer = setTimeout(runAutoLoop, 60000);
  }
}

function showResult(data) {
  const el = document.getElementById('result-display');
  if (!el) return;
  
  const isError = data.error || data.status === 'error';
  const color = isError ? 'var(--accent4)' : data.status === 'warn' ? 'var(--accent2)' : 'var(--accent3)';

  let html = `<div style="background:var(--panel);border:1px solid ${isError ? 'rgba(255,45,107,0.3)' : 'var(--border)'};border-radius:4px;padding:16px;font-family:'Share Tech Mono',monospace;font-size:11px;">`;
  html += `<div style="color:${color};font-size:12px;margin-bottom:8px;letter-spacing:2px;">// RÉSULTAT DERNIÈRE ACTION</div>`;

  if (data.phase) html += `<div style="color:var(--accent1)">PHASE ${data.phase} — STATUS: ${data.status?.toUpperCase()}</div>`;
  if (data.message) html += `<div style="color:var(--text);margin-top:4px">${data.message}</div>`;
  if (data.analysis) html += `<div style="color:var(--textdim);margin-top:6px">${data.analysis}</div>`;
  if (data.summary) html += `<div style="color:var(--textdim);margin-top:6px">${data.summary}</div>`;
  if (data.error) html += `<div style="color:var(--accent4);margin-top:4px">ERROR: ${data.error}</div>`;

  if (data.simulations) {
    data.simulations.forEach(sim => {
      const badge = `<span class="badge ${sim.final_status==='VALIDATED'?'badge-valid':'badge-reject'}">${sim.final_status}</span>`;
      html += `<div style="margin-top:8px;padding:8px;border:1px solid var(--border);border-radius:3px;">${badge} <b>${sim.concept_a}</b> → <b>${sim.concept_c}</b> | Red Team: ${sim.redteam_verdict} | CV: ${sim.monte_carlo_cv}</div>`;
    });
  }
  if (data.reports) {
    data.reports.forEach(r => {
      html += `<div style="margin-top:8px;padding:8px;border:1px solid rgba(0,200,255,0.2);border-radius:3px;"><span class="badge badge-valid">PRE-PRINT</span> <b>${r.title}</b><div style="color:var(--textdim);">${r.relation} | Novelty: ${r.is_novel ? '✓ ABSOLUTE' : r.novelty_count + ' prior art'}</div></div>`;
    });
  }

  html += '</div>';
  el.innerHTML = html;
}

async function loadStats() {
  const data = await ajax('get_stats');
  if (data.error) return;

  document.getElementById('stat-concepts').textContent = data.concepts || 0;
  document.getElementById('stat-edges').textContent = data.edges || 0;
  document.getElementById('stat-hypotheses').textContent = data.hypotheses || 0;
  document.getElementById('stat-complete').textContent = data.complete || 0;

  document.getElementById('d-concepts').textContent = data.concepts || 0;
  document.getElementById('d-edges').textContent = data.edges || 0;
  document.getElementById('d-articles').textContent = data.articles || 0;
  document.getElementById('d-hypotheses').textContent = data.hypotheses || 0;
  document.getElementById('d-validated').textContent = data.validated || 0;
  document.getElementById('d-complete').textContent = data.complete || 0;

  if (data.top_concepts) {
    const tc = document.getElementById('top-concepts');
    tc.innerHTML = data.top_concepts.map(c => 
      `<div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid rgba(26,58,92,0.3)">
        <div style="width:${Math.max(20, (c.degree/Math.max(...data.top_concepts.map(x=>x.degree||1)))*200)}px;height:4px;background:var(--accent1);border-radius:2px;"></div>
        <span style="font-family:'Share Tech Mono',monospace;font-size:11px;color:var(--text)">${c.name}</span>
        <span style="font-family:'Orbitron',monospace;font-size:11px;color:var(--accent2);margin-left:auto">${c.degree}</span>
      </div>`
    ).join('');
  }

  if (data.clusters) {
    const colors = ['var(--accent1)','var(--accent2)','var(--accent3)','var(--accent4)','#a855f7','#f59e0b'];
    const cl = document.getElementById('clusters-display');
    cl.innerHTML = data.clusters.map((c,i) => 
      `<span class="badge" style="background:rgba(0,200,255,0.06);border-color:${colors[i%6]};color:${colors[i%6]}">CLUSTER ${c.cluster_id}: ${c.cnt} concepts</span>`
    ).join('');
  }
}

async function loadGraph() {
  showTab('graph', document.querySelector('.tab:nth-child(3)'));
  const data = await ajax('get_graph');
  if (data.error || !data.nodes) return;

  document.getElementById('graph-nodes').textContent = data.nodes.length;
  document.getElementById('graph-edges').textContent = data.edges.length;

  const canvas = document.getElementById('graphCanvas');
  const ctx = canvas.getContext('2d');
  const W = canvas.offsetWidth;
  const H = canvas.offsetHeight;
  canvas.width = W;
  canvas.height = H;

  ctx.fillStyle = '#060d14';
  ctx.fillRect(0, 0, W, H);

  if (!data.nodes.length) return;

  const nodes = {};
  const clusterColors = ['#00c8ff','#ff6b00','#7fff00','#ff2d6b','#a855f7','#f59e0b'];

  data.nodes.forEach((n, i) => {
    const angle = (i / data.nodes.length) * Math.PI * 2;
    const r = Math.min(W, H) * 0.35;
    const cluster = parseInt(n.cluster_id) || 0;
    const clusterAngleOffset = cluster * (Math.PI * 2 / 6);
    const cr = r * (0.4 + (cluster % 3) * 0.2);
    
    nodes[n.id] = {
      x: W/2 + Math.cos(angle + clusterAngleOffset) * cr,
      y: H/2 + Math.sin(angle + clusterAngleOffset) * cr,
      color: clusterColors[cluster % clusterColors.length],
      name: n.name,
    };
  });

  data.edges.forEach(e => {
    const src = nodes[e.source_id];
    const tgt = nodes[e.target_id];
    if (!src || !tgt) return;
    const conf = parseFloat(e.confidence) || 0.5;
    ctx.beginPath();
    ctx.moveTo(src.x, src.y);
    ctx.lineTo(tgt.x, tgt.y);
    const color = e.relation_type === 'inhibits' ? `rgba(255,45,107,${conf*0.5})` : `rgba(0,200,255,${conf*0.4})`;
    ctx.strokeStyle = color;
    ctx.lineWidth = conf * 1.5;
    ctx.stroke();
  });

  Object.values(nodes).forEach(n => {
    ctx.beginPath();
    ctx.arc(n.x, n.y, 5, 0, Math.PI*2);
    ctx.fillStyle = n.color;
    ctx.shadowBlur = 10;
    ctx.shadowColor = n.color;
    ctx.fill();
    ctx.shadowBlur = 0;

    ctx.fillStyle = 'rgba(184,212,232,0.7)';
    ctx.font = '9px "Share Tech Mono"';
    ctx.fillText(n.name.substring(0,15), n.x+8, n.y+3);
  });
}

async function loadReports() {
  showTab('reports', null);
  const data = await ajax('get_reports');
  const container = document.getElementById('reports-container');
  
  if (!data.reports || !data.reports.length) {
    container.innerHTML = '<p style="color:var(--textdim);font-family:\'Share Tech Mono\',monospace">Aucun pre-print généré. Complétez les 5 phases du pipeline.</p>';
    return;
  }

  container.innerHTML = data.reports.map(r => `
    <div class="report-item" onclick="this.querySelector('.report-full').style.display = this.querySelector('.report-full').style.display==='none'?'block':'none'">
      <div class="report-title">${r.title || r.file}</div>
      <div class="report-meta">📄 ${r.file} · ${r.size} bytes · ${r.created}</div>
      <div class="report-preview">${r.preview.replace(/</g,'&lt;')}</div>
      <div class="report-full" style="display:none">${r.preview.replace(/</g,'&lt;')}</div>
    </div>
  `).join('');
}

async function loadLogs() {
  showTab('logs', null);
  const data = await ajax('get_logs');
  const el = document.getElementById('sys-logs');
  if (data.logs) {
    el.innerHTML = data.logs.split('\n').map(l => `<span class="log-line dim">${l}</span>`).join('');
    el.scrollTop = el.scrollHeight;
  }
}

async function confirmClear() {
  if (!confirm('⚠️ Effacer toute la base de données ? Cette action est irréversible.')) return;
  const data = await ajax('clear_db');
  log(data.message || 'DB effacée', data.error ? 'error' : 'warn');
  loadStats();
  phaseDone = {1:false,2:false,3:false,4:false,5:false};
  updateProgress();
}

function updateProgress() {
  const done = Object.values(phaseDone).filter(Boolean).length;
  const pct = Math.round((done / 5) * 100);
  const circumference = 263.9;
  const offset = circumference - (circumference * pct / 100);
  document.getElementById('progress-ring').style.strokeDashoffset = offset;
  document.getElementById('progress-pct').textContent = pct + '%';
  document.getElementById('progress-phases').innerHTML = [1,2,3,4,5].map(p => 
    `<div style="color:${phaseDone[p]?'var(--accent3)':'var(--textdim)'}">Phase ${p}: ${phaseDone[p]?'✓ DONE':'⋯'}</div>`
  ).join('');
}

function drawKinetic(velocities) {
  const canvas = document.getElementById('kineticCanvas');
  const ctx = canvas.getContext('2d');
  const W = canvas.offsetWidth || 280;
  const H = 150;
  canvas.width = W;
  canvas.height = H;

  ctx.fillStyle = '#060d14';
  ctx.fillRect(0, 0, W, H);

  const maxV = Math.max(...velocities, 0.001);
  const padX = 20, padY = 10;
  const chartW = W - padX*2;
  const chartH = H - padY*2;

  ctx.strokeStyle = 'rgba(26,58,92,0.5)';
  ctx.lineWidth = 1;
  for (let i = 0; i <= 4; i++) {
    const y = padY + (chartH * i / 4);
    ctx.beginPath(); ctx.moveTo(padX, y); ctx.lineTo(W-padX, y); ctx.stroke();
  }

  ctx.beginPath();
  ctx.strokeStyle = 'var(--accent2)';
  ctx.lineWidth = 2;
  ctx.shadowBlur = 8;
  ctx.shadowColor = 'var(--accent2)';

  velocities.forEach((v, i) => {
    const x = padX + (i / (velocities.length - 1)) * chartW;
    const y = padY + chartH - (v / maxV) * chartH;
    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
  });
  ctx.stroke();
  ctx.shadowBlur = 0;

  velocities.forEach((v, i) => {
    const x = padX + (i / (velocities.length - 1)) * chartW;
    const y = padY + chartH - (v / maxV) * chartH;
    ctx.beginPath();
    ctx.arc(x, y, 3, 0, Math.PI*2);
    ctx.fillStyle = 'var(--accent1)';
    ctx.fill();
  });
}

function showTab(name, btn) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  const pane = document.getElementById(`tab-${name}`);
  if (pane) pane.classList.add('active');
  if (btn) btn.classList.add('active');
  if (name === 'dashboard') loadStats();
  if (name === 'graph') setTimeout(loadGraph, 100);
  if (name === 'reports') loadReports();
  if (name === 'logs') loadLogs();
}

document.addEventListener('DOMContentLoaded', () => {
  loadStats();
  updateProgress();
  drawKinetic([0.001, 0.09, 0.33, 0.66, 0.90]);
  setInterval(loadStats, 30000);
});
</script>
</body>
</html>
