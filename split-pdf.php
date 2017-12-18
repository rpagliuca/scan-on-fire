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

$extraEvinceParameters = '';
if (!empty($CFG->extraEvinceParameters)) {
    $extraEvinceParameters = $CFG->extraEvinceParameters;
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
            $cmd = "evince --page-index=$firstPage $extraEvinceParameters in/$date.pdf >/dev/null 2>&1 &"; 
            exec($cmd);
            $foundUc = findSimilarUCS($lastUc);
            if ($foundUc) {
                $lastUc = $foundUc['uc'];
                $nome = $foundUc['nome'];
            } else {
                $lastUc = '___ ' . $lastUc;
                $nome = "NÃO ENCONTRADO - REVISAR";
            }
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
        $ucCopy[$i] = '@';
        for ($j = 0; $j <= strlen($uc); $j++) {
            $ucCopy2 = $ucCopy;
            $ucCopy2[$j] = '@';
            $similares[] = "$ucCopy2";
        }
    }

    $similaresInClause = implode($similares, "|");

    $similaresInClause = str_replace('@', '.+', $similaresInClause);

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
        ORDER BY PF.nome
    ";

    $results = $db->query($sql);

    $counter = 0;
    $options = [];
    foreach ($results as $row) {
        $counter++;
        echo "[$counter] Similar UC: {$row['valor_identificador_uc']} - {$row['nome']} - {$row['cpf']} \n";
        $options[$counter] = [
            'uc' => $row['valor_identificador_uc'],
            'nome' => $row['nome'],
            'cpf' => $row['cpf']
        ];
        $cpf = preg_replace('[^0-9]', '', $options[$counter]['cpf']);
        if (strlen($cpf) === 11) {
            $cpf = substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
        }
        $options[$counter]['cpf'] = $cpf;
    }

    $confirmationNeeded = true;
    while ($confirmationNeeded) {
        $option = readline("Choose an option (0 to skip): ");
        $preview = null;
        if ($option == '0') {
            $return = false;
            $preview = 'skip';
        } elseif (!empty($options[$option]['nome'])) {
            $return = $options[$option];
            $preview = "{$options[$option]['nome']} - CPF {$options[$option]['cpf']} - UC {$options[$option]['uc']}";
        }
        if ($preview) {
            $confirmationOption = readline("Confirm option {$preview} (yes/no): [yes] ");
            if ($confirmationOption == "" or $confirmationOption == "yes") {
                $confirmationNeeded = false;
            }
        }
    }
    return $return;
}
