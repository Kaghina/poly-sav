<?php
// Composant commun : tableau des documents GED (+ fenêtres "envoyer par mail" et "renommer")
// Utilisé par documents_client.php (GED d'un client) et recherche_documents.php (recherche globale).

declare(strict_types=1);

function renderDocTable(array $docs, bool $showClient): void
{
    ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Document</th>
                <?php if ($showClient): ?><th>Client</th><?php endif; ?>
                <th class="hide-sm">Type</th>
                <th class="hide-sm r">Taille</th>
                <th class="hide-sm">Ajouté le</th>
                <th class="r">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($docs as $d): ?>
                <?php
                $id = (int)$d['id'];
                $mime = (string)$d['mime'];
                $ico = docIcon($mime);
                $contenu = (string)$d['doc_contenu'];
                ?>
                <tr>
                    <td>
                        <div class="cell-main">
                            <span class="file-icon <?= e($ico) ?>"><?= icon($ico, 18) ?></span>
                            <div style="min-width:0;">
                                <div style="display:flex;align-items:center;gap:4px;">
                                    <a class="cell-title" href="document_action.php?action=open&amp;id=<?= $id ?>" target="_blank" rel="noopener" style="word-break:break-all;"><?= e((string)$d['original_name']) ?></a>
                                    <button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-open="renameModal-<?= $id ?>" title="Renommer"><?= icon('edit', 14) ?></button>
                                </div>
                                <div class="cell-sub">
                                    <?php if ($contenu !== '' && $contenu !== '-'): ?><?= e($contenu) ?><?php endif; ?>
                                    <?php if ($d['devis_id'] !== null): ?>
                                        <?= ($contenu !== '' && $contenu !== '-') ? ' · ' : '' ?><a href="devis_client.php?id_devis=<?= e((string)$d['devis_id']) ?>">Voir le devis</a>
                                        <?php if (!empty($d['devis_statut'])): ?>
                                            <span class="badge <?= $d['devis_statut'] === 'TERMINE' ? 'badge-success' : 'badge-warning' ?>" style="height:20px;font-size:11px;margin-left:4px;"><?= $d['devis_statut'] === 'TERMINE' ? 'Terminé' : 'En cours' ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <?php if ($showClient): ?>
                        <td>
                            <a class="cell-title" href="fiche_client.php?id=<?= e((string)$d['client_code']) ?>"><?= e(nomClient($d, 'client_nom', 'client_prenom')) ?></a>
                            <div class="cell-sub">Client n° <?= e((string)$d['client_code']) ?></div>
                        </td>
                    <?php endif; ?>
                    <td class="hide-sm">
                        <span class="badge badge-brand no-dot"><?= e((string)$d['doc_type']) ?></span>
                        <div class="cell-sub" style="margin-top:3px;"><?= e((string)$d['doc_nature']) ?></div>
                    </td>
                    <td class="hide-sm r num muted"><?= e(fmtTaille((int)$d['size_bytes'])) ?></td>
                    <td class="hide-sm muted nowrap"><?= e(fmtDateTimeFr((string)$d['created_at'])) ?></td>
                    <td>
                        <div class="actions">
                            <a class="btn btn-sm btn-icon" href="document_action.php?action=open&amp;id=<?= $id ?>" target="_blank" rel="noopener" title="Ouvrir"><?= icon('external', 15) ?></a>
                            <a class="btn btn-sm btn-icon" href="document_action.php?action=download&amp;id=<?= $id ?>" title="Télécharger l'original"><?= icon('download', 15) ?></a>
                            <a class="btn btn-sm" href="document_action.php?action=pdf&amp;id=<?= $id ?>" title="Télécharger en PDF"><?= icon('pdf', 15) ?> PDF</a>
                            <button type="button" class="btn btn-sm btn-icon" data-modal-open="mailModal-<?= $id ?>" title="Envoyer par mail (PDF)"><?= icon('mail', 15) ?></button>
                            <form method="post" class="inline" action="document_action.php?action=delete&amp;id=<?= $id ?>" onsubmit="return confirm('Supprimer définitivement ce document ?');">
                                <button type="submit" class="btn btn-sm btn-icon btn-danger" title="Supprimer"><?= icon('trash', 15) ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    // Fenêtres modales (en dehors du tableau)
    foreach ($docs as $d) {
        $id = (int)$d['id'];
        echo mailModal(
            'mailModal-' . $id,
            'document_action.php?action=mail&id=' . $id,
            (string)$d['doc_type'] . ' — client n° ' . (string)$d['client_code'],
            pdfFileName((string)$d['original_name'])
        );
        echo renameModal('renameModal-' . $id, $id, (string)$d['original_name']);
    }
}
