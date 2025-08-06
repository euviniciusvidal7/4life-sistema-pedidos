<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'error' => 'Usuário não autenticado']));
}

header('Content-Type: application/json');

// Carregar configurações
require_once 'config.php';

// Verificar conexão com banco de dados
$db_status = [];
try {
    $conn = conectarBD();
    $db_status['connected'] = true;
    $db_status['error'] = null;
    
    // Verificar tabela de usuários
    $user_table = [];
    $result = $conn->query("SHOW COLUMNS FROM usuarios");
    if ($result) {
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        $user_table['columns'] = $columns;
        $user_table['has_login_column'] = in_array('login', $columns);
        $user_table['has_nome_column'] = in_array('nome', $columns);
        $user_table['error'] = null;
    } else {
        $user_table['error'] = "Erro ao verificar colunas: " . $conn->error;
    }
    $db_status['user_table'] = $user_table;
    
    // Verificar usuário atual
    $user_info = [];
    if (isset($_SESSION['usuario_id'])) {
        $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['usuario_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            // Remover senha por segurança
            if (isset($row['senha'])) {
                $row['senha'] = '********';
            }
            $user_info['data'] = $row;
            $user_info['error'] = null;
        } else {
            $user_info['error'] = "Usuário não encontrado";
        }
        $stmt->close();
    } else {
        $user_info['error'] = "ID de usuário não definido na sessão";
    }
    $db_status['current_user'] = $user_info;
    
} catch (Exception $e) {
    $db_status['connected'] = false;
    $db_status['error'] = $e->getMessage();
}

// Verificar diretórios de anotações
$possible_paths = [
    './anotacoes',                  // Relativo ao script atual
    __DIR__ . '/anotacoes',         // Caminho absoluto na mesma pasta do script
    '/home/u180568174/domains/suplements.tech/public_html/dashboard/anotacoes' // Caminho absoluto completo
];

$dir_status = [];
foreach ($possible_paths as $path) {
    $path_status = [];
    $path_status['path'] = $path;
    $path_status['exists'] = file_exists($path);
    $path_status['is_dir'] = is_dir($path);
    $path_status['is_writable'] = is_writable($path);
    $path_status['resolved_path'] = realpath($path) ?: 'N/A';
    
    // Se o diretório existe, listar alguns arquivos
    if ($path_status['exists'] && $path_status['is_dir']) {
        try {
            $files = scandir($path);
            $json_files = array_filter($files, function($file) {
                return pathinfo($file, PATHINFO_EXTENSION) === 'json';
            });
            $path_status['files_count'] = count($json_files);
            $path_status['files_sample'] = array_slice($json_files, 0, 5); // Mostrar até 5 arquivos
            
            // Testar escrita
            $test_file = $path . '/test_diag_' . uniqid() . '.txt';
            $write_test = @file_put_contents($test_file, 'teste de escrita');
            $path_status['write_test'] = $write_test !== false;
            if ($path_status['write_test']) {
                @unlink($test_file);
                $path_status['cleanup_test'] = true;
            }
        } catch (Exception $e) {
            $path_status['scan_error'] = $e->getMessage();
        }
    }
    
    // Se o diretório não existe, verificar se podemos criá-lo
    if (!$path_status['exists']) {
        $parent_dir = dirname($path);
        $path_status['parent_dir'] = $parent_dir;
        $path_status['parent_exists'] = file_exists($parent_dir);
        $path_status['parent_is_writable'] = is_writable($parent_dir);
        
        if ($path_status['parent_exists'] && $path_status['parent_is_writable']) {
            try {
                $create_result = @mkdir($path, 0755, true);
                $path_status['create_attempted'] = true;
                $path_status['create_result'] = $create_result;
                
                if ($create_result) {
                    $path_status['exists'] = true;
                    $path_status['is_dir'] = true;
                    $path_status['is_writable'] = is_writable($path);
                    
                    // Testar escrita no diretório recém-criado
                    $test_file = $path . '/test_diag_' . uniqid() . '.txt';
                    $write_test = @file_put_contents($test_file, 'teste de escrita');
                    $path_status['write_test'] = $write_test !== false;
                    if ($path_status['write_test']) {
                        @unlink($test_file);
                        $path_status['cleanup_test'] = true;
                    }
                    
                    // Limpar o diretório criado para teste
                    @rmdir($path);
                }
            } catch (Exception $e) {
                $path_status['create_error'] = $e->getMessage();
            }
        }
    }
    
    $dir_status[] = $path_status;
}

// Verificar variáveis de sessão
$session_status = [
    'active' => isset($_SESSION) && !empty($_SESSION),
    'usuario_id' => $_SESSION['usuario_id'] ?? null,
    'usuario_login' => $_SESSION['usuario_login'] ?? null,
    'nivel_acesso' => $_SESSION['nivel_acesso'] ?? null
];

// Informações do servidor
$server_info = [
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'],
    'script_filename' => $_SERVER['SCRIPT_FILENAME'],
    'script_directory' => __DIR__,
    'current_directory' => getcwd(),
    'hostname' => gethostname(),
    'user' => function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user(),
    'open_basedir' => ini_get('open_basedir'),
    'memory_limit' => ini_get('memory_limit'),
    'file_uploads' => ini_get('file_uploads')
];

// Retornar resultados completos
echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'database_status' => $db_status,
    'directories_status' => $dir_status,
    'session_status' => $session_status,
    'server_info' => $server_info
], JSON_PRETTY_PRINT);
?> 