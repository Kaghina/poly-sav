<?php
// Fichier avec toutes les fonctions communes du projet (connexion BDD, helpers, etc.)

declare(strict_types=1);

// On charge Composer pour utiliser PHPMailer (emails) et Dompdf (génération PDF)
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dompdf\Dompdf;
use Dompdf\Options;

// Config locale optionnelle (non obligatoire) : permet de surcharger les constantes
// ci-dessous sans toucher au code (ex: DB_PORT, LOCAL_ROOT_USER, LOCAL_ROOT_PASS...).
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Config MySQL - fonctionne automatiquement sur MAMP, WAMP et XAMPP
const DB_HOST = 'localhost';
const DB_NAME = 'poly_php';
const DB_USER = 'user1';
const DB_PASS = 'hcetylop';
const DB_CHARSET = 'utf8mb4';
// Ports testés dans l'ordre si DB_PORT n'est pas défini dans config.local.php :
// 3306 = MySQL standard, 3307 = MariaDB (WAMP), 3308 = 2e MySQL (WAMP), 8889 = MAMP
const DB_PORTS_AUTO = [3306, 3307, 3308, 8889];
// Mode debug : affiche les erreurs détaillées (à mettre false en prod)
const APP_DEBUG = true;

// Config pour l'envoi de mails.
// Les identifiants (SMTP_USER, SMTP_PASS, SMTP_FROM_EMAIL) sont SECRETS : ils se trouvent
// dans config.local.php (non versionné). Modèle : config.local.example.php
defined('SMTP_HOST') || define('SMTP_HOST', 'smtp.gmail.com');
defined('SMTP_PORT') || define('SMTP_PORT', 587);
defined('SMTP_USER') || define('SMTP_USER', '');
defined('SMTP_PASS') || define('SMTP_PASS', '');
defined('SMTP_FROM_EMAIL') || define('SMTP_FROM_EMAIL', SMTP_USER);
defined('SMTP_FROM_NAME') || define('SMTP_FROM_NAME', 'POLY SAV');

// Liste des ports MySQL à essayer
function getDbPorts(): array
{
    if (defined('DB_PORT')) {
        return [(int)constant('DB_PORT')];
    }
    return DB_PORTS_AUTO;
}

// Ouvre une connexion PDO (sans base si $dbName est vide)
function openPdo(int $port, string $user, string $pass, string $dbName = ''): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ($dbName !== '' ? ';dbname=' . $dbName : '') . ';charset=' . DB_CHARSET;
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2,  // Évite d'attendre longtemps sur un port qui ne répond pas
    ]);
}

// Crée la base poly_php à partir de base_poly.sql si elle n'existe pas encore
function createDatabaseIfMissing(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW DATABASES LIKE '" . DB_NAME . "'");
    if ($stmt->fetch() !== false) {
        return;  // La base existe déjà
    }

    $sqlFile = __DIR__ . '/base_poly.sql';
    $sql = is_file($sqlFile) ? file_get_contents($sqlFile) : false;
    if ($sql === false) {
        throw new RuntimeException("Fichier base_poly.sql introuvable ou illisible.");
    }

    // On enlève les lignes de gestion d'utilisateur (déjà faites par autoInitDatabase)
    $sql = preg_replace('/^(CREATE USER IF NOT EXISTS|GRANT ALL PRIVILEGES|FLUSH PRIVILEGES).*?;/mi', '', $sql);

    $pdo->exec($sql);
}

