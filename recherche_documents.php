<?php
// Page de recherche de documents dans la GED avec filtres avancés

declare(strict_types=1);

require_once __DIR__ . '/ressources_communes.php';
require_once __DIR__ . '/partials/layout.php';
require_once __DIR__ . '/partials/doc_table.php';

$pdo = getConnexion();

$clientCode = getGetInt('client', 0);
$docType = trim(getGetString('type', ''));
$docNature = trim(getGetString('nature', ''));
$contenu = trim(getGetString('contenu', ''));
$nomFichier = trim(getGetString('fichier', ''));
$dateMin = getGetString('date_min', '');
$dateMax = getGetString('date_max', '');

// Récupération de la liste des clients pour le filtre
$clients = [];
try {
    $stmt = $pdo->query("SELECT code, nom, prenom FROM clients ORDER BY nom ASC, prenom ASC");
    $clients = $stmt->fetchAll();
} catch (Throwable $t) {
    $clients = [];  // Si erreur, on laisse vide
}

$sql = "
    SELECT d.*, c.nom AS client_nom, c.prenom AS client_prenom,
           dv.statut AS devis_statut, dv.reference AS devis_reference
    FROM ged_documents d
    INNER JOIN clients c ON c.code = d.client_code
    LEFT JOIN devis dv ON dv.id = d.devis_id
";
$where = [];
$params = [];

if ($clientCode > 0) {
    $where[] = "d.client_code = :client";
    $params[':client'] = $clientCode;
}
if ($docType !== '') {
    $where[] = "d.doc_type = :type";
    $params[':type'] = $docType;
}
if ($docNature !== '') {
    $where[] = "d.doc_nature = :nature";
    $params[':nature'] = $docNature;
}
if ($contenu !== '') {
    $where[] = "d.doc_contenu LIKE :contenu";
    $params[':contenu'] = '%' . $contenu . '%';
}
if ($nomFichier !== '') {
    $where[] = "d.original_name LIKE :fichier";
    $params[':fichier'] = '%' . $nomFichier . '%';
}
if ($dateMin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateMin)) {
    $where[] = "DATE(d.created_at) >= :date_min";
    $params[':date_min'] = $dateMin;
}
if ($dateMax !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateMax)) {
    $where[] = "DATE(d.created_at) <= :date_max";
    $params[':date_max'] = $dateMax;
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY d.created_at DESC, d.id DESC";

$docs = [];
$erreur = '';
$exception = null;
$okMsg = '';
if (getGetString('deleted', '') === '1') {
    $okMsg = "Document supprimé avec succès.";
}
if (getGetString('renamed', '') === '1') {
    $okMsg = "Document renommé avec succès.";
}
if (getGetString('mailed', '') === '1') {
    $okMsg = "Email envoyé avec succès.";
}
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $docs = $stmt->fetchAll();
} catch (Throwable $t) {
    $erreur = "Erreur lors de la recherche de documents.";
    $exception = $t;
    $docs = [];
}
?>
<?php
appHeader(
    'Documents',
    'Recherchez dans tous les documents de la GED : devis archivés, pièces d’identité, emails…',
    'docs'
);
$filtresActifs = ($clientCode > 0 || $docType !== '' || $docNature !== '' || $contenu !== '' || $nomFichier !== '' || $dateMin !== '' || $dateMax !== '');
?>

<?php if ($okMsg !== ''): ?>
    <?php afficheSucces($okMsg); ?>
<?php endif; ?>
<?php if ($erreur !== ''): ?>
    <?php afficheErreur($erreur, $exception); ?>
<?php endif; ?>

<form method="get" action="recherche_documents.php" class="card">
    <div class="card-body">
        <div class="filters">
            <div class="field">
                <label for="client">Client</label>
                <select id="client" name="client">
                    <option value="">Tous les clients</option>
                    <?php foreach ($clients as $c): ?>
                        <?php $code = (int)$c['code']; ?>
                        <option value="<?= e((string)$code) ?>" <?= $code === $clientCode ? 'selected' : '' ?>><?= e(nomClient($c)) ?> (n° <?= e((string)$code) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="">Tous les types</option>
                    <?php foreach (['CNI' => 'Pièce d\'identité (CNI)', 'EMAIL' => 'Email', 'DEVIS' => 'Devis', 'AUTRE' => 'Autre'] as $v => $lib): ?>
                        <option value="<?= e($v) ?>" <?= $docType === $v ? 'selected' : '' ?>><?= e($lib) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="nature">Nature</label>
                <select id="nature" name="nature">
                    <option value="">Toutes les natures</option>
                    <?php foreach (['PDF' => 'PDF', 'IMAGE' => 'Image', 'TEXTE' => 'Texte', 'HTML' => 'HTML', 'FICHIER' => 'Fichier'] as $v => $lib): ?>
                        <option value="<?= e($v) ?>" <?= $docNature === $v ? 'selected' : '' ?>><?= e($lib) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="fichier">Nom du fichier</label>
                <div class="input-icon">
                    <?= icon('search', 16) ?>
                    <input type="text" id="fichier" name="fichier" value="<?= e($nomFichier) ?>" placeholder="ex : devis, cni…" autocomplete="off">
                </div>
            </div>
            <div class="field">
                <label for="contenu">Description / mots-clés</label>
                <input type="text" id="contenu" name="contenu" value="<?= e($contenu) ?>" placeholder="ex : recto, facture…" autocomplete="off">
            </div>
            <div class="field">
                <label for="date_min">Ajouté à partir du</label>
                <input type="date" id="date_min" name="date_min" value="<?= e($dateMin) ?>">
            </div>
            <div class="field">
                <label for="date_max">Jusqu'au</label>
                <input type="date" id="date_max" name="date_max" value="<?= e($dateMax) ?>">
            </div>
        </div>
        <div class="filters-actions">
            <button class="btn btn-primary" type="submit"><?= icon('search', 16) ?> Rechercher</button>
            <?php if ($filtresActifs): ?>
                <a class="btn btn-ghost" href="recherche_documents.php"><?= icon('x', 16) ?> Effacer les filtres</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<section class="section">
    <div class="section-head">
        <h2 class="section-title"><?= icon('folder', 20) ?> Résultats <span class="count"><?= count($docs) ?></span></h2>
    </div>
    <div class="card">
        <?php if (count($docs) === 0): ?>
            <div class="empty">
                <div class="empty-icon"><?= icon('search', 28) ?></div>
                <div class="empty-title">Aucun document trouvé</div>
                <p class="empty-text">Modifiez les filtres, ou ajoutez des documents depuis la page « Documents » d’un client.</p>
            </div>
        <?php else: ?>
            <?php renderDocTable($docs, true); ?>
        <?php endif; ?>
    </div>
</section>

<?php appFooter(); ?>
