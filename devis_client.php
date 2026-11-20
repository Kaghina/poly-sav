<?php
// Page devis : création/modification (id_client) ou affichage/impression (id_devis)

declare(strict_types=1);

require_once __DIR__ . '/ressources_communes.php';
require_once __DIR__ . '/partials/layout.php';

$pdo = getConnexion();

// Infos de la société qui apparaissent sur le devis
const SOCIETE_NOM = 'POLY SAV';
const SOCIETE_ADRESSE = "1 rue de la MIAGE\n75000 Paris";
const SOCIETE_TEL = '01 02 03 04 05';
const SOCIETE_EMAIL = 'contact@poly-sav.local';

$action = getGetString('action', '');
$idClient = getGetInt('id_client', 0);
$idDevis = getGetInt('id_devis', 0);
$modifier = getGetString('modifier', '');

// Initialisation des variables
$erreur = '';
$warn = getGetString('warn', '');
$warningMsg = '';
$warningException = null;
if ($warn === 'ged') {
    $warningMsg = "Le devis est enregistré en base, mais la sauvegarde physique (GED) a échoué. Réessayez ou vérifiez les droits du dossier _storage/.";
}
$devisValide = false;
$modeEdition = false;
$statutDevis = 'EN_COURS';
$lignesDevis = [];
$totalHT = 0.0;
$tvaParTaux = [];
$totalTVA = 0.0;
$totalTTC = 0.0;
$dateDevis = date('Y-m-d');
$referenceDevis = '';
$lignesSaisies = [];  // Lignes saisies par l'utilisateur (réaffichées en cas d'erreur)
$erreurException = null;
$okMsg = '';
if (getGetString('mailed', '') === '1') {
    $okMsg = "Le devis a été envoyé par mail au format PDF.";
}

// Téléchargement du devis au format PDF
if ($idDevis > 0 && $action === 'pdf') {
    try {
        [$pdf, $entetePdf] = genererPdfDevis($pdo, $idDevis);
        streamContent($pdf, 'application/pdf', 'devis-' . (string)$entetePdf['reference'] . '.pdf', true);
    } catch (Throwable $t) {
        $erreur = "Impossible de générer le PDF du devis.";
        $erreurException = $t;
    }
}

// Traitement des actions sur un devis existant (terminer, supprimer, envoyer par mail)
if ($idDevis > 0 && $action !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'terminer') {
        try {
            devisUpdateStatut($pdo, $idDevis, 'TERMINE');
            // Le statut apparaît sur le PDF : on met à jour la copie archivée dans la GED
            $warnGed = '';
            try {
                archiverDevisDansGed($pdo, $idDevis);
            } catch (Throwable $t) {
                $warnGed = '&warn=ged';
            }
            header('Location: devis_client.php?id_devis=' . $idDevis . $warnGed);
            exit;
        } catch (Throwable $t) {
            $erreur = "Impossible de terminer le devis.";
            $erreurException = $t;
        }
    } elseif ($action === 'mail') {
        $to = trim((string)($_POST['to'] ?? ''));
        try {
            [$pdf, $entetePdf] = genererPdfDevis($pdo, $idDevis);
            $ref = (string)$entetePdf['reference'];
            $subject = trim((string)($_POST['subject'] ?? ''));
            if ($subject === '') {
                $subject = 'Votre devis ' . $ref . ' — ' . SOCIETE_NOM;
            }
            $body = trim((string)($_POST['body'] ?? ''));
            if ($body === '') {
                $body = "Bonjour,\n\nVeuillez trouver ci-joint votre devis " . $ref
                    . " d'un montant de " . fmt((float)$entetePdf['total_ttc']) . " TTC.\n\nCordialement,\n" . SOCIETE_NOM;
            }
            sendMailWithAttachment($to, $subject, $body, $pdf, 'devis-' . $ref . '.pdf', 'application/pdf');
            header('Location: devis_client.php?id_devis=' . $idDevis . '&mailed=1');
            exit;
        } catch (Throwable $t) {
            $erreur = "Impossible d'envoyer le devis par mail.";
            $erreurException = $t;
        }
    } elseif ($action === 'supprimer') {
        try {
            $entete = getDevisEntete($pdo, $idDevis);
            if ($entete !== null) {
                $clientCode = (int)$entete['client_code'];
                $docDevis = gedGetDocumentByDevis($pdo, $idDevis);
                devisDelete($pdo, $idDevis);
                // On supprime aussi la copie du devis archivée dans la GED (fichier + ligne)
                if ($docDevis !== null) {
                    gedDeleteDocument($pdo, $docDevis);
                }
                header('Location: fiche_client.php?id=' . $clientCode);
                exit;
            }
        } catch (Throwable $t) {
            $erreur = "Impossible de supprimer le devis.";
            $erreurException = $t;
        }
    }
}

$client = ($idClient > 0) ? getClientComplet($pdo, $idClient) : null;
$articles = getArticles($pdo);

// Mode édition : on charge les données du devis existant
if ($idDevis > 0 && $modifier === '1') {
    $entete = getDevisEntete($pdo, $idDevis);
    if ($entete !== null && (string)$entete['statut'] === 'EN_COURS') {
        $modeEdition = true;
        $idClient = (int)$entete['client_code'];
        $client = getClientComplet($pdo, $idClient);
        $statutDevis = (string)$entete['statut'];
        $dateDevis = (string)$entete['date_devis'];
        $referenceDevis = (string)$entete['reference'];
        
        // Chargement des lignes et totaux existants
        $lignesDevis = getDevisLignes($pdo, $idDevis);
        $tvaParTaux = getDevisTva($pdo, $idDevis);
        $totalHT = (float)$entete['total_ht'];
        $totalTVA = (float)$entete['total_tva'];
        $totalTTC = (float)$entete['total_ttc'];
    }
}

if ($referenceDevis === '') {
    $referenceDevis = 'DEV-' . $idClient . '-' . date('Ymd-His');
}

// Formate un montant en euros
function fmt(float $montant): string
{
    return number_format($montant, 2, ',', ' ') . ' €';
}

