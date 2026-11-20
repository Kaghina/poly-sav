<?php
// Page de recherche de clients avec filtres (nom, pays, motif, date)

declare(strict_types=1);

require_once __DIR__ . '/ressources_communes.php';
require_once __DIR__ . '/partials/layout.php';

$pdo = getConnexion();
$pays = getPays($pdo);
$motifs = getMotifs($pdo);
$formes = getFormesJuridiques($pdo);


// Récupération des critères de recherche depuis l'URL
$nom = getGetString('nom', '');
$codeMotif = getGetString('motif', '');
$codePays = getGetString('pays', '');

// Date minimum (format YYYY-MM-DD pour les input type="date")
$dateMin = getGetString('date_min', '1950-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateMin)) {
    $dateMin = '1950-01-01';  // Valeur par défaut si format invalide
}

// Construction de la requête SQL selon les filtres actifs

$sql = "
    SELECT c.code, c.nom, c.prenom, c.date_entree, p.libelle AS pays, m.libelle AS motif
    FROM clients c
    INNER JOIN pays p ON p.code = c.code_pays
    INNER JOIN motifs m ON m.code = c.code_motif
";

$where = [];
$params = [];

if ($nom !== '') {
    // Recherche qui matche dans le nom OU le prénom
    $where[] = "(c.nom LIKE :nom OR c.prenom LIKE :prenom)";
    $like = '%' . $nom . '%';
    $params[':nom'] = $like;
    $params[':prenom'] = $like;
}

if ($codeMotif !== '') {
    // Filtre sur le motif (doit être un chiffre)
    if (ctype_digit($codeMotif)) {
        $where[] = "c.code_motif = :motif";
        $params[':motif'] = (int)$codeMotif;
    } else {
        $codeMotif = '';  // Invalide, on ignore
    }
}

if ($codePays !== '') {
    // Filtre sur le pays (2 lettres majuscules comme FR, BE...)
    if (preg_match('/^[A-Z]{2}$/', $codePays)) {
        $where[] = "c.code_pays = :pays";
        $params[':pays'] = $codePays;
    } else {
        $codePays = '';  // Invalide, on ignore
    }
}

$where[] = "c.date_entree >= :date_min";
$params[':date_min'] = $dateMin;

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY c.nom ASC, c.prenom ASC";

$resultats = [];
$erreur = '';
$exception = null;
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultats = $stmt->fetchAll();
} catch (Throwable $t) {
    $erreur = "Erreur lors de la recherche. Vérifiez la base de données et réessayez.";
    $exception = $t;
}
?>
<?php
$nbFiltres = (int)($nom !== '') + (int)($codePays !== '') + (int)($codeMotif !== '') + (int)($dateMin !== '1950-01-01');
appHeader(
    'Clients',
    'Recherchez un client, consultez sa fiche, ses devis et ses documents.',
    'clients',
    [],
    '',
    '<button type="button" class="btn btn-primary" data-modal-open="createClientModal">' . icon('user-plus', 18) . ' Nouveau client</button>'
);
?>

<?php if ($erreur !== ''): ?>
    <?php afficheErreur($erreur, $exception); ?>
<?php endif; ?>

