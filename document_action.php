<?php
// Actions sur les documents : ouvrir, télécharger, envoyer par email

declare(strict_types=1);

require_once __DIR__ . '/ressources_communes.php';
require_once __DIR__ . '/partials/layout.php';

$pdo = getConnexion();

$action = strtolower(getGetString('action', ''));
$idDoc = getGetInt('id', 0);

if ($idDoc <= 0) {
    http_response_code(400);
    appHeader('Action document', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
    afficheErreur("Identifiant document invalide.");
    appFooter();
    exit;
}

$doc = gedGetDocument($pdo, $idDoc);
if ($doc === null) {
    http_response_code(404);
    appHeader('Action document', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
    afficheErreur("Document introuvable.");
    appFooter();
    exit;
}

try {
    $abs = in_array($action, ['open', 'download', 'pdf', 'mail'], true)
        ? gedResolveAbsolutePath((string)$doc['relative_path'])
        : '';
} catch (Throwable $t) {
    http_response_code(404);
    appHeader('Action document', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
    afficheErreur("Fichier introuvable.", $t);
    appFooter();
    exit;
}

$downloadName = (string)$doc['original_name'];
// Page de retour par défaut : la GED du client
$retour = 'documents_client.php?id=' . (int)$doc['client_code'];
$mime = (string)$doc['mime'];

if ($action === 'open') {
    streamFile($abs, $mime, $downloadName, false);
}

if ($action === 'download') {
    streamFile($abs, $mime, $downloadName, true);
}

// Conversion du document en PDF (utilisée pour le téléchargement PDF et l'envoi par mail)
$pdfName = pdfFileName($downloadName);
$getPdf = function () use ($doc, $abs, $retour): string {
    try {
        $pdf = gedDocumentToPdf($doc, $abs);
    } catch (Throwable $t) {
        $pdf = null;
    }
    if ($pdf === null) {
        http_response_code(415);
        appHeader('Conversion PDF', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
        afficheErreur("Ce document ne peut pas être converti en PDF (formats acceptés : PDF, image PNG/JPEG/GIF, texte, HTML).", $t ?? null);
        echo '<div class="btn-row"><a class="btn" href="' . e($retour) . '">Retour</a></div>';
        appFooter();
        exit;
    }
    return $pdf;
};

if ($action === 'pdf') {
    streamContent($getPdf(), 'application/pdf', $pdfName, true);
}

if ($action === 'mail') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        appHeader('Envoyer par mail', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
        afficheErreur("Méthode non autorisée.");
        appFooter();
        exit;
    }
    $to = trim((string)($_POST['to'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    if ($subject === '') {
        $subject = 'Document client #' . (string)$doc['client_code'] . ' — ' . (string)$doc['doc_type'];
    }
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') {
        $body = "Bonjour,\n\nVeuillez trouver en pièce jointe le document demandé.\n\nCordialement,\nPOLY SAV";
    }

    // Le document est toujours envoyé au format PDF
    $pdf = $getPdf();

    try {
        $ok = sendMailWithAttachment(
            $to,
            $subject,
            $body,
            $pdf,
            $pdfName,
            'application/pdf'
        );
    } catch (Throwable $t) {
        http_response_code(400);
        appHeader('Envoyer par mail', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
        afficheErreur("Impossible d'envoyer le mail.", $t);
        echo '<div class="btn-row"><a class="btn" href="' . e($retour) . '">Retour</a></div>';
        appFooter();
        exit;
    }

    if (!$ok) {
        http_response_code(500);
        appHeader('Envoyer par mail', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
        afficheErreur("Échec d'envoi du mail. Vérifiez la configuration SMTP.");
        echo '<div class="btn-row"><a class="btn" href="' . e($retour) . '">Retour</a></div>';
        appFooter();
        exit;
    }

    // Retour à la page précédente avec message de succès
    redirectBack($retour, ['mailed' => '1']);
}

if ($action === 'rename') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        appHeader('Renommer le document', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
        afficheErreur("Méthode non autorisée.");
        appFooter();
        exit;
    }
    
    $newName = trim((string)($_POST['new_name'] ?? ''));
    if ($newName === '') {
        http_response_code(400);
        appHeader('Renommer le document', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
        afficheErreur("Le nom du fichier ne peut pas être vide.");
        echo '<div class="btn-row"><a class="btn" href="' . e($retour) . '">Retour</a></div>';
        appFooter();
        exit;
    }
    
    $newName = sanitizeFilename($newName);
    
    try {
        $sql = "UPDATE ged_documents SET original_name = :new_name WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':new_name' => $newName, ':id' => $idDoc]);
        
        // Retour avec message de succès
        redirectBack($retour, ['renamed' => '1']);
    } catch (Throwable $t) {
        http_response_code(500);
        appHeader('Renommer le document', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
        afficheErreur("Impossible de renommer le document.", $t);
        echo '<div class="btn-row"><a class="btn" href="' . e($retour) . '">Retour</a></div>';
        appFooter();
        exit;
    }
}

if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        appHeader('Supprimer le document', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
        afficheErreur("Méthode non autorisée.");
        appFooter();
        exit;
    }
    
    try {
        // Suppression du fichier physique puis de l'enregistrement en base
        gedDeleteDocument($pdo, $doc);

        // Retour avec message de succès
        redirectBack($retour, ['deleted' => '1']);
    } catch (Throwable $t) {
        http_response_code(500);
        appHeader('Supprimer le document', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
        afficheErreur("Impossible de supprimer le document.", $t);
        echo '<div class="btn-row"><a class="btn" href="' . e($retour) . '">Retour</a></div>';
        appFooter();
        exit;
    }
}

http_response_code(400);
appHeader('Action document', 'Erreur', 'docs', [['label' => 'Documents', 'href' => 'recherche_documents.php']]);
afficheErreur("Action inconnue.");
appFooter();

