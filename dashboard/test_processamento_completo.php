<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// Incluir configurações
require_once 'config.php';

echo "<h1>Teste Completo de Processamento e Remoção</h1>";

// Verificar se há pedidos JSON para testar
$pedidos_paths = [
    '../2025/pedidos',
    '../../2025/pedidos',
    $_SERVER['DOCUMENT_ROOT'] . '/2025/pedidos',
    dirname($_SERVER['DOCUMENT_ROOT']) . '/2025/pedidos',
    './2025/pedidos'
];

$local_path = null;
foreach ($pedidos_paths as $path) {
    if (is_dir($path)) {
        $local_path = $path;
        break;
    }
}

if ($local_path === null) {
    echo "<p style='color: red;'>❌ Nenhum diretório de pedidos encontrado!</p>";
    exit;
}

echo "<p><strong>Diretório de pedidos:</strong> $local_path</p>";

// Verificar index.json
$index_path = $local_path . '/index.json';
if (!file_exists($index_path)) {
    echo "<p style='color: red;'>❌ Index.json não encontrado!</p>";
    exit;
}

$index_content = file_get_contents($index_path);
$index_data = json_decode($index_content, true);

if (!is_array($index_data) || empty($index_data)) {
    echo "<p style='color: red;'>❌ Index.json vazio ou inválido!</p>";
    exit;
}

echo "<p>✅ Index.json encontrado com " . count($index_data) . " pedidos</p>";

// Mostrar alguns pedidos disponíveis
echo "<h2>Pedidos Disponíveis para Teste:</h2>";
$count = 0;
foreach ($index_data as $key => $pedido) {
    if ($count >= 5) break;
    $nome = $pedido['NOME'] ?? 'Nome não informado';
    $status = $pedido['status'] ?? 'Status não informado';
    echo "<p>$count: Chave='$key', Nome='$nome', Status='$status'</p>";
    $count++;
}

// Formulário para testar processamento
echo "<h2>Teste de Processamento:</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['testar'])) {
    $arquivo_teste = $_POST['arquivo_teste'] ?? '';
    $tipo_teste = $_POST['tipo_teste'] ?? 'processar';
    
    if (empty($arquivo_teste)) {
        echo "<p style='color: red;'>❌ Arquivo não informado!</p>";
    } else {
        echo "<h3>Testando processamento do arquivo: $arquivo_teste</h3>";
        
        // Dados de teste
        $test_data = [
            'arquivo' => $arquivo_teste,
            'tipo' => $tipo_teste,
            'rastreio' => ($tipo_teste === 'envio') ? 'TEST123456' : '',
            'tratamento' => '1 Mês',
            'transportadora' => 'CTT',
            'origem' => 'json',
            'processado' => true
        ];
        
        echo "<h4>Dados enviados:</h4>";
        echo "<pre>" . json_encode($test_data, JSON_PRETTY_PRINT) . "</pre>";
        
        // Fazer requisição para processar_pedido.php
        $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/processar_pedido.php';
        
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
        
        echo "<h4>Resposta do Servidor:</h4>";
        echo "<p>Código HTTP: $http_code</p>";
        
        if ($curl_error) {
            echo "<p style='color: red;'>Erro cURL: $curl_error</p>";
        }
        
        if ($response) {
            $json_response = json_decode($response, true);
            if ($json_response !== null) {
                echo "<pre>" . json_encode($json_response, JSON_PRETTY_PRINT) . "</pre>";
                
                if (isset($json_response['success']) && $json_response['success']) {
                    echo "<div style='color: green; font-weight: bold;'>✅ PROCESSAMENTO REALIZADO COM SUCESSO!</div>";
                    
                    // Verificar se o arquivo foi removido
                    echo "<h4>Verificando Remoção:</h4>";
                    
                    // Recarregar index.json
                    $new_index_content = file_get_contents($index_path);
                    $new_index_data = json_decode($new_index_content, true);
                    
                    $arquivo_base = str_replace('.json', '', $arquivo_teste);
                    $found_in_index = false;
                    
                    foreach ($new_index_data as $key => $item) {
                        $item_arquivo = isset($item['arquivo']) ? str_replace('.json', '', $item['arquivo']) : '';
                        if ($key === $arquivo_base || $item_arquivo === $arquivo_base) {
                            $found_in_index = true;
                            break;
                        }
                    }
                    
                    if ($found_in_index) {
                        echo "<p style='color: red;'>❌ Arquivo ainda existe no index.json</p>";
                    } else {
                        echo "<p style='color: green;'>✅ Arquivo removido do index.json</p>";
                    }
                    
                    // Verificar arquivo individual
                    $arquivo_individual = $local_path . '/' . $arquivo_teste;
                    if (!str_ends_with($arquivo_individual, '.json')) {
                        $arquivo_individual .= '.json';
                    }
                    
                    if (file_exists($arquivo_individual)) {
                        echo "<p style='color: red;'>❌ Arquivo individual ainda existe: " . basename($arquivo_individual) . "</p>";
                    } else {
                        echo "<p style='color: green;'>✅ Arquivo individual removido</p>";
                    }
                    
                } else {
                    echo "<div style='color: red; font-weight: bold;'>❌ ERRO NO PROCESSAMENTO: " . ($json_response['error'] ?? 'Erro desconhecido') . "</div>";
                }
            } else {
                echo "<p style='color: red;'>❌ Resposta não é JSON válido</p>";
                echo "<pre>$response</pre>";
            }
        } else {
            echo "<p style='color: red;'>❌ Resposta vazia</p>";
        }
    }
}

// Formulário
echo '<form method="POST">';
echo '<div style="margin: 20px 0;">';
echo '<label>Arquivo para testar:</label><br>';
echo '<select name="arquivo_teste" required>';
echo '<option value="">Selecione um arquivo...</option>';

$count = 0;
foreach ($index_data as $key => $pedido) {
    if ($count >= 10) break; // Limitar a 10 opções
    $nome = $pedido['NOME'] ?? 'Nome não informado';
    echo "<option value=\"$key\">$key - $nome</option>";
    $count++;
}

echo '</select>';
echo '</div>';

echo '<div style="margin: 20px 0;">';
echo '<label>Tipo de processamento:</label><br>';
echo '<select name="tipo_teste" required>';
echo '<option value="processar">Processar (PRONTO PARA ENVIO)</option>';
echo '<option value="envio">Envio (EM TRÂNSITO)</option>';
echo '<option value="ligar">Ligar (A LIGAR)</option>';
echo '</select>';
echo '</div>';

echo '<button type="submit" name="testar" style="background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px;">Testar Processamento</button>';
echo '</form>';

echo '<br><a href="pedidos.php">Voltar para Pedidos</a>';
?> 