<form method="get" action="recherche_client.php" class="card">
    <div class="card-body">
        <div class="filters">
            <div class="field">
                <label for="nom">Nom ou prénom</label>
                <div class="input-icon">
                    <?= icon('search', 16) ?>
                    <input type="text" id="nom" name="nom" value="<?= e($nom) ?>" placeholder="ex : Dupont, Jean…" autocomplete="off">
                </div>
            </div>
            <div class="field">
                <label for="pays">Pays</label>
                <select id="pays" name="pays">
                    <option value="">Tous les pays</option>
                    <?php foreach ($pays as $code => $libelle): ?>
                        <option value="<?= e($code) ?>" <?= $code === $codePays ? 'selected' : '' ?>><?= e($libelle) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="motif">Motif de contact</label>
                <select id="motif" name="motif">
                    <option value="">Tous les motifs</option>
                    <?php foreach ($motifs as $code => $libelle): ?>
                        <option value="<?= e((string)$code) ?>" <?= (string)$code === $codeMotif ? 'selected' : '' ?>><?= e($libelle) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="date_min">Client depuis le</label>
                <input type="date" id="date_min" name="date_min" value="<?= e($dateMin) ?>">
            </div>
        </div>
        <div class="filters-actions">
            <button class="btn btn-primary" type="submit"><?= icon('search', 16) ?> Rechercher</button>
            <?php if ($nbFiltres > 0): ?>
                <a class="btn btn-ghost" href="recherche_client.php"><?= icon('x', 16) ?> Effacer les filtres</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<section class="section">
    <div class="section-head">
        <h2 class="section-title"><?= icon('users', 20) ?> Résultats <span class="count"><?= count($resultats) ?></span></h2>
    </div>

    <div class="card">
        <?php if (count($resultats) === 0): ?>
            <div class="empty">
                <div class="empty-icon"><?= icon('search', 28) ?></div>
                <div class="empty-title">Aucun client trouvé</div>
                <p class="empty-text">Modifiez vos critères de recherche ou créez un nouveau client.</p>
                <button type="button" class="btn btn-primary" data-modal-open="createClientModal"><?= icon('user-plus', 16) ?> Nouveau client</button>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Client</th>
                        <th>Pays</th>
                        <th class="hide-sm">Motif</th>
                        <th class="hide-sm">Client depuis</th>
                        <th class="r">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($resultats as $row): ?>
                        <?php $code = (string)$row['code']; ?>
                        <tr>
                            <td>
                                <div class="cell-main">
                                    <span class="avatar"><?= e(initiales((string)$row['nom'], (string)$row['prenom'])) ?></span>
                                    <div>
                                        <a class="cell-title" href="fiche_client.php?id=<?= e($code) ?>"><?= e(nomClient($row)) ?></a>
                                        <div class="cell-sub">Client n° <?= e($code) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= e((string)$row['pays']) ?></td>
                            <td class="hide-sm"><span class="badge badge-neutral no-dot"><?= e((string)$row['motif']) ?></span></td>
                            <td class="hide-sm muted"><?= e(fmtDateFr((string)$row['date_entree'])) ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-sm" href="fiche_client.php?id=<?= e($code) ?>"><?= icon('user', 15) ?> Fiche</a>
                                    <a class="btn btn-sm btn-icon" href="documents_client.php?id=<?= e($code) ?>" title="Documents du client"><?= icon('folder', 15) ?></a>
                                    <a class="btn btn-sm btn-icon" href="devis_client.php?id_client=<?= e($code) ?>" title="Nouveau devis"><?= icon('file-plus', 15) ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Fenêtre : création d'un client -->
<div class="modal-overlay" id="createClientModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="createClientTitle">
        <div class="modal-header">
            <h3 id="createClientTitle"><?= icon('user-plus', 20) ?> Nouveau client</h3>
            <button type="button" class="modal-close" data-modal-close title="Fermer"><?= icon('x', 18) ?></button>
        </div>
        <form method="post" action="client_action.php?action=create">
            <div class="modal-body">
                <div class="callout" style="margin-bottom:18px;"><?= icon('info', 18) ?><span>Le numéro client est attribué automatiquement et la date d'entrée est celle d'aujourd'hui.</span></div>
                <div class="form-grid">
                    <div class="field">
                        <label for="new_nom">Nom *</label>
                        <input type="text" id="new_nom" name="nom" required maxlength="50" placeholder="ex : Dupont" autofocus>
                    </div>
                    <div class="field">
                        <label for="prenom">Prénom *</label>
                        <input type="text" id="prenom" name="prenom" required maxlength="50" placeholder="ex : Jean">
                    </div>
                    <div class="field">
                        <label for="date_naissance">Date de naissance *</label>
                        <input type="date" id="date_naissance" name="date_naissance" required max="<?= e(date('Y-m-d')) ?>">
                    </div>
                    <div class="field">
                        <label for="code_pays">Pays *</label>
                        <select id="code_pays" name="code_pays" required>
                            <option value="">Choisir…</option>
                            <?php foreach ($pays as $code => $libelle): ?>
                                <option value="<?= e($code) ?>"><?= e($libelle) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field full">
                        <label for="forme">Forme juridique *</label>
                        <select id="forme" name="forme" required>
                            <option value="">Choisir…</option>
                            <?php foreach ($formes as $code => $libelle): ?>
                                <option value="<?= e((string)$code) ?>"><?= e($libelle) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="code_motif">Motif de contact *</label>
                        <select id="code_motif" name="code_motif" required>
                            <option value="">Choisir…</option>
                            <?php foreach ($motifs as $code => $libelle): ?>
                                <option value="<?= e((string)$code) ?>"><?= e($libelle) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="numero_secu_social">N° de sécurité sociale</label>
                        <input type="text" id="numero_secu_social" name="numero_secu_social" maxlength="20" placeholder="Optionnel" inputmode="numeric">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><?= icon('check', 16) ?> Créer le client</button>
            </div>
        </form>
    </div>
</div>

<?php appFooter(); ?>
