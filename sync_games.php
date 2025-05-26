<?php

// --- CONFIGURATION DU FICHIER ---
$localDumpFile = 'gamenium_game.sql'; // Votre fichier dump local
$outputSqlFile = 'gamenium_prod_sync.sql'; // Le fichier SQL généré pour la production

// --- CONFIGURATION DE LA BASE DE DONNÉES DE PRODUCTION (OPTIONNEL) ---
// Décommentez et remplissez ces informations si vous voulez que le script exécute directement les requêtes.
// Sinon, vous utiliserez le fichier SQL généré manuellement.
// $dbHost = 'votre_host_prod';
// $dbName = 'votre_nom_base_prod';
// $dbUser = 'votre_utilisateur_prod';
// $dbPass = 'votre_mot_de_passe_prod';

// Liste des colonnes de votre table 'game' (sauf 'id').
// Cette liste est cruciale. Elle a été extraite de votre `gamenium_dump.sql`.
// L'ordre doit être EXACTEMENT celui de votre `CREATE TABLE` dans le dump, SANS la colonne `id`.
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

/**
 * Une fonction plus robuste pour diviser une chaîne de valeurs SQL en un tableau.
 * Gère les guillemets (simples et doubles) et les parenthèses (pour les JSON imbriqués).
 * @param string $s La chaîne de valeurs à diviser (ex: "1, 'value', '{\"key\":\"val\"}'")
 * @return array Un tableau de valeurs individuelles.
 */
function splitSqlValues($s) {
    $values = [];
    $currentValue = '';
    $inQuote = false; // Vrai si on est à l'intérieur d'une chaîne guillemetée
    $quoteChar = ''; // Garde le type de guillemet (' ou ")
    $parenLevel = 0; // Niveau d'imbrication des parenthèses (pour les JSON/arrays)
    $i = 0;
    $len = strlen($s);

    while ($i < $len) {
        $char = $s[$i];

        if ($inQuote) {
            if ($char === $quoteChar) {
                // Si c'est un guillemet simple, vérifie si c'est un échappement ('')
                if ($quoteChar === "'" && ($i + 1 < $len && $s[$i+1] === "'")) {
                    $currentValue .= "''"; // Garde les deux guillemets pour la valeur
                    $i++; // Avance l'index pour sauter le deuxième guillemet de l'échappement
                } else {
                    $inQuote = false;
                    $quoteChar = '';
                    $currentValue .= $char; // Ajoute le guillemet de fermeture
                }
            } else {
                $currentValue .= $char;
            }
        } else {
            if ($char === "'" || $char === '"') {
                $inQuote = true;
                $quoteChar = $char;
                $currentValue .= $char;
            } elseif ($char === '(') {
                $parenLevel++;
                $currentValue .= $char;
            } elseif ($char === ')') {
                $parenLevel--;
                $currentValue .= $char;
            } elseif ($char === ',' && $parenLevel === 0) {
                // On a trouvé une virgule de séparation de haut niveau et n'est pas dans un guillemet ni dans des parenthèses imbriquées
                $values[] = trim($currentValue);
                $currentValue = '';
            } else {
                $currentValue .= $char;
            }
        }
        $i++;
    }
    $values[] = trim($currentValue); // Ajouter la dernière valeur
    return $values;
}


// --- PROCESSUS PRINCIPAL ---

echo "Début du traitement...\n";

// Lire tout le contenu du fichier dump.
$dumpContent = file_get_contents($localDumpFile);
if ($dumpContent === false) {
    die("Erreur: Impossible de lire le fichier source '$localDumpFile'\n");
}

$outputContent = ''; // Contenu du nouveau fichier SQL
$insertCount = 0;

// Regex pour trouver tous les blocs INSERT INTO `game`
// Capture: 1) le nom de la table (toujours `game`), 2) la liste des colonnes, 3) tous les groupes VALUES
$insertRegex = '/(INSERT INTO `game` \((.*?)\) VALUES\s*)((?:\s*\(.*?\),?)+);/s';