// Fonction qui crée automatiquement la BDD et l'utilisateur au premier lancement.
// Retourne le port sur lequel la base est disponible.
function autoInitDatabase(): int
{
    // Liste des identifiants root à tester (selon MAMP/WAMP/XAMPP)
    $rootCredentials = [];
    if (defined('LOCAL_ROOT_USER') && defined('LOCAL_ROOT_PASS')) {
        $rootCredentials[] = [(string)constant('LOCAL_ROOT_USER'), (string)constant('LOCAL_ROOT_PASS')];
    }
    $rootCredentials[] = ['root', ''];         // WAMP/XAMPP (Windows)
    $rootCredentials[] = ['root', 'root'];     // MAMP (Mac)
    $rootCredentials[] = ['root', 'password']; // Parfois utilisé

    $errors = [];

    foreach (getDbPorts() as $port) {
        // 1. Avec root : on crée user1 puis la base si besoin
        foreach ($rootCredentials as [$rootUser, $rootPass]) {
            try {
                $pdoRoot = openPdo($port, $rootUser, $rootPass);
            } catch (Throwable $e) {
                $errors[] = "port $port / $rootUser : " . $e->getMessage();
                continue;
            }
            try {
                $pdoRoot->exec("CREATE USER IF NOT EXISTS '" . DB_USER . "'@'localhost' IDENTIFIED BY '" . DB_PASS . "'");
                $pdoRoot->exec("GRANT ALL PRIVILEGES ON *.* TO '" . DB_USER . "'@'localhost' WITH GRANT OPTION");
                $pdoRoot->exec("FLUSH PRIVILEGES");
                createDatabaseIfMissing($pdoRoot);
                return $port;
            } catch (Throwable $e) {
                $errors[] = "port $port / $rootUser (init) : " . $e->getMessage();
            }
        }

        // 2. Sans root : user1 a peut-être été créé à la main
        try {
            $pdoUser = openPdo($port, DB_USER, DB_PASS);
            createDatabaseIfMissing($pdoUser);
            return $port;
        } catch (Throwable $e) {
            $errors[] = "port $port / " . DB_USER . " : " . $e->getMessage();
        }
    }

    // Rien n'a marché : message d'aide
    $msg = "Impossible de se connecter à MySQL / MariaDB. Vérifiez que WAMP/MAMP est démarré (icône verte).";
    if (APP_DEBUG) {
        $msg .= "\n\nTentatives :\n- " . implode("\n- ", $errors);
        $msg .= "\n\nSOLUTION : dans phpMyAdmin, exécutez le fichier base_poly.sql,";
        $msg .= "\nou créez un fichier config.local.php avec par exemple :";
        $msg .= "\n<?php const DB_PORT = 3307; const LOCAL_ROOT_USER = 'root'; const LOCAL_ROOT_PASS = '';";
    }
    error_log($msg);
    throw new RuntimeException($msg);
}

// Fonction qui renvoie la connexion à la BDD (toujours la même instance)
function getConnexion(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;  // Déjà connecté, on renvoie la même connexion
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Erreurs explicites
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Tableaux associatifs
        PDO::ATTR_EMULATE_PREPARES   => false,                   // Vraies requêtes préparées
        PDO::ATTR_TIMEOUT            => 2,
    ];

    // 1. Cas normal : la base existe déjà, on cherche le bon port avec user1
    foreach (getDbPorts() as $port) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            return $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            continue;
        }
    }

    // 2. Premier lancement : on crée l'utilisateur et la base, puis on se connecte
    try {
        $port = autoInitDatabase();
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        return $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (Throwable $t) {
        // Page d'erreur lisible plutôt qu'une "Fatal error" PHP
        http_response_code(500);
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Base de données indisponible</title>'
            . '<link rel="stylesheet" href="assets/app.css"></head><body><main class="container">'
            . '<div class="alert alert-danger"><div style="font-weight:800;margin-bottom:6px;">Base de données indisponible</div>'
            . '<pre style="white-space:pre-wrap;margin:0;">' . e($t->getMessage()) . '</pre></div></main></body></html>';
        exit;
    }
}

// Sécurise une chaîne avant de l'afficher en HTML (protection XSS)
function e(?string $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Récupère un paramètre GET de type string, avec une valeur par défaut
function getGetString(string $key, string $default = ''): string
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    return trim((string)$_GET[$key]);
}

// Récupère un paramètre GET de type int, avec validation
function getGetInt(string $key, int $default = 0): int
{
    $raw = getGetString($key, '');
    if ($raw === '' || !ctype_digit($raw)) {
        return $default;
    }
    return (int)$raw;
}

// Affiche un message d'erreur stylé (avec détails techniques si debug activé)
function afficheErreur(string $message, ?Throwable $t = null): void
{
    echo '<div class="alert alert-danger" role="alert">';
    echo function_exists('icon') ? icon('alert', 20) : '';
    echo '<div><strong>Erreur</strong><div>' . e($message) . '</div>';
    if ($t !== null && APP_DEBUG) {
        echo '<pre>Détail technique (debug) : ' . e($t->getMessage()) . '</pre>';
    }
    echo '</div></div>';
}

// Formatte un montant en euros (ex: 1234.56 => "1 234,56 €")
function fmtEuro(float $montant): string
{
    return number_format($montant, 2, ',', ' ') . ' €';
}