// Génère le HTML du devis destiné à être converti en PDF par Dompdf.
// Mise en page avec des tableaux (Dompdf ne gère pas flexbox / grid).
function buildDevisHtmlSnapshot(
    array $client,
    array $lignesDevis,
    array $tvaParTaux,
    float $totalHT,
    float $totalTVA,
    float $totalTTC,
    string $referenceDevis,
    string $dateDevis,
    string $socNom,
    string $socAdr,
    string $socTel,
    string $socMail,
    string $statut = 'EN_COURS'
): string {
    $clientNom = mb_strtoupper((string)$client['nom'], 'UTF-8');
    $clientPrenom = (string)$client['prenom'];
    $clientCode = (string)$client['code'];
    $clientPays = (string)$client['pays_libelle'];
    $clientForme = (string)($client['forme_libelle'] ?? '');
    $clientEntree = fmtDateFr((string)$client['date_entree']);
    $clientNss = $client['numero_secu_social'] !== null ? (string)$client['numero_secu_social'] : 'Non renseigné';

    // Statut : couleurs du badge
    $termine = $statut === 'TERMINE';
    $statutLib = $termine ? 'TERMINÉ' : 'EN COURS';
    $statutBg = $termine ? '#dcfce7' : '#fef3c7';
    $statutFg = $termine ? '#166534' : '#92400e';

    // Date de validité : 30 jours après la date du devis (reçue au format jj/mm/aaaa)
    $dateObj = DateTime::createFromFormat('d/m/Y', $dateDevis) ?: new DateTime();
    $dateValidite = (clone $dateObj)->modify('+30 days')->format('d/m/Y');

    $unites = ['H' => 'heure', 'M' => 'minute', 'S' => 'seconde'];

    // Logo de la société, intégré directement dans le PDF
    $logoPath = __DIR__ . '/assets/img/logo.png';
    $logoImg = is_file($logoPath)
        ? '<img src="data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath)) . '" alt="">'
        : '';

    // Lignes du tableau
    $rows = '';
    $i = 0;
    $totalBrut = 0.0;
    foreach ($lignesDevis as $l) {
        $red = isset($l['reduction_pct']) ? (float)$l['reduction_pct'] : 0.0;
        $redText = $red > 0
            ? '<span class="red">-' . e(number_format($red, 2, ',', '')) . ' %</span>'
            : '<span class="muted">—</span>';
        $unite = (string)$l['unite'];
        $uniteLib = $unites[$unite] ?? $unite;
        $totalBrut += (float)$l['qte'] * (float)$l['forfait_ht'];
        $zebra = ($i++ % 2 === 1) ? ' class="zebra"' : '';

        $rows .= '<tr' . $zebra . '>'
            . '<td class="code">' . e((string)$l['code']) . '</td>'
            . '<td>' . e((string)$l['designation']) . '</td>'
            . '<td class="r">' . e((string)$l['qte']) . ' <span class="muted small">' . e($uniteLib) . ((int)$l['qte'] > 1 ? 's' : '') . '</span></td>'
            . '<td class="r">' . e(fmt((float)$l['forfait_ht'])) . '</td>'
            . '<td class="r">' . $redText . '</td>'
            . '<td class="r">' . e(number_format((float)$l['taux_tva'], 1, ',', '')) . ' %</td>'
            . '<td class="r strong">' . e(fmt((float)$l['montant_ht'])) . '</td>'
            . '</tr>';
    }
    $totalRemise = round($totalBrut - $totalHT, 2);

    // Détail TVA
    $tvaRows = '';
    foreach ($tvaParTaux as $taux => $montant) {
        $tvaRows .= '<tr><td class="sub">TVA ' . e(number_format((float)$taux, 1, ',', '')) . ' %</td>'
            . '<td class="r sub">' . e(fmt((float)$montant)) . '</td></tr>';
    }
    $remiseRow = $totalRemise > 0
        ? '<tr><td class="sub">dont remises</td><td class="r sub red">-' . e(fmt($totalRemise)) . '</td></tr>'
        : '';

    return '<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Devis ' . e($referenceDevis) . '</title>
  <style>
    @page { margin: 34px 0 60px 0; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #1f2937; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    .r { text-align: right; }
    .c { text-align: center; }
    .muted { color: #6b7280; }
    .small { font-size: 8px; }
    .strong { font-weight: bold; }
    .red { color: #dc2626; }
    .page { padding: 0 36px; }

    /* Bandeau d en-tête */
    .band { background: #0836a0; color: #ffffff; padding: 26px 36px 22px 36px; margin-top: -34px; }
    .band td { vertical-align: middle; color: #ffffff; }
    .logo { width: 52px; height: 52px; background: #ffffff; text-align: center; vertical-align: middle; border-radius: 10px; padding: 0; }
    .logo img { width: 42px; height: 33px; }
    .soc-nom { font-size: 18px; font-weight: bold; letter-spacing: 1px; }
    .soc-sub { font-size: 8.5px; color: #bfdbfe; }
    .doc-titre { font-size: 30px; font-weight: bold; letter-spacing: 3px; text-align: right; }
    .doc-ref { font-size: 10px; color: #bfdbfe; text-align: right; }
    .accent { height: 5px; background: #3b82f6; }

    /* Bande d infos */
    .infos { margin: 18px 0 16px 0; }
    .infos td { width: 25%; padding: 9px 12px; background: #f1f5f9; border-right: 3px solid #ffffff; vertical-align: top; }
    .lbl { font-size: 7.5px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 3px; }
    .val { font-size: 11px; font-weight: bold; color: #0f172a; }
    .badge { display: inline-block; padding: 3px 9px; border-radius: 9px; font-size: 8.5px; font-weight: bold; }

    /* Cartes émetteur / client */
    .card { border: 1px solid #e2e8f0; padding: 11px 14px; vertical-align: top; }
    .card-client { border-left: 4px solid #3b82f6; }
    .card-titre { font-size: 7.5px; color: #3b82f6; font-weight: bold; text-transform: uppercase; letter-spacing: 1.2px; margin-bottom: 6px; }
    .card-nom { font-size: 12px; font-weight: bold; color: #0f172a; margin-bottom: 3px; }
    .card div { line-height: 1.55; }

    /* Tableau des lignes */
    .lignes { margin-top: 20px; }
    .lignes th { background: #0836a0; color: #ffffff; font-size: 8px; text-transform: uppercase; letter-spacing: .6px; padding: 8px 8px; text-align: left; }
    .lignes th.r { text-align: right; }
    .lignes td { padding: 9px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; }
    .lignes tr.zebra td { background: #f8fafc; }
    .lignes td.code { font-weight: bold; color: #0836a0; }

    /* Bas de page : conditions + totaux */
    .bas { margin-top: 18px; }
    .bas > tbody > tr > td { vertical-align: top; }
    .conditions { background: #f8fafc; border: 1px solid #e2e8f0; padding: 11px 13px; line-height: 1.6; }
    .conditions .card-titre { color: #475569; }
    .totaux td { padding: 6px 10px; }
    .totaux .sub { color: #64748b; font-size: 8.5px; padding-top: 2px; padding-bottom: 2px; padding-left: 20px; }
    .totaux .ligne-tot td { border-bottom: 1px solid #e5e7eb; font-weight: bold; }
    .totaux td.ttc-box { background: #0836a0; padding: 11px 10px; }
    .totaux .ttc-box td { color: #ffffff; font-size: 13px; font-weight: bold; padding: 0; }

    /* Signatures */
    .signatures { margin-top: 26px; }
    .sign { border: 1px dashed #94a3b8; height: 78px; padding: 9px 12px; vertical-align: top; }
    .sign-titre { font-weight: bold; color: #0f172a; margin-bottom: 3px; }

    /* Pied de page répété sur chaque page */
    .footer { position: fixed; bottom: -42px; left: 36px; right: 36px; border-top: 1px solid #e2e8f0; padding-top: 7px; font-size: 7.5px; color: #94a3b8; }
    .pagenum:before { content: counter(page); }
  </style>
</head>
<body>
  <div class="footer">
    <table>
      <tr>
        <td>' . e($socNom) . ' — ' . e(str_replace("\n", ', ', $socAdr)) . ' — Tél. ' . e($socTel) . ' — ' . e($socMail) . '</td>
        <td class="r">Devis ' . e($referenceDevis) . ' — page <span class="pagenum"></span></td>
      </tr>
    </table>
  </div>

  <div class="band">
    <table>
      <tr>
        <td style="width:66px;"><table style="width:52px;"><tr><td class="logo">' . $logoImg . '</td></tr></table></td>
        <td>
          <div class="soc-nom">' . e(mb_strtoupper($socNom, 'UTF-8')) . '</div>
          <div class="soc-sub">Service après-vente &amp; interventions</div>
        </td>
        <td>
          <div class="doc-titre">DEVIS</div>
          <div class="doc-ref">N° ' . e($referenceDevis) . '</div>
        </td>
      </tr>
    </table>
  </div>
  <div class="accent"></div>

  <div class="page">
    <table class="infos">
      <tr>
        <td><div class="lbl">Référence</div><div class="val" style="font-size:9.5px;">' . e($referenceDevis) . '</div></td>
        <td><div class="lbl">Date d&#39;émission</div><div class="val">' . e($dateDevis) . '</div></td>
        <td><div class="lbl">Valable jusqu&#39;au</div><div class="val">' . e($dateValidite) . '</div></td>
        <td style="border-right:none;"><div class="lbl">Statut</div><span class="badge" style="background:' . $statutBg . ';color:' . $statutFg . ';">' . e($statutLib) . '</span></td>
      </tr>
    </table>

    <table>
      <tr>
        <td class="card" style="width:48%;">
          <div class="card-titre">Émetteur</div>
          <div class="card-nom">' . e($socNom) . '</div>
          <div>' . nl2br(e($socAdr)) . '</div>
          <div>Tél. ' . e($socTel) . '</div>
          <div>' . e($socMail) . '</div>
        </td>
        <td style="width:4%;"></td>
        <td class="card card-client">
          <div class="card-titre">Adressé à</div>
          <div class="card-nom">' . e($clientNom) . ' ' . e($clientPrenom) . '</div>
          ' . ($clientForme !== '' ? '<div>' . e($clientForme) . '</div>' : '') . '
          <div>Client n° ' . e($clientCode) . ' — ' . e($clientPays) . '</div>
          <div class="muted">Client depuis le ' . e($clientEntree) . '</div>
          <div class="muted">N° SS : ' . e($clientNss) . '</div>
        </td>
      </tr>
    </table>

    <table class="lignes">
      <thead>
        <tr>
          <th style="width:9%;">Code</th>
          <th>Désignation</th>
          <th class="r" style="width:14%;">Quantité</th>
          <th class="r" style="width:13%;">Prix unit. HT</th>
          <th class="r" style="width:10%;">Remise</th>
          <th class="r" style="width:8%;">TVA</th>
          <th class="r" style="width:14%;">Montant HT</th>
        </tr>
      </thead>
      <tbody>' . $rows . '</tbody>
    </table>

    <table class="bas">
      <tr>
        <td style="width:50%;">
          <div class="conditions">
            <div class="card-titre">Conditions</div>
            <div>• Devis valable 30 jours, jusqu&#39;au ' . e($dateValidite) . '.</div>
            <div>• Paiement à réception de facture.</div>
            <div>• Prix exprimés en euros.</div>
            <div>• Unités : H = heure, M = minute, S = seconde.</div>
          </div>
        </td>
        <td style="width:5%;"></td>
        <td>
          <table class="totaux">
            <tr class="ligne-tot"><td>Total HT</td><td class="r">' . e(fmt($totalHT)) . '</td></tr>
            ' . $remiseRow . '
            <tr class="ligne-tot"><td>Total TVA</td><td class="r">' . e(fmt($totalTVA)) . '</td></tr>
            ' . $tvaRows . '
            <tr><td colspan="2" style="padding:4px;"></td></tr>
            <tr><td colspan="2" class="ttc-box"><table><tr><td>TOTAL TTC</td><td class="r">' . e(fmt($totalTTC)) . '</td></tr></table></td></tr>
          </table>
        </td>
      </tr>
    </table>

    <table class="signatures">
      <tr>
        <td class="sign" style="width:48%;">
          <div class="sign-titre">Pour ' . e($socNom) . '</div>
          <div class="muted small">Cachet et signature</div>
        </td>
        <td style="width:4%;"></td>
        <td class="sign">
          <div class="sign-titre">Bon pour accord — le client</div>
          <div class="muted small">Date, signature précédée de la mention « Bon pour accord »</div>
        </td>
      </tr>
    </table>
  </div>
</body>
</html>';
}

// Génère le PDF du devis (contenu binaire)
function buildDevisPdf(
    array $client,
    array $lignesDevis,
    array $tvaParTaux,
    float $totalHT,
    float $totalTVA,
    float $totalTTC,
    string $referenceDevis,
    string $dateDevis,
    string $socNom,
    string $socAdr,
    string $socTel,
    string $socMail,
    string $statut = 'EN_COURS'
): string {
    return htmlToPdf(buildDevisHtmlSnapshot(
        $client, $lignesDevis, $tvaParTaux, $totalHT, $totalTVA, $totalTTC,
        $referenceDevis, $dateDevis, $socNom, $socAdr, $socTel, $socMail, $statut
    ));
}

// Relit un devis en base et génère son PDF. Retourne [contenu PDF, en-tête du devis]
function genererPdfDevis(PDO $pdo, int $idDevis): array
{
    $entete = getDevisEntete($pdo, $idDevis);
    if ($entete === null) {
        throw new RuntimeException("Devis introuvable.");
    }
    $client = getClientComplet($pdo, (int)$entete['client_code']);
    if ($client === null) {
        throw new RuntimeException("Client du devis introuvable.");
    }

    $pdf = buildDevisPdf(
        $client,
        getDevisLignes($pdo, $idDevis),
        getDevisTva($pdo, $idDevis),
        (float)$entete['total_ht'],
        (float)$entete['total_tva'],
        (float)$entete['total_ttc'],
        (string)$entete['reference'],
        fmtDateFr((string)$entete['date_devis']),
        (string)$entete['societe_nom'],
        (string)$entete['societe_adresse'],
        (string)$entete['societe_tel'],
        (string)$entete['societe_email'],
        (string)$entete['statut']
    );
    return [$pdf, $entete];
}

// (Re)génère la copie PDF du devis archivée dans la GED (remplace l'ancienne version)
function archiverDevisDansGed(PDO $pdo, int $idDevis): void
{
    [$pdf, $entete] = genererPdfDevis($pdo, $idDevis);
    $reference = (string)$entete['reference'];

    $ancienDoc = gedGetDocumentByDevis($pdo, $idDevis);
    if ($ancienDoc !== null) {
        gedDeleteDocument($pdo, $ancienDoc);
    }

    gedSaveContentDocument(
        $pdo,
        (int)$entete['client_code'],
        $pdf,
        'devis-' . $reference . '.pdf',
        'DEVIS',
        'PDF',
        'Devis SAV ' . $reference,
        $idDevis,
        'devis'
    );
}

// Lecture d'un devis existant en mode CONSULTATION (pas édition)
if ($idDevis > 0 && !$modeEdition) {
    $entete = null;
    try {
        $entete = getDevisEntete($pdo, $idDevis);
    } catch (Throwable $t) {
        $entete = null;
    }

    if ($entete === null) {
        $erreur = "Devis introuvable.";
        $client = null;
    } else {
        $idClient = (int)$entete['client_code'];
        $client = getClientComplet($pdo, $idClient);
        $dateDevis = (string)$entete['date_devis'];
        $referenceDevis = (string)$entete['reference'];
        $statutDevis = (string)$entete['statut'];

        $lignesDevis = getDevisLignes($pdo, $idDevis);
        $tvaParTaux = getDevisTva($pdo, $idDevis);

        $totalHT = (float)$entete['total_ht'];
        $totalTVA = (float)$entete['total_tva'];
        $totalTTC = (float)$entete['total_ttc'];
        $devisValide = true;
    }
} elseif ($idClient <= 0 && !$modeEdition) {
    $erreur = "Identifiant client invalide.";
} elseif ($client === null) {
    $erreur = "Client introuvable.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Calcul serveur (ne pas faire confiance aux valeurs calculées côté JS) + sauvegarde en base
    // IMPORTANT : réinitialiser les tableaux pour éviter de garder les anciennes lignes
    $lignesDevis = [];
    $tvaParTaux = [];
    $totalHT = 0.0;
    $totalTVA = 0.0;
    
    $codes = $_POST['article_code'] ?? [];
    $qtes = $_POST['quantite'] ?? [];
    $reductions = $_POST['reduction'] ?? [];

    if (!is_array($codes) || !is_array($qtes)) {
        $erreur = "Formulaire invalide.";
    } else {
        $saisie = [];
        for ($i = 0; $i < max(count($codes), count($qtes)); $i++) {
            $codeArticle = isset($codes[$i]) ? trim((string)$codes[$i]) : '';
            $qteRaw = isset($qtes[$i]) ? trim((string)$qtes[$i]) : '';
            $reductionRaw = isset($reductions[$i]) ? trim((string)$reductions[$i]) : '0';

            if ($codeArticle === '' && $qteRaw === '') {
                continue;
            }

            if ($codeArticle === '') {
                continue;
            }

            if ($qteRaw === '' || !preg_match('/^\d+$/', $qteRaw)) {
                continue;
            }
            $qte = (int)$qteRaw;
            if ($qte <= 0) {
                continue;
            }

            // Réduction (0-100%)
            $reduction = 0.0;
            if ($reductionRaw !== '' && preg_match('/^\d+([.,]\d{1,2})?$/', $reductionRaw)) {
                $reduction = (float)str_replace(',', '.', $reductionRaw);
                if ($reduction < 0) {
                    $reduction = 0;
                }
                if ($reduction > 100) {
                    $reduction = 100;
                }
            }

            $saisie[] = ['code' => $codeArticle, 'qte' => $qte, 'reduction' => $reduction];
        }

        // Pour réafficher le formulaire tel quel si une erreur survient
        foreach ($saisie as $ls) {
            $lignesSaisies[] = ['code' => $ls['code'], 'qte' => $ls['qte'], 'reduction_pct' => $ls['reduction']];
        }

        if (count($saisie) === 0) {
            $erreur = "Veuillez saisir au moins une ligne de devis (article + quantité).";
        } else {
            // On relit les infos article en base.
            $uniqueCodes = array_values(array_unique(array_map(fn($l) => $l['code'], $saisie)));

            $placeholders = implode(',', array_fill(0, count($uniqueCodes), '?'));
            $sql = "
                SELECT a.code, a.designation, a.unite, a.forfait_ht, t.taux AS taux_tva
                FROM articles a
                INNER JOIN tva t ON t.code = a.tva
                WHERE a.code IN ($placeholders)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($uniqueCodes);
            $rows = $stmt->fetchAll();

            $articlesByCode = [];
            foreach ($rows as $r) {
                $articlesByCode[$r['code']] = $r;
            }

            foreach ($saisie as $ligne) {
                if (!isset($articlesByCode[$ligne['code']])) {
                    continue;
                }
                $a = $articlesByCode[$ligne['code']];
                $forfait = (float)$a['forfait_ht'];
                $taux = (float)$a['taux_tva'];
                $qte = (int)$ligne['qte'];
                $reduction = (float)$ligne['reduction'];

                // Calcul : montant brut HT puis application réduction
                $montantBrutHT = round($qte * $forfait, 2);
                $montantReduction = round($montantBrutHT * ($reduction / 100.0), 2);
                $montantLigneHT = round($montantBrutHT - $montantReduction, 2);
                $montantLigneTVA = round($montantLigneHT * ($taux / 100.0), 2);

                $lignesDevis[] = [
                    'code' => $a['code'],
                    'designation' => $a['designation'],
                    'unite' => $a['unite'],
                    'forfait_ht' => $forfait,
                    'reduction_pct' => $reduction,
                    'taux_tva' => $taux,
                    'qte' => $qte,
                    'montant_ht' => $montantLigneHT,
                ];

                $totalHT += $montantLigneHT;
                $tauxKey = (string)$taux;
                if (!isset($tvaParTaux[$tauxKey])) {
                    $tvaParTaux[$tauxKey] = 0.0;
                }
                $tvaParTaux[$tauxKey] += $montantLigneTVA;
            }

            if (count($lignesDevis) === 0) {
                $erreur = "Aucune ligne de devis valide (articles inconnus ou quantités invalides).";
            } else {
                ksort($tvaParTaux, SORT_NUMERIC);
                foreach ($tvaParTaux as $tauxKey => $montant) {
                    $tvaParTaux[$tauxKey] = round((float)$montant, 2);
                    $totalTVA += $tvaParTaux[$tauxKey];
                }
                $totalHT = round($totalHT, 2);
                $totalTVA = round($totalTVA, 2);
                $totalTTC = round($totalHT + $totalTVA, 2);

                // Sauvegarde (transaction) + redirection (évite le double submit au refresh)
                try {
                    $pdo->beginTransaction();

                    $devisIdToUpdate = 0;
                    
                    // Si on modifie un devis EN_COURS existant, on UPDATE
                    if ($modeEdition && $idDevis > 0) {
                        // D'abord supprimer les anciennes lignes (AVANT l'UPDATE)
                        $pdo->prepare("DELETE FROM devis_lignes WHERE devis_id = :id")->execute([':id' => $idDevis]);
                        $pdo->prepare("DELETE FROM devis_tva WHERE devis_id = :id")->execute([':id' => $idDevis]);
                        
                        $sqlUpdateDevis = "
                            UPDATE devis SET
                                total_ht = :total_ht,
                                total_tva = :total_tva,
                                total_ttc = :total_ttc
                            WHERE id = :id AND statut = 'EN_COURS'
                        ";
                        $stmtDevis = $pdo->prepare($sqlUpdateDevis);
                        $stmtDevis->execute([
                            ':id' => $idDevis,
                            ':total_ht' => number_format($totalHT, 2, '.', ''),
                            ':total_tva' => number_format($totalTVA, 2, '.', ''),
                            ':total_ttc' => number_format($totalTTC, 2, '.', ''),
                        ]);
                        
                        $devisIdToUpdate = $idDevis;
                    } else {
                        // Nouveau devis (INSERT)
                        $sqlInsertDevis = "
                            INSERT INTO devis
                                (client_code, reference, date_devis, statut, total_ht, total_tva, total_ttc,
                                 societe_nom, societe_adresse, societe_tel, societe_email)
                            VALUES
                                (:client_code, :reference, NOW(), 'EN_COURS', :total_ht, :total_tva, :total_ttc,
                                 :societe_nom, :societe_adresse, :societe_tel, :societe_email)
                        ";
                        $stmtDevis = $pdo->prepare($sqlInsertDevis);
                        $stmtDevis->execute([
                            ':client_code' => $idClient,
                            ':reference' => $referenceDevis,
                            ':total_ht' => number_format($totalHT, 2, '.', ''),
                            ':total_tva' => number_format($totalTVA, 2, '.', ''),
                            ':total_ttc' => number_format($totalTTC, 2, '.', ''),
                            ':societe_nom' => SOCIETE_NOM,
                            ':societe_adresse' => SOCIETE_ADRESSE,
                            ':societe_tel' => SOCIETE_TEL,
                            ':societe_email' => SOCIETE_EMAIL,
                        ]);
                        $devisIdToUpdate = (int)$pdo->lastInsertId();
                    }
                    
                    $idDevisCree = $devisIdToUpdate;

                    $sqlInsertLigne = "
                        INSERT INTO devis_lignes
                            (devis_id, article_code, designation, unite, quantite, forfait_ht, reduction_pct, taux_tva, montant_ht)
                        VALUES
                            (:devis_id, :article_code, :designation, :unite, :quantite, :forfait_ht, :reduction_pct, :taux_tva, :montant_ht)
                    ";
                    $stmtLigne = $pdo->prepare($sqlInsertLigne);
                    foreach ($lignesDevis as $l) {
                        $stmtLigne->execute([
                            ':devis_id' => $idDevisCree,
                            ':article_code' => (string)$l['code'],
                            ':designation' => (string)$l['designation'],
                            ':unite' => (string)$l['unite'],
                            ':quantite' => (int)$l['qte'],
                            ':forfait_ht' => number_format((float)$l['forfait_ht'], 2, '.', ''),
                            ':reduction_pct' => number_format((float)$l['reduction_pct'], 2, '.', ''),
                            ':taux_tva' => (string)$l['taux_tva'],
                            ':montant_ht' => number_format((float)$l['montant_ht'], 2, '.', ''),
                        ]);
                    }

                    $sqlInsertTva = "
                        INSERT INTO devis_tva (devis_id, taux_tva, montant_tva)
                        VALUES (:devis_id, :taux_tva, :montant_tva)
                    ";
                    $stmtTva = $pdo->prepare($sqlInsertTva);
                    foreach ($tvaParTaux as $tauxKey => $montant) {
                        $stmtTva->execute([
                            ':devis_id' => $idDevisCree,
                            ':taux_tva' => (string)$tauxKey,
                            ':montant_tva' => number_format((float)$montant, 2, '.', ''),
                        ]);
                    }

                    $pdo->commit();

                    // Sauvegarde physique du devis + indexation GED.
                    // En modification, l'ancienne version archivée est remplacée par la nouvelle.
                    $warn = '';
                    try {
                        archiverDevisDansGed($pdo, $idDevisCree);
                    } catch (Throwable $t) {
                        $warn = '&warn=ged';
                    }

                    header('Location: devis_client.php?id_devis=' . $idDevisCree . $warn);
                    exit;
                } catch (Throwable $t) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $erreur = "Le devis a été calculé mais n'a pas pu être enregistré (tables manquantes ?).";
                    if (defined('APP_DEBUG') && APP_DEBUG) {
                        $erreur .= " " . $t->getMessage();
                    }
                    $devisValide = true;
                }
            }
        }
    }
}

$articlesJson = [];
foreach ($articles as $a) {
    $articlesJson[] = [
        'code' => (string)$a['code'],
        'designation' => (string)$a['designation'],
        'unite' => (string)$a['unite'],
        'forfait_ht' => (float)$a['forfait_ht'],
        'taux_tva' => (float)$a['taux_tva'],
    ];
}
?>
<?php
$nomClientAff = $client !== null ? nomClient($client) : 'Client';
if ($modeEdition) {
    $titrePage = 'Modifier le devis';
    $sousTitre = 'Référence ' . $referenceDevis . ' — les montants sont recalculés à l’enregistrement.';
} elseif ($idDevis > 0) {
    $titrePage = 'Devis ' . $referenceDevis;
    $sousTitre = 'Consultez, téléchargez ou envoyez ce devis au client.';
} else {
    $titrePage = 'Nouveau devis';
    $sousTitre = 'Ajoutez les prestations : les totaux se calculent automatiquement.';
}
$fil = [['label' => 'Clients', 'href' => 'recherche_client.php']];
if ($client !== null) {
    $fil[] = ['label' => $nomClientAff, 'href' => 'fiche_client.php?id=' . (string)$client['code']];
}
$fil[] = ['label' => $idDevis > 0 ? (string)$referenceDevis : 'Nouveau devis', 'href' => '#'];

appHeader($titrePage, $sousTitre, 'clients', $fil);

$socNom = isset($entete) && $entete ? (string)$entete['societe_nom'] : SOCIETE_NOM;
$socAdr = isset($entete) && $entete ? (string)$entete['societe_adresse'] : SOCIETE_ADRESSE;
$socTel = isset($entete) && $entete ? (string)$entete['societe_tel'] : SOCIETE_TEL;
$socMail = isset($entete) && $entete ? (string)$entete['societe_email'] : SOCIETE_EMAIL;
$unitesLib = ['H' => 'heure', 'M' => 'minute', 'S' => 'seconde'];
?>

<?php if ($okMsg !== ''): ?>
    <?php afficheSucces($okMsg); ?>
<?php endif; ?>
<?php if ($warningMsg !== ''): ?>
    <?php afficheAvertissement($warningMsg); ?>
<?php endif; ?>
<?php if ($erreur !== ''): ?>
    <?php afficheErreur($erreur, $erreurException); ?>
<?php endif; ?>

<?php if ($client === null): ?>
    <a class="btn" href="recherche_client.php"><?= icon('arrow-left', 16) ?> Retour à la liste des clients</a>
<?php endif; ?>

<?php if ($client !== null && !$devisValide): ?>
    <?php
    // ===================== SAISIE / MODIFICATION =====================
    $formAction = $modeEdition
        ? 'devis_client.php?id_devis=' . $idDevis . '&modifier=1'
        : 'devis_client.php?id_client=' . $idClient;
    if (!empty($lignesSaisies)) {
        $lignesAffichees = $lignesSaisies;
    } elseif ($modeEdition && !empty($lignesDevis)) {
        $lignesAffichees = $lignesDevis;
    } else {
        $lignesAffichees = [['code' => '', 'qte' => 1, 'reduction_pct' => 0]];
    }
    ?>
    <form method="post" action="<?= e($formAction) ?>">
        <div class="split">
            <div class="card">
                <div class="card-head">
                    <h2 class="card-title"><?= icon('layers', 18) ?> Prestations</h2>
                    <button type="button" class="btn btn-sm" id="btn-ajouter"><?= icon('plus', 15) ?> Ajouter une ligne</button>
                </div>
                <div class="table-wrap">
                    <table class="table lines-table">
                        <thead>
                        <tr>
                            <th>Article</th>
                            <th>Quantité</th>
                            <th>Remise %</th>
                            <th class="r">Prix unit. HT</th>
                            <th class="r">TVA</th>
                            <th class="r">Montant HT</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody id="lignes-body">
                        <?php foreach ($lignesAffichees as $ligne): ?>
                            <?php
                            $codeSel = (string)($ligne['code'] ?? '');
                            $qteSel = (int)($ligne['qte'] ?? 1);
                            $redSel = (float)($ligne['reduction_pct'] ?? 0);
                            ?>
                            <tr class="ligne">
                                <td>
                                    <div class="article-picker">
                                        <select name="article_code[]" aria-label="Article">
                                            <option value="">Choisir un article…</option>
                                            <?php foreach ($articles as $a): ?>
                                                <option value="<?= e((string)$a['code']) ?>"
                                                        data-forfait="<?= e((string)$a['forfait_ht']) ?>"
                                                        data-tva="<?= e((string)$a['taux_tva']) ?>"
                                                        data-unite="<?= e((string)$a['unite']) ?>"
                                                    <?= $codeSel === (string)$a['code'] ? 'selected' : '' ?>><?= e((string)$a['code']) ?> — <?= e((string)$a['designation']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-icon btn-loupe" title="Rechercher un article" aria-label="Rechercher un article"><?= icon('search', 16) ?></button>
                                    </div>
                                </td>
                                <td class="col-qte"><input type="number" name="quantite[]" min="1" step="1" value="<?= e((string)$qteSel) ?>" aria-label="Quantité"></td>
                                <td class="col-red"><input type="number" name="reduction[]" min="0" max="100" step="0.01" value="<?= e(number_format($redSel, 2, '.', '')) ?>" aria-label="Remise en %"></td>
                                <td class="forfait r num muted">—</td>
                                <td class="tva r num muted">—</td>
                                <td class="montant r amount">0,00 €</td>
                                <td class="r"><button type="button" class="btn btn-icon btn-sm btn-ghost btn-supprimer" title="Supprimer la ligne"><?= icon('trash', 15) ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-foot">
                    <span class="small muted"><?= icon('info', 15) ?> Astuce : la loupe permet de chercher un article par code ou par nom. Les montants affichés sont recalculés par le serveur à l’enregistrement.</span>
                </div>
            </div>

            <aside class="card summary">
                <div class="card-head">
                    <h2 class="card-title"><?= icon('euro', 18) ?> Récapitulatif</h2>
                </div>
                <div class="card-body">
                    <div class="cell-main" style="margin-bottom:16px;">
                        <span class="avatar"><?= e(initiales((string)$client['nom'], (string)$client['prenom'])) ?></span>
                        <div>
                            <div class="cell-title"><?= e($nomClientAff) ?></div>
                            <div class="cell-sub">Client n° <?= e((string)$client['code']) ?> · <?= e((string)$client['pays_libelle']) ?></div>
                        </div>
                    </div>
                    <div class="summary-row"><span>Total HT</span><strong class="num" id="total-ht">0,00 €</strong></div>
                    <div class="summary-row"><span>Total TVA</span><strong class="num" id="total-tva">0,00 €</strong></div>
                    <div id="detail-tva"></div>
                    <div class="summary-total"><span>Total TTC</span><span class="num" id="total-ttc">0,00 €</span></div>
                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px;height:44px;">
                        <?= icon('check', 18) ?> <?= $modeEdition ? 'Enregistrer les modifications' : 'Valider le devis' ?>
                    </button>
                    <a class="btn btn-ghost" style="width:100%;margin-top:8px;" href="<?= $modeEdition ? 'devis_client.php?id_devis=' . e((string)$idDevis) : 'fiche_client.php?id=' . e((string)$client['code']) ?>">Annuler</a>
                </div>
            </aside>
        </div>
    </form>
<?php endif; ?>

<?php if ($client !== null && $devisValide): ?>
    <?php // ===================== CONSULTATION ===================== ?>
    <div class="card no-print" style="margin-bottom:20px;">
        <div class="card-body btn-row">
            <?php if ($idDevis > 0): ?>
                <a class="btn btn-primary" href="devis_client.php?id_devis=<?= e((string)$idDevis) ?>&amp;action=pdf"><?= icon('download', 16) ?> Télécharger le PDF</a>
                <button type="button" class="btn" data-modal-open="mailDevisModal"><?= icon('mail', 16) ?> Envoyer par mail</button>
            <?php endif; ?>
            <button type="button" class="btn" onclick="window.print()"><?= icon('printer', 16) ?> Imprimer</button>
            <?php if ($idDevis > 0 && $statutDevis === 'EN_COURS'): ?>
                <a class="btn" href="devis_client.php?id_devis=<?= e((string)$idDevis) ?>&amp;modifier=1"><?= icon('edit', 16) ?> Modifier</a>
                <form method="post" class="inline" action="devis_client.php?id_devis=<?= e((string)$idDevis) ?>&amp;action=terminer" onsubmit="return confirm('Marquer ce devis comme terminé ? Il ne pourra plus être modifié.');">
                    <button type="submit" class="btn btn-success"><?= icon('check-circle', 16) ?> Marquer terminé</button>
                </form>
            <?php endif; ?>
            <span class="spacer"></span>
            <a class="btn btn-ghost" href="devis_client.php?id_client=<?= e((string)$idClient) ?>"><?= icon('file-plus', 16) ?> Nouveau devis</a>
            <?php if ($idDevis > 0): ?>
                <form method="post" class="inline" action="devis_client.php?id_devis=<?= e((string)$idDevis) ?>&amp;action=supprimer" onsubmit="return confirm('Supprimer définitivement ce devis ? Cette action est irréversible.');">
                    <button type="submit" class="btn btn-danger btn-icon" title="Supprimer le devis"><?= icon('trash', 16) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $dateObj = date_create((string)$dateDevis) ?: date_create();
    $dateValidite = (clone $dateObj)->modify('+30 days')->format('d/m/Y');
    $totalRemise = 0.0;
    foreach ($lignesDevis as $l) {
        $totalRemise += (float)$l['qte'] * (float)$l['forfait_ht'] - (float)$l['montant_ht'];
    }
    ?>
    <article class="paper" id="zone-impression">
        <header class="paper-band">
            <div class="paper-brand">
                <span class="paper-logo"><img src="assets/img/logo.png" alt="Logo <?= e($socNom) ?>"></span>
                <div>
                    <div class="paper-soc"><?= e(mb_strtoupper($socNom, 'UTF-8')) ?></div>
                    <div class="paper-soc-sub">Service après-vente &amp; interventions</div>
                </div>
            </div>
            <div class="paper-title">
                <strong>DEVIS</strong>
                <span>N° <?= e($referenceDevis) ?></span>
            </div>
        </header>
        <div class="paper-body">
            <div class="paper-infos">
                <div>
                    <div class="paper-label">Référence</div>
                    <div class="paper-value" style="display:flex;align-items:center;gap:6px;">
                        <span class="small"><?= e($referenceDevis) ?></span>
                        <button type="button" class="btn btn-ghost btn-icon btn-sm no-print" onclick="copyText('<?= e($referenceDevis) ?>', this)" title="Copier la référence"><?= icon('copy', 14) ?></button>
                    </div>
                </div>
                <div><div class="paper-label">Date d’émission</div><div class="paper-value"><?= e(fmtDateFr((string)$dateDevis)) ?></div></div>
                <div><div class="paper-label">Valable jusqu’au</div><div class="paper-value"><?= e($dateValidite) ?></div></div>
                <div>
                    <div class="paper-label">Statut</div>
                    <div class="paper-value">
                        <?php if ($statutDevis === 'TERMINE'): ?>
                            <span class="badge badge-success">Terminé</span>
                        <?php else: ?>
                            <span class="badge badge-warning">En cours</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="paper-parties">
                <div class="party">
                    <div class="party-label">Émetteur</div>
                    <div class="party-name"><?= e($socNom) ?></div>
                    <div><?= nl2br(e($socAdr)) ?></div>
                    <div>Tél. <?= e($socTel) ?> · <?= e($socMail) ?></div>
                </div>
                <div class="party to">
                    <div class="party-label">Adressé à</div>
                    <div class="party-name"><?= e($nomClientAff) ?></div>
                    <div><?= e((string)$client['forme_libelle']) ?></div>
                    <div>Client n° <?= e((string)$client['code']) ?> — <?= e((string)$client['pays_libelle']) ?></div>
                    <div class="muted small">Client depuis le <?= e(fmtDateFr((string)$client['date_entree'])) ?> · N° SS : <?= $client['numero_secu_social'] !== null ? e((string)$client['numero_secu_social']) : 'non renseigné' ?></div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Code</th>
                        <th>Désignation</th>
                        <th class="r">Quantité</th>
                        <th class="r">Prix unit. HT</th>
                        <th class="r">Remise</th>
                        <th class="r">TVA</th>
                        <th class="r">Montant HT</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lignesDevis as $l): ?>
                        <?php
                        $red = (float)($l['reduction_pct'] ?? 0);
                        $u = (string)$l['unite'];
                        $uLib = ($unitesLib[$u] ?? $u) . ((int)$l['qte'] > 1 ? 's' : '');
                        ?>
                        <tr>
                            <td><strong style="color:var(--brand);"><?= e((string)$l['code']) ?></strong></td>
                            <td><?= e((string)$l['designation']) ?></td>
                            <td class="r num"><?= e((string)$l['qte']) ?> <span class="muted small"><?= e($uLib) ?></span></td>
                            <td class="r num"><?= e(fmt((float)$l['forfait_ht'])) ?></td>
                            <td class="r num"><?= $red > 0 ? '<span class="red">-' . e(number_format($red, 2, ',', '')) . ' %</span>' : '<span class="muted">—</span>' ?></td>
                            <td class="r num"><?= e(number_format((float)$l['taux_tva'], 1, ',', '')) ?> %</td>
                            <td class="r amount"><?= e(fmt((float)$l['montant_ht'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="paper-bottom">
                <div class="conditions">
                    <strong>Conditions</strong>
                    Devis valable 30 jours, jusqu’au <?= e($dateValidite) ?>.<br>
                    Paiement à réception de facture. Prix exprimés en euros.<br>
                    Unités : H = heure, M = minute, S = seconde.
                </div>
                <div>
                    <div class="summary-row"><span>Total HT</span><strong class="num"><?= e(fmt($totalHT)) ?></strong></div>
                    <?php if ($totalRemise > 0.004): ?>
                        <div class="summary-row sub"><span>dont remises</span><span class="num red">-<?= e(fmt($totalRemise)) ?></span></div>
                    <?php endif; ?>
                    <div class="summary-row"><span>Total TVA</span><strong class="num"><?= e(fmt($totalTVA)) ?></strong></div>
                    <?php foreach ($tvaParTaux as $taux => $montant): ?>
                        <div class="summary-row sub"><span>TVA <?= e(number_format((float)$taux, 1, ',', '')) ?> %</span><span class="num"><?= e(fmt((float)$montant)) ?></span></div>
                    <?php endforeach; ?>
                    <div class="summary-total"><span>Total TTC</span><span class="num"><?= e(fmt($totalTTC)) ?></span></div>
                </div>
            </div>
        </div>
    </article>

    <?php if ($idDevis > 0): ?>
        <?= mailModal(
            'mailDevisModal',
            'devis_client.php?id_devis=' . $idDevis . '&action=mail',
            'Devis ' . $referenceDevis,
            'devis-' . $referenceDevis . '.pdf',
            'Votre devis ' . $referenceDevis . ' — ' . SOCIETE_NOM
        ) ?>
    <?php endif; ?>
<?php endif; ?>

<script>
// JS : calcul affiché en direct
(function () {
    const tableBody = document.getElementById('lignes-body');
    const btnAjouter = document.getElementById('btn-ajouter');
    if (!tableBody || !btnAjouter) return; // si on est en mode "récapitulatif"

    function fmtEuro(value) {
        return value.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    function getSelectedData(selectEl) {
        const opt = selectEl.options[selectEl.selectedIndex];
        if (!opt || !opt.value) return null;
        return {
            code: opt.value,
            forfait: parseFloat(opt.dataset.forfait || '0'),
            taux: parseFloat(opt.dataset.tva || '0'),
            unite: opt.dataset.unite || ''
        };
    }

    function recalculer() {
        let totalHT = 0;
        let totalTVA = 0;
        const tvaParTaux = {};

        const lignes = tableBody.querySelectorAll('tr.ligne');
        lignes.forEach(tr => {
            const select = tr.querySelector('select');
            const inputQte = tr.querySelector('input[name="quantite[]"]');
            const inputReduction = tr.querySelector('input[name="reduction[]"]');
            const cellForfait = tr.querySelector('.forfait');
            const cellTva = tr.querySelector('.tva');
            const cellMontant = tr.querySelector('.montant');

            const data = getSelectedData(select);
            const qte = parseInt(inputQte.value || '0', 10);
            const reduction = parseFloat(inputReduction.value || '0');

            if (!data || !qte || qte <= 0) {
                cellForfait.textContent = '—';
                cellTva.textContent = '—';
                cellMontant.textContent = fmtEuro(0);
                return;
            }

            // Calcul : montant brut HT - réduction
            const montantBrutHT = Math.round((qte * data.forfait) * 100) / 100;
            const montantReductionHT = Math.round((montantBrutHT * reduction / 100) * 100) / 100;
            const montantHT = Math.round((montantBrutHT - montantReductionHT) * 100) / 100;
            const montantTVA = Math.round((montantHT * data.taux / 100) * 100) / 100;

            cellForfait.textContent = fmtEuro(data.forfait) + ' / ' + (data.unite || '');
            cellTva.textContent = (data.taux).toString().replace('.', ',') + ' %';
            cellMontant.textContent = fmtEuro(montantHT);

            totalHT += montantHT;
            totalTVA += montantTVA;
            const key = data.taux.toString();
            tvaParTaux[key] = (tvaParTaux[key] || 0) + montantTVA;
        });

        totalHT = Math.round(totalHT * 100) / 100;
        totalTVA = Math.round(totalTVA * 100) / 100;
        const totalTTC = Math.round((totalHT + totalTVA) * 100) / 100;

        document.getElementById('total-ht').textContent = fmtEuro(totalHT);
        document.getElementById('total-tva').textContent = fmtEuro(totalTVA);
        document.getElementById('total-ttc').textContent = fmtEuro(totalTTC);

        const detail = document.getElementById('detail-tva');
        detail.innerHTML = '';
        Object.keys(tvaParTaux).sort((a,b) => parseFloat(a)-parseFloat(b)).forEach(taux => {
            const montant = Math.round(tvaParTaux[taux] * 100) / 100;
            const div = document.createElement('div');
            div.className = 'summary-row sub';
            const l = document.createElement('span');
            l.textContent = 'TVA ' + parseFloat(taux).toFixed(1).replace('.', ',') + ' %';
            const v = document.createElement('span');
            v.className = 'num';
            v.textContent = fmtEuro(montant);
            div.appendChild(l);
            div.appendChild(v);
            detail.appendChild(div);
        });
    }

    function bindRow(tr) {
        const select = tr.querySelector('select');
        const inputQte = tr.querySelector('input[name="quantite[]"]');
        const inputReduction = tr.querySelector('input[name="reduction[]"]');
        const btnSuppr = tr.querySelector('.btn-supprimer');
        const btnLoupe = tr.querySelector('.btn-loupe');

        select.addEventListener('change', recalculer);
        inputQte.addEventListener('input', recalculer);
        inputReduction.addEventListener('input', recalculer);
        btnSuppr.addEventListener('click', () => {
            const lignes = tableBody.querySelectorAll('tr.ligne');
            if (lignes.length <= 1) {
                select.value = '';
                inputQte.value = '1';
                inputReduction.value = '0';
                recalculer();
                return;
            }
            tr.remove();
            recalculer();
        });

        btnLoupe.addEventListener('click', () => {
            const recherche = window.prompt('Rechercher un article (code ou nom) :', '');
            if (!recherche) return;
            const query = recherche.trim().toUpperCase();

            // Recherche dans code ET désignation
            const matches = [];
            for (let i = 0; i < select.options.length; i++) {
                const opt = select.options[i];
                if (!opt.value) continue;
                
                const code = (opt.value || '').toUpperCase();
                const texte = (opt.textContent || '').toUpperCase();
                
                if (code.includes(query) || texte.includes(query)) {
                    matches.push({
                        value: opt.value,
                        text: opt.textContent,
                        exactCodeMatch: code === query,
                        startsWithCode: code.startsWith(query),
                        startsWithText: texte.startsWith(query)
                    });
                }
            }

            if (matches.length === 0) {
                window.alert('Aucun article trouvé pour : ' + recherche);
                return;
            }

            // Tri : exact > commence par code > commence par texte > contient
            matches.sort((a, b) => {
                if (a.exactCodeMatch !== b.exactCodeMatch) return b.exactCodeMatch ? 1 : -1;
                if (a.startsWithCode !== b.startsWithCode) return b.startsWithCode ? 1 : -1;
                if (a.startsWithText !== b.startsWithText) return b.startsWithText ? 1 : -1;
                return 0;
            });

            if (matches.length === 1) {
                select.value = matches[0].value;
            } else {
                // Plusieurs résultats : afficher la liste
                const liste = matches.slice(0, 10).map((m, idx) => (idx + 1) + '. ' + m.text).join('\n');
                const choix = window.prompt(
                    '' + matches.length + ' article(s) trouvé(s) :\n\n' + liste + '\n\nEntrez le numéro (1-' + Math.min(10, matches.length) + ') :',
                    '1'
                );
                const num = parseInt(choix || '0', 10);
                if (num > 0 && num <= matches.length) {
                    select.value = matches[num - 1].value;
                }
            }
            recalculer();
        });
    }

    btnAjouter.addEventListener('click', () => {
        const first = tableBody.querySelector('tr.ligne');
        const clone = first.cloneNode(true);
        const select = clone.querySelector('select');
        const inputQte = clone.querySelector('input[name="quantite[]"]');
        const inputReduction = clone.querySelector('input[name="reduction[]"]');
        select.value = '';
        inputQte.value = '1';
        inputReduction.value = '0';
        clone.querySelector('.forfait').textContent = '—';
        clone.querySelector('.tva').textContent = '—';
        clone.querySelector('.montant').textContent = '0,00 €';

        tableBody.appendChild(clone);
        bindRow(clone);
        recalculer();
        
        // Scroll vers la nouvelle ligne et focus sur le select
        clone.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => select.focus(), 300);
    });

    tableBody.querySelectorAll('tr.ligne').forEach(bindRow);
    recalculer();
})();
</script>

<?php appFooter(); ?>