// Utilise preg_replace_callback pour trouver et remplacer chaque INSERT
$outputContent = preg_replace_callback($insertRegex, function($matches) use (&$insertCount, $columnsToUpdate) {
    // $matches[0] : La correspondance complète de l'INSERT (y compris INSERT INTO `game` (...), VALUES (...), (...) ;)
    // $matches[1] : "INSERT INTO `game` (...)"
    // $matches[2] : La liste des colonnes (ex: `id`, `id_giant_bomb`, ...)
    // $matches[3] : Le bloc de valeurs (ex: (1, 'val1'), (2, 'val2'))

    $originalColsString = $matches[2]; // `id`, `id_giant_bomb`, `guid`...
    $valuesBlock = $matches[3]; // (1, 1, '3030-1', ...), (2, 2, '3030-2', ...)

    $originalCols = array_map('trim', explode(',', $originalColsString));

    // On s'attend à ce que 'id' soit la première colonne
    if (isset($originalCols[0]) && $originalCols[0] === '`id`') {
        array_shift($originalCols); // Supprime `id` de la liste des colonnes pour les nouveaux INSERTs
    } else {
        // Si le format n'est pas celui attendu, on retourne l'INSERT original non modifié.
        // Cela devrait couvrir le cas où l'erreur précédente s'est produite.
        error_log("Avertissement: Ligne d'INSERT au format inattendu (colonne `id` manquante ou non au début dans la liste des colonnes). L'INSERT original est conservé.\nMatching statement: " . substr($matches[0], 0, 500) . "...\n");
        return $matches[0];
    }

    $transformedInserts = [];

    // Diviser le bloc de valeurs en tuples individuels (..., ..., ...), (..., ..., ...)
    preg_match_all('/\((?>[^()]+|(?R))*\)/', $valuesBlock, $valueTuplesMatches);

    foreach ($valueTuplesMatches[0] as $tupleString) {
        // Supprimer les parenthèses extérieures
        $tupleContent = substr($tupleString, 1, -1);

        // Diviser les valeurs à l'intérieur du tuple en utilisant la fonction robuste
        $individualValues = splitSqlValues($tupleContent);

        // Supprimer la première valeur (l'ID local)
        if (isset($individualValues[0])) {
            array_shift($individualValues);
        } else {
            error_log("Avertissement: Tuple de valeurs vide ou mal formé: '{$tupleString}'. Ce tuple sera ignoré.\n");
            continue; // Passe au tuple suivant
        }

        // Reconstruire la chaîne de valeurs pour le nouvel INSERT
        $newValuesString = implode(', ', $individualValues);

        // Construire l'instruction INSERT INTO pour une seule ligne
        $newColsString = implode(', ', $originalCols);
        $baseInsert = "INSERT INTO `game` ({$newColsString}) VALUES ({$newValuesString})";

        // Construire la clause ON DUPLICATE KEY UPDATE
        $updateParts = [];
        // Commencer à l'index 1 car id_giant_bomb est la première colonne dans $columnsToUpdate
        // et elle est la clé unique, donc on ne la met pas à jour avec VALUES().
        for ($j = 1; $j < count($columnsToUpdate); $j++) {
            $colName = $columnsToUpdate[$j];
            $updateParts[] = "{$colName} = VALUES({$colName})";
        }
        $onDuplicateKeyUpdate = "ON DUPLICATE KEY UPDATE\n  " . implode(",\n  ", $updateParts);

        $transformedInserts[] = $baseInsert . "\n" . $onDuplicateKeyUpdate . ";";
        $insertCount++;
    }

    // Retourne toutes les requêtes transformées, séparées par un retour à la ligne
    return implode("\n", $transformedInserts) . "\n"; // Ajouter un retour à la ligne final pour la propreté

}, $dumpContent);


// Écrire le contenu transformé dans le fichier de sortie
if (file_put_contents($outputSqlFile, $outputContent) === false) {
    die("Erreur: Impossible d'écrire le fichier de sortie '$outputSqlFile'\n");
}

echo "Transformation terminée. $insertCount jeux transformés en requêtes ON DUPLICATE KEY UPDATE. Le fichier modifié est prêt : $outputSqlFile\n";

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

        // Lire le fichier SQL transformé et l'exécuter commande par commande
        $sqlCommands = file($outputSqlFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $currentCommand = '';
        $executedCount = 0;
        foreach ($sqlCommands as $line) {
            $currentCommand .= $line . "\n"; // Ajouter le retour à la ligne
            if (str_ends_with(trim($line), ';')) { // La commande se termine par un point-virgule
                try {
                    $pdo->exec($currentCommand);
                    $executedCount++;
                    if ($executedCount % 1000 == 0) {
                        echo "  $executedCount requêtes exécutées...\n";
                    }
                } catch (PDOException $e) {
                    echo "Erreur lors de l'exécution: " . $e->getMessage() . "\n";
                    echo "Commande problématique: " . substr($currentCommand, 0, 500) . "...\n";
                    // Vous pouvez choisir de die() ici ou de continuer
                }
                $currentCommand = ''; // Réinitialiser la commande
            }
        }
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