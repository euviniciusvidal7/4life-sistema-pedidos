<?php
// Script para verificar e criar diretórios necessários para o sistema
header('Content-Type: text/plain');

// Função para testar e criar diretório
function checkAndCreateDir($path, $create = true) {
    echo "Verificando diretório: $path\n";
    
    if (is_dir($path)) {
        echo "  - Existe\n";
        echo "  - Permissões: " . substr(sprintf('%o', fileperms($path)), -4) . "\n";
        return true;
    } else {
        echo "  - Não existe\n";
        if ($create) {
            echo "  - Tentando criar...\n";
            if (mkdir($path, 0755, true)) {
                echo "  - Criado com sucesso\n";
                return true;
            } else {
                echo "  - FALHA AO CRIAR\n";
                echo "  - Erro: " . error_get_last()['message'] . "\n";
                return false;
            }
        }
        return false;
    }
}

// Definir caminhos para verificar
echo "=== VERIFICAÇÃO DE DIRETÓRIOS ===\n\n";

// Diretório raiz
$document_root = $_SERVER['DOCUMENT_ROOT'];
echo "Document Root: $document_root\n\n";

// Verificar diretórios relativos à raiz
$rootRelativePaths = [
    '/2025',
    '/2025/pedidos',
    '/backups',
    '/backups/pedidos',
    '/logs'
];

foreach ($rootRelativePaths as $relativePath) {
    $fullPath = $document_root . $relativePath;
    echo "Caminho: $fullPath\n";
    checkAndCreateDir($fullPath);
    echo "\n";
}

// Verificar diretórios relativos ao script atual
echo "Diretório atual: " . __DIR__ . "\n\n";

$scriptRelativePaths = [
    '../2025',
    '../2025/pedidos',
    '../backups',
    '../backups/pedidos',
    '../logs'
];

foreach ($scriptRelativePaths as $relativePath) {
    $fullPath = realpath(__DIR__ . '/' . $relativePath) ?: __DIR__ . '/' . $relativePath;
    echo "Caminho: $fullPath\n";
    checkAndCreateDir($fullPath);
    echo "\n";
}

// Listar arquivos em pedidos
$pedidosPaths = [
    $document_root . '/2025/pedidos',
    __DIR__ . '/../2025/pedidos'
];

foreach ($pedidosPaths as $pedidosPath) {
    if (is_dir($pedidosPath)) {
        echo "Listando arquivos em: $pedidosPath\n";
        $files = scandir($pedidosPath);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                $filePath = $pedidosPath . '/' . $file;
                echo "  - $file";
                echo " (" . (is_readable($filePath) ? 'R' : '-');
                echo (is_writable($filePath) ? 'W' : '-');
                echo (is_executable($filePath) ? 'X' : '-');
                echo ")";
                echo " Tamanho: " . filesize($filePath) . " bytes\n";
            }
        }
        echo "\n";
    }
}

echo "=== VERIFICAÇÃO CONCLUÍDA ===\n";
?> 