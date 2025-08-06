<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

// Incluir configurações
require_once 'config.php';

// Verificar configurações do Trello
if (!defined('TRELLO_API_KEY') || !defined('TRELLO_TOKEN') || !defined('TRELLO_BOARD_ID')) {
    die('Configurações do Trello não definidas');
}

// Buscar listas do board do Trello
$url_lists = "https://api.trello.com/1/boards/" . TRELLO_BOARD_ID . "/lists?key=" . TRELLO_API_KEY . "&token=" . TRELLO_TOKEN;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_lists);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);

if (curl_errno($ch)) {
    die('Erro ao acessar API do Trello: ' . curl_error($ch));
}

curl_close($ch);

$lists = json_decode($response, true);
if (!$lists || !is_array($lists)) {
    die('Erro ao decodificar resposta do Trello');
}

// IDs das listas definidos no código
$trello_list_ids = [
    'novos' => '67f70b1e66425a64aa863e9f',
    'ligar' => '67fe3f6feeaedb56af382407',
    'pronto_envio' => '67f70b1e66425a64aa863ea0',
    'transito' => '67f70b1e66425a64aa863ea1',
    'entregue' => '67f71b2d0034671f377035e8',
    'devolucao' => '6808cff12b9ae87d1d519329'
];

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug - Listas do Trello</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-4">
        <h1>Debug - Listas do Trello</h1>
        
        <div class="row">
            <div class="col-md-6">
                <h3>Listas Encontradas no Board</h3>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Nome da Lista</th>
                                <th>ID</th>
                                <th>Status Mapeado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lists as $list): ?>
                            <?php
                            // Determinar status mapeado
                            $status_mapeado = 'nao_listado';
                            if (stripos($list['name'], 'CLIENTES A LIGAR') !== false) {
                                $status_mapeado = 'ligar';
                            } else if (stripos($list['name'], 'NOVO CLIENTE') !== false) {
                                $status_mapeado = 'aguardando';
                            } else if (stripos($list['name'], 'PRONTO PARA ENVIO') !== false) {
                                $status_mapeado = 'em_processamento';
                            } else if (stripos($list['name'], 'EM TRANSITO') !== false || stripos($list['name'], 'EM TRÂNSITO') !== false) {
                                $status_mapeado = 'processado';
                            } else if (stripos($list['name'], 'DEVOLUCAO') !== false || stripos($list['name'], 'DEVOLUÇÃO') !== false || stripos($list['name'], 'DEVOLVIDAS') !== false) {
                                $status_mapeado = 'devolucao';
                            } else if (stripos($list['name'], 'ENTREGUE') !== false || stripos($list['name'], 'PAGO') !== false) {
                                $status_mapeado = 'concluido';
                            }
                            
                            // Verificar se corresponde aos IDs definidos
                            if ($list['id'] === $trello_list_ids['novos']) {
                                $status_mapeado = 'aguardando (ID)';
                            } else if ($list['id'] === $trello_list_ids['ligar']) {
                                $status_mapeado = 'ligar (ID)';
                            } else if ($list['id'] === $trello_list_ids['pronto_envio']) {
                                $status_mapeado = 'em_processamento (ID)';
                            } else if ($list['id'] === $trello_list_ids['transito']) {
                                $status_mapeado = 'processado (ID)';
                            } else if ($list['id'] === $trello_list_ids['entregue']) {
                                $status_mapeado = 'concluido (ID)';
                            } else if ($list['id'] === $trello_list_ids['devolucao']) {
                                $status_mapeado = 'devolucao (ID)';
                            }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($list['name']) ?></td>
                                <td><code><?= htmlspecialchars($list['id']) ?></code></td>
                                <td>
                                    <span class="badge <?= $status_mapeado === 'nao_listado' ? 'bg-warning' : 'bg-success' ?>">
                                        <?= $status_mapeado ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="col-md-6">
                <h3>IDs Definidos no Código</h3>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Categoria</th>
                                <th>ID Definido</th>
                                <th>Lista Correspondente</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trello_list_ids as $categoria => $id_definido): ?>
                            <?php
                            // Encontrar lista correspondente
                            $lista_correspondente = 'Não encontrada';
                            foreach ($lists as $list) {
                                if ($list['id'] === $id_definido) {
                                    $lista_correspondente = $list['name'];
                                    break;
                                }
                            }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($categoria) ?></td>
                                <td><code><?= htmlspecialchars($id_definido) ?></code></td>
                                <td>
                                    <span class="badge <?= $lista_correspondente === 'Não encontrada' ? 'bg-danger' : 'bg-success' ?>">
                                        <?= htmlspecialchars($lista_correspondente) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <h4 class="mt-4">Constantes Definidas</h4>
                <ul class="list-group">
                    <li class="list-group-item">
                        <strong>TRELLO_API_KEY:</strong> 
                        <?= defined('TRELLO_API_KEY') ? 'Definida' : 'Não definida' ?>
                    </li>
                    <li class="list-group-item">
                        <strong>TRELLO_TOKEN:</strong> 
                        <?= defined('TRELLO_TOKEN') ? 'Definido' : 'Não definido' ?>
                    </li>
                    <li class="list-group-item">
                        <strong>TRELLO_BOARD_ID:</strong> 
                        <?= defined('TRELLO_BOARD_ID') ? htmlspecialchars(TRELLO_BOARD_ID) : 'Não definido' ?>
                    </li>
                    <li class="list-group-item">
                        <strong>TRELLO_LIST_ID_TRANSITO:</strong> 
                        <?= defined('TRELLO_LIST_ID_TRANSITO') ? htmlspecialchars(TRELLO_LIST_ID_TRANSITO) : 'Não definido' ?>
                    </li>
                </ul>
            </div>
        </div>
        
        <div class="mt-4">
            <a href="pedidos.php" class="btn btn-primary">Voltar para Pedidos</a>
        </div>
    </div>
</body>
</html> 