// Formatte une date en français (ex: "2024-01-30" => "30/01/2024")
function fmtDateFr(?string $date): string
{
    if ($date === null || $date === '') {
        return '-';
    }
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }
    return date('d/m/Y', $timestamp);
}

// Formatte une date+heure en français (ex: "2024-01-30 14:30:00" => "30/01/2024 14:30")
function fmtDateTimeFr(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '-';
    }
    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return $datetime;
    }
    return date('d/m/Y H:i', $timestamp);
}

// Récupère la liste des pays pour les formulaires (code => libellé)
function getPays(PDO $pdo): array
{
    $sql = "SELECT code, libelle FROM pays ORDER BY libelle";
    $stmt = $pdo->query($sql);
    $pays = [];
    foreach ($stmt->fetchAll() as $row) {
        $pays[$row['code']] = $row['libelle'];
    }
    return $pays;
}

// Récupère la liste des motifs de contact
function getMotifs(PDO $pdo): array
{
    $sql = "SELECT code, libelle FROM motifs ORDER BY libelle";
    $stmt = $pdo->query($sql);
    $motifs = [];
    foreach ($stmt->fetchAll() as $row) {
        $motifs[(int)$row['code']] = $row['libelle'];
    }
    return $motifs;
}

// Récupère les formes juridiques d'entreprise
function getFormesJuridiques(PDO $pdo): array
{
    $sql = "SELECT code, libelle FROM formes_juridiques ORDER BY code";
    $stmt = $pdo->query($sql);
    $formes = [];
    foreach ($stmt->fetchAll() as $row) {
        $formes[(int)$row['code']] = $row['libelle'];
    }
    return $formes;
}

// Récupère tous les articles avec leurs infos (prix, TVA, etc.)
function getArticles(PDO $pdo): array
{
    $sql = "
        SELECT a.code, a.designation, a.unite, a.forfait_ht, t.taux AS taux_tva
        FROM articles a
        INNER JOIN tva t ON t.code = a.tva
        ORDER BY a.code
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

// Récupère toutes les infos d'un client avec jointures (pays, motif, forme juridique)
function getClientComplet(PDO $pdo, int $idClient): ?array
{
    $sql = "
        SELECT
            c.code,
            c.nom,
            c.prenom,
            c.date_naissance,
            c.numero_secu_social,
            c.date_entree,
            c.forme,
            fj.libelle AS forme_libelle,
            c.code_pays,
            p.libelle AS pays_libelle,
            c.code_motif,
            m.libelle AS motif_libelle
        FROM clients c
        INNER JOIN formes_juridiques fj ON fj.code = c.forme
        INNER JOIN pays p ON p.code = c.code_pays
        INNER JOIN motifs m ON m.code = c.code_motif
        WHERE c.code = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $idClient]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;  // null si pas trouvé
}

// Récupère l'historique des devis d'un client (du plus récent au plus ancien)
function getDevisParClient(PDO $pdo, int $idClient): array
{
    $sql = "
        SELECT d.id, d.reference, d.date_devis, d.statut, d.total_ht, d.total_tva, d.total_ttc
        FROM devis d
        WHERE d.client_code = :id
        ORDER BY d.date_devis DESC, d.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $idClient]);
    return $stmt->fetchAll();
}

// Récupère l'en-tête d'un devis (infos générales + infos client)
function getDevisEntete(PDO $pdo, int $idDevis): ?array
{
    $sql = "
        SELECT
            d.id,
            d.reference,
            d.date_devis,
            d.statut,
            d.total_ht,
            d.total_tva,
            d.total_ttc,
            d.societe_nom,
            d.societe_adresse,
            d.societe_tel,
            d.societe_email,
            c.code AS client_code,
            c.nom AS client_nom,
            c.prenom AS client_prenom,
            c.numero_secu_social,
            c.date_entree,
            p.libelle AS pays_libelle
        FROM devis d
        INNER JOIN clients c ON c.code = d.client_code
        INNER JOIN pays p ON p.code = c.code_pays
        WHERE d.id = :id_devis
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_devis' => $idDevis]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

// Récupère les lignes d'un devis (articles, quantités, prix, réductions...)
function getDevisLignes(PDO $pdo, int $idDevis): array
{
    $sql = "
        SELECT
            l.article_code AS code,
            l.designation,
            l.unite,
            l.quantite AS qte,
            l.forfait_ht,
            l.reduction_pct,
            l.taux_tva,
            l.montant_ht
        FROM devis_lignes l
        WHERE l.devis_id = :id_devis
        ORDER BY l.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_devis' => $idDevis]);
    return $stmt->fetchAll();
}

// Récupère le détail de la TVA par taux pour un devis
function getDevisTva(PDO $pdo, int $idDevis): array
{
    $sql = "
        SELECT taux_tva, montant_tva
        FROM devis_tva
        WHERE devis_id = :id_devis
        ORDER BY taux_tva ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_devis' => $idDevis]);
    $detail = [];
    foreach ($stmt->fetchAll() as $row) {
        $detail[(string)$row['taux_tva']] = (float)$row['montant_tva'];
    }
    return $detail;
}

// ============================================================
// GED - Gestion Électronique des Documents
// ============================================================

// Constantes pour le stockage des fichiers
const STORAGE_DIR = __DIR__ . '/_storage';
const STORAGE_CLIENTS_DIR = STORAGE_DIR . '/clients';
const STORAGE_MAX_BYTES = 20_000_000;  // Limite de 20 Mo par fichier
// Types de fichiers acceptés à l'upload (tous convertibles en PDF)
const GED_UPLOAD_MIMES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'text/plain'];

