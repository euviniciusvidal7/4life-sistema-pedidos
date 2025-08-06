<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'error' => 'Usuário não autenticado']));
}

// Verificar se a requisição é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Método não permitido']));
}

// Receber dados do formulário como JSON
$dados_json = file_get_contents('php://input');
$dados = json_decode($dados_json, true);

if (!$dados || !isset($dados['action']) || $dados['action'] !== 'create_dir') {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'Dados inválidos']));
}

header('Content-Type: application/json');

// Possíveis caminhos para o diretório de anotações
$possible_paths = [
    './anotacoes',                  // Relativo ao script atual
    __DIR__ . '/anotacoes',         // Caminho absoluto na mesma pasta do script
    '/home/u180568174/domains/suplements.tech/public_html/dashboard/anotacoes' // Caminho absoluto completo
];

$success = false;
$error_messages = [];
$created_path = '';

foreach ($possible_paths as $path) {
    try {
        // Verificar se o diretório já existe
        if (file_exists($path) && is_dir($path)) {
            // Verificar permissões
            if (is_writable($path)) {
                $success = true;
                $created_path = $path;
                break;
            } else {
                $error_messages[] = "O diretório $path já existe, mas não tem permissão de escrita";
                
                // Tentar corrigir permissões
                if (@chmod($path, 0755)) {
                    if (is_writable($path)) {
                        $success = true;
                        $created_path = $path;
                        $error_messages[] = "Permissões corrigidas para $path";
                        break;
                    }
                }
            }
        } else {
            // Verificar permissões do diretório pai
            $parent_dir = dirname($path);
            if (file_exists($parent_dir) && is_dir($parent_dir) && is_writable($parent_dir)) {
                // Tentar criar o diretório
                if (@mkdir($path, 0755, true)) {
                    $success = true;
                    $created_path = $path;
                    break;
                } else {
                    $error = error_get_last();
                    $error_messages[] = "Não foi possível criar o diretório $path: " . ($error ? $error['message'] : 'Erro desconhecido');
                }
            } else {
                $error_messages[] = "Diretório pai $parent_dir não existe ou não tem permissão de escrita";
            }
        }
    } catch (Exception $e) {
        $error_messages[] = "Exceção ao processar $path: " . $e->getMessage();
    }
}

// Testar escrita no diretório criado/existente
if ($success) {
    $test_file = $created_path . '/test_write_' . uniqid() . '.txt';
    if (@file_put_contents($test_file, 'Teste de escrita') === false) {
        $success = false;
        $error_messages[] = "Não foi possível escrever no diretório $created_path";
    } else {
        // Limpar arquivo de teste
        @unlink($test_file);
    }
}

// Retornar resultado
echo json_encode([
    'success' => $success,
    'path' => $created_path,
    'errors' => $error_messages,
    'timestamp' => date('Y-m-d H:i:s')
]);
?> 