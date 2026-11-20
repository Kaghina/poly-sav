-- Script d'initialisation : création de la BDD + tables + données de test

-- Création de l'utilisateur MySQL
-- Normalement fait automatiquement par ressources_communes.php, mais disponible ici si besoin
CREATE USER IF NOT EXISTS 'user1'@'localhost' IDENTIFIED BY 'hcetylop';
GRANT ALL PRIVILEGES ON *.* TO 'user1'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;

-- Suppression et recréation de la base (pour repartir de zéro)
DROP DATABASE IF EXISTS poly_php;
CREATE DATABASE poly_php CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE poly_php;

-- Table des pays
CREATE TABLE pays (
    code CHAR(2) PRIMARY KEY,
    libelle VARCHAR(50) NOT NULL
);

INSERT INTO pays (code, libelle) VALUES
('FR', 'France'),
('BE', 'Belgique'),
('MA', 'Maroc'),
('TN', 'Tunisie'),
('DZ', 'Algérie');

-- Table des motifs de contact
CREATE TABLE motifs (
    code INT PRIMARY KEY,
    libelle VARCHAR(50) NOT NULL
);

INSERT INTO motifs (code, libelle) VALUES
(1, 'Téléphone'),
(2, 'Mail'),
(3, 'Web');

-- Table des formes juridiques d'entreprise
CREATE TABLE formes_juridiques (
    code INT PRIMARY KEY,
    libelle VARCHAR(100) NOT NULL
);

INSERT INTO formes_juridiques (code, libelle) VALUES
(1, 'Micro-Entreprise'),
(2, 'Entreprise Individuelle'),
(3, 'Entreprise Individuelle à responsabilité limitée'),
(4, 'Entreprise unipersonnelle à responsabilité limitée'),
(5, 'Société par actions simplifiée unipersonnelle'),
(6, 'Société à responsabilité limitée'),
(7, 'Société par actions simplifiée'),
(8, 'Société anonyme');

-- Table des clients
CREATE TABLE clients (
    code INT PRIMARY KEY,
    nom VARCHAR(50) NOT NULL,
    prenom VARCHAR(50) NOT NULL,
    forme INT NOT NULL,
    date_naissance DATE NOT NULL,
    numero_secu_social VARCHAR(20),
    code_pays CHAR(2) NOT NULL,
    date_entree DATE NOT NULL,
    code_motif INT NOT NULL,
    CONSTRAINT fk_client_forme FOREIGN KEY (forme) REFERENCES formes_juridiques(code),
    CONSTRAINT fk_client_pays FOREIGN KEY (code_pays) REFERENCES pays(code),
    CONSTRAINT fk_client_motif FOREIGN KEY (code_motif) REFERENCES motifs(code)
);

-- Quelques clients de test
INSERT INTO clients VALUES
(1, 'SY', 'Omar', 1, '1978-01-20', '178017830240455', 'FR', '2023-02-01', 1),
(2, 'DEPARDIEU', 'Gérard', 8, '1948-12-27', '148127504406759', 'FR', '2023-04-05', 2),
(3, 'DUJARDIN', 'Jean', 2, '1972-06-19', '172065903800855', 'FR', '2023-06-12', 3),
(4, 'RENO', 'Jean', 7, '1948-07-30', NULL, 'MA', '2023-08-18', 1),
(5, 'COTILLARD', 'Marion', 3, '1975-09-30', '275097503200542', 'FR', '2023-09-26', 1),
(6, 'CASSEL', 'Vincent', 6, '1966-11-23', '166117500600711', 'FR', '2023-01-01', 3),
(7, 'GREEN', 'Eva', 4, '1980-06-17', '280067500400733', 'FR', '2023-11-15', 2),
(8, 'EFIRA', 'Virginie', 5, '1977-05-05', NULL, 'BE', '2023-10-30', 2);

-- Table des unités de mesure
CREATE TABLE unites (
    code CHAR(1) PRIMARY KEY,
    libelle VARCHAR(20) NOT NULL
);

INSERT INTO unites VALUES
('S', 'Secondes'),
('M', 'Minutes'),
('H', 'Heures');

-- Table des taux de TVA
CREATE TABLE tva (
    code INT PRIMARY KEY,
    taux DECIMAL(4,1) NOT NULL
);

INSERT INTO tva VALUES
(0, 0.0),
(1, 20.0),
(2, 10.0),
(3, 5.5);

-- Table des articles/prestations vendus
CREATE TABLE articles (
    code VARCHAR(5) PRIMARY KEY,
    designation VARCHAR(100) NOT NULL,
    unite CHAR(1) NOT NULL,
    forfait_ht DECIMAL(8,2) NOT NULL,
    tva INT NOT NULL,
    CONSTRAINT fk_article_unite FOREIGN KEY (unite) REFERENCES unites(code),
    CONSTRAINT fk_article_tva FOREIGN KEY (tva) REFERENCES tva(code)
);

