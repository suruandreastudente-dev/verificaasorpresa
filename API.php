<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

// Funzione helper per la connessione al DB
function getDB() {
    $host = '127.0.0.1';
    $db   = 'slim_framework';
    $user = 'root'; // Cambia con il tuo utente
    $pass = '';     // Cambia con la tua password
    $charset = 'utf8mb4';
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    return new PDO($dsn, $user, $pass, $options);
}

// Funzione helper per la paginazione e risposta JSON
function executeQueryAndRespond(Request $request, Response $response, string $sql, array $params = []) {
    $queryParams = $request->getQueryParams();
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 100;
    $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;

    $sql .= " LIMIT :limit OFFSET :offset";
    
    $pdo = getDB();
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $results = $stmt->fetchAll();

    $response->getBody()->write(json_encode([
        'success' => true,
        'limit' => $limit,
        'offset' => $offset,
        'data' => $results
    ]));
    
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
}

// 1. Trovare i pnome dei pezzi per cui esiste un qualche fornitore
$app->get('/pezzi-forniti', function (Request $request, Response $response) {
    $sql = "SELECT DISTINCT P.pnome 
            FROM Pezzi P 
            JOIN Catalogo C ON P.pid = C.pid";
    return executeQueryAndRespond($request, $response, $sql);
});

// 2. Trovare gli fnome dei fornitori che forniscono ogni pezzo
$app->get('/fornitori-tutti-pezzi', function (Request $request, Response $response) {
    // Divisione relazionale
    $sql = "SELECT F.fnome 
            FROM Fornitori F 
            JOIN Catalogo C ON F.fid = C.fid 
            GROUP BY F.fid, F.fnome 
            HAVING COUNT(DISTINCT C.pid) = (SELECT COUNT(*) FROM Pezzi)";
    return executeQueryAndRespond($request, $response, $sql);
});

// 3. Trovare gli fnome dei fornitori che forniscono tutti i pezzi di un certo colore (es. rossi)
// Endpoint: /fornitori-tutti-pezzi-colore/rosso
$app->get('/fornitori-tutti-pezzi-colore/{colore}', function (Request $request, Response $response, array $args) {
    $colore = $args['colore'];
    $sql = "SELECT F.fnome 
            FROM Fornitori F 
            WHERE NOT EXISTS (
                SELECT P.pid FROM Pezzi P 
                WHERE P.colore = :colore AND NOT EXISTS (
                    SELECT C.pid FROM Catalogo C 
                    WHERE C.fid = F.fid AND C.pid = P.pid
                )
            )";
    return executeQueryAndRespond($request, $response, $sql, [':colore' => $colore]);
});

// 4. Trovare i pnome dei pezzi forniti da un fornitore specifico e da nessun altro
// Endpoint: /pezzi-esclusivi/Acme
$app->get('/pezzi-esclusivi/{nome_fornitore}', function (Request $request, Response $response, array $args) {
    $nome = $args['nome_fornitore'];
    $sql = "SELECT P.pnome 
            FROM Pezzi P 
            JOIN Catalogo C ON P.pid = C.pid 
            JOIN Fornitori F ON C.fid = F.fid 
            WHERE F.fnome = :nome AND P.pid NOT IN (
                SELECT C2.pid 
                FROM Catalogo C2 
                JOIN Fornitori F2 ON C2.fid = F2.fid 
                WHERE F2.fnome != :nome
            )";
    return executeQueryAndRespond($request, $response, $sql, [':nome' => $nome]);
});

// 5. Trovare i fid dei fornitori che ricaricano su alcuni pezzi più del costo medio di quel pezzo
$app->get('/fornitori-sopra-media', function (Request $request, Response $response) {
    $sql = "SELECT DISTINCT C.fid 
            FROM Catalogo C 
            WHERE C.costo > (
                SELECT AVG(C2.costo) 
                FROM Catalogo C2 
                WHERE C2.pid = C.pid
            )";
    return executeQueryAndRespond($request, $response, $sql);
});

// 6. Per ciascun pezzo, trovare gli fnome dei fornitori che ricaricano di più su quel pezzo
$app->get('/fornitori-costo-massimo', function (Request $request, Response $response) {
    $sql = "SELECT P.pnome, F.fnome, C.costo 
            FROM Pezzi P 
            JOIN Catalogo C ON P.pid = C.pid 
            JOIN Fornitori F ON C.fid = F.fid 
            WHERE C.costo = (
                SELECT MAX(C2.costo) 
                FROM Catalogo C2 
                WHERE C2.pid = C.pid
            )";
    return executeQueryAndRespond($request, $response, $sql);
});

// 7. Trovare i fid dei fornitori che forniscono solo pezzi di un colore
// Endpoint: /fornitori-solo-colore/rosso
$app->get('/fornitori-solo-colore/{colore}', function (Request $request, Response $response, array $args) {
    $colore = $args['colore'];
    $sql = "SELECT C.fid 
            FROM Catalogo C 
            JOIN Pezzi P ON C.pid = P.pid 
            GROUP BY C.fid 
            HAVING SUM(CASE WHEN P.colore != :colore THEN 1 ELSE 0 END) = 0";
    return executeQueryAndRespond($request, $response, $sql, [':colore' => $colore]);
});

// 8. Trovare i fid dei fornitori che forniscono un pezzo colore A e un pezzo colore B
// Esempio: /fornitori-due-colori?c1=rosso&c2=verde
$app->get('/fornitori-due-colori', function (Request $request, Response $response) {
    $queryParams = $request->getQueryParams();
    $c1 = $queryParams['c1'] ?? 'rosso';
    $c2 = $queryParams['c2'] ?? 'verde';
    
    $sql = "SELECT C.fid 
            FROM Catalogo C 
            JOIN Pezzi P ON C.pid = P.pid 
            WHERE P.colore IN (:c1, :c2) 
            GROUP BY C.fid 
            HAVING COUNT(DISTINCT P.colore) = 2";
    return executeQueryAndRespond($request, $response, $sql, [':c1' => $c1, ':c2' => $c2]);
});

// 9. Trovare i fid dei fornitori che forniscono un pezzo colore A o uno colore B
// Esempio: /fornitori-colore-or?c1=rosso&c2=verde
$app->get('/fornitori-colore-or', function (Request $request, Response $response) {
    $queryParams = $request->getQueryParams();
    $c1 = $queryParams['c1'] ?? 'rosso';
    $c2 = $queryParams['c2'] ?? 'verde';

    $sql = "SELECT DISTINCT C.fid 
            FROM Catalogo C 
            JOIN Pezzi P ON C.pid = P.pid 
            WHERE P.colore IN (:c1, :c2)";
    return executeQueryAndRespond($request, $response, $sql, [':c1' => $c1, ':c2' => $c2]);
});

// 10. Trovare i pid dei pezzi forniti da almeno X fornitori
// Esempio: /pezzi-multi-fornitori?min=2
$app->get('/pezzi-multi-fornitori', function (Request $request, Response $response) {
    $queryParams = $request->getQueryParams();
    $min = isset($queryParams['min']) ? (int)$queryParams['min'] : 2;

    $sql = "SELECT pid 
            FROM Catalogo 
            GROUP BY pid 
            HAVING COUNT(DISTINCT fid) >= :min";
            
    // Usiamo bind separato perché il limite/minimo sono interi
    $stmt = getDB()->prepare($sql . " LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':min', $min, PDO::PARAM_INT);
    
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 100;
    $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $results = $stmt->fetchAll();

    $response->getBody()->write(json_encode([
        'success' => true,
        'limit' => $limit,
        'offset' => $offset,
        'data' => $results
    ]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

$app->run();