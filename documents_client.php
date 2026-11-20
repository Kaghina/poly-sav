<?php
// Page GED : liste des documents d'un client + upload de nouveaux docs

declare(strict_types=1);

require_once __DIR__ . '/ressources_communes.php';
require_once __DIR__ . '/partials/layout.php';
require_once __DIR__ . '/partials/doc_table.php';

$pdo = getConnexion();

$idClient = getGetInt('id', 0);
$client = ($idClient > 0) ? getClientComplet($pdo, $idClient) : null;
$erreur = '';
$exception = null;

if ($idClient <= 0) {
    $erreur = "Identifiant client invalide.";
} elseif ($client === null) {
    $erreur = "Client introuvable.";
}

$okMsg = '';
if (getGetString('ok', '') === '1') {
    $okMsg = "Document enregistré avec succès et indexé dans la GED.";
}
if (getGetString('deleted', '') === '1') {
    $okMsg = "Document supprimé avec succès.";
}
if (getGetString('renamed', '') === '1') {
    $okMsg = "Document renommé avec succès.";
}
if (getGetString('mailed', '') === '1') {
    $okMsg = "Email envoyé avec succès.";
}

if ($client !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $docType = trim((string)($_POST['doc_type'] ?? 'AUTRE'));
    $docNature = trim((string)($_POST['doc_nature'] ?? 'FICHIER'));
    $docContenu = trim((string)($_POST['doc_contenu'] ?? ''));
    $file = $_FILES['fichier'] ?? null;

    try {
        if (!is_array($file)) {
            throw new RuntimeException("Aucun fichier reçu.");
        }
        gedSaveUploadedDocument($pdo, $idClient, $file, $docType, $docNature, $docContenu);
        header('Location: documents_client.php?id=' . $idClient . '&ok=1');
        exit;
    } catch (Throwable $t) {
        $erreur = "Impossible d'enregistrer le document.";
        $exception = $t;
    }
}

$docs = [];
if ($client !== null) {
    try {
        $docs = gedGetDocumentsByClient($pdo, $idClient);
    } catch (Throwable $t) {
        $docs = [];
        $erreur = "Impossible de charger les documents.";
        $exception = $t;
    }
}

// Calcul des stats : taille totale et nombre par type
$totalSize = 0;
$docsByType = [];
foreach ($docs as $d) {
    $totalSize += (int)$d['size_bytes'];
    $type = (string)$d['doc_type'];
    $docsByType[$type] = ($docsByType[$type] ?? 0) + 1;
}
?>
<?php
$nomAff = $client !== null ? nomClient($client) : 'Client';
appHeader(
    'Documents du client',
    'GED : ajoutez, consultez et envoyez les documents de ' . $nomAff . '.',
    'docs',
    [
        ['label' => 'Clients', 'href' => 'recherche_client.php'],
        ['label' => $nomAff, 'href' => 'fiche_client.php?id=' . (string)$idClient],
        ['label' => 'Documents', 'href' => '#'],
    ],
    '',
    $client !== null ? '<a class="btn" href="fiche_client.php?id=' . e((string)$idClient) . '">' . icon('user', 16) . ' Fiche client</a>' : ''
);
?>

<?php if ($okMsg !== ''): ?>
    <?php afficheSucces($okMsg); ?>
<?php endif; ?>
<?php if ($erreur !== ''): ?>
    <?php afficheErreur($erreur, $exception); ?>
<?php endif; ?>

<?php if ($client === null): ?>
    <a class="btn" href="recherche_client.php"><?= icon('arrow-left', 16) ?> Retour à la liste des clients</a>