// Vérifie qu'un dossier existe, sinon le crée
function ensureDir(string $path): void
{
    if (is_dir($path)) {
        return;  // Le dossier existe déjà
    }
    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Impossible de créer le dossier: $path");
    }
}

// Nettoie un nom de fichier pour éviter les problèmes de sécurité et d'encodage
function sanitizeFilename(string $name): string
{
    $name = basename($name);  // Supprime tout chemin
    $name = preg_replace('/[^\p{L}\p{N}\.\-\_\s]/u', '_', $name) ?? 'document';  // Garde que lettres, chiffres, .-_
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? 'document';  // Normalise les espaces
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'document';  // Nom par défaut si vide ou suspect
    }
    // Limite à 180 caractères pour éviter les chemins trop longs
    if (mb_strlen($name, 'UTF-8') > 180) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 160, 'UTF-8');
        $name = $ext !== '' ? ($base . '.' . $ext) : $base;
    }
    return $name;
}

// Retourne le dossier de stockage d'un client (le crée si besoin)
function getClientStorageDir(int $clientCode): string
{
    if ($clientCode <= 0) {
        throw new InvalidArgumentException("Client invalide.");
    }
    ensureDir(STORAGE_CLIENTS_DIR);
    $dir = STORAGE_CLIENTS_DIR . '/' . $clientCode;
    ensureDir($dir);
    return $dir;
}

// Retourne le chemin relatif de base pour un client
function getClientRelativeBase(int $clientCode): string
{
    return '_storage/clients/' . $clientCode;
}

// Détecte le type MIME d'un fichier (pour les en-têtes HTTP)
function detectMime(string $path): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return 'application/octet-stream';  // Type par défaut si échec
    }
    $mime = finfo_file($finfo, $path);
    return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
}

// Récupère un document de la GED par son ID
function gedGetDocument(PDO $pdo, int $docId): ?array
{
    $sql = "SELECT * FROM ged_documents WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $docId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

// Récupère tous les documents d'un client (du plus récent au plus ancien)
function gedGetDocumentsByClient(PDO $pdo, int $clientCode): array
{
    $sql = "
        SELECT d.*
        FROM ged_documents d
        WHERE d.client_code = :client
        ORDER BY d.created_at DESC, d.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':client' => $clientCode]);
    return $stmt->fetchAll();
}

// Insère un nouveau document dans la table GED
function gedInsertDocument(PDO $pdo, array $doc): int
{
    $sql = "
        INSERT INTO ged_documents
            (client_code, devis_id, doc_type, doc_nature, doc_contenu,
             original_name, stored_name, relative_path, mime, size_bytes, sha256)
        VALUES
            (:client_code, :devis_id, :doc_type, :doc_nature, :doc_contenu,
             :original_name, :stored_name, :relative_path, :mime, :size_bytes, :sha256)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':client_code' => (int)$doc['client_code'],
        ':devis_id' => $doc['devis_id'] ?? null,
        ':doc_type' => (string)$doc['doc_type'],
        ':doc_nature' => (string)$doc['doc_nature'],
        ':doc_contenu' => (string)$doc['doc_contenu'],
        ':original_name' => (string)$doc['original_name'],
        ':stored_name' => (string)$doc['stored_name'],
        ':relative_path' => (string)$doc['relative_path'],
        ':mime' => (string)$doc['mime'],
        ':size_bytes' => (int)$doc['size_bytes'],
        ':sha256' => (string)$doc['sha256'],
    ]);
    return (int)$pdo->lastInsertId();  // Retourne l'ID du document créé
}

