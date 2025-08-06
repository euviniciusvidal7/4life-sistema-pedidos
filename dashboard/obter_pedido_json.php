<?php
// Configurações e funções comuns
require_once 'config.php';
require_once 'funcoes.php';

// Verificar se a requisição é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Obter os dados do pedido
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

// Extrair nome e data
$nome = $data['nome'] ?? '';
$data_pedido = $data['data'] ?? '';

if (empty($nome) || empty($data_pedido)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'error' => 'Nome e data são obrigatórios']);
    exit;
}

// Caminhos possíveis para o arquivo index.json
$caminhos_possiveis = [
    '../../2025/pedidos/index.json',
    $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos/index.json',
    dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos/index.json',
];

// Buscar o arquivo index.json
$index_path = null;
foreach ($caminhos_possiveis as $caminho) {
    if (file_exists($caminho)) {
        $index_path = $caminho;
        break;
    }
}

if ($index_path === null) {
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['success' => false, 'error' => 'Arquivo index.json não encontrado']);
    exit;
}

// Ler o conteúdo do arquivo index.json
$index_content = file_get_contents($index_path);
if ($index_content === false) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => false, 'error' => 'Erro ao ler o arquivo index.json']);
    exit;
}

// Decodificar o conteúdo do arquivo
$pedidos = json_decode($index_content, true);
if (!$pedidos) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => false, 'error' => 'Erro ao decodificar o arquivo index.json']);
    exit;
}

// Buscar o pedido pelo nome e data
$arquivo = null;
foreach ($pedidos as $index => $pedido) {
    if (
        (isset($pedido['NOME']) && strtolower($pedido['NOME']) === strtolower($nome)) &&
        (isset($pedido['DATA']) && $pedido['DATA'] === $data_pedido)
    ) {
        // Gerar o nome do arquivo
        $arquivo = $index . '.json';
        break;
    }
}

if ($arquivo === null) {
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['success' => false, 'error' => 'Pedido não encontrado']);
    exit;
}

// Retornar o nome do arquivo
echo json_encode(['success' => true, 'arquivo' => $arquivo]);
exit; 