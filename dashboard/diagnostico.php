<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    die('Acesso negado');
}

// Incluir configurações
require_once 'config.php';

echo "<h1>Diagnóstico do Sistema</h1>";

// Testar conexão com o banco de dados
echo "<h2>Teste de Conexão com o Banco de Dados</h2>";
try {
    $conn = conectarBD();
    echo "<p style='color:green'>✓ Conexão com o banco de dados estabelecida com sucesso.</p>";
    
    // Verificar tabelas
    $result = $conn->query("SHOW TABLES");
    echo "<p>Tabelas encontradas:</p><ul>";
    while ($row = $result->fetch_array()) {
        echo "<li>" . $row[0] . "</li>";
    }
    echo "</ul>";
    
    // Verificar registros de entregas
    $count = $conn->query("SELECT COUNT(*) FROM entregas")->fetch_row()[0];
    echo "<p>Total de entregas no banco: " . $count . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Erro na conexão com o banco: " . $e->getMessage() . "</p>";
}

// Testar API do Trello
echo "<h2>Teste de API do Trello</h2>";
echo "<p>Usando:<br>API Key: " . substr(TRELLO_API_KEY, 0, 5) . "..." . "<br>Token: " . substr(TRELLO_TOKEN, 0, 5) . "..." . "</p>";

try {
    // Testar conexão com o Board
    $url = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
    $response = @file_get_contents($url);
    
    if ($response === false) {
        echo "<p style='color:red'>✗ Erro ao acessar o Board do Trello. Verificar API Key/Token/Board ID.</p>";
    } else {
        $board_data = json_decode($response, true);
        echo "<p style='color:green'>✓ Board do Trello acessado com sucesso: " . $board_data['name'] . "</p>";
        
        // Testar listas
        $lists_url = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/lists?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;
        $lists_response = @file_get_contents($lists_url);
        
        if ($lists_response === false) {
            echo "<p style='color:red'>✗ Erro ao acessar listas do Board.</p>";
        } else {
            $lists = json_decode($lists_response, true);
            echo "<p style='color:green'>✓ Listas encontradas: " . count($lists) . "</p>";
            echo "<ul>";
            foreach ($lists as $list) {
                echo "<li>" . $list['name'] . " (ID: " . $list['id'] . ")</li>";
            }
            echo "</ul>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Erro ao testar API do Trello: " . $e->getMessage() . "</p>";
}

// Verificar configurações do PHP
echo "<h2>Configurações do PHP</h2>";
echo "<p>Versão do PHP: " . phpversion() . "</p>";
echo "<p>allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'Habilitado' : 'Desabilitado') . "</p>";
echo "<p>max_execution_time: " . ini_get('max_execution_time') . "</p>";
?>