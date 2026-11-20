<?php
// Page d'affichage d'un client : ses infos + historique de ses devis

declare(strict_types=1);

require_once __DIR__ . '/ressources_communes.php';
require_once __DIR__ . '/partials/layout.php';

$pdo = getConnexion();

$idClient = getGetInt('id', 0);
$okMsg = '';
if (getGetString('created', '') === '1') {
    $okMsg = "Client créé avec succès ! Vous pouvez maintenant créer des devis et ajouter des documents.";
}

if ($idClient <= 0) {
    http_response_code(400);
    $client = null;
    $erreur = "Identifiant client invalide.";
    $historiqueDevis = [];
} else {
    $client = getClientComplet($pdo, $idClient);
    $erreur = ($client === null) ? "Client introuvable." : '';
    $historiqueDevis = [];
    if ($client !== null) {
        try {
            // Récupération des filtres de recherche de devis
            $dDateMin = getGetString('devis_date_min', '');
            $dDateMax = getGetString('devis_date_max', '');
            $dMontantMin = getGetString('devis_montant_min', '');
            $dMontantMax = getGetString('devis_montant_max', '');

            $hasFiltres = ($dDateMin !== '' || $dDateMax !== '' || $dMontantMin !== '' || $dMontantMax !== '');
            $historiqueDevis = $hasFiltres
                ? searchDevis($pdo, $idClient, $dDateMin, $dDateMax, $dMontantMin, $dMontantMax)
                : getDevisParClient($pdo, $idClient);
        } catch (Throwable $t) {
            $historiqueDevis = [];
            $erreur = "Impossible de charger l'historique des devis.";
        }
    }
}
?>
<?php
$nomAffiche = $client !== null ? nomClient($client) : 'Client';
appHeader(
    'Fiche client',
    'Informations du client et historique de ses devis.',
    'clients',
    [
        ['label' => 'Clients', 'href' => 'recherche_client.php'],
        ['label' => $nomAffiche, 'href' => 'fiche_client.php?id=' . (string)$idClient],
    ]
);

// Statistiques des devis affichés
$totalHT = 0.0;
$totalTTC = 0.0;
$nbEnCours = 0;
foreach ($historiqueDevis as $d) {
    $totalHT += (float)$d['total_ht'];
    $totalTTC += (float)$d['total_ttc'];
    if ((string)$d['statut'] !== 'TERMINE') {
        $nbEnCours++;
    }
}
$fDateMin = getGetString('devis_date_min', '');
$fDateMax = getGetString('devis_date_max', '');
$fMontantMin = getGetString('devis_montant_min', '');
$fMontantMax = getGetString('devis_montant_max', '');
$filtresActifs = ($fDateMin . $fDateMax . $fMontantMin . $fMontantMax) !== '';
?>

<?php if ($okMsg !== ''): ?>
    <?php afficheSucces($okMsg); ?>
<?php endif; ?>

<?php if ($erreur !== ''): ?>
    <?php afficheErreur($erreur); ?>
<?php endif; ?>

<?php if ($client === null): ?>
    <a class="btn" href="recherche_client.php"><?= icon('arrow-left', 16) ?> Retour à la liste des clients</a>
