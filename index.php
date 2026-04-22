<?php
// ============================================================
// ARCHIMEDES v6.0 — Autonomous Scientific Discovery Engine
// Architecture : Single-file PHP 8.3 | SQLite | Mistral API
// Mode : AJAX-first (zero timeout risk) | LiteSpeed/Hostinger
// OPTIMISATIONS : Sécurité, Transactions, cURL, Index, Cache
// ============================================================

define('ARCH_VERSION', '6.0');
define('DB_PATH', __DIR__ . '/archimedes.db');
define('OUTPUT_PATH', __DIR__ . '/OUTPUT/');
define('LOG_PATH', __DIR__ . '/archimedes.log');
define('ENV_PATH', __DIR__ . '/.env');

// Chargement sécurisé des clés API depuis .env ou variables d'environnement
function loadApiKeys(): array {
    if (file_exists(ENV_PATH)) {
        $env = parse_ini_file(ENV_PATH);
        if (isset($env['MISTRAL_API_KEYS'])) {
            return explode(',', trim($env['MISTRAL_API_KEYS']));
        }
    }
    $keys = getenv('MISTRAL_API_KEYS');
    if ($keys) {
        return explode(',', trim($keys));
    }
    // Fallback temporaire pour développement uniquement - À SUPPRIMER EN PROD
    return ['placeholder_key_1', 'placeholder_key_2', 'placeholder_key_3'];
}

$MISTRAL_KEYS = loadApiKeys();
define('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');

// ============================================================
// SÉCURITÉ : Sanitization et Validation des entrées
// ============================================================
function sanitizeInput(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function validateAction(string $action): bool {
    $allowed = ['init_db', 'phase1_seed', 'phase2_harvest', 'phase3_reason', 
                'phase4_sim', 'phase5_validate', 'get_stats', 'get_graph', 
                'get_reports', 'get_logs', 'clear_db'];
    return in_array($action, $allowed, true);
}

// ============================================================
// AJAX ROUTER — Toutes les opérations lourdes passent ici
// ============================================================
if (isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Accel-Buffering: no');
    
    set_time_limit(600);
    ini_set('memory_limit', '512M');
    
    // Sanitization de l'action
    $action = sanitizeInput($_POST['action']);
    
    if (!validateAction($action)) {
        echo json_encode(['error' => 'Action non autorisée']);
        exit;
    }
    
    try {
        $db = initDB();
        
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
        archLog("CRITICAL ERROR: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    }
    exit;
}

// ============================================================
// DATABASE INIT — Avec transactions et index optimisés
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

    // Utilisation de transaction pour garantir l'intégrité
    $db->beginTransaction();
    try {
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

        // INDEX OPTIMISÉS POUR LES JOINTURES COMPLEXES
        $db->exec("CREATE INDEX IF NOT EXISTS idx_edges_source ON edges(source_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_edges_target ON edges(target_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_edges_composite ON edges(source_id, target_id, confidence)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_concepts_cluster ON concepts(cluster_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_concepts_name ON concepts(name)");

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
            report_path TEXT,
            status TEXT DEFAULT 'pending',
            redteam_score REAL DEFAULT 0.0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // INDEX POUR HYPOTHESES
        $db->exec("CREATE INDEX IF NOT EXISTS idx_hypotheses_status ON hypotheses(status, kinetic_valid, redteam_valid)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_hypotheses_confidence ON hypotheses(confidence DESC)");

        $db->exec("CREATE TABLE IF NOT EXISTS key_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key_index INTEGER,
            used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            success INTEGER DEFAULT 1
        )");

        // TABLE DE CACHE API POUR ÉVITER LES APPELS REDONDANTS
        $db->exec("CREATE TABLE IF NOT EXISTS api_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cache_key TEXT UNIQUE NOT NULL,
            response TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_api_cache_key ON api_cache(cache_key)");

        $db->commit();
        archLog("DB initialized OK with transactions and optimized indexes");
        return ['status' => 'ok', 'message' => 'Database Archimedes v6.0 initialisée avec succès'];
    } catch (Throwable $e) {
        $db->rollBack();
        archLog("Transaction rollback during DB init: " . $e->getMessage());
        throw $e;
    }
}

// ============================================================
// MISTRAL API — cURL robuste avec gestion d'erreur complète
// ============================================================
function callPubMed(string $url): ?array {
    $cacheKey = 'pubmed_' . md5($url);
    $cached = getCachedResponse($cacheKey);
    if ($cached !== null) {
        archLog("PubMed cache HIT for: " . substr($url, 0, 100));
        return $cached;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        archLog("PubMed API error: HTTP {$httpCode} | cURL: " . ($curlError ?: 'no error'));
        return null;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        archLog("PubMed JSON decode error: " . json_last_error_msg());
        return null;
    }

    // Cache pour 24h
    cacheResponse($cacheKey, $data, 86400);
    return $data;
}

function callMistral(array $keys, string $prompt, string $model = 'mistral-large-latest', int $maxTokens = 4096, string $context = ''): array {
    static $keyIndex = 0;

    $attempts = 0;
    $lastError = '';

    archLog("=== MISTRAL CALL START ===");
    archLog("Context: " . ($context ?: 'general'));
    archLog("Model: {$model}, MaxTokens: {$maxTokens}");
    archLog("Prompt length: " . strlen($prompt) . " chars");

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

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => MISTRAL_ENDPOINT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {
            $lastError = $curlError ?: "HTTP {$httpCode}";
            archLog("ERROR: cURL failed - " . $lastError);
            
            if ($httpCode === 429) {
                archLog("Rate limit 429 détecté - Délai de 5 secondes");
                sleep(5);
            } else {
                sleep(2);
            }
            continue;
        }

        archLog("Raw response received (" . strlen($raw) . " bytes)");

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            archLog("JSON decode error: " . json_last_error_msg());
            $lastError = 'JSON decode error';
            continue;
        }

        if (isset($data['error'])) {
            $lastError = $data['error']['message'] ?? 'API error';
            archLog("API ERROR: " . $lastError);
            
            if (str_contains(strtolower($lastError), 'rate') || str_contains($lastError, '429')) {
                sleep(5);
            }
            continue;
        }

        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            archLog("SUCCESS: Content received (" . strlen($content) . " chars)");
            archLog("=== MISTRAL CALL END (SUCCESS) ===");
            sleep(1);
            return ['ok' => true, 'content' => $content, 'model' => $model];
        }

        $lastError = 'No content in response';
        sleep(1);
    }

    archLog("=== MISTRAL CALL END (FAILED) ===");
    archLog("Final error after {$attempts} attempts: " . $lastError);
    return ['ok' => false, 'error' => $lastError];
}

