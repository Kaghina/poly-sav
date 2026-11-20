<?php
// Gabarit commun : en-tête (logo + navigation), titre de page, fil d'Ariane, pied de page, icônes

declare(strict_types=1);

const APP_NAME = 'POLY SAV';
const APP_TAGLINE = 'Gestion clients, devis & documents';

// Jeu d'icônes unique pour tout le site (tracés au format "Lucide", trait de 2px)
function icon(string $name, int $size = 18, string $class = ''): string
{
    static $paths = [
        'users'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user'       => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'user-plus'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>',
        'file'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'file-text'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/>',
        'file-plus'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/>',
        'folder'     => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'search'     => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'refresh'    => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'plus'       => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'calendar'   => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'globe'      => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'tag'        => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
        'briefcase'  => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'clock'      => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'check'      => '<polyline points="20 6 9 17 4 12"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'x'          => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'edit'       => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'trash'      => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'download'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'upload'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'external'   => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        'mail'       => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'printer'    => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'eye'        => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'copy'       => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'euro'       => '<path d="M4 10h12"/><path d="M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'alert'      => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'info'       => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'image'      => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'hash'       => '<line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>',
        'send'       => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'shield'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'layers'     => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'hard-drive' => '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/>',
        'pdf'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15h6"/><path d="M12 12v6"/>',
    ];
    $inner = $paths[$name] ?? $paths['file'];
    return '<svg class="icon ' . e($class) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

function appActive(string $current, string $expected): string
{
    return $current === $expected ? ' class="active" aria-current="page"' : '';
}

/**
 * Affiche le haut de page : barre de navigation, fil d'Ariane, titre et sous-titre.
 * $breadcrumbs : [['label' => ..., 'href' => ...], ...]
 * $actionsHtml : boutons affichés à droite du titre (optionnel)
 */
function appHeader(string $title, string $subtitle, string $currentPage, array $breadcrumbs = [], string $extraHeadHtml = '', string $actionsHtml = ''): void
{
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/app.css">
    <?= $extraHeadHtml ?>
</head>
<body>
<header class="topbar no-print">
    <div class="topbar-inner">
        <a class="brand" href="recherche_client.php" aria-label="<?= e(APP_NAME) ?> — accueil">
            <span class="brand-mark"><img src="assets/img/logo.png" alt=""></span>
            <span class="brand-text">
                <span class="brand-name"><?= e(APP_NAME) ?></span>
                <span class="brand-tagline"><?= e(APP_TAGLINE) ?></span>
            </span>
        </a>
        <nav class="nav" aria-label="Navigation principale">
            <a href="recherche_client.php"<?= appActive($currentPage, 'clients') ?>><?= icon('users') ?><span>Clients</span></a>
            <a href="recherche_documents.php"<?= appActive($currentPage, 'docs') ?>><?= icon('folder') ?><span>Documents</span></a>
        </nav>
    </div>
</header>

<main class="container">
    <div class="page-head no-print">
        <?php if (!empty($breadcrumbs)): ?>
            <nav class="breadcrumbs" aria-label="Fil d'Ariane">
                <?php foreach ($breadcrumbs as $i => $bc): ?>
                    <?php if ($i > 0): ?><?= icon('chevron-right', 14, 'sep') ?><?php endif; ?>
                    <?php
                    $label = trim((string)$bc['label']) ?: 'Lien';
                    $href = trim((string)$bc['href']) ?: '#';
                    $isLast = $i === count($breadcrumbs) - 1;
                    ?>
                    <?php if ($isLast): ?>
                        <span aria-current="page"><?= e($label) ?></span>
                    <?php else: ?>
                        <a href="<?= e($href) ?>"><?= e($label) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <div class="page-title-row">
            <div>
                <h1 class="page-title"><?= e($title) ?></h1>
                <?php if ($subtitle !== ''): ?><p class="page-subtitle"><?= e($subtitle) ?></p><?php endif; ?>
            </div>
            <?php if ($actionsHtml !== ''): ?><div class="page-actions"><?= $actionsHtml ?></div><?php endif; ?>
        </div>
    </div>
    <?php
}

function appFooter(): void
{
    ?>
</main>
<footer class="site-footer no-print">
    <div class="footer-inner">
        <span class="footer-brand"><img src="assets/img/logo.png" alt=""> <?= e(APP_NAME) ?></span>
        <span>1 rue de la MIAGE, 75000 Paris · 01 02 03 04 05</span>
        <span>© <?= date('Y') ?> — TP Programmation Web</span>
    </div>
</footer>
<script>
// Fenêtres modales : ouverture / fermeture (bouton, clic sur le fond, touche Échap)
function openModal(id) {
    var m = document.getElementById(id);
    if (!m) return;
    m.classList.add('active');
    document.body.style.overflow = 'hidden';
    var f = m.querySelector('[autofocus], input:not([type=hidden]), select, textarea');
    if (f) setTimeout(function () { f.focus(); if (f.select && f.type === 'text') f.select(); }, 60);
}
function closeModal(id) {
    var m = document.getElementById(id);
    if (m) m.classList.remove('active');
    if (!document.querySelector('.modal-overlay.active')) document.body.style.overflow = '';
}
document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.classList && t.classList.contains('modal-overlay')) closeModal(t.id);
    var opener = t.closest ? t.closest('[data-modal-open]') : null;
    if (opener) openModal(opener.getAttribute('data-modal-open'));
    var closer = t.closest ? t.closest('[data-modal-close]') : null;
    if (closer) closeModal(closer.closest('.modal-overlay').id);
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(function (m) { closeModal(m.id); });
});
// Copie dans le presse-papier (ex : référence de devis)
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(function () {
        var old = btn.innerHTML;
        btn.classList.add('copied');
        btn.textContent = 'Copié';
        setTimeout(function () { btn.innerHTML = old; btn.classList.remove('copied'); }, 1400);
    });
}
</script>
</body>
</html>
    <?php
}