<?php else: ?>
    <?php $code = (string)$client['code']; ?>
    <div class="hero">
        <div class="hero-top">
            <div class="hero-id">
                <span class="hero-avatar"><?= e(initiales((string)$client['nom'], (string)$client['prenom'])) ?></span>
                <div>
                    <div class="hero-name"><?= e($nomAffiche) ?></div>
                    <div class="hero-sub">
                        <button type="button" class="chip" onclick="copyText('<?= e($code) ?>', this)" title="Copier le numéro client"><?= icon('hash', 13) ?> Client n° <?= e($code) ?></button>
                        <span><?= e((string)$client['forme_libelle']) ?></span>
                    </div>
                </div>
            </div>
            <div class="btn-row">
                <a class="btn" href="documents_client.php?id=<?= e($code) ?>"><?= icon('folder', 16) ?> Documents</a>
                <a class="btn btn-primary" href="devis_client.php?id_client=<?= e($code) ?>"><?= icon('file-plus', 16) ?> Nouveau devis</a>
            </div>
        </div>
        <div class="kv">
            <div class="kv-item">
                <div class="kv-label"><?= icon('calendar', 14) ?> Date de naissance</div>
                <div class="kv-value"><?= e(fmtDateFr((string)$client['date_naissance'])) ?></div>
            </div>
            <div class="kv-item">
                <div class="kv-label"><?= icon('globe', 14) ?> Pays</div>
                <div class="kv-value"><?= e((string)$client['pays_libelle']) ?> <span class="muted">(<?= e((string)$client['code_pays']) ?>)</span></div>
            </div>
            <div class="kv-item">
                <div class="kv-label"><?= icon('tag', 14) ?> Motif de contact</div>
                <div class="kv-value"><?= e((string)$client['motif_libelle']) ?></div>
            </div>
            <div class="kv-item">
                <div class="kv-label"><?= icon('clock', 14) ?> Client depuis</div>
                <div class="kv-value"><?= e(fmtDateFr((string)$client['date_entree'])) ?></div>
            </div>
            <div class="kv-item">
                <div class="kv-label"><?= icon('shield', 14) ?> N° de sécurité sociale</div>
                <div class="kv-value mono"><?= $client['numero_secu_social'] !== null ? e((string)$client['numero_secu_social']) : '<span class="muted">Non renseigné</span>' ?></div>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="section-head">
            <h2 class="section-title"><?= icon('file-text', 20) ?> Devis <span class="count"><?= count($historiqueDevis) ?></span></h2>
            <a class="btn btn-sm btn-ghost" href="recherche_documents.php?client=<?= e($code) ?>&amp;type=DEVIS"><?= icon('folder', 15) ?> Devis archivés (GED)</a>
        </div>

        <?php if (count($historiqueDevis) > 0): ?>
            <div class="stats">
                <div class="stat"><span class="stat-icon"><?= icon('file-text', 20) ?></span><div><div class="stat-label">Devis</div><div class="stat-value"><?= count($historiqueDevis) ?></div></div></div>
                <div class="stat"><span class="stat-icon"><?= icon('clock', 20) ?></span><div><div class="stat-label">En cours</div><div class="stat-value"><?= $nbEnCours ?></div></div></div>
                <div class="stat"><span class="stat-icon"><?= icon('euro', 20) ?></span><div><div class="stat-label">Total HT</div><div class="stat-value"><?= e(fmtEuro($totalHT)) ?></div></div></div>
                <div class="stat"><span class="stat-icon"><?= icon('euro', 20) ?></span><div><div class="stat-label">Total TTC</div><div class="stat-value"><?= e(fmtEuro($totalTTC)) ?></div></div></div>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="get" action="fiche_client.php" class="card-body" style="border-bottom:1px solid var(--border);">
                <input type="hidden" name="id" value="<?= e($code) ?>">
                <div class="filters">
                    <div class="field">
                        <label for="devis_date_min">Du</label>
                        <input type="date" id="devis_date_min" name="devis_date_min" value="<?= e($fDateMin) ?>">
                    </div>
                    <div class="field">
                        <label for="devis_date_max">Au</label>
                        <input type="date" id="devis_date_max" name="devis_date_max" value="<?= e($fDateMax) ?>">
                    </div>
                    <div class="field">
                        <label for="devis_montant_min">Montant HT min (€)</label>
                        <input type="text" id="devis_montant_min" name="devis_montant_min" value="<?= e($fMontantMin) ?>" placeholder="ex : 100" inputmode="decimal">
                    </div>
                    <div class="field">
                        <label for="devis_montant_max">Montant HT max (€)</label>
                        <input type="text" id="devis_montant_max" name="devis_montant_max" value="<?= e($fMontantMax) ?>" placeholder="ex : 500" inputmode="decimal">
                    </div>
                </div>
                <div class="filters-actions">
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('search', 15) ?> Filtrer</button>
                    <?php if ($filtresActifs): ?>
                        <a class="btn btn-ghost btn-sm" href="fiche_client.php?id=<?= e($code) ?>"><?= icon('x', 15) ?> Effacer</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (count($historiqueDevis) === 0): ?>
                <div class="empty">
                    <div class="empty-icon"><?= icon('file-text', 28) ?></div>
                    <div class="empty-title"><?= $filtresActifs ? 'Aucun devis ne correspond aux filtres' : 'Aucun devis pour ce client' ?></div>
                    <p class="empty-text">Créez un devis en quelques clics : choisissez les prestations, le total est calculé automatiquement.</p>
                    <a class="btn btn-primary" href="devis_client.php?id_client=<?= e($code) ?>"><?= icon('file-plus', 16) ?> Créer un devis</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Référence</th>
                            <th>Date</th>
                            <th>Statut</th>
                            <th class="r hide-sm">Total HT</th>
                            <th class="r hide-sm">TVA</th>
                            <th class="r">Total TTC</th>
                            <th class="r">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($historiqueDevis as $d): ?>
                            <?php $idD = (string)$d['id']; ?>
                            <tr>
                                <td><a class="cell-title" href="devis_client.php?id_devis=<?= e($idD) ?>"><?= e((string)$d['reference']) ?></a></td>
                                <td class="muted nowrap"><?= e(fmtDateTimeFr((string)$d['date_devis'])) ?></td>
                                <td>
                                    <?php if ((string)$d['statut'] === 'TERMINE'): ?>
                                        <span class="badge badge-success">Terminé</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">En cours</span>
                                    <?php endif; ?>
                                </td>
                                <td class="r num hide-sm"><?= e(fmtEuro((float)$d['total_ht'])) ?></td>
                                <td class="r num muted hide-sm"><?= e(fmtEuro((float)$d['total_tva'])) ?></td>
                                <td class="r amount"><?= e(fmtEuro((float)$d['total_ttc'])) ?></td>
                                <td>
                                    <div class="actions">
                                        <a class="btn btn-sm" href="devis_client.php?id_devis=<?= e($idD) ?>"><?= icon('eye', 15) ?> Voir</a>
                                        <a class="btn btn-sm btn-icon" href="devis_client.php?id_devis=<?= e($idD) ?>&amp;action=pdf" title="Télécharger le PDF"><?= icon('download', 15) ?></a>
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
<?php endif; ?>

<?php appFooter(); ?>
