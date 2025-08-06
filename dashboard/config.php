<?php
// Configurações do Banco de Dados - JÁ CONFIGURADO COM SEUS DADOS
define('DB_HOST', 'localhost');
define('DB_NAME', 'u180568174_Database4life'); 
define('DB_USER', 'u180568174_4life'); 
define('DB_PASS', 'Jovem7153!'); 

// Configurações do Trello - JÁ CONFIGURADAS COM SEUS DADOS
define('TRELLO_API_KEY', '8c0c31e03d0a063c4c6aeb289762ec8a');
define('TRELLO_TOKEN', 'ATTA577747f2b0995cd0fe2dcabe0ef099a7ba9e47e9cd6adbc52ab4453dd84c85b30547EC11');
define('TRELLO_BOARD_ID', 'EAAXj6tq');
define('TRELLO_LIST_ID_TRANSITO', '67f70b1e66425a64aa863ea1'); // ID da lista "EM TRANSITO"
define('TRELLO_LIST_ID_PRONTO_ENVIO', '67f70b1e66425a64aa863ea0'); // ID da lista "PRONTO PARA ENVIO"

// Função para conectar ao banco de dados
function conectarBD() {
  $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
  if ($conn->connect_error) {
    die("Falha na conexão: " . $conn->connect_error);
  }
  $conn->set_charset("utf8");
  return $conn;
}

// Função para formatar data legível
function formatarData($timestamp) {
    if (is_numeric($timestamp)) {
        return date('d/m/Y H:i', $timestamp);
    } else {
        return 'Data inválida';
    }
}

// Função para registrar logs
function registrarLog($acao, $detalhes = '') {
    $timestamp = date('Y-m-d H:i:s');
    $usuario = isset($_SESSION['usuario_login']) ? $_SESSION['usuario_login'] : 'Visitante';
    $ip = $_SERVER['REMOTE_ADDR'];
    $log = "$timestamp | $usuario | $ip | $acao | $detalhes\n";
    file_put_contents('../logs/sistema.log', $log, FILE_APPEND);
}
?>