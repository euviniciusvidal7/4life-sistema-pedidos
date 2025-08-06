<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// Incluir configurações
require_once 'config.php';

// Simular dados de teste
$test_data = [
    'arquivo' => 'test_pedido.json',
    'tipo' => 'processar',
    'tratamento' => '1 Mês',
    'rastreio' => '',
    'processado' => true
];

echo "<h1>Teste de Processamento de Pedidos</h1>";
echo "<h2>Dados de Teste:</h2>";
echo "<pre>" . json_encode($test_data, JSON_PRETTY_PRINT) . "</pre>";

// Verificar configurações do Trello
echo "<h2>Configurações do Trello:</h2>";
echo "<ul>";
echo "<li>TRELLO_API_KEY: " . (defined('TRELLO_API_KEY') && !empty(TRELLO_API_KEY) ? 'Definida' : 'Não definida ou vazia') . "</li>";
echo "<li>TRELLO_TOKEN: " . (defined('TRELLO_TOKEN') && !empty(TRELLO_TOKEN) ? 'Definido' : 'Não definido ou vazio') . "</li>";
echo "<li>TRELLO_BOARD_ID: " . (defined('TRELLO_BOARD_ID') && !empty(TRELLO_BOARD_ID) ? TRELLO_BOARD_ID : 'Não definido ou vazio') . "</li>";
echo "<li>TRELLO_LIST_ID_TRANSITO: " . (defined('TRELLO_LIST_ID_TRANSITO') && !empty(TRELLO_LIST_ID_TRANSITO) ? TRELLO_LIST_ID_TRANSITO : 'Não definido ou vazio') . "</li>";
echo "</ul>";

// Verificar caminhos de pedidos
echo "<h2>Verificação de Caminhos:</h2>";
$pedidos_paths = [
    '../2025/pedidos',
    $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos',
    dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos',
    '../../2025/pedidos'
];

foreach ($pedidos_paths as $path) {
    echo "<li>$path: " . (is_dir($path) ? 'Existe' : 'Não existe') . "</li>";
}

// Teste de requisição para processar_pedido.php
echo "<h2>Teste de Requisição:</h2>";

$url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/processar_pedido.php';
echo "<p>URL de teste: $url</p>";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Cookie: ' . $_SERVER['HTTP_COOKIE']
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);

curl_close($ch);

echo "<h3>Resposta do Servidor:</h3>";
echo "<p>Código HTTP: $http_code</p>";
if ($curl_error) {
    echo "<p>Erro cURL: $curl_error</p>";
}
echo "<pre>$response</pre>";

// Verificar se a resposta é JSON válido
$json_response = json_decode($response, true);
if ($json_response !== null) {
    echo "<h3>Resposta Decodificada:</h3>";
    echo "<pre>" . json_encode($json_response, JSON_PRETTY_PRINT) . "</pre>";
} else {
    echo "<p>A resposta não é um JSON válido.</p>";
}

echo '<br><a href="pedidos.php">Voltar para Pedidos</a>';
?> 