// Sauvegarde un contenu texte/HTML comme document GED (ex: pour un devis généré)
function gedSaveContentDocument(
    PDO $pdo,
    int $clientCode,
    string $content,
    string $originalName,
    string $docType,
    string $docNature,
    string $docContenu,
    ?int $devisId = null,
    string $subFolder = 'docs'
): int {
    $originalName = sanitizeFilename($originalName);
    
    // On calcule le hash SHA-256 du contenu pour détecter les doublons
    $sha256 = hash('sha256', $content);
    if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
        throw new RuntimeException("Impossible de calculer le hash du document.");
    }

    // On vérifie qu'on n'a pas déjà ce document pour ce client (anti-doublon)
    $stmt = $pdo->prepare("SELECT id FROM ged_documents WHERE client_code = :c AND sha256 = :h");
    $stmt->execute([':c' => $clientCode, ':h' => $sha256]);
    $existing = $stmt->fetch();
    if ($existing !== false) {
        throw new RuntimeException("Un document identique existe déjà pour ce client.");
    }

    // Création du dossier de destination
    $clientDir = getClientStorageDir($clientCode);
    $subDir = $clientDir . '/' . $subFolder;
    ensureDir($subDir);

    // Génération du nom de fichier sur le serveur (avec timestamp + début du hash)
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $storedName = strtoupper($docType) . '-' . $clientCode . '-' . date('Ymd-His') . '-' . substr($sha256, 0, 12) . ($ext !== '' ? ('.' . $ext) : '');
    $destAbs = $subDir . '/' . $storedName;
    
    // Écriture du fichier sur le disque
    $bytes = file_put_contents($destAbs, $content);
    if ($bytes === false) {
        throw new RuntimeException("Échec d'écriture du document sur le serveur.");
    }

    $relativePath = getClientRelativeBase($clientCode) . '/' . $subFolder . '/' . $storedName;
    $mime = detectMime($destAbs);

    // Enregistrement en BDD
    return gedInsertDocument($pdo, [
        'client_code' => $clientCode,
        'devis_id' => $devisId,
        'doc_type' => trim($docType) !== '' ? trim($docType) : 'AUTRE',
        'doc_nature' => trim($docNature) !== '' ? trim($docNature) : 'FICHIER',
        'doc_contenu' => trim($docContenu) !== '' ? trim($docContenu) : '-',
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'relative_path' => $relativePath,
        'mime' => $mime,
        'size_bytes' => (int)$bytes,
        'sha256' => $sha256,
    ]);
}

/**
 * Sauvegarde un fichier uploadé pour un client (GED).
 * Stratégie d’indexation (commentaire demandé) :
 * - Indexation métier: (doc_type, doc_nature, doc_contenu) fournis par l’opérateur.
 * - Anti-doublon fiable: hash SHA-256 du contenu, unique par client.
 *   => empêche la sauvegarde d’un document strictement identique même si renommé.
 */
