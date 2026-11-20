<?php
// Gestion des actions sur les clients (création, modification, etc.)

declare(strict_types=1);

require_once __DIR__ . '/ressources_communes.php';
require_once __DIR__ . '/partials/layout.php';

$pdo = getConnexion();

$action = strtolower(getGetString('action', ''));

if ($action === 'create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        appHeader('Créer un client', 'Erreur', 'clients', [['label' => 'Clients', 'href' => 'recherche_client.php']]);
        afficheErreur("Méthode non autorisée.");
        appFooter();
        exit;
    }
    
    // Récupération des données du formulaire
    $nom = trim((string)($_POST['nom'] ?? ''));
    $prenom = trim((string)($_POST['prenom'] ?? ''));
    $forme = (int)($_POST['forme'] ?? 0);
    $dateNaissance = trim((string)($_POST['date_naissance'] ?? ''));
    $numeroSecu = trim((string)($_POST['numero_secu_social'] ?? ''));
    $codePays = trim((string)($_POST['code_pays'] ?? ''));
    $codeMotif = (int)($_POST['code_motif'] ?? 0);
    
    // Validation des champs obligatoires
    $errors = [];
    if ($nom === '') {
        $errors[] = "Le nom est obligatoire.";
    }
    if ($prenom === '') {
        $errors[] = "Le prénom est obligatoire.";
    }
    if (!mb_check_encoding($nom . $prenom . $numeroSecu, 'UTF-8')) {
        $errors[] = "Caractères invalides dans le formulaire (encodage non UTF-8).";
    }
    if (mb_strlen($nom, 'UTF-8') > 50 || mb_strlen($prenom, 'UTF-8') > 50) {
        $errors[] = "Le nom et le prénom ne doivent pas dépasser 50 caractères.";
    }
    // On vérifie que les codes existent vraiment en base (sinon erreur de clé étrangère)
    if (!array_key_exists($forme, getFormesJuridiques($pdo))) {
        $errors[] = "La forme juridique est obligatoire.";
    }
    $dateOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateNaissance, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])
        && $dateNaissance <= date('Y-m-d');
    if (!$dateOk) {
        $errors[] = "La date de naissance est obligatoire, doit être valide et ne peut pas être dans le futur.";
    }
    if (!array_key_exists($codePays, getPays($pdo))) {
        $errors[] = "Le pays est obligatoire.";
    }
    if (!array_key_exists($codeMotif, getMotifs($pdo))) {
        $errors[] = "Le motif est obligatoire.";
    }
    if ($numeroSecu !== '' && !preg_match('/^[0-9A-Ba-b ]{1,20}$/', $numeroSecu)) {
        $errors[] = "Le numéro de sécurité sociale ne doit contenir que des chiffres (20 caractères max).";
    }

    if (!empty($errors)) {
        http_response_code(400);
        appHeader('Créer un client', 'Erreur', 'clients', [['label' => 'Clients', 'href' => 'recherche_client.php']]);
        // afficheErreur échappe le HTML : on sépare donc les erreurs par un espace (et non <br>)
        afficheErreur(implode(' ', $errors));
        echo '<div class="btn-row"><a class="btn" href="recherche_client.php">Retour</a></div>';
        appFooter();
        exit;
    }
    
    try {
        // Génération automatique du code client (on prend le max + 1)
        $stmt = $pdo->query("SELECT MAX(code) AS max_code FROM clients");
        $row = $stmt->fetch();
        $newCode = ($row && $row['max_code'] !== null) ? (int)$row['max_code'] + 1 : 1;
        
        // La date d'entrée est fixée à aujourd'hui
        $dateEntree = date('Y-m-d');
        
        // Insertion en base
        $sql = "
            INSERT INTO clients 
                (code, nom, prenom, forme, date_naissance, numero_secu_social, code_pays, date_entree, code_motif)
            VALUES 
                (:code, :nom, :prenom, :forme, :date_naissance, :numero_secu, :code_pays, :date_entree, :code_motif)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':code' => $newCode,
            ':nom' => $nom,
            ':prenom' => $prenom,
            ':forme' => $forme,
            ':date_naissance' => $dateNaissance,
            ':numero_secu' => $numeroSecu !== '' ? $numeroSecu : null,
            ':code_pays' => $codePays,
            ':date_entree' => $dateEntree,
            ':code_motif' => $codeMotif,
        ]);
        
        // Redirection vers la fiche du client créé
        header('Location: fiche_client.php?id=' . $newCode . '&created=1');
        exit;
    } catch (Throwable $t) {
        http_response_code(500);
        appHeader('Créer un client', 'Erreur', 'clients', [['label' => 'Clients', 'href' => 'recherche_client.php']]);
        afficheErreur("Impossible de créer le client.", $t);
        echo '<div class="btn-row"><a class="btn" href="recherche_client.php">Retour</a></div>';
        appFooter();
        exit;
    }
}

http_response_code(400);
appHeader('Action client', 'Erreur', 'clients', [['label' => 'Clients', 'href' => 'recherche_client.php']]);
afficheErreur("Action inconnue.");
appFooter();
