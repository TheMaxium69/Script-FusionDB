<?php

// --- CONFIGURATION DU FICHIER ---
$localDumpFile = 'gamenium_dump.sql'; // Votre fichier dump local
$outputSqlFile = 'gamenium_prod_sync.sql'; // Le fichier SQL généré pour la production

// --- CONFIGURATION DE LA BASE DE DONNÉES DE PRODUCTION ---
// Décommentez et remplissez ces informations si vous voulez que le script exécute directement les requêtes.
// Sinon, vous utiliserez le fichier SQL généré manuellement.
// $dbHost = 'votre_host_prod';
// $dbName = 'votre_nom_base_prod';
// $dbUser = 'votre_utilisateur_prod';
// $dbPass = 'votre_mot_de_passe_prod';

// Liste des colonnes de votre table 'game' (sauf 'id').
// Assurez-vous que cette liste est COMPLÈTE et dans le BON ORDRE.
// Vous pouvez trouver cet ordre en regardant le `CREATE TABLE` dans votre gamenium_dump.sql
$columnsToUpdate = [
    '`id_giant_bomb`',
    '`guid`',
    '`name`',
    '`aliases`',
    '`api_detail_url`',
    '`date_added`',
    '`date_last_updated`',
    '`deck`',
    '`description`',
    '`expected_release_day`',
    '`expected_release_month`',
    '`expected_release_year`',
    '`image`',
    '`image_tags`',
    '`number_of_user_reviews`',
    '`original_game_rating`',
    '`original_release_date`',
    '`platforms`',
    '`site_detail_url`',
    '`expected_release_quarter`'
];

// --- FONCTIONS DU SCRIPT ---

/**
 * Transforme une ligne INSERT standard en INSERT ... ON DUPLICATE KEY UPDATE.
 * Gère les guillemets et les virgules pour les valeurs.
 */
function transformInsertStatement($line, $columnsToUpdate) {
    // 1. Isoler les noms de colonnes et les valeurs
    // Regex pour capturer les noms de colonnes et les valeurs d'un INSERT
    preg_match('/INSERT INTO `game` \((.*?)\) VALUES \((.*?)\);/', $line, $matches);

    if (count($matches) < 3) {
        // La ligne n'est pas un INSERT attendu, la retourner telle quelle ou l'ignorer
        return $line;
    }

    $originalCols = explode(', ', $matches[1]); // Ex: `id`, `id_giant_bomb`, `guid`...
    $originalValues = splitValuesPreservingQuotes($matches[2]); // Ex: 1, '3030-1', '3030-1'...

    // Vérifier si l'ID est la première colonne et la supprimer
    if (isset($originalCols[0]) && $originalCols[0] === '`id`') {
        array_shift($originalCols); // Supprime `id` de la liste des colonnes
        array_shift($originalValues); // Supprime la valeur correspondante (l'ID local)
    } else {
        // Si `id` n'est pas la première colonne ou n'est pas trouvé, c'est une erreur dans le format attendu.
        // Vous pouvez choisir de loguer l'erreur ou de sauter cette ligne.
        echo "Avertissement: Ligne d'INSERT au format inattendu (colonne `id` manquante ou non au début).\n";
        return $line; // Retourne la ligne originale pour éviter une erreur SQL
    }

    // Reconstruction de l'INSERT sans la colonne `id` et sa valeur
    $newColsString = implode(', ', $originalCols);
    $newValuesString = implode(', ', $originalValues);

    $baseInsert = "INSERT INTO `game` ({$newColsString}) VALUES ({$newValuesString})";

    // Construction de la clause ON DUPLICATE KEY UPDATE
    $updateParts = [];
    // On commence à l'index 1 car id_giant_bomb est la première colonne dans $columnsToUpdate
    // et elle est la clé unique, donc on ne la met pas à jour avec VALUES().
    for ($i = 1; $i < count($columnsToUpdate); $i++) {
        $colName = $columnsToUpdate[$i]; // Ex: `guid`, `name`
        $updateParts[] = "{$colName} = VALUES({$colName})";
    }
    $onDuplicateKeyUpdate = "ON DUPLICATE KEY UPDATE\n  " . implode(",\n  ", $updateParts);

    return $baseInsert . "\n" . $onDuplicateKeyUpdate . ";";
}

/**
 * Fonction pour séparer les valeurs d'une chaîne, en préservant les virgules
 * à l'intérieur des chaînes de caractères guillemetées.
 * Très simpliste, peut nécessiter une amélioration pour des cas complexes d'échappement.
 */