function gedSaveUploadedDocument(
    PDO $pdo,
    int $clientCode,
    array $file,
    string $docType,
    string $docNature,
    string $docContenu,
    ?int $devisId = null
): int {
    if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(uploadErrorMessage((int)($file['error'] ?? -1)));
    }
    if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
        throw new RuntimeException("Upload invalide (tmp_name manquant).");
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException("Upload refusé (source non reconnue).");
    }
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > STORAGE_MAX_BYTES) {
        throw new RuntimeException("Taille de fichier invalide (max " . STORAGE_MAX_BYTES . " octets).");
    }

    $originalName = sanitizeFilename((string)($file['name'] ?? 'document'));
    $sha256 = hash_file('sha256', $file['tmp_name']);
    if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
        throw new RuntimeException("Impossible de calculer le hash du document.");
    }

    // Anti-doublon par client (même contenu)
    $stmt = $pdo->prepare("SELECT id FROM ged_documents WHERE client_code = :c AND sha256 = :h");
    $stmt->execute([':c' => $clientCode, ':h' => $sha256]);
    $existing = $stmt->fetch();
    if ($existing !== false) {
        throw new RuntimeException("Un document identique est déjà stocké pour ce client (détection par hash).");
    }

    $mime = detectMime($file['tmp_name']);
    // Un .txt qui contient des balises peut être détecté comme HTML : on le traite comme du texte brut
    // (il sera servi en text/plain, donc jamais interprété par le navigateur)
    $extUpload = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extUpload === 'txt' && str_starts_with($mime, 'text/')) {
        $mime = 'text/plain';
    }
    // Seuls les formats convertibles en PDF sont acceptés (les documents sont envoyés en PDF)
    if (!in_array($mime, GED_UPLOAD_MIMES, true)) {
        throw new RuntimeException("Format non accepté ($mime). Formats autorisés : PDF, PNG, JPEG, GIF, texte (.txt).");
    }

    $clientDir = getClientStorageDir($clientCode);
    $subDir = $clientDir . '/docs';
    ensureDir($subDir);

    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $storedName = 'DOC-' . $clientCode . '-' . date('Ymd-His') . '-' . substr($sha256, 0, 12) . ($ext !== '' ? ('.' . $ext) : '');
    $destAbs = $subDir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        throw new RuntimeException("Échec de déplacement du fichier uploadé.");
    }

    $relativePath = getClientRelativeBase($clientCode) . '/docs/' . $storedName;

    return gedInsertDocument($pdo, [
        'client_code' => $clientCode,
        'devis_id' => $devisId,
        'doc_type' => trim($docType) !== '' ? trim($docType) : 'AUTRE',
        'doc_nature' => trim($docNature) !== '' ? trim($docNature) : 'FICHIER',
        'doc_contenu' => trim($docContenu) !== '' ? trim($docContenu) : '-',
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'relative_path' => $relativePath,
        'mime' => $mime,
        'size_bytes' => $size,
        'sha256' => $sha256,
    ]);
}

// Convertit un chemin relatif en chemin absolu sécurisé
// Empêche les attaques de type "path traversal" (../../etc/passwd)
function gedResolveAbsolutePath(string $relativePath): string
{
    // Empêche les traversals: on n’accepte que _storage/...
    $relativePath = ltrim($relativePath, '/');
    if (!str_starts_with($relativePath, '_storage/')) {
        throw new RuntimeException("Chemin interdit (doit être dans _storage).");
    }
    $abs = __DIR__ . '/' . $relativePath;
    $real = realpath($abs);
    $realStorage = realpath(STORAGE_DIR);
    
    // Double vérification : le fichier doit vraiment être dans le dossier _storage
    if ($real === false || $realStorage === false || !str_starts_with($real, $realStorage . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException("Fichier introuvable ou tentative d'accès suspect.");
    }
    return $real;
}

// Envoie un fichier au navigateur (affichage ou téléchargement)
function streamFile(string $absolutePath, string $mime, string $downloadName, bool $asAttachment): void
{
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        http_response_code(404);
        echo "Fichier introuvable.";
        exit;
    }
    
    $size = filesize($absolutePath);
    if ($size === false) {
        $size = 0;
    }

    // Headers HTTP pour le téléchargement/affichage
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');  // Sécurité
    if (in_array($mime, ['text/html', 'image/svg+xml'], true)) {
        header('Content-Security-Policy: sandbox'); // Empêche tout script contenu dans le fichier de s'exécuter
    }
    header('Content-Length: ' . (string)$size);
    
    // attachment = téléchargement, inline = affichage dans le navigateur
    $disp = $asAttachment ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $downloadName) . '"');
    
    readfile($absolutePath);  // Envoie le contenu du fichier
    exit;
}

// Envoie un contenu (ex: PDF généré en mémoire) au navigateur
function streamContent(string $content, string $mime, string $downloadName, bool $asAttachment): void
{
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string)strlen($content));
    $disp = $asAttachment ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disp . '; filename="' . str_replace(['"', "\r", "\n"], '', $downloadName) . '"');
    echo $content;
    exit;
}

// ============================================================
// PDF (bibliothèque Dompdf)
// ============================================================

// Convertit une page HTML en PDF (format A4) et renvoie le contenu binaire du PDF
function htmlToPdf(string $html): string
{
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');  // Police qui gère les accents et le symbole €
    $options->set('isRemoteEnabled', false);      // Pas de chargement de ressources externes (sécurité)
    $options->set('isPhpEnabled', false);
    $options->setChroot(__DIR__);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdf = $dompdf->output();
    if (!is_string($pdf) || $pdf === '') {
        throw new RuntimeException("La génération du PDF a échoué.");
    }
    return $pdf;
}

