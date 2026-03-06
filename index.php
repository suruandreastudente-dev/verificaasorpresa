<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

// Diciamo a Slim che il progetto vive in questa sottocartella!
$app->setBasePath('/verificaasorpresa');

$app->addRoutingMiddleware();
// ... il resto del codice ...


//Inpaginazione in HTML delle query
$app->get('/', function (Request $request, Response $response) {

    $html = <<<HTML
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Gestione Fornitori e Pezzi</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background-color: #f8f9fa; padding-top: 2rem; }
            .container { max-width: 900px; }
            #dynamic-params { display: none; margin-top: 1rem; }
        </style>
    </head>
    <body>

    <div class="container bg-white p-4 rounded shadow">
        <h1 class="mb-4 text-primary">Interrogazioni Database Fornitori</h1>

        <div class="mb-3">
            <label for="querySelect" class="form-label fw-bold">Seleziona l'interrogazione:</label>
            <select id="querySelect" class="form-select">
                <option value="" disabled selected>-- Scegli una query --</option>
                <option value="/pezzi-forniti" data-params="none">1. Pezzi con almeno un fornitore</option>
                <option value="/fornitori-tutti-pezzi" data-params="none">2. Fornitori che forniscono TUTTI i pezzi</option>
                <option value="/fornitori-tutti-pezzi-colore/{param1}" data-params="colore">3. Fornitori che forniscono tutti i pezzi di un colore</option>
                <option value="/pezzi-esclusivi/{param1}" data-params="fornitore">4. Pezzi esclusivi di un fornitore</option>
                <option value="/fornitori-sopra-media" data-params="none">5. Fornitori con ricarico sopra la media</option>
                <option value="/fornitori-costo-massimo" data-params="none">6. Fornitori con il ricarico massimo per pezzo</option>
                <option value="/fornitori-solo-colore/{param1}" data-params="colore">7. Fornitori che forniscono SOLO pezzi di un colore</option>
                <option value="/fornitori-due-colori?c1={param1}&c2={param2}" data-params="due_colori">8. Fornitori che forniscono pezzi di due colori specifici</option>
                <option value="/fornitori-colore-or?c1={param1}&c2={param2}" data-params="due_colori">9. Fornitori che forniscono pezzi colore A o colore B</option>
                <option value="/pezzi-multi-fornitori?min={param1}" data-params="numero">10. Pezzi forniti da almeno X fornitori</option>
            </select>
        </div>

        <div id="dynamic-params" class="bg-light p-3 rounded border mb-3">
            <div id="param-colore" class="param-group d-none">
                <label class="form-label">Colore:</label>
                <input type="text" id="input-colore" class="form-control" placeholder="Es. rosso">
            </div>
            <div id="param-fornitore" class="param-group d-none">
                <label class="form-label">Nome Fornitore:</label>
                <input type="text" id="input-fornitore" class="form-control" placeholder="Es. Acme">
            </div>
            <div id="param-due_colori" class="param-group d-none row">
                <div class="col">
                    <label class="form-label">Colore 1:</label>
                    <input type="text" id="input-c1" class="form-control" placeholder="Es. rosso">
                </div>
                <div class="col">
                    <label class="form-label">Colore 2:</label>
                    <input type="text" id="input-c2" class="form-control" placeholder="Es. verde">
                </div>
            </div>
            <div id="param-numero" class="param-group d-none">
                <label class="form-label">Numero minimo di fornitori:</label>
                <input type="number" id="input-numero" class="form-control" value="2" min="1">
            </div>
        </div>

        <button id="btnEsegui" class="btn btn-primary w-100 mb-4" disabled>Esegui Query</button>

        <div id="results-container" class="d-none">
            <h4 class="mb-3">Risultati:</h4>
            <div class="table-responsive">
                <table class="table table-striped table-bordered" id="resultsTable">
                    <thead class="table-dark" id="tableHead"></thead>
                    <tbody id="tableBody"></tbody>
                </table>
            </div>
            <p id="no-results" class="text-muted d-none">Nessun risultato trovato.</p>
        </div>
    </div>

    <script>
        
        const baseUrl = '/verificaasorpresa/API.php'; 
        const select = document.getElementById('querySelect');
        const dynamicParams = document.getElementById('dynamic-params');
        const btnEsegui = document.getElementById('btnEsegui');
        const paramGroups = document.querySelectorAll('.param-group');

        select.addEventListener('change', function() {
            btnEsegui.disabled = false;
            paramGroups.forEach(el => el.classList.add('d-none'));
            dynamicParams.style.display = 'none';

            const paramType = this.options[this.selectedIndex].dataset.params;
            
            if (paramType !== 'none') {
                dynamicParams.style.display = 'block';
                document.getElementById('param-' + paramType).classList.remove('d-none');
            }
        });

        btnEsegui.addEventListener('click', async () => {
            let endpoint = select.value;
            const paramType = select.options[select.selectedIndex].dataset.params;

            if (paramType === 'colore') endpoint = endpoint.replace('{param1}', document.getElementById('input-colore').value);
            if (paramType === 'fornitore') endpoint = endpoint.replace('{param1}', document.getElementById('input-fornitore').value);
            if (paramType === 'numero') endpoint = endpoint.replace('{param1}', document.getElementById('input-numero').value);
            if (paramType === 'due_colori') {
                endpoint = endpoint.replace('{param1}', document.getElementById('input-c1').value);
                endpoint = endpoint.replace('{param2}', document.getElementById('input-c2').value);
            }

            try {
                console.log(baseUrl + endpoint);
                const response = await fetch(baseUrl + endpoint);
                const json = await response.json();
                renderTable(json.data);
            } catch (error) {
                console.error("Errore di connessione", error);
                alert("Errore nell'esecuzione della query.");
            }
        });

        function renderTable(data) {
            document.getElementById('results-container').classList.remove('d-none');
            const thead = document.getElementById('tableHead');
            const tbody = document.getElementById('tableBody');
            const noResults = document.getElementById('no-results');
            const table = document.getElementById('resultsTable');

            thead.innerHTML = '';
            tbody.innerHTML = '';

            if (!data || data.length === 0) {
                table.classList.add('d-none');
                noResults.classList.remove('d-none');
                return;
            }

            table.classList.remove('d-none');
            noResults.classList.add('d-none');

            const keys = Object.keys(data[0]);
            const trHead = document.createElement('tr');
            keys.forEach(key => {
                const th = document.createElement('th');
                th.textContent = key.toUpperCase();
                trHead.appendChild(th);
            });
            thead.appendChild(trHead);

            data.forEach(row => {
                const tr = document.createElement('tr');
                keys.forEach(key => {
                    const td = document.createElement('td');
                    td.textContent = row[key];
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
        }
    </script>
    </body>
    </html>
HTML;

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html')->withStatus(200);
});

$app->run();