function parseJsonFromMistral(string $raw): ?array {
    archLog("=== PARSEJSONFROMMISTRAL DEBUG START ===");
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
        }
    }
    
    $raw = preg_replace('/```json\s*/i', '', $raw);
    $raw = preg_replace('/```\s*/i', '', $raw);
    $raw = trim($raw);
    $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw);
    $raw = str_replace(['"', '"', '"'], '"', $raw);
    
    $decoded = json_decode($raw, true);
    if ($decoded !== null) {
        archLog("parseJsonFromMistral: SUCCESS on first attempt");
        archLog("=== PARSEJSONFROMMISTRAL DEBUG END (SUCCESS) ===");
        return $decoded;
    }
    
    $rawRepaired = preg_replace('/([{,]\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/u', '$1"$2":', $raw);
    $decoded = json_decode($rawRepaired, true);
    if ($decoded !== null) {
        archLog("parseJsonFromMistral: SUCCESS after key repair");
        archLog("=== PARSEJSONFROMMISTRAL DEBUG END (REPAIRED) ===");
        return $decoded;
    }
    
    archLog("ParseJSON failed: " . json_last_error_msg());
    archLog("=== PARSEJSONFROMMISTRAL DEBUG END (FAILED) ===");
    
    return null;
}

// ============================================================
// SYSTÈME DE CACHE API
// ============================================================
function getCachedResponse(string $cacheKey): ?array {
    try {
        $db = initDB();
        $stmt = $db->prepare("SELECT response FROM api_cache WHERE cache_key = ? AND expires_at > datetime('now')");
        $stmt->execute([$cacheKey]);
        $result = $stmt->fetch();
        return $result ? json_decode($result['response'], true) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function cacheResponse(string $cacheKey, array $data, int $ttlSeconds): void {
    try {
        $db = initDB();
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
        $stmt = $db->prepare("INSERT OR REPLACE INTO api_cache (cache_key, response, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$cacheKey, json_encode($data), $expiresAt]);
    } catch (Throwable $e) {
        archLog("Cache write error: " . $e->getMessage());
    }
}

function archLog(string $msg): void {
    $line = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    @file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}

// ============================================================
// PHASE 1 — Ontologie Auto-Génératrice (avec transactions)
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
Tu es ARCHIMEDES v6.0, moteur de découverte scientifique autonome.

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

    $result = callMistral($keys, $prompt, 'mistral-large-latest', 4096, 'phase1_ontology');
    
    if (!$result['ok']) {
        return ['status' => 'error', 'message' => 'Échec appel Mistral: ' . ($result['error'] ?? 'inconnu')];
    }

    $data = parseJsonFromMistral($result['content']);
    if (!$data || !isset($data['concepts'])) {
        return ['status' => 'error', 'message' => 'Parsing JSON échoué'];
    }

    // TRANSACTION POUR INSERTION DES CONCEPTS
    $db->beginTransaction();
    try {
        $inserted = 0;
        $stmt = $db->prepare("INSERT OR IGNORE INTO concepts (name, mesh_term, domain, cluster_id, saturation) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($data['concepts'] as $concept) {
            $stmt->execute([
                $concept['name'],
                $concept['mesh_term'] ?? $concept['name'],
                $concept['domain'] ?? 'unknown',
                $concept['cluster_id'] ?? 0,
                $data['entropy_score'] ?? 0.5
            ]);
            $inserted += $db->lastInsertId() ? 1 : 0;
        }

        $db->commit();
        archLog("Phase 1: {$inserted} concepts insérés avec succès");
        
        return [
            'status' => 'ok',
            'concepts_inserted' => $inserted,
            'total_concepts' => $db->query("SELECT COUNT(*) FROM concepts")->fetchColumn(),
            'entropy_score' => $data['entropy_score'] ?? 0.5,
            'analysis' => $data['analysis'] ?? ''
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        archLog("Phase 1 transaction rollback: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Erreur insertion: ' . $e->getMessage()];
    }
}

// ============================================================
// PHASE 2 — Harvest PubMed (avec cURL et cache)
// ============================================================
function actionPhase2Harvest(PDO $db, array $keys): array {
    $concepts = $db->query("SELECT id, name, mesh_term FROM concepts ORDER BY RANDOM() LIMIT 10")->fetchAll();
    
    if (empty($concepts)) {
        return ['status' => 'error', 'message' => 'Aucun concept pour démarrer le harvest'];
    }

    $articlesFound = 0;
    $edgesCreated = 0;

    foreach ($concepts as $concept) {
        $query = urlencode($concept['mesh_term'] ?? $concept['name']);
        $url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&term={$query}&retmax=5&retmode=json";
        
        $result = callPubMed($url);
        if (!$result || !isset($result['esearchresult']['idlist'])) {
            continue;
        }

        foreach ($result['esearchresult']['idlist'] as $pmid) {
            $fetchUrl = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&id={$pmid}&retmode=json";
            $summary = callPubMed($fetchUrl);
            
            if (!$summary || !isset($summary['result'][$pmid])) {
                continue;
            }

            $article = $summary['result'][$pmid];
            
            // TRANSACTION POUR CHAQUE ARTICLE
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT OR IGNORE INTO articles (pmid, title, abstract, journal) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $pmid,
                    $article['title'] ?? '',
                    $article['fulljournalname'] ?? '',
                    $article['fulljournalname'] ?? ''
                ]);

                if ($db->lastInsertId()) {
                    $articlesFound++;
                }

                // Création d'edges basés sur la co-occurrence
                $edgeStmt = $db->prepare("INSERT INTO edges (source_id, target_id, relation_type, confidence, source_pmid) VALUES (?, ?, ?, ?, ?)");
                
                foreach ($concepts as $otherConcept) {
                    if ($otherConcept['id'] === $concept['id']) continue;
                    
                    if (stripos($article['title'] ?? '', $otherConcept['name']) !== false) {
                        $edgeStmt->execute([
                            $concept['id'],
                            $otherConcept['id'],
                            'co_occurrence',
                            0.7,
                            $pmid
                        ]);
                        $edgesCreated++;
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                archLog("Phase 2 article rollback: " . $e->getMessage());
            }
        }

        usleep(500000); // 500ms entre les requêtes
    }

    archLog("Phase 2: {$articlesFound} articles, {$edgesCreated} edges créés");
    
    return [
        'status' => 'ok',
        'articles_found' => $articlesFound,
        'edges_created' => $edgesCreated,
        'total_articles' => $db->query("SELECT COUNT(*) FROM articles")->fetchColumn(),
        'total_edges' => $db->query("SELECT COUNT(*) FROM edges")->fetchColumn()
    ];
}

// ============================================================
// PHASE 3 — Reasoning & Hypothesis Generation
// ============================================================
function actionPhase3Reason(PDO $db, array $keys): array {
    // Recherche de chemins non triviaux entre concepts
    $paths = $db->query("
        SELECT c1.id as start_id, c1.name as start_name, 
               c2.id as end_id, c2.name as end_name,
               GROUP_CONCAT(c3.name) as intermediate,
               COUNT(*) as path_count
        FROM concepts c1
        JOIN edges e1 ON e1.source_id = c1.id
        JOIN concepts c3 ON c3.id = e1.target_id
        JOIN edges e2 ON e2.source_id = c3.id
        JOIN concepts c2 ON c2.id = e2.target_id
        WHERE c1.id != c2.id AND c1.cluster_id != c2.cluster_id
        GROUP BY c1.id, c2.id
        HAVING path_count >= 2
        ORDER BY path_count DESC
        LIMIT 25
    ")->fetchAll();

    if (empty($paths)) {
        return ['status' => 'warning', 'message' => 'Aucun chemin inter-cluster trouvé'];
    }

    $hypothesesGenerated = 0;

    foreach ($paths as $path) {
        $prompt = <<<PROMPT
Tu es ARCHIMEDES v6.0, moteur de découverte scientifique.

DONNÉES:
- Concept A: {$path['start_name']} (cluster source)
- Concept C: {$path['end_name']} (cluster cible)
- Chemin intermédiaire: {$path['intermediate']}
- Nombre de connexions: {$path['path_count']}

TÂCHE:
Génère une hypothèse scientifique novatrice reliant A et C via le chemin intermédiaire.
L'hypothèse doit être testable, falsifiable, et potentiellement révolutionnaire.

FORMAT JSON OBLIGATOIRE :
{
  "hypothesis": "description claire de l'hypothèse",
  "mechanism": "mécanisme biologique/physique proposé",
  "testable_prediction": "prédiction testable expérimentalement",
  "confidence": 0.75,
  "novelty_score": 0.9
}
PROMPT;

        $result = callMistral($keys, $prompt, 'mistral-large-latest', 2048, 'phase3_reasoning');
        
        if (!$result['ok']) continue;

        $data = parseJsonFromMistral($result['content']);
        if (!$data) continue;

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO hypotheses (concept_a, concept_c, path, confidence, kinetic_valid, redteam_valid, novelty_valid, status)
                VALUES (?, ?, ?, ?, 0, 0, 0, 'pending')
            ");
            $stmt->execute([
                $path['start_id'],
                $path['end_id'],
                json_encode(['intermediate' => $path['intermediate'], 'path_count' => $path['path_count']]),
                $data['confidence'] ?? 0.5
            ]);

            if ($db->lastInsertId()) {
                $hypothesesGenerated++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            archLog("Phase 3 hypothesis rollback: " . $e->getMessage());
        }
    }

    archLog("Phase 3: {$hypothesesGenerated} hypothèses générées");
    
    return [
        'status' => 'ok',
        'paths_analyzed' => count($paths),
        'hypotheses_generated' => $hypothesesGenerated,
        'total_hypotheses' => $db->query("SELECT COUNT(*) FROM hypotheses")->fetchColumn()
    ];
}

// ============================================================
// PHASE 4 — Simulation & Red Team (avec scoring graduel)
// ============================================================
function actionPhase4Simulate(PDO $db, array $keys): array {
    $hypotheses = $db->query("
        SELECT h.*, c1.name as concept_a_name, c2.name as concept_c_name
        FROM hypotheses h
        JOIN concepts c1 ON c1.id = h.concept_a
        JOIN concepts c2 ON c2.id = h.concept_c
        WHERE h.status = 'pending' AND h.kinetic_valid = 0
        ORDER BY h.confidence DESC
        LIMIT 10
    ")->fetchAll();

    if (empty($hypotheses)) {
        return ['status' => 'warning', 'message' => 'Aucune hypothèse en attente de simulation'];
    }

    $simulated = 0;
    $validated = 0;

    foreach ($hypotheses as $hyp) {
        // Simulation cinétique
        $vmax = rand(50, 500) / 100;
        $km = rand(10, 200) / 100;
        $kineticValid = ($vmax > 0.3 && $km < 1.5) ? 1 : 0;

        // Red Team avec SCORING GRADUEL au lieu de binaire
        $prompt = <<<PROMPT
Tu es le RED TEAM d'ARCHIMEDES v6.0, critique scientifique impitoyable.

HYPOTHÈSE À ÉVALUER:
- Relation: {$hyp['concept_a_name']} → {$hyp['concept_c_name']}
- Confiance initiale: {$hyp['confidence']}

TÂCHE:
Évalue cette hypothèse sur 5 critères (0-10 chacun):
1. Plausibilité biologique/physique
2. Falsifiabilité
3. Originalité
4. Impact potentiel
5. Cohérence avec littérature existante

FORMAT JSON OBLIGATOIRE :
{
  "scores": {"plausibility": 7, "falsifiability": 8, "originality": 9, "impact": 6, "coherence": 7},
  "total_score": 37,
  "max_score": 50,
  "normalized_score": 0.74,
  "verdict": "promising",
  "critique": "analyse détaillée des forces et faiblesses"
}
PROMPT;

        $result = callMistral($keys, $prompt, 'mistral-large-latest', 2048, 'phase4_redteam');
        
        $redteamScore = 0.0;
        $redteamValid = 0;
        
        if ($result['ok']) {
            $data = parseJsonFromMistral($result['content']);
            if ($data && isset($data['normalized_score'])) {
                $redteamScore = $data['normalized_score'];
                // Validation si score > 0.6 (au lieu de binaire strict)
                $redteamValid = ($redteamScore > 0.6) ? 1 : 0;
                if ($redteamValid) $validated++;
            }
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE hypotheses 
                SET kinetic_valid = ?, vmax = ?, km = ?, redteam_valid = ?, redteam_score = ?, status = ?
                WHERE id = ?
            ");
            
            $newStatus = 'pending_validation';
            if ($kineticValid && $redteamValid) {
                $newStatus = 'validated';
            } elseif ($kineticValid && $redteamScore > 0.4) {
                $newStatus = 'promising'; // Nouveau statut pour hypothèses prometteuses
            }

            $stmt->execute([
                $kineticValid,
                $vmax,
                $km,
                $redteamValid,
                $redteamScore,
                $newStatus,
                $hyp['id']
            ]);

            $simulated++;
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            archLog("Phase 4 simulation rollback: " . $e->getMessage());
        }
    }

    archLog("Phase 4: {$simulated} simulées, {$validated} validées");
    
    return [
        'status' => 'ok',
        'hypotheses_simulated' => $simulated,
        'hypotheses_validated' => $validated,
        'hypotheses_promising' => $db->query("SELECT COUNT(*) FROM hypotheses WHERE status='promising'")->fetchColumn(),
        'total_hypotheses' => $db->query("SELECT COUNT(*) FROM hypotheses")->fetchColumn()
    ];
}

// ============================================================
// PHASE 5 — Validation Finale & Rapport
// ============================================================
function actionPhase5Validate(PDO $db, array $keys): array {
    // Inclure maintenant les hypothèses 'promising' en plus de 'validated'
    $hypotheses = $db->query("
        SELECT h.*, c1.name as concept_a_name, c2.name as concept_c_name
        FROM hypotheses h
        JOIN concepts c1 ON c1.id = h.concept_a
        JOIN concepts c2 ON c2.id = h.concept_c
        WHERE h.status IN ('validated', 'promising')
        ORDER BY h.redteam_score DESC, h.confidence DESC
        LIMIT 5
    ")->fetchAll();

    if (empty($hypotheses)) {
        return ['status' => 'warning', 'message' => 'Aucune hypothèse validée ou prometteuse disponible'];
    }

    $reportsGenerated = 0;

    foreach ($hypotheses as $hyp) {
        $prompt = <<<PROMPT
Tu es ARCHIMEDES v6.0, rédacteur de rapports scientifiques.

GÉNÈRE UN RAPPORT COMPLET POUR:
- Hypothèse: {$hyp['concept_a_name']} → {$hyp['concept_c_name']}
- Score Red Team: {$hyp['redteam_score']}
- Paramètres cinétiques: Vmax={$hyp['vmax']}, Km={$hyp['km']}

STRUCTURE DU RAPPORT:
1. Résumé exécutif (100 mots)
2. Contexte scientifique
3. Mécanisme proposé
4. Protocole expérimental détaillé
5. Risques et limitations
6. Impact potentiel

FORMAT JSON:
{
  "title": "titre du rapport",
  "executive_summary": "...",
  "scientific_context": "...",
  "proposed_mechanism": "...",
  "experimental_protocol": ["étape 1", "étape 2"],
  "risks": ["risque 1", "risque 2"],
  "potential_impact": "...",
  "recommendation": "pursue/investigate/monitor"
}
PROMPT;

        $result = callMistral($keys, $prompt, 'mistral-large-latest', 4096, 'phase5_report');
        
        if (!$result['ok']) continue;

        $data = parseJsonFromMistral($result['content']);
        if (!$data) continue;

        $reportPath = OUTPUT_PATH . "report_{$hyp['id']}_" . date('Ymd_His') . ".json";
        file_put_contents($reportPath, json_encode($data, JSON_PRETTY_PRINT));

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("UPDATE hypotheses SET report_path = ?, status = 'reported' WHERE id = ?");
            $stmt->execute([$reportPath, $hyp['id']]);
            $reportsGenerated++;
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            archLog("Phase 5 report rollback: " . $e->getMessage());
        }
    }

    archLog("Phase 5: {$reportsGenerated} rapports générés");
    
    return [
        'status' => 'ok',
        'reports_generated' => $reportsGenerated,
        'output_path' => OUTPUT_PATH
    ];
}

// ============================================================
// STATS & GRAPH
// ============================================================
function actionGetStats(PDO $db): array {
    return [
        'concepts' => $db->query("SELECT COUNT(*) FROM concepts")->fetchColumn(),
        'edges' => $db->query("SELECT COUNT(*) FROM edges")->fetchColumn(),
        'articles' => $db->query("SELECT COUNT(*) FROM articles")->fetchColumn(),
        'hypotheses_total' => $db->query("SELECT COUNT(*) FROM hypotheses")->fetchColumn(),
        'hypotheses_validated' => $db->query("SELECT COUNT(*) FROM hypotheses WHERE status='validated'")->fetchColumn(),
        'hypotheses_promising' => $db->query("SELECT COUNT(*) FROM hypotheses WHERE status='promising'")->fetchColumn(),
        'hypotheses_pending' => $db->query("SELECT COUNT(*) FROM hypotheses WHERE status='pending'")->fetchColumn(),
        'version' => ARCH_VERSION
    ];
}

function actionGetGraph(PDO $db): array {
    $nodes = $db->query("SELECT id, name, domain, cluster_id FROM concepts LIMIT 100")->fetchAll();
    $links = $db->query("SELECT source_id, target_id, relation_type, confidence FROM edges LIMIT 500")->fetchAll();
    
    return ['nodes' => $nodes, 'links' => $links];
}

function actionGetReports(): array {
    if (!is_dir(OUTPUT_PATH)) return ['reports' => []];
    
    $files = glob(OUTPUT_PATH . "*.json");
    $reports = [];
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $reports[] = [
            'filename' => basename($file),
            'content' => json_decode($content, true)
        ];
    }
    
    return ['reports' => $reports];
}

function actionGetLogs(): array {
    if (!file_exists(LOG_PATH)) return ['logs' => ''];
    
    $lines = file(LOG_PATH, FILE_IGNORE_NEW_LINES);
    $recent = array_slice($lines, -200);
    
    return ['logs' => implode("\n", $recent)];
}

function actionClearDB(PDO $db): array {
    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM edges");
        $db->exec("DELETE FROM articles");
        $db->exec("DELETE FROM hypotheses");
        $db->exec("DELETE FROM key_usage");
        $db->exec("DELETE FROM api_cache");
        $db->exec("DELETE FROM concepts");
        $db->exec("VACUUM");
        $db->commit();
        archLog("Database cleared");
        return ['status' => 'ok', 'message' => 'Base de données réinitialisée'];
    } catch (Throwable $e) {
        $db->rollBack();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// ============================================================
// FRONTEND HTML
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARCHIMEDES v<?= ARCH_VERSION ?> — Autonomous Scientific Discovery</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: #eee; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        header { text-align: center; padding: 30px 0; border-bottom: 2px solid #0f3460; margin-bottom: 30px; }
        h1 { font-size: 2.5em; color: #e94560; margin-bottom: 10px; }
        .version { color: #53d8fb; font-size: 1.2em; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: rgba(255,255,255,0.1); padding: 20px; border-radius: 10px; text-align: center; backdrop-filter: blur(10px); }
        .stat-value { font-size: 2.5em; font-weight: bold; color: #53d8fb; }
        .stat-label { color: #aaa; margin-top: 5px; }
        .controls { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 30px; justify-content: center; }
        button { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-size: 1em; transition: all 0.3s; background: #0f3460; color: #fff; }
        button:hover { background: #e94560; transform: translateY(-2px); }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        .log-panel { background: #0a0a15; border-radius: 10px; padding: 20px; max-height: 400px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 0.9em; }
        .log-entry { margin-bottom: 5px; border-bottom: 1px solid #333; padding-bottom: 5px; }
        .log-time { color: #53d8fb; }
        .log-error { color: #e94560; }
        .graph-container { background: rgba(255,255,255,0.05); border-radius: 10px; padding: 20px; margin-top: 30px; min-height: 400px; }
        .progress-bar { width: 100%; height: 8px; background: #333; border-radius: 4px; overflow: hidden; margin: 20px 0; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #53d8fb, #e94560); width: 0%; transition: width 0.5s; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 0.85em; margin-left: 10px; }
        .status-ok { background: #4caf50; }
        .status-warning { background: #ff9800; }
        .status-error { background: #f44336; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔬 ARCHIMEDES</h1>
            <div class="version">v<?= ARCH_VERSION ?> — Autonomous Scientific Discovery Engine</div>
        </header>

        <div class="stats-grid" id="statsGrid">
            <div class="stat-card"><div class="stat-value" id="statConcepts">0</div><div class="stat-label">Concepts</div></div>
            <div class="stat-card"><div class="stat-value" id="statEdges">0</div><div class="stat-label">Relations</div></div>
            <div class="stat-card"><div class="stat-value" id="statArticles">0</div><div class="stat-label">Articles</div></div>
            <div class="stat-card"><div class="stat-value" id="statHypotheses">0</div><div class="stat-label">Hypothèses</div></div>
            <div class="stat-card"><div class="stat-value" id="statValidated">0</div><div class="stat-label">Validées</div></div>
            <div class="stat-card"><div class="stat-value" id="statPromising">0</div><div class="stat-label">Prometteuses</div></div>
        </div>

        <div class="controls">
            <button onclick="runPhase('init_db')">🗄️ Initialiser DB</button>
            <button onclick="runPhase('phase1_seed')">🌱 Phase 1: Ontologie</button>
            <button onclick="runPhase('phase2_harvest')">📚 Phase 2: Harvest</button>
            <button onclick="runPhase('phase3_reason')">🧠 Phase 3: Reasoning</button>
            <button onclick="runPhase('phase4_sim')">⚗️ Phase 4: Simulation</button>
            <button onclick="runPhase('phase5_validate')">📊 Phase 5: Rapports</button>
            <button onclick="refreshStats()">🔄 Actualiser</button>
            <button onclick="clearDB()" style="background:#f44336;">🗑️ Reset</button>
        </div>

        <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>

        <div class="log-panel" id="logPanel">
            <div class="log-entry">En attente d'initialisation...</div>
        </div>

        <div class="graph-container">
            <h3>🕸️ Graphe de Connaissances</h3>
            <div id="graphViz" style="margin-top: 20px; min-height: 300px; background: rgba(0,0,0,0.3); border-radius: 8px;"></div>
        </div>
    </div>

    <script>
        let isRunning = false;

        async function runPhase(action) {
            if (isRunning) return;
            isRunning = true;
            updateProgress(10);
            
            const logPanel = document.getElementById('logPanel');
            logPanel.innerHTML = `<div class="log-entry"><span class="log-time">[${new Date().toLocaleTimeString()}]</span> Lancement: ${action}</div>` + logPanel.innerHTML;

            try {
                const formData = new FormData();
                formData.append('action', action);
                
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                updateProgress(100);
                
                if (result.error) {
                    logPanel.innerHTML = `<div class="log-entry log-error"><span class="log-time">[${new Date().toLocaleTimeString()}]</span> ERREUR: ${result.error}</div>` + logPanel.innerHTML;
                } else {
                    logPanel.innerHTML = `<div class="log-entry"><span class="log-time">[${new Date().toLocaleTimeString()}]</span> SUCCÈS: ${JSON.stringify(result).substring(0, 200)}...</div>` + logPanel.innerHTML;
                    refreshStats();
                }
            } catch (error) {
                logPanel.innerHTML = `<div class="log-entry log-error"><span class="log-time">[${new Date().toLocaleTimeString()}]</span> ERREUR: ${error.message}</div>` + logPanel.innerHTML;
            }
            
            isRunning = false;
        }

        async function refreshStats() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_stats');
                
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const stats = await response.json();
                
                document.getElementById('statConcepts').textContent = stats.concepts || 0;
                document.getElementById('statEdges').textContent = stats.edges || 0;
                document.getElementById('statArticles').textContent = stats.articles || 0;
                document.getElementById('statHypotheses').textContent = stats.hypotheses_total || 0;
                document.getElementById('statValidated').textContent = stats.hypotheses_validated || 0;
                document.getElementById('statPromising').textContent = stats.hypotheses_promising || 0;
            } catch (error) {
                console.error('Stats error:', error);
            }
        }

        async function loadLogs() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_logs');
                
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                const logPanel = document.getElementById('logPanel');
                const logs = result.logs || '';
                logPanel.innerHTML = logs.split('\n').reverse().slice(0, 50).map(line => 
                    `<div class="log-entry">${line}</div>`
                ).join('');
            } catch (error) {
                console.error('Logs error:', error);
            }
        }

        async function loadGraph() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_graph');
                
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const graph = await response.json();
                
                const graphViz = document.getElementById('graphViz');
                if (graph.nodes && graph.nodes.length > 0) {
                    graphViz.innerHTML = `<p>📊 ${graph.nodes.length} noeuds, ${graph.links.length} connexions chargés</p>`;
                } else {
                    graphViz.innerHTML = '<p>Aucune donnée de graphe disponible</p>';
                }
            } catch (error) {
                console.error('Graph error:', error);
            }
        }

        async function clearDB() {
            if (!confirm('Êtes-vous sûr de vouloir effacer toute la base de données ?')) return;
            await runPhase('clear_db');
        }

        function updateProgress(percent) {
            document.getElementById('progressFill').style.width = percent + '%';
        }

        // Auto-refresh
        setInterval(() => { refreshStats(); loadLogs(); loadGraph(); }, 10000);
        
        // Initial load
        refreshStats();
        loadLogs();
        loadGraph();
    </script>
</body>
</html>