// Remplace l'extension d'un nom de fichier par .pdf (ex: "photo.png" => "photo.pdf")
function pdfFileName(string $name): string
{
    $base = pathinfo($name, PATHINFO_FILENAME);
    return ($base !== '' ? $base : 'document') . '.pdf';
}

// Mini-page HTML utilisée pour convertir une image ou un texte en PDF
function pdfWrapperHtml(string $title, string $bodyHtml): string
{
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' . e($title) . '</title>'
        . '<style>body{font-family:"DejaVu Sans",sans-serif;font-size:11px;color:#111827;margin:0}'
        . 'h1{font-size:13px;margin:0 0 10px 0;color:#374151}'
        . 'img{max-width:100%;max-height:700px}'
        . 'pre{white-space:pre-wrap;word-wrap:break-word;font-family:"DejaVu Sans Mono",monospace;font-size:10px}</style>'
        . '</head><body><h1>' . e($title) . '</h1>' . $bodyHtml . '</body></html>';
}

/**
 * Renvoie la version PDF d'un document de la GED.
 * - PDF : renvoyé tel quel
 * - HTML (ex: anciens devis) : converti avec Dompdf
 * - Images (PNG, JPEG, GIF) : intégrées dans une page A4
 * - Texte brut : mis en page dans un PDF
 * Retourne null si le format ne peut pas être converti (ex: .docx, .zip).
 */
function gedDocumentToPdf(array $doc, string $absPath): ?string
{
    // Type enregistré à l'upload (sinon détection sur le fichier)
    $mime = (string)($doc['mime'] ?? '');
    if ($mime === '' || $mime === 'application/octet-stream') {
        $mime = detectMime($absPath);
    }
    $title = (string)$doc['original_name'];

    if ($mime === 'application/pdf') {
        $content = file_get_contents($absPath);
        return $content === false ? null : $content;
    }
    if ($mime === 'text/html') {
        $html = file_get_contents($absPath);
        return $html === false ? null : htmlToPdf($html);
    }
    if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
        $data = file_get_contents($absPath);
        if ($data === false) {
            return null;
        }
        $img = '<img src="data:' . $mime . ';base64,' . base64_encode($data) . '" alt="">';
        return htmlToPdf(pdfWrapperHtml($title, $img));
    }
    if (str_starts_with($mime, 'text/')) {
        $text = file_get_contents($absPath);
        if ($text === false) {
            return null;
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        return htmlToPdf(pdfWrapperHtml($title, '<pre>' . e($text) . '</pre>'));
    }
    return null;  // Format non convertible
}

// Envoie un email avec une pièce jointe (contenu en mémoire) via Gmail SMTP
// Config à faire dans les constantes SMTP_* en haut du fichier
function sendMailWithAttachment(
    string $to,
    string $subject,
    string $bodyText,
    string $attachmentContent,
    string $fileName,
    string $mime
): bool {
    // Validation de l'email destinataire
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Adresse email invalide.");
    }
    if ($attachmentContent === '') {
        throw new RuntimeException("Pièce jointe vide.");
    }
    if (SMTP_USER === '' || SMTP_PASS === '') {
        throw new RuntimeException("Envoi de mail non configuré : copiez config.local.example.php en config.local.php et renseignez SMTP_USER / SMTP_PASS.");
    }

    $mail = new PHPMailer(true);

    try {
        // Config du serveur SMTP (Gmail)
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;        // smtp.gmail.com
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;        // Votre email Gmail
        $mail->Password   = SMTP_PASS;        // Mot de passe d'application Gmail
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;  // TLS sur port 587
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        // Définir l'expéditeur
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        // Définir le destinataire
        $mail->addAddress($to);

        // Ajouter la pièce jointe
        $mail->addStringAttachment($attachmentContent, $fileName, PHPMailer::ENCODING_BASE64, $mime);

        // Définir le contenu
        $mail->isHTML(false);  // Texte brut (pas de HTML)
        $mail->Subject = $subject;
        $mail->Body    = $bodyText;

        // Envoyer le mail
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new RuntimeException("Erreur lors de l'envoi : " . $mail->ErrorInfo, 0, $e);
    }
}

