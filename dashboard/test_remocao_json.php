<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// Incluir configurações
require_once 'config.php';

echo "<h1>Teste de Remoção de Arquivos JSON</h1>";

// Definir caminhos possíveis para os pedidos
$pedidos_paths = [
    '../2025/pedidos',
    '../../2025/pedidos',
    $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos',
    dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos',
    './2025/pedidos'
];

echo "<h2>Verificando Caminhos dos Pedidos:</h2>";
$local_path = null;
foreach ($pedidos_paths as $path) {
    $exists = is_dir($path);
    echo "<p>$path: " . ($exists ? "✅ Existe" : "❌ Não existe") . "</p>";
    if ($exists && $local_path === null) {
        $local_path = $path;
    }
}

if ($local_path === null) {
    echo "<p style='color: red;'>❌ Nenhum diretório de pedidos encontrado!</p>";
    exit;
}

echo "<p><strong>Usando diretório:</strong> $local_path</p>";

// Verificar index.json
$index_path = $local_path . '/index.json';
echo "<h2>Verificando Index.json:</h2>";
echo "<p>Caminho: $index_path</p>";

if (file_exists($index_path)) {
    echo "<p>✅ Index.json existe</p>";
    
    $index_content = file_get_contents($index_path);
    if ($index_content !== false) {
        $index_data = json_decode($index_content, true);
        if (is_array($index_data)) {
            echo "<p>✅ Index.json é válido com " . count($index_data) . " itens</p>";
            
            // Mostrar alguns pedidos de exemplo
            echo "<h3>Primeiros 3 pedidos no índice:</h3>";
            $count = 0;
            foreach ($index_data as $key => $pedido) {
                if ($count >= 3) break;
                $nome = $pedido['NOME'] ?? 'Nome não informado';
                $status = $pedido['status'] ?? 'Status não informado';
                echo "<p>$count: Chave='$key', Nome='$nome', Status='$status'</p>";
                $count++;
            }
        } else {
            echo "<p>❌ Index.json não é um array válido</p>";
        }
    } else {
        echo "<p>❌ Erro ao ler index.json</p>";
    }
} else {
    echo "<p>❌ Index.json não existe</p>";
}

// Verificar permissões de escrita
echo "<h2>Verificando Permissões:</h2>";
$writable = is_writable($local_path);
echo "<p>Diretório de pedidos é gravável: " . ($writable ? "✅ Sim" : "❌ Não") . "</p>";

if (file_exists($index_path)) {
    $index_writable = is_writable($index_path);
    echo "<p>Index.json é gravável: " . ($index_writable ? "✅ Sim" : "❌ Não") . "</p>";
}

// Listar arquivos JSON individuais
echo "<h2>Arquivos JSON Individuais:</h2>";
$json_files = glob($local_path . '/*.json');
$json_files = array_filter($json_files, function($file) {
    return basename($file) !== 'index.json';
});

echo "<p>Encontrados " . count($json_files) . " arquivos JSON individuais:</p>";
foreach (array_slice($json_files, 0, 5) as $file) {
    $filename = basename($file);
    $size = filesize($file);
    $writable = is_writable($file);
    echo "<p>$filename (${size} bytes) - Gravável: " . ($writable ? "✅" : "❌") . "</p>";
}

// Teste de função de remoção
echo "<h2>Teste da Função de Remoção:</h2>";

// Simular a função removerPedidoDoIndice
function testarRemocaoDoIndice($arquivo) {
    echo "<h3>Testando remoção para arquivo: $arquivo</h3>";
    
    // Múltiplos caminhos possíveis para o index.json
    $caminhos_index = [
        '../2025/pedidos/index.json',
        '../../2025/pedidos/index.json',
        $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos/index.json',
        dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos/index.json',
        './2025/pedidos/index.json'
    ];
    
    $index_path = null;
    $index_content = null;
    
    // Encontrar o arquivo index.json
    foreach ($caminhos_index as $caminho) {
        echo "<p>Tentando caminho: $caminho</p>";
        if (file_exists($caminho)) {
            $index_path = $caminho;
            $index_content = @file_get_contents($caminho);
            if ($index_content !== false) {
                echo "<p>✅ Index.json encontrado em: $caminho</p>";
                break;
            }
        }
    }
    
    if ($index_path === null || $index_content === false) {
        echo "<p>❌ Index.json não encontrado em nenhum dos caminhos</p>";
        return false;
    }
    
    $index = json_decode($index_content, true);
    if (!is_array($index)) {
        echo "<p>❌ Index.json não contém um array válido</p>";
        return false;
    }
    
    echo "<p>Index carregado com " . count($index) . " itens</p>";
    
    // Remover extensão .json se presente para comparação
    $arquivo_base = str_replace('.json', '', $arquivo);
    echo "<p>Procurando por arquivo base: $arquivo_base</p>";
    
    // Procurar o pedido no índice
    $found = false;
    $original_count = count($index);
    
    foreach ($index as $key => $item) {
        // Verificar múltiplas formas de identificação
        $item_arquivo = isset($item['arquivo']) ? str_replace('.json', '', $item['arquivo']) : '';
        $item_nome = isset($item['NOME']) ? $item['NOME'] : '';
        
        echo "<p>Verificando item: chave='$key', arquivo='$item_arquivo', nome='$item_nome'</p>";
        
        if ($item_arquivo === $arquivo_base || 
            (isset($item['arquivo']) && $item['arquivo'] === $arquivo) ||
            $key === $arquivo_base) {
            
            echo "<p>✅ Pedido encontrado no índice: chave=$key, arquivo=$item_arquivo</p>";
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        echo "<p>❌ Pedido não encontrado no índice para remoção: $arquivo</p>";
    }
    
    return $found;
}

// Testar com um arquivo de exemplo se existir
if (!empty($json_files)) {
    $exemplo_file = basename($json_files[0]);
    testarRemocaoDoIndice($exemplo_file);
}

echo '<br><a href="pedidos.php">Voltar para Pedidos</a>';
?> 