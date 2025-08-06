<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'error' => 'Usuário não autenticado']));
}

// Verificar se a requisição é GET e contém o parâmetro necessário
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['pedido_id']) || empty($_GET['pedido_id'])) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'ID do pedido não fornecido']));
}

// Sanitizar o ID do pedido
$pedido_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['pedido_id']);
$pedido_id = preg_replace('/\.json$/', '', $pedido_id);

// Tentar diferentes caminhos para o diretório de anotações
$anotacoes = [];
$found = false;
$error_messages = [];
$path_used = '';

$possible_paths = [
    './anotacoes',                  // Relativo ao script atual (nome corrigido)
    __DIR__ . '/anotacoes',         // Caminho absoluto na mesma pasta do script
    '/home/u180568174/domains/suplements.tech/public_html/dashboard/anotacoes' // Caminho absoluto completo
];

foreach ($possible_paths as $diretorio_anotacoes) {
    $arquivo_anotacoes = "{$diretorio_anotacoes}/{$pedido_id}.json";
    
    if (file_exists($arquivo_anotacoes)) {
        try {
            // Carregar o conteúdo do arquivo
            $conteudo = @file_get_contents($arquivo_anotacoes);
            if ($conteudo === false) {
                $error_messages[] = "Não foi possível ler o arquivo: {$arquivo_anotacoes}";
                continue;
            }
            
            $dados = json_decode($conteudo, true);
            
            // Verificar se o JSON foi decodificado corretamente
            if ($dados === null) {
                $error_messages[] = "Arquivo existe mas não é um JSON válido em: {$arquivo_anotacoes}";
                continue;
            }
            
            $anotacoes = $dados;
            $path_used = $diretorio_anotacoes;
            $found = true;
            break;
        } catch (Exception $e) {
            $error_messages[] = "Exceção ao ler arquivo: " . $e->getMessage();
        }
    }
}

// Se não encontrou nenhum arquivo em nenhum dos caminhos
if (!$found) {
    // Verificar se algum dos diretórios existe pelo menos
    $dir_exists = false;
    foreach ($possible_paths as $dir) {
        if (is_dir($dir)) {
            $dir_exists = true;
            $path_used = $dir;
            break;
        }
    }
    
    // Retornar array vazio com informações de diagnóstico
    echo json_encode([
        'success' => true, 
        'anotacoes' => [],
        'debug' => [
            'path_checked' => $possible_paths,
            'path_exists' => $dir_exists,
            'path_used' => $path_used,
            'errors' => $error_messages
        ]
    ]);
    exit;
}

// Ordenar por timestamp (mais recentes primeiro)
usort($anotacoes, function($a, $b) {
    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
});

// Retornar as anotações
echo json_encode([
    'success' => true, 
    'anotacoes' => $anotacoes,
    'path_used' => $path_used
]);
?> 