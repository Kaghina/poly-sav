<?php
// Modèle de configuration locale.
// 1. Copiez ce fichier en "config.local.php" (même dossier).
// 2. Renseignez vos valeurs. config.local.php n'est jamais envoyé sur Git.

// --- Envoi des mails (obligatoire pour la fonction "Envoyer par mail") ---
// Avec Gmail : activez la validation en 2 étapes puis créez un "mot de passe d'application"
// (Compte Google > Sécurité > Mots de passe des applications).
const SMTP_USER = 'votre.adresse@gmail.com';
const SMTP_PASS = 'motdepasseapplication';
const SMTP_FROM_EMAIL = 'votre.adresse@gmail.com';
// const SMTP_HOST = 'smtp.gmail.com';
// const SMTP_PORT = 587;
// const SMTP_FROM_NAME = 'POLY SAV';

// --- Base de données (optionnel : détectée automatiquement) ---
// const DB_PORT = 3307;              // 3306 MySQL, 3307 MariaDB (WAMP), 8889 MAMP
// const LOCAL_ROOT_USER = 'root';    // compte administrateur MySQL
// const LOCAL_ROOT_PASS = '';