INSERT INTO articles VALUES
('A1', 'Intervention à l’heure', 'H', 72.00, 1),
('A2', 'Intervention à la minute', 'M', 1.20, 2),
('A3', 'Intervention à la seconde', 'S', 0.02, 3);

-- Table des devis (en-tête uniquement, les lignes sont dans devis_lignes)
CREATE TABLE devis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_code INT NOT NULL,
    reference VARCHAR(50) NOT NULL,
    date_devis DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('EN_COURS', 'TERMINE') NOT NULL DEFAULT 'EN_COURS',
    total_ht DECIMAL(10,2) NOT NULL,
    total_tva DECIMAL(10,2) NOT NULL,
    total_ttc DECIMAL(10,2) NOT NULL,
    societe_nom VARCHAR(100) NOT NULL,
    societe_adresse VARCHAR(255) NOT NULL,
    societe_tel VARCHAR(30) NOT NULL,
    societe_email VARCHAR(120) NOT NULL,
    CONSTRAINT fk_devis_client FOREIGN KEY (client_code) REFERENCES clients(code),
    CONSTRAINT uq_devis_reference UNIQUE (reference),
    INDEX idx_devis_client_date (client_code, date_devis),
    INDEX idx_devis_statut (statut)
);

-- Table des lignes de devis (détail des articles vendus)
CREATE TABLE devis_lignes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    devis_id INT NOT NULL,
    article_code VARCHAR(5) NOT NULL,
    designation VARCHAR(100) NOT NULL,
    unite CHAR(1) NOT NULL,
    quantite INT NOT NULL,
    forfait_ht DECIMAL(10,2) NOT NULL,
    reduction_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,  -- Réduction en % (ex: 10.50 = 10,5%)
    taux_tva DECIMAL(4,1) NOT NULL,
    montant_ht DECIMAL(10,2) NOT NULL,  -- Montant après application de la réduction
    CONSTRAINT fk_ligne_devis FOREIGN KEY (devis_id) REFERENCES devis(id) ON DELETE CASCADE,
    CONSTRAINT fk_ligne_article FOREIGN KEY (article_code) REFERENCES articles(code),
    INDEX idx_lignes_devis (devis_id)
);

-- Table du détail TVA par taux pour chaque devis
CREATE TABLE devis_tva (
    devis_id INT NOT NULL,
    taux_tva DECIMAL(4,1) NOT NULL,
    montant_tva DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (devis_id, taux_tva),
    CONSTRAINT fk_devis_tva_devis FOREIGN KEY (devis_id) REFERENCES devis(id) ON DELETE CASCADE
);

-- Table GED : stockage des documents clients
-- Les fichiers sont sauvegardés physiquement dans _storage/
-- Anti-doublon : le hash SHA-256 empêche de sauvegarder 2 fois le même fichier
CREATE TABLE ged_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_code INT NOT NULL,
    devis_id INT NULL,

    -- Champs de catégorisation pour la recherche
    doc_type VARCHAR(30) NOT NULL,     -- Type : DEVIS, CNI, EMAIL, AUTRE...
    doc_nature VARCHAR(50) NOT NULL,   -- Nature : PDF, IMAGE, TEXTE...
    doc_contenu VARCHAR(120) NOT NULL, -- Description libre (ex: "copie recto-verso")

    -- Infos sur le fichier
    original_name VARCHAR(255) NOT NULL,  -- Nom d'origine
    stored_name VARCHAR(255) NOT NULL,    -- Nom sur le serveur
    relative_path VARCHAR(500) NOT NULL,  -- Chemin relatif
    mime VARCHAR(120) NOT NULL,           -- Type MIME
    size_bytes INT NOT NULL,              -- Taille en octets
    sha256 CHAR(64) NOT NULL,             -- Hash pour anti-doublon
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ged_docs_client FOREIGN KEY (client_code) REFERENCES clients(code),
    CONSTRAINT fk_ged_docs_devis FOREIGN KEY (devis_id) REFERENCES devis(id) ON DELETE SET NULL,

    UNIQUE KEY uq_ged_client_sha256 (client_code, sha256),  -- Pas de doublon par client
    UNIQUE KEY uq_ged_devis (devis_id),                      -- Un seul doc par devis
    INDEX idx_ged_client_date (client_code, created_at),
    INDEX idx_ged_type (doc_type),
    INDEX idx_ged_nature (doc_nature),
    INDEX idx_ged_contenu (doc_contenu)
);