function splitValuesPreservingQuotes($s) {
    $values = [];
    $inQuote = false;
    $currentValue = '';
    $len = strlen($s);

    for ($i = 0; $i < $len; $i++) {
        $char = $s[$i];
        if ($char == "'") {
            $inQuote = !$inQuote;
            $currentValue .= $char; // Garder le guillemet pour la valeur
        } elseif ($char == ',' && !$inQuote) {
            $values[] = trim($currentValue);
            $currentValue = '';
        } else {
            $currentValue .= $char;
        }
    }
    $values[] = trim($currentValue); // Ajouter la dernière valeur
    return $values;
}


// --- PROCESSUS PRINCIPAL ---

echo "Début du traitement...\n";

// Ouvrir les fichiers
$inputFile = fopen($localDumpFile, 'r');
if (!$inputFile) {
    die("Erreur: Impossible d'ouvrir le fichier source '$localDumpFile'\n");
}

$outputFile = fopen($outputSqlFile, 'w');
if (!$outputFile) {
    die("Erreur: Impossible de créer le fichier de sortie '$outputSqlFile'\n");
}

$lineCount = 0;
$insertCount = 0;

while (($line = fgets($inputFile)) !== false) {
    $lineCount++;
    $line = trim($line); // Enlever les espaces en début/fin de ligne

    // Si la ligne commence par INSERT INTO `game`, la transformer
    if (str_starts_with($line, 'INSERT INTO `game`')) {
        $transformedLine = transformInsertStatement($line, $columnsToUpdate);
        fwrite($outputFile, $transformedLine . "\n");
        $insertCount++;
    } else {
        // Pour toutes les autres lignes (CREATE TABLE, ALTER TABLE, commentaires, etc.), les copier telles quelles
        fwrite($outputFile, $line . "\n");
    }

    if ($lineCount % 10000 === 0) {
        echo "Traitement de $lineCount lignes...\n";
    }
}

fclose($inputFile);
fclose($outputFile);

echo "Transformation terminée. $insertCount insertions transformées. Le fichier modifié est prêt : $outputSqlFile\n";

// --- EXÉCUTION SUR LA BASE DE DONNÉES DE PRODUCTION (OPTIONNEL) ---
// Décommentez cette section si vous avez configuré les variables $dbHost, etc.
/*
if (isset($dbHost)) {
    echo "Tentative de connexion à la base de données de production...\n";
    try {
        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "Connexion à la base de données de production réussie.\n";

        echo "Exécution des requêtes depuis '$outputSqlFile' sur la production...\n";
        $sqlCommands = file_get_contents($outputSqlFile);

        // Exécuter toutes les requêtes. Attention aux très très gros fichiers SQL.
        // Pour les très gros fichiers, il serait mieux de lire le fichier et d'exécuter requête par requête.
        // Pour ce cas, on suppose que PDO peut gérer le fichier entier.
        $stmt = $pdo->prepare($sqlCommands);
        $stmt->execute();

        // Pour un très gros fichier, une approche ligne par ligne serait:
        // $stmt = null;
        // $sqlLines = file($outputSqlFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // $currentCommand = '';
        // foreach ($sqlLines as $line) {
        //     $currentCommand .= $line;
        //     if (str_ends_with(trim($line), ';')) { // Commande terminée par un point-virgule
        //         try {
        //             $pdo->exec($currentCommand); // Exécute la commande
        //         } catch (PDOException $e) {
        //             echo "Erreur lors de l'exécution: " . $e->getMessage() . "\n";
        //             echo "Commande: " . substr($currentCommand, 0, 200) . "...\n";
        //             // Continuer ou arrêter ici
        //         }
        //         $currentCommand = ''; // Réinitialise pour la prochaine commande
        //     }
        // }


        echo "Toutes les requêtes ont été exécutées sur la base de production.\n";

    } catch (PDOException $e) {
        die("Erreur de connexion ou d'exécution SQL: " . $e->getMessage() . "\n");
    } finally {
        $pdo = null; // Fermer la connexion
    }
} else {
    echo "\nLes informations de connexion à la base de données de production ne sont pas configurées.\n";
    echo "Veuillez remplir les variables \$dbHost, \$dbName, \$dbUser, \$dbPass pour l'exécution directe.\n";
    echo "Vous pouvez importer manuellement le fichier généré : $outputSqlFile\n";
}
*/

echo "\nLe processus est terminé. Le fichier de synchronisation prêt est : $outputSqlFile\n";
echo "Vérifiez ce fichier avant de l'importer dans votre base de production.\n";
echo "Vous pouvez l'importer manuellement avec un outil comme phpMyAdmin ou Dbeaver, ou en ligne de commande avec 'mysql -u ... -p ... < gamenium_prod_sync.sql'\n";

?>