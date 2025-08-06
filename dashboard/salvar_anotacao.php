<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'error' => 'Usuário não autenticado']));
}

// Verificar se a requisição é POST e contém dados JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Método não permitido']));
}

// Receber dados do formulário como JSON
$dados_json = file_get_contents('php://input');
$dados = json_decode($dados_json, true);

if (!$dados || !isset($dados['pedido_id']) || !isset($dados['comentario']) || empty(trim($dados['comentario']))) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'Dados incompletos ou inválidos']));
}

// Tentar diferentes caminhos para o diretório de anotações
$success = false;
$error_messages = [];
$possible_paths = [
    './anotacoes',                  // Relativo ao script atual (nome corrigido)
    __DIR__ . '/anotacoes',         // Caminho absoluto na mesma pasta do script
    '/home/u180568174/domains/suplements.tech/public_html/dashboard/anotacoes' // Caminho absoluto completo
];

foreach ($possible_paths as $diretorio_anotacoes) {
    try {
        // Verificar se o diretório existe, caso contrário, criar
        if (!file_exists($diretorio_anotacoes)) {
            if (!@mkdir($diretorio_anotacoes, 0755, true)) {
                $error_messages[] = "Não foi possível criar o diretório: {$diretorio_anotacoes} - " . error_get_last()['message'];
                continue;
            }
        }
        
        // Testar permissões de escrita
        $test_file = $diretorio_anotacoes . '/test_write.txt';
        if (@file_put_contents($test_file, 'test') === false) {
            $error_messages[] = "Não há permissão de escrita em: {$diretorio_anotacoes}";
            continue;
        }
        
        // Limpar o arquivo de teste
        @unlink($test_file);
        
        // Nome do arquivo com base no ID do pedido
        $pedido_id = sanitize_filename($dados['pedido_id']);
        $arquivo_anotacoes = "{$diretorio_anotacoes}/{$pedido_id}.json";
        
        // Obter informações do usuário para registrar quem fez a anotação
        require_once 'config.php';
        $conn = conectarBD();
        
        // Verificar quais colunas existem na tabela usuarios
        $colunas_sql = "SHOW COLUMNS FROM usuarios";
        $colunas_result = $conn->query($colunas_sql);
        $colunas_existentes = [];
        
        if ($colunas_result) {
            while ($coluna = $colunas_result->fetch_assoc()) {
                $colunas_existentes[] = $coluna['Field'];
            }
        }
        
        $usuario_nome = 'Usuário';
        
        // Usar a coluna 'login' que é a que contém o nome do usuário
        if (in_array('login', $colunas_existentes)) {
            $stmt = $conn->prepare("SELECT login FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['usuario_id']);
            $stmt->execute();
            $stmt->bind_result($usuario_nome);
            $stmt->fetch();
            $stmt->close();
        }
        
        // Se não conseguiu obter o login, tentar outras colunas como fallback
        if (empty($usuario_nome) && in_array('nome', $colunas_existentes)) {
            $stmt = $conn->prepare("SELECT nome FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['usuario_id']);
            $stmt->execute();
            $stmt->bind_result($usuario_nome);
            $stmt->fetch();
            $stmt->close();
        }
        else if (empty($usuario_nome) && in_array('username', $colunas_existentes)) {
            $stmt = $conn->prepare("SELECT username FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['usuario_id']);
            $stmt->execute();
            $stmt->bind_result($usuario_nome);
            $stmt->fetch();
            $stmt->close();
        }
        else if (empty($usuario_nome) && in_array('email', $colunas_existentes)) {
            $stmt = $conn->prepare("SELECT email FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['usuario_id']);
            $stmt->execute();
            $stmt->bind_result($usuario_nome);
            $stmt->fetch();
            $stmt->close();
        }
        
        // Estrutura da anotação
        $nova_anotacao = [
            'id' => uniqid(),
            'usuario_id' => $_SESSION['usuario_id'],
            'usuario_nome' => $usuario_nome ?: 'Usuário',
            'comentario' => trim($dados['comentario']),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Carregar anotações existentes ou criar novo arquivo
        $anotacoes = [];
        if (file_exists($arquivo_anotacoes)) {
            $conteudo = file_get_contents($arquivo_anotacoes);
            $anotacoes = json_decode($conteudo, true) ?: [];
        }
        
        // Adicionar nova anotação ao array
        $anotacoes[] = $nova_anotacao;
        
        // Salvar no arquivo
        if (file_put_contents($arquivo_anotacoes, json_encode($anotacoes, JSON_PRETTY_PRINT))) {
            // Retornar sucesso com os dados da anotação
            echo json_encode([
                'success' => true, 
                'message' => 'Anotação salva com sucesso',
                'anotacao' => $nova_anotacao,
                'path_used' => $diretorio_anotacoes
            ]);
            $success = true;
            break;
        } else {
            $error_messages[] = "Falha ao escrever no arquivo: {$arquivo_anotacoes}";
        }
    } catch (Exception $e) {
        $error_messages[] = "Exceção: " . $e->getMessage() . " em {$diretorio_anotacoes}";
    }
}

if (!$success) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Erro ao salvar anotação', 
        'details' => $error_messages,
        'server_info' => [
            'script_path' => __FILE__,
            'document_root' => $_SERVER['DOCUMENT_ROOT'],
            'script_dir' => __DIR__,
            'current_dir' => getcwd(),
            'user' => get_current_user(),
            'php_version' => PHP_VERSION
        ]
    ]);
}

// Função para sanitizar nome de arquivo
function sanitize_filename($filename) {
    // Remover a extensão .json se existir
    $filename = preg_replace('/\.json$/', '', $filename);
    
    // Remover caracteres inválidos para nomes de arquivo
    $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename);
    
    return $filename;
}
?> 