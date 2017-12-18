#!/usr/bin/php
<?php

$config = 'config.json';
if (file_exists($config)) {
    $metaobject = json_decode(file_get_contents($config));
    if (json_last_error() == JSON_ERROR_NONE) {
        $CFG = $metaobject;
    } else {
        echo "Error loading config.json\n";
        exit(1);
    }
}

function connectReadWrite() {
    global $CFG;
    try {
        if (isset($CFG->database, $CFG->database->application, $CFG->database->application->host, $CFG->database->application->schema, $CFG->database->application->user, $CFG->database->application->password, $CFG->database->application->port, $CFG->database->application->charset)) {
            $db = new PDO("mysql:host={$CFG->database->application->host};dbname={$CFG->database->application->schema};port={$CFG->database->application->port}", $CFG->database->application->user, $CFG->database->application->password, array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $CFG->database->application->charset));
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $db;
        } else {
            throw new Exception("Database connection not found");
        }
    } catch (PDOException $e) {
        echo $e->getMessage();
        exit;
    }
}

if (empty($argv[1])) {
    echo "Informe a data como parâmetro.\n";
    exit(1);
}

$date = $argv[1];

echo "Verificando existência de arquivos de entrada...\n";

if (!file_exists("in/$date.pdf")) {
    echo "Arquivo in/$date.pdf não encontrado.\n";
    exit(1);
}

if (!file_exists("in/$date.csv")) {
    echo "Arquivo in/$date.csv não encontrado.\n";
    exit(1);
}

$db = connectReadWrite();

$firstPage = 0;
$lastPage = 0;
$lastUc = '';

mkdir("out/$date");

foreach (file("in/$date.csv") as $line) {
    $values = explode(',', $line);
    $lastPage = trim($values[0]) - 1;
    if ($firstPage > 0) {
        echo "Splitting UC " . $lastUc . " - Pages: " . $firstPage . " to " . $lastPage . "...\n";
        $range = implode(range($firstPage, $lastPage), " ");

        $results = $db->query("
            SELECT 
                UC.valor_identificador_uc, PF.nome
            FROM
                EnergyConsumerUnit UC
            LEFT JOIN
                PartnerPf PF ON UC.id_parceiro_beneficiado = PF.id_parceiro
            WHERE
                UC.valor_identificador_uc = '$lastUc'
                    AND UC.id_concessionaria = 12
        ");

        $nome = null;
        foreach ($results as $row) {
            if (!empty($row['nome'])) {
                $nome = $row['nome'];
            }
        }

        if ($nome === null) {
            echo "UC $lastUc is invalid.\n";
            echo "Opening PDF on page $firstPage...\n";
            exec("evince --page-label=$firstPage in/$date.pdf >/dev/null 2>&1 &");
            $foundUc = findSimilarUCS($lastUc);
            $lastUc = $foundUc['uc'];
            $nome = $foundUc['nome'];
        }

        exec("pdftk in/$date.pdf cat $range output \"out/$date/$lastUc - $nome.pdf\"");
        echo "Finished splitting UC " . $lastUc . " - Pages: " . $firstPage . " to " . $lastPage . "...\n";
    }
    $firstPage = trim($values[0]);
    $lastUc = trim($values[1]);
}

function findSimilarUCS($uc)
{
    global $db;

    echo "Searching for similar UCs...\n";

    $similares = [];
    for ($i = 0; $i <= strlen($uc); $i++) {
        $ucCopy = $uc;
        $ucCopy[$i] = '.';
        for ($j = 0; $j <= strlen($uc); $j++) {
            $ucCopy2 = $ucCopy;
            $ucCopy2[$j] = '.';
            $similares[] = "$ucCopy2";
        }
    }

    $similaresInClause = implode($similares, "|");
    
    $sql = "
        SELECT 
            UC.valor_identificador_uc, PF.nome, PF.cpf
        FROM
            EnergyConsumerUnit UC
        LEFT JOIN
            PartnerPf PF ON UC.id_parceiro_beneficiado = PF.id_parceiro
        WHERE
            UC.valor_identificador_uc REGEXP '$similaresInClause'
                AND UC.id_concessionaria = 12
    ";

    $results = $db->query($sql);

    $counter = 0;
    $options = [];
    foreach ($results as $row) {
        $counter++;
        echo "[$counter] Similar UC: {$row['valor_identificador_uc']} - {$row['nome']} - {$row['cpf']} \n";
        $options[$counter] = [
            'uc' => $row['valor_identificador_uc'],
            'nome' => $row['nome']
        ];
    }
    $option = readline("Choose an option: ");
    return $options[$option];
}