// Recherche de devis avec filtres optionnels (dates et montants)
function searchDevis(PDO $pdo, int $clientCode, string $dateMin, string $dateMax, string $montantMin, string $montantMax): array
{
    $sql = "
        SELECT d.id, d.reference, d.date_devis, d.statut, d.total_ht, d.total_tva, d.total_ttc
        FROM devis d
        WHERE d.client_code = :client
    ";
    $params = [':client' => $clientCode];
    $where = [];

    // Filtres sur les dates (si remplis)
    if ($dateMin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateMin)) {
        $where[] = "DATE(d.date_devis) >= :date_min";
        $params[':date_min'] = $dateMin;
    }
    if ($dateMax !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateMax)) {
        $where[] = "DATE(d.date_devis) <= :date_max";
        $params[':date_max'] = $dateMax;
    }

    // Filtres sur les montants (si remplis)
    if ($montantMin !== '' && preg_match('/^\d+([.,]\d{1,2})?$/', $montantMin)) {
        $min = (float)str_replace(',', '.', $montantMin);
        $where[] = "d.total_ht >= :m_min";
        $params[':m_min'] = number_format($min, 2, '.', '');
    }
    if ($montantMax !== '' && preg_match('/^\d+([.,]\d{1,2})?$/', $montantMax)) {
        $max = (float)str_replace(',', '.', $montantMax);
        $where[] = "d.total_ht <= :m_max";
        $params[':m_max'] = number_format($max, 2, '.', '');
    }

    // On ajoute les filtres à la requête
    if (!empty($where)) {
        $sql .= " AND " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY d.date_devis DESC, d.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Change le statut d'un devis (EN_COURS ou TERMINE)
function devisUpdateStatut(PDO $pdo, int $idDevis, string $statut): bool
{
    if (!in_array($statut, ['EN_COURS', 'TERMINE'], true)) {
        throw new InvalidArgumentException("Statut invalide (doit être EN_COURS ou TERMINE).");
    }
    $sql = "UPDATE devis SET statut = :statut WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([':statut' => $statut, ':id' => $idDevis]);
}

// Supprime un devis (et ses lignes grâce au ON DELETE CASCADE)
function devisDelete(PDO $pdo, int $idDevis): bool
{
    $sql = "DELETE FROM devis WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([':id' => $idDevis]);
}


// Supprime un document GED : le fichier physique puis la ligne en base
function gedDeleteDocument(PDO $pdo, array $doc): void
{
    try {
        $abs = gedResolveAbsolutePath((string)$doc['relative_path']);
        if (is_file($abs)) {
            unlink($abs);
        }
    } catch (RuntimeException $e) {
        // Fichier déjà absent : on supprime quand même l'entrée en base
    }
    $stmt = $pdo->prepare("DELETE FROM ged_documents WHERE id = :id");
    $stmt->execute([':id' => (int)$doc['id']]);
}

// Récupère le document GED associé à un devis (ou null)
function gedGetDocumentByDevis(PDO $pdo, int $devisId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM ged_documents WHERE devis_id = :id");
    $stmt->execute([':id' => $devisId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

// Traduit un code d'erreur d'upload PHP en message compréhensible
function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Fichier trop volumineux (limite du serveur : " . ini_get('upload_max_filesize') . ").",
        UPLOAD_ERR_PARTIAL => "Le fichier n'a été que partiellement envoyé.",
        UPLOAD_ERR_NO_FILE => "Aucun fichier sélectionné.",
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => "Erreur serveur lors de l'upload (code $code).",
        default => "Upload invalide (code $code).",
    };
}

// Redirige vers la page précédente (si elle est sur le même site) en y ajoutant
// un paramètre de succès. Les anciens paramètres de succès sont retirés pour
// éviter qu'ils s'accumulent dans l'URL (?deleted=1&renamed=1&...).
function redirectBack(string $fallback, array $flags = []): void
{
    $target = $fallback;
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $parts = parse_url($referer);
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $refHost = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($parts !== false && $refHost === $host && isset($parts['path'])) {
            $target = $parts['path'];
            parse_str($parts['query'] ?? '', $query);
            unset($query['ok'], $query['deleted'], $query['renamed'], $query['mailed']);
            $query = array_merge($query, $flags);
            $target .= $query ? '?' . http_build_query($query) : '';
            header('Location: ' . $target);
            exit;
        }
    }
    if ($flags) {
        $target .= (str_contains($target, '?') ? '&' : '?') . http_build_query($flags);
    }
    header('Location: ' . $target);
    exit;
}