<?php else: ?>
    <?php
    arsort($docsByType);
    $topType = array_key_first($docsByType);
    ?>
    <div class="stats">
        <div class="stat"><span class="stat-icon"><?= icon('folder', 20) ?></span><div><div class="stat-label">Documents</div><div class="stat-value"><?= count($docs) ?></div></div></div>
        <div class="stat"><span class="stat-icon"><?= icon('hard-drive', 20) ?></span><div><div class="stat-label">Espace utilisé</div><div class="stat-value"><?= e(fmtTaille($totalSize)) ?></div></div></div>
        <div class="stat"><span class="stat-icon"><?= icon('tag', 20) ?></span><div><div class="stat-label">Type principal</div><div class="stat-value"><?= e($topType !== null ? (string)$topType : '—') ?></div></div></div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2 class="card-title"><?= icon('upload', 18) ?> Ajouter un document</h2>
            <span class="small muted">PDF, PNG, JPEG, GIF ou texte · <?= e(number_format(STORAGE_MAX_BYTES / 1000000, 0, ',', ' ')) ?> Mo max · doublons détectés automatiquement</span>
        </div>
        <form method="post" enctype="multipart/form-data" action="documents_client.php?id=<?= e((string)$idClient) ?>" class="card-body">
            <div class="grid-2" style="align-items:stretch;">
                <label class="dropzone" id="dropzone" for="fichier">
                    <input type="file" id="fichier" name="fichier" required accept=".pdf,.png,.jpg,.jpeg,.gif,.txt,application/pdf,image/png,image/jpeg,image/gif,text/plain">
                    <?= icon('upload', 30) ?>
                    <div class="dropzone-title" id="dropzone-title">Glissez un fichier ici ou cliquez pour parcourir</div>
                    <div class="dropzone-hint">Il pourra ensuite être envoyé par mail au format PDF.</div>
                </label>
                <div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="doc_type">Type de document</label>
                            <select id="doc_type" name="doc_type">
                                <option value="CNI">Pièce d'identité (CNI)</option>
                                <option value="EMAIL">Email</option>
                                <option value="DEVIS">Devis</option>
                                <option value="AUTRE" selected>Autre</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="doc_nature">Nature</label>
                            <select id="doc_nature" name="doc_nature">
                                <option value="PDF">PDF</option>
                                <option value="IMAGE">Image</option>
                                <option value="TEXTE">Texte</option>
                                <option value="FICHIER" selected>Fichier</option>
                            </select>
                        </div>
                        <div class="field full">
                            <label for="doc_contenu">Description / mots-clés</label>
                            <input type="text" id="doc_contenu" name="doc_contenu" maxlength="120" placeholder="ex : copie recto-verso, facture 2025…">
                        </div>
                    </div>
                    <div class="filters-actions">
                        <button class="btn btn-primary" type="submit"><?= icon('upload', 16) ?> Enregistrer le document</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <section class="section">
        <div class="section-head">
            <h2 class="section-title"><?= icon('folder', 20) ?> Documents <span class="count"><?= count($docs) ?></span></h2>
        </div>
        <div class="card">
            <?php if (count($docs) === 0): ?>
                <div class="empty">
                    <div class="empty-icon"><?= icon('folder', 28) ?></div>
                    <div class="empty-title">Aucun document pour ce client</div>
                    <p class="empty-text">Ajoutez une pièce d'identité, un email ou tout autre justificatif avec le formulaire ci-dessus. Les devis validés y sont archivés automatiquement.</p>
                </div>
            <?php else: ?>
                <?php renderDocTable($docs, false); ?>
            <?php endif; ?>
        </div>
    </section>

    <script>
    // Zone de dépôt : glisser-déposer + affichage du fichier choisi
    (function () {
        var zone = document.getElementById('dropzone');
        var input = document.getElementById('fichier');
        var title = document.getElementById('dropzone-title');
        if (!zone || !input) return;
        function show() {
            if (input.files.length > 0) {
                zone.classList.add('has-file');
                title.textContent = input.files[0].name; // textContent : jamais interprété comme du HTML
                var nature = document.getElementById('doc_nature');
                var t = input.files[0].type;
                if (t === 'application/pdf') nature.value = 'PDF';
                else if (t.indexOf('image/') === 0) nature.value = 'IMAGE';
                else if (t.indexOf('text/') === 0) nature.value = 'TEXTE';
            }
        }
        input.addEventListener('change', show);
        ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('drag-over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('drag-over'); }); });
        zone.addEventListener('drop', function (e) {
            if (e.dataTransfer.files.length > 0) { input.files = e.dataTransfer.files; show(); }
        });
    })();
    </script>
<?php endif; ?>

<?php appFooter(); ?>