// Affiche un message de succès homogène
function afficheSucces(string $message): void
{
    echo '<div class="alert alert-success" role="status">' . icon('check-circle', 20) . '<div><strong>Succès</strong><div>' . e($message) . '</div></div></div>';
}

// Affiche un avertissement homogène
function afficheAvertissement(string $message): void
{
    echo '<div class="alert alert-warning" role="status">' . icon('alert', 20) . '<div><strong>Attention</strong><div>' . e($message) . '</div></div></div>';
}

// Fenêtre modale réutilisable pour envoyer un document par mail (pièce jointe PDF)
function mailModal(string $id, string $action, string $docLabel, string $pdfName, string $subjectPlaceholder = 'Objet (optionnel)'): string
{
    return '<div class="modal-overlay" id="' . e($id) . '">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="' . e($id) . '-t">
    <div class="modal-header">
      <h3 id="' . e($id) . '-t">' . icon('mail', 20) . ' Envoyer par mail</h3>
      <button type="button" class="modal-close" data-modal-close title="Fermer">' . icon('x', 18) . '</button>
    </div>
    <form method="post" action="' . e($action) . '">
      <div class="modal-body">
        <div class="attachment">' . icon('pdf', 22) . '<div><strong>' . e($pdfName) . '</strong><span>' . e($docLabel) . ' — joint au format PDF</span></div></div>
        <div class="field"><label for="' . e($id) . '-to">Destinataire *</label>
          <input type="email" id="' . e($id) . '-to" name="to" required placeholder="client@exemple.com"></div>
        <div class="field"><label for="' . e($id) . '-subject">Objet</label>
          <input type="text" id="' . e($id) . '-subject" name="subject" placeholder="' . e($subjectPlaceholder) . '"></div>
        <div class="field"><label for="' . e($id) . '-body">Message</label>
          <textarea id="' . e($id) . '-body" name="body" rows="4" placeholder="Optionnel : un message par défaut est utilisé"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" data-modal-close>Annuler</button>
        <button type="submit" class="btn btn-primary">' . icon('send', 16) . ' Envoyer</button>
      </div>
    </form>
  </div>
</div>';
}

// Fenêtre modale réutilisable pour renommer un document
function renameModal(string $id, int $docId, string $currentName): string
{
    return '<div class="modal-overlay" id="' . e($id) . '">
  <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="' . e($id) . '-t">
    <div class="modal-header">
      <h3 id="' . e($id) . '-t">' . icon('edit', 20) . ' Renommer le document</h3>
      <button type="button" class="modal-close" data-modal-close title="Fermer">' . icon('x', 18) . '</button>
    </div>
    <form method="post" action="document_action.php?action=rename&amp;id=' . $docId . '">
      <div class="modal-body">
        <div class="field"><label for="' . e($id) . '-name">Nouveau nom du fichier</label>
          <input type="text" id="' . e($id) . '-name" name="new_name" value="' . e($currentName) . '" required maxlength="180"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" data-modal-close>Annuler</button>
        <button type="submit" class="btn btn-primary">' . icon('check', 16) . ' Enregistrer</button>
      </div>
    </form>
  </div>
</div>';
}

// Icône adaptée au type MIME d'un document
function docIcon(string $mime): string
{
    if ($mime === 'application/pdf') {
        return 'pdf';
    }
    if (str_starts_with($mime, 'image/')) {
        return 'image';
    }
    return 'file-text';
}

// Initiales d'un client pour son avatar (ex : "CASSEL", "Vincent" => "VC")
function initiales(string $nom, string $prenom): string
{
    return mb_strtoupper(mb_substr($prenom, 0, 1, 'UTF-8') . mb_substr($nom, 0, 1, 'UTF-8'), 'UTF-8');
}

// Nom complet affiché partout de la même façon : "Prénom NOM"
function nomClient(array $c, string $kNom = 'nom', string $kPrenom = 'prenom'): string
{
    return trim((string)$c[$kPrenom] . ' ' . mb_strtoupper((string)$c[$kNom], 'UTF-8'));
}

// Taille lisible (Ko / Mo)
function fmtTaille(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
    }
    return number_format(max($bytes, 1) / 1024, 1, ',', ' ') . ' Ko';
}
