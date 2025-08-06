<?php
session_start();

// Verificar se está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';
$conn = conectarBD();

// Obter informações do usuário
$nivel_acesso = '';
$stmt = $conn->prepare("SELECT nivel_acesso FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $_SESSION['usuario_id']);
$stmt->execute();
$stmt->bind_result($nivel_acesso);
$stmt->fetch();
$stmt->close();

// Verificar permissão de acesso
if ($nivel_acesso != 'logistica' && $nivel_acesso != 'recuperacao') {
    $_SESSION['erro_mensagem'] = "Você não tem permissão para acessar a área de recuperação.";
    header('Location: dashboard.php');
    exit;
}

// Diretório dos pedidos
$diretorio_pedidos = '../2025/pedidos';

// Processar ações de pedido
$mensagem = '';
$tipo_mensagem = '';

// Verificar se o diretório existe
if (!file_exists($diretorio_pedidos)) {
    mkdir($diretorio_pedidos, 0755, true);
    $mensagem = 'Diretório de pedidos criado.';
    $tipo_mensagem = 'info';
}

// Listar arquivos no diretório
$pedidos_recuperacao = array();
if (is_dir($diretorio_pedidos)) {
    $arquivos = scandir($diretorio_pedidos);
    
    foreach ($arquivos as $arquivo) {
        if ($arquivo != '.' && $arquivo != '..' && pathinfo($arquivo, PATHINFO_EXTENSION) == 'json') {
            $caminho_completo = $diretorio_pedidos . '/' . $arquivo;
            
            // Ler o conteúdo do arquivo
            $conteudo = file_get_contents($caminho_completo);
            $pedido = json_decode($conteudo, true);
            
            // Verificar se o pedido está marcado como em recuperação (Rec = true)
            if ($pedido && isset($pedido['Rec']) && $pedido['Rec'] === true) {
                
                // Verificar se o pedido tem mais de 10 minutos (600 segundos)
                $tempo_atual = time();
                $tempo_criacao = isset($pedido['criado_em']) ? $pedido['criado_em'] : 0;
                
                // Filtrar apenas pedidos com mais de 10 minutos desde a criação
                if ($tempo_atual - $tempo_criacao > 600) {
                    // Adicionar informações do arquivo
                    $pedido['arquivo'] = $arquivo;
                    $pedido['data_modificacao'] = date('d/m/Y H:i:s', filemtime($caminho_completo));
                    $pedido['tempo_abandono'] = floor(($tempo_atual - $tempo_criacao) / 60); // Tempo em minutos
                    
                    // Adicionar à lista de pedidos em recuperação
                    $pedidos_recuperacao[] = $pedido;
                }
            }
        }
    }
    
    // Ordenar por data de modificação (mais recentes primeiro)
    usort($pedidos_recuperacao, function($a, $b) {
        $data_a = isset($a['timestamp']) ? strtotime($a['timestamp']) : 0;
        $data_b = isset($b['timestamp']) ? strtotime($b['timestamp']) : 0;
        return $data_b - $data_a;
    });
}

include 'header.php';
?>

<style>
    /* Variáveis de cores para combinar com a identidade 4Life */
    :root {
        --primary-color: #2E7D32;       /* Verde escuro - primário */
        --secondary-color: #1B5E20;     /* Verde mais escuro - secundário */
        --accent-color: #81C784;        /* Verde claro - destaque */
        --shadow-color: rgba(0, 77, 64, 0.15); /* Sombra esverdeada */
        --red-gradient-start: #e53935;  /* Vermelho início - para recuperação */
        --red-gradient-end: #f44336;    /* Vermelho fim - para recuperação */
        --facebook-gradient-start: #3b5998;
        --facebook-gradient-end: #5b7bd5;
        --whatsapp-gradient-start: #25d366;
        --whatsapp-gradient-end: #128C7E;
        --purple-gradient-start: #9C27B0;
        --purple-gradient-end: #673AB7;
        
        /* Cores adicionais da paleta 4Life */
        --light-green: #e8f5e9;         /* Verde claro para backgrounds */
        --darker-green: #1B5E20;        /* Verde mais escuro para textos importantes */
        --surface-color: #ffffff;        /* Cor para superfícies de cards */
        --border-radius: 16px;           /* Raio de borda padrão */
        --border-radius-lg: 24px;        /* Raio de borda grande */
        --transition-bezier: cubic-bezier(0.165, 0.84, 0.44, 1); /* Transição suave */
    }
    
    /* Notificações flutuantes */
    .notification-toast {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        max-width: 400px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        border-radius: 12px;
        opacity: 0;
        transform: translateY(-20px);
        transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
    }
    
    .notification-toast.show {
        opacity: 1;
        transform: translateY(0);
    }
    
    /* Estilo para o badge roxo */
    .bg-purple {
        background: linear-gradient(135deg, var(--purple-gradient-start), var(--purple-gradient-end));
    }
    
    /* Reset e estilos gerais alinhados com o tema 4Life */
    body {
        background-color: var(--light-green);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    /* Cabeçalho da página estilizado */
    .page-header {
        background: linear-gradient(135deg, var(--red-gradient-start), var(--red-gradient-end));
        border-radius: var(--border-radius-lg);
        padding: 35px;
        margin-bottom: 35px;
        box-shadow: 0 10px 25px rgba(229, 57, 53, 0.3);
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.1);
        transform: translateZ(0);
    }
    
    .page-header::before {
        content: '';
        position: absolute;
        top: 20px;
        right: 20px;
        width: 120px;
        height: 40px;
        background-image: url('https://suplements.tech/Assets/banner.png');
        background-size: contain;
        background-repeat: no-repeat;
        background-position: center;
        opacity: 0.2;
        z-index: 1;
        border-radius: 8px;
    }
    
    .page-header::after {
        content: '';
        position: absolute;
        bottom: -40px;
        right: -40px;
        width: 220px;
        height: 220px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        z-index: 0;
    }
    
    .page-header h1 {
        color: white;
        margin: 0;
        font-weight: 700;
        position: relative;
        z-index: 1;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        letter-spacing: 0.5px;
        font-size: 2.2rem;
    }
    
    .page-header p {
        color: rgba(255, 255, 255, 0.95);
        margin-top: 12px;
        margin-bottom: 0;
        font-size: 1.15rem;
        max-width: 700px;
        position: relative;
        z-index: 1;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        line-height: 1.5;
    }
    
    /* Cards 4Life modernizados */
    .dashboard-card {
        border-radius: var(--border-radius);
        border: none;
        overflow: hidden;
        box-shadow: 0 10px 30px var(--shadow-color);
        transition: all 0.4s var(--transition-bezier);
        margin-bottom: 20px;
        position: relative;
        transform: translateZ(0);
    }
    
    .dashboard-card:hover {
        transform: translateY(-10px) scale(1.02);
        box-shadow: 0 15px 35px rgba(0,0,0,0.2);
    }
    
    .dashboard-card .card-body {
        position: relative;
        overflow: hidden;
        padding: 28px;
        z-index: 2;
    }
    
    .dashboard-card .d-flex {
        position: relative;
        z-index: 2;
    }
    
    .dashboard-card .card-title {
        font-weight: 700;
        font-size: 1.4rem;
        margin-bottom: 5px;
        letter-spacing: 0.5px;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }
    
    .dashboard-card p {
        margin-bottom: 0;
        font-size: 1rem;
        letter-spacing: 0.3px;
        opacity: 0.9;
    }
    
    .dashboard-card .display-4 {
        font-weight: 800;
        letter-spacing: -1px;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
        font-size: 3.5rem;
    }
    
    .dashboard-card .icon-bg {
        position: absolute;
        bottom: -30px;
        right: -20px;
        font-size: 140px;
        opacity: 0.15;
        transform: rotate(-10deg);
        transition: all 0.6s ease;
        z-index: 1;
    }
    
    .dashboard-card:hover .icon-bg {
        transform: rotate(5deg) scale(1.2);
        opacity: 0.2;
    }
    
    /* Cores personalizadas dos cards */
    .bg-danger {
        background: linear-gradient(135deg, var(--red-gradient-start), var(--red-gradient-end)) !important;
    }
    
    .bg-facebook {
        background: linear-gradient(135deg, var(--facebook-gradient-start), var(--facebook-gradient-end));
    }
    
    .bg-whatsapp {
        background: linear-gradient(135deg, var(--whatsapp-gradient-start), var(--whatsapp-gradient-end));
    }
    
    /* Tabela 4Life modernizada */
    .table-card {
        border-radius: var(--border-radius);
        overflow: hidden;
        box-shadow: 0 12px 30px var(--shadow-color);
        border: none;
        margin-bottom: 35px;
        background-color: var(--surface-color);
        transition: all 0.3s ease;
    }
    
    .table-card:hover {
        box-shadow: 0 15px 35px var(--shadow-color);
    }
    
    .table-header {
        background: linear-gradient(135deg, var(--red-gradient-start), var(--red-gradient-end));
        padding: 22px 28px;
    }
    
    .table-header h5 {
        font-weight: 700;
        letter-spacing: 0.5px;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        font-size: 1.25rem;
    }
    
    .table-header .badge {
        padding: 8px 16px;
        font-size: 0.95rem;
        font-weight: 600;
        border-radius: 30px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25);
    }
    
    .table-header .btn {
        border-radius: var(--border-radius);
        font-weight: 600;
        padding: 10px 16px;
        transition: all 0.3s;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        letter-spacing: 0.3px;
    }
    
    .table-header .btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(0, 0, 0, 0.3);
    }
    
    .table-responsive {
        border-radius: 0 0 var(--border-radius) var(--border-radius);
        overflow: hidden;
    }
    
    .table {
        margin-bottom: 0;
    }
    
    .table th {
        font-weight: 600;
        background-color: rgba(129, 199, 132, 0.08);
        border-top: none;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        font-size: 0.8rem;
        color: var(--darker-green);
        padding: 16px 20px;
        white-space: nowrap;
    }
    
    .table td {
        padding: 18px 20px;
        vertical-align: middle;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }
    
    .table tbody tr {
        transition: all 0.2s;
    }
    
    .table tbody tr:hover {
        background-color: rgba(129, 199, 132, 0.1);
        transform: translateY(-1px);
    }
    
    /* Badges e botões 4Life style */
    .badge-stage {
        padding: 7px 15px;
        border-radius: 30px;
        font-weight: 600;
        font-size: 0.75rem;
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
        letter-spacing: 0.4px;
    }
    
    .action-btn {
        border-radius: var(--border-radius);
        padding: 8px 16px;
        font-size: 0.8rem;
        font-weight: 600;
        transition: all 0.3s var(--transition-bezier);
        margin: 0 3px;
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.12);
        letter-spacing: 0.4px;
    }
    
    .action-btn:hover {
        transform: translateY(-4px);
        box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
    }
    
    .btn-outline-primary, .btn-outline-light {
        border-width: 2px;
    }
    
    /* Modals do tema 4Life */
    .modal-content {
        border: none;
        border-radius: 20px;
        box-shadow: 0 15px 35px rgba(0,0,0,0.25);
        overflow: hidden;
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
    }
    
    /* Efeito de borda interna para modais */
    .modal-content::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: 20px;
        border: 1px solid rgba(255,255,255,0.1);
        pointer-events: none;
    }
    
    /* Melhoria visual para cards dentro dos modais */
    .modal .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        overflow: hidden;
    }
    
    .modal .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    
    .modal .card-header {
        border-bottom: none;
        position: relative;
        z-index: 1;
    }
    
    .modal .card-body {
        position: relative;
        z-index: 1;
    }
    
    /* Melhoria para o efeito de entrada do modal */
    @keyframes modalFadeIn {
        from {
            opacity: 0;
            transform: translateY(-30px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
    
    .modal.show .modal-content {
        animation: modalFadeIn 0.4s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
    }
    
    .modal-header {
        background: linear-gradient(135deg, #1a237e, #283593);
        padding: 1.5rem;
        border: none;
        position: relative;
        overflow: hidden;
    }
    
    .modal-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(45deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
        z-index: 1;
    }
    
    .modal-header .modal-title {
        color: white;
        font-size: 1.5rem;
        font-weight: 600;
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .modal-header .btn-close {
        background-color: rgba(255,255,255,0.2);
        padding: 0.75rem;
        border-radius: 12px;
        transition: all 0.3s ease;
        position: relative;
        z-index: 10;
        opacity: 1;
        filter: invert(1) grayscale(100%) brightness(200%);
    }
    
    .modal-header .btn-close:hover {
        background-color: rgba(255,255,255,0.3);
        transform: rotate(90deg);
    }
    
    .modal-body {
        padding: 2rem;
        background: #f8f9fa;
    }
    
    .modal-footer {
        padding: 20px 28px;
        border-top: 1px solid rgba(0, 0, 0, 0.08);
    }
    
    /* Melhorias para os cards dentro de modais */
    .modal-body .card {
        border-radius: var(--border-radius);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        overflow: hidden;
        transition: all 0.3s;
    }
    
    .modal-body .card-header {
        padding: 16px 20px;
        font-weight: 600;
        letter-spacing: 0.3px;
    }
    
    .modal-body .card-body {
        padding: 20px;
    }
    
    /* Alertas modernizados */
    .alert {
        border-radius: var(--border-radius);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        border: none;
    }
    
    .alert-info {
        background-color: rgba(3, 169, 244, 0.1);
        color: #0288d1;
    }
    
    /* Inputs estilizados para formulários */
    .input-group {
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        border-radius: 10px;
        overflow: hidden;
    }
    
    .input-group-text {
        background-color: rgba(46, 125, 50, 0.1);
        color: var(--primary-color);
        border: 1px solid rgba(46, 125, 50, 0.2);
        border-right: none;
        font-size: 1rem;
    }
    
    .form-control, .form-select {
        border: 1px solid rgba(46, 125, 50, 0.2);
        padding: 12px 15px;
        transition: all 0.3s;
    }
    
    .form-control:focus, .form-select:focus {
        box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.2);
        border-color: var(--primary-color);
    }
    
    /* Botões principais */
    .btn-warning {
        background-color: #FF9800;
        border-color: #FF9800;
        color: #fff;
        box-shadow: 0 4px 10px rgba(255, 152, 0, 0.3);
    }
    
    .btn-warning:hover {
        background-color: #F57C00;
        border-color: #F57C00;
        color: #fff;
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(255, 152, 0, 0.4);
    }
    
    .btn-success {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        box-shadow: 0 4px 10px var(--shadow-color);
    }
    
    .btn-success:hover {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
        transform: translateY(-3px);
        box-shadow: 0 6px 15px var(--shadow-color);
    }
    
    /* Efeitos de animação */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .dashboard-card {
        animation: fadeInUp 0.6s var(--transition-bezier) forwards;
    }
    
    .dashboard-card:nth-child(1) {
        animation-delay: 0.1s;
    }
    
    .dashboard-card:nth-child(2) {
        animation-delay: 0.25s;
    }
    
    .dashboard-card:nth-child(3) {
        animation-delay: 0.4s;
    }

    /* Animações adicionais para elementos interativos */
    @keyframes pulseLight {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); box-shadow: 0 0 15px var(--accent-color); }
        100% { transform: scale(1); }
    }

    @keyframes slideInRight {
        from { transform: translateX(30px); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }

    @keyframes shimmer {
        0% { background-position: -1000px 0; }
        100% { background-position: 1000px 0; }
    }

    /* Melhorias para elementos interativos */
    .table .tbody tr {
        transition: all 0.35s var(--transition-bezier);
    }

    .table tbody tr:hover td {
        background-color: rgba(46, 125, 50, 0.05);
    }

    .badge {
        transition: all 0.3s var(--transition-bezier);
    }

    .badge:hover {
        transform: translateY(-2px);
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
    }

    /* Efeito de loading */
    .loading-shimmer {
        background: linear-gradient(90deg, 
            rgba(255, 255, 255, 0.1) 0%, 
            rgba(255, 255, 255, 0.2) 50%, 
            rgba(255, 255, 255, 0.1) 100%);
        background-size: 1000px 100%;
        animation: shimmer 2s infinite linear;
    }

    /* Efeito de novas linhas */
    .new-row {
        animation: slideInRight 0.6s var(--transition-bezier) forwards;
    }

    /* Botão de atualizar com animação */
    .btn-atualizar {
        transition: all 0.4s var(--transition-bezier);
    }

    .btn-atualizar:hover i {
        transform: rotate(180deg);
    }

    /* Botão de ação com efeito de destaque */
    .action-btn.highlight {
        animation: pulseLight 1.5s var(--transition-bezier) infinite;
    }

    /* Refinamento dos elementos de tabela */
    .table th {
        position: relative;
        overflow: hidden;
    }

    .table th::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 2px;
        background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
        transform: scaleX(0);
        transition: transform 0.4s var(--transition-bezier);
        transform-origin: left;
    }

    .table th:hover::after {
        transform: scaleX(1);
    }

    /* Separador visível entre linhas */
    .table tbody tr:not(:last-child) td {
        border-bottom: 1px solid rgba(46, 125, 50, 0.08);
    }

    /* Melhoria no visual das células com status */
    td .badge-stage {
        position: relative;
        z-index: 1;
    }

    /* Efeito hover nos cards da página inicial */
    .dashboard-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: radial-gradient(circle at top right, rgba(255,255,255,0.2), transparent 70%);
        opacity: 0;
        transition: opacity 0.6s var(--transition-bezier);
        z-index: 1;
        border-radius: var(--border-radius);
    }

    .dashboard-card:hover::before {
        opacity: 1;
    }

    /* Melhoria nos botões de ação da tabela */
    .btn-group .action-btn {
        position: relative;
        overflow: hidden;
    }

    .btn-group .action-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, 
            rgba(255, 255, 255, 0), 
            rgba(255, 255, 255, 0.2), 
            rgba(255, 255, 255, 0));
        transition: all 0.6s ease;
    }

    .btn-group .action-btn:hover::before {
        left: 100%;
    }

    /* Estilos adicionais para o modal */
    .modal-content {
        border-radius: 1rem;
        overflow: hidden;
    }

    .modal-header {
        padding: 1.5rem;
    }

    .card {
        transition: all 0.3s ease;
    }

    .card:hover {
        transform: translateY(-5px);
    }

    .rounded-4 {
        border-radius: 1rem !important;
    }

    .rounded-top-4 {
        border-top-left-radius: 1rem !important;
        border-top-right-radius: 1rem !important;
    }

    .bg-gradient-dark {
        background: linear-gradient(45deg, #343a40, #495057);
    }

    .bg-gradient-primary {
        background: linear-gradient(45deg, #2E7D32, #388E3C);
    }

    .bg-gradient-info {
        background: linear-gradient(45deg, #0288d1, #039be5);
    }

    .bg-gradient-success {
        background: linear-gradient(45deg, #2E7D32, #43a047);
    }

    .bg-gradient-purple {
        background: linear-gradient(45deg, #9C27B0, #673AB7);
    }

    .chat-container {
        max-height: 300px;
        overflow-y: auto;
    }

    .chat-message {
        padding: 1rem;
        margin-bottom: 1rem;
        border-radius: 0.75rem;
        background-color: #f8f9fa;
        border-left: 4px solid #2E7D32;
    }

    .chat-message:last-child {
        margin-bottom: 0;
    }

    .timeline-container {
        position: relative;
        padding: 1.5rem 0;
    }

    .timeline-item {
        position: relative;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        background: #fff;
        border-radius: 1rem;
        box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
        transition: all 0.3s ease;
        border-left: 4px solid #e9ecef;
    }

    .timeline-item.concluido {
        border-left-color: #28a745;
    }

    .timeline-item.pendente {
        border-left-color: #6c757d;
        opacity: 0.7;
    }

    .timeline-item:hover {
        transform: translateX(5px);
        box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
    }

    .timeline-icon {
        position: absolute;
        left: -20px;
        top: 50%;
        transform: translateY(-50%);
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--bs-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.15);
    }

    .timeline-content {
        padding-left: 1.5rem;
    }

    .timeline-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
        font-weight: 600;
    }

    .timeline-time {
        font-size: 0.875rem;
        color: #6c757d;
        font-weight: normal;
    }

    .timeline-description {
        font-size: 0.875rem;
        color: #495057;
    }

    .marco-especial {
        background: #f8f9fa;
    }

    .marco-especial .timeline-icon {
        background: var(--purple-gradient-start);
    }

    .progress {
        border-radius: 1rem;
        overflow: hidden;
        background-color: rgba(0,0,0,0.05);
    }

    .progress-bar {
        transition: width 0.6s ease;
    }

    /* Animações */
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .modal.show .modal-content {
        animation: slideIn 0.3s ease forwards;
    }

    .card {
        animation: slideIn 0.3s ease forwards;
    }

    .card:nth-child(2) {
        animation-delay: 0.1s;
    }

    .card:nth-child(3) {
        animation-delay: 0.2s;
    }

    .card:nth-child(4) {
        animation-delay: 0.3s;
    }

    .card:nth-child(5) {
        animation-delay: 0.4s;
    }

    /* Estilos do Modal */
    .modal-content {
        border: none;
        border-radius: 20px;
        box-shadow: 0 15px 35px rgba(0,0,0,0.25);
        overflow: hidden;
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
    }

    /* Efeito de borda interna para modais */
    .modal-content::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: 20px;
        border: 1px solid rgba(255,255,255,0.1);
        pointer-events: none;
    }

    /* Melhoria visual para cards dentro dos modais */
    .modal .card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        overflow: hidden;
    }

    .modal .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    .modal .card-header {
        background: linear-gradient(135deg, var(--bs-primary), var(--bs-primary-dark, #1565C0));
        color: white;
        border: none;
        padding: 1.25rem;
        border-radius: 16px 16px 0 0;
        position: relative;
        overflow: hidden;
    }

    .modal .card-header::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 100px;
        height: 100%;
        background: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.1) 100%);
        transform: skewX(-15deg);
    }

    .modal .card-header h6 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .modal .card-body {
        padding: 1.5rem;
    }

    /* Informações do Cliente */
    .info-label {
        color: #6c757d;
        font-size: 0.875rem;
        font-weight: 500;
        margin-bottom: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .info-value {
        color: #2c3e50;
        font-size: 1rem;
        font-weight: 500;
        margin-bottom: 1rem;
        padding: 0.75rem 1rem;
        background: #f8f9fa;
        border-radius: 10px;
        border: 1px solid #e9ecef;
    }

    /* Timeline */
    .timeline-container {
        position: relative;
        padding: 1.5rem 0;
    }

    .timeline-item {
        position: relative;
        padding: 1.25rem;
        margin-bottom: 1rem;
        background: white;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.05);
        border-left: 4px solid;
        transition: all 0.3s ease;
    }

    .timeline-item.concluido {
        border-left-color: #28a745;
    }

    .timeline-item.pendente {
        border-left-color: #dee2e6;
        opacity: 0.75;
    }

    .timeline-item:hover {
        transform: translateX(5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }

    .timeline-icon {
        position: absolute;
        left: -22px;
        top: 50%;
        transform: translateY(-50%);
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: var(--bs-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        border: 4px solid white;
    }

    .timeline-content {
        padding-left: 1rem;
    }

    .timeline-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: #2c3e50;
    }

    .timeline-time {
        font-size: 0.875rem;
        color: #6c757d;
        font-weight: normal;
    }

    .timeline-description {
        font-size: 0.875rem;
        color: #495057;
    }

    /* Chat Messages */
    .chat-container {
        max-height: 400px;
        overflow-y: auto;
        padding: 1rem;
        background: #f8f9fa;
        border-radius: 16px;
    }

    .chat-message {
        background: white;
        padding: 1.25rem;
        border-radius: 12px;
        margin-bottom: 1rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #e9ecef;
        transition: all 0.3s ease;
    }

    .chat-message:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }

    .chat-message:last-child {
        margin-bottom: 0;
    }

    .chat-message .message-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #e9ecef;
    }

    .chat-message .message-title {
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .chat-message .message-time {
        font-size: 0.875rem;
        color: #6c757d;
    }

    .chat-message .message-content {
        color: #495057;
        line-height: 1.5;
    }
    
    /* Estilos específicos para perguntas e respostas */
    .chat-message .message-content .bg-light {
        border-left: 3px solid #0d6efd;
    }
    
    .chat-message .message-content .bg-success {
        border-left: 3px solid #198754;
    }

    /* Botões do Modal */
    .modal-footer {
        background: #f8f9fa;
        border-top: 1px solid #e9ecef;
        padding: 1.5rem;
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
    }

    .modal-footer .btn {
        padding: 0.75rem 1.5rem;
        font-weight: 500;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.3s ease;
    }

    .modal-footer .btn:hover {
        transform: translateY(-2px);
    }

    .modal-footer .btn-secondary {
        background: #e9ecef;
        border: none;
        color: #495057;
    }

    .modal-footer .btn-success {
        background: linear-gradient(135deg, #28a745, #218838);
        border: none;
    }

    /* Scrollbar Personalizada */
    .modal ::-webkit-scrollbar {
        width: 8px;
    }

    .modal ::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }

    .modal ::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 4px;
    }

    .modal ::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }

    /* Badges e Status */
    .modal .badge {
        padding: 0.5rem 1rem;
        border-radius: 30px;
        font-weight: 500;
        font-size: 0.875rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }

    /* Badge de versão incompatível */
    .badge.bg-danger.incompativel {
        animation: pulseBadge 1.5s infinite;
        box-shadow: 0 0 10px rgba(220, 53, 69, 0.5);
    }

    /* Badge de tamanho menor para a tabela */
    .badge-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.7rem;
        font-weight: 500;
    }

    .badge-sm.incompativel {
        animation: pulseBadge 1.5s infinite;
        box-shadow: 0 0 8px rgba(220, 53, 69, 0.5);
    }
    
    /* Separador vertical para badges */
    .badge-divider {
        display: inline-block;
        width: 1px;
        height: 12px;
        background-color: rgba(0, 0, 0, 0.1);
        vertical-align: middle;
        position: relative;
        top: -1px;
    }

    @keyframes pulseBadge {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); box-shadow: 0 0 15px rgba(220, 53, 69, 0.7); }
        100% { transform: scale(1); }
    }

    /* Animações */
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .modal.show .modal-content {
        animation: slideIn 0.3s ease forwards;
    }

    .modal .row > [class*='col-'] {
        opacity: 0;
        animation: slideIn 0.3s ease forwards;
    }

    .modal .row > [class*='col-']:nth-child(2) {
        animation-delay: 0.1s;
    }

    .modal .row > [class*='col-']:nth-child(3) {
        animation-delay: 0.2s;
    }

    .modal .row > [class*='col-']:nth-child(4) {
        animation-delay: 0.3s;
    }

    /* Melhorias adicionais para modais */
    @media (max-width: 768px) {
        .modal-dialog {
            margin: 0.5rem;
            max-width: calc(100% - 1rem);
        }
        
        .modal-body {
            padding: 1.25rem;
        }
        
        .modal-header {
            padding: 1.25rem;
        }
        
        .modal-footer {
            padding: 1.25rem;
            flex-direction: column;
        }
        
        .modal-footer .btn {
            width: 100%;
            margin-bottom: 0.5rem;
        }
        
        .modal-footer .btn:last-child {
            margin-bottom: 0;
        }
        
        .row.g-4 {
            --bs-gutter-y: 1rem;
        }
    }

    /* Melhorias de acessibilidade e foco */
    .btn-close:focus {
        box-shadow: 0 0 0 0.25rem rgba(255,255,255,0.5);
        outline: none;
    }

    .modal-backdrop.show {
        opacity: 0.7;
    }

    /* Animação de entrada e saída do modal */
    .modal.fade .modal-dialog {
        transition: transform 0.3s ease-out, opacity 0.3s ease;
        transform: translateY(-20px);
        opacity: 0;
    }

    .modal.show .modal-dialog {
        transform: translateY(0);
        opacity: 1;
    }

    .modal.fade .modal-content {
        transition: all 0.3s ease;
    }

    /* Melhoria visual para o modal de detalhes */
    #modalDetalhes .modal-header {
        background: linear-gradient(135deg, #1a237e, #283593);
    }

    /* Melhoria visual para o modal de processamento */
    #modalProcessarPedido .modal-header {
        background: linear-gradient(135deg, #FF9800, #F57C00);
    }

    #modalProcessarPedido .modal-header .btn-close {
        filter: invert(0);
    }

    /* Efeito de hover nos cards do modal */
    .modal .card {
        transition: all 0.3s ease;
    }

    .modal .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    /* Remover botão de fechar alternativo */
    .modal-header .btn-fechar-alternativo {
        display: none;
    }

    @media (max-width: 576px) {
        .modal-header .btn-fechar-alternativo {
            width: 32px;
            height: 32px;
            font-size: 1rem;
        }
    }

    /* Melhorias visuais finais para modais */
    .modal-content {
        box-shadow: 0 15px 35px rgba(0,0,0,0.25);
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
    }

    /* Efeito de borda interna para modais */
    .modal-content::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: 20px;
        border: 1px solid rgba(255,255,255,0.1);
        pointer-events: none;
    }

    /* Melhoria para o efeito de entrada do modal */
    @keyframes modalFadeIn {
        from {
            opacity: 0;
            transform: translateY(-30px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .modal.show .modal-content {
        animation: modalFadeIn 0.4s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
    }

    /* Melhorias para os botões do modal */
    .modal-footer .btn {
        border-radius: 12px;
        padding: 0.75rem 1.5rem;
        font-weight: 500;
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .modal-footer .btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }

    .modal-footer .btn:active {
        transform: translateY(-1px);
    }

    /* Melhoria para o backdrop do modal */
    .modal-backdrop {
        backdrop-filter: blur(5px);
        background-color: rgba(0,0,0,0.5);
        transition: all 0.3s ease;
    }

    /* Melhoria para scrollbar dentro do modal */
    .modal-body::-webkit-scrollbar {
        width: 8px;
    }

    .modal-body::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.05);
        border-radius: 4px;
    }

    .modal-body::-webkit-scrollbar-thumb {
        background: rgba(0,0,0,0.2);
        border-radius: 4px;
    }

    .modal-body::-webkit-scrollbar-thumb:hover {
        background: rgba(0,0,0,0.3);
    }

    /* Efeito de foco nos inputs dentro do modal */
    .modal input:focus,
    .modal select:focus,
    .modal textarea:focus {
        box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.2);
        border-color: var(--primary-color);
    }
    
    /* Estilos para anotações */
    .anotacao-item {
        border-left: 4px solid var(--primary-color);
        transition: all 0.3s ease;
    }
    
    .anotacao-item:hover {
        transform: translateX(5px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    }
    
    .comentario-texto {
        line-height: 1.5;
        color: #495057;
    }
    
    #form-anotacao textarea {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        transition: all 0.3s ease;
    }
    
    #form-anotacao textarea:focus {
        background-color: #fff;
        box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        border-color: var(--primary-color);
    }
    
    #btn-salvar-anotacao {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        border: none;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
    }
    
    #btn-salvar-anotacao:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
    }
    
    #btn-salvar-anotacao:active {
        transform: translateY(-1px);
    }
    
    /* Estilo para o indicador de carregamento das anotações */
    #lista-anotacoes .spinner-border {
        width: 2rem;
        height: 2rem;
        color: var(--primary-color);
    }
    
    /* Animação de entrada para novas anotações */
    @keyframes fadeInAnotacao {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .anotacao-item {
        animation: fadeInAnotacao 0.5s ease forwards;
    }

    /* Melhoria nos botões de ação da tabela */
    .btn-group .action-btn {
        position: relative;
        overflow: hidden;
    }

    .btn-group .action-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, 
            rgba(255, 255, 255, 0), 
            rgba(255, 255, 255, 0.2), 
            rgba(255, 255, 255, 0));
        transition: all 0.6s ease;
    }

    .btn-group .action-btn:hover::before {
        left: 100%;
    }

    /* Correção para dropdown de ações */
    .table-responsive {
        overflow: visible !important;
        position: static !important;
    }
    
    .table-container {
        overflow: visible !important;
        position: static !important;
    }
    
    .card-body {
        overflow: visible !important;
        position: static !important;
    }
    
    .card {
        overflow: visible !important;
        position: static !important;
    }
    
    .table {
        overflow: visible !important;
        position: static !important;
    }
    
    .table td {
        overflow: visible !important;
        position: static !important;
    }
    
    .table td:last-child {
        position: relative !important;
        overflow: visible !important;
    }
    
    .btn-group {
        position: relative !important;
        z-index: 1000 !important;
    }
    
    .dropdown {
        position: relative !important;
        z-index: 1001 !important;
    }
    
    .dropdown-menu {
        position: absolute !important;
        z-index: 99999 !important;
        min-width: 200px;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.25) !important;
        border: 1px solid rgba(0, 0, 0, 0.15);
        border-radius: 0.375rem;
        background-color: #fff !important;
        top: 100% !important;
        left: auto !important;
        right: 0 !important;
        transform: none !important;
        margin-top: 2px !important;
    }
    
    .dropdown-menu.show {
        display: block !important;
        position: absolute !important;
        z-index: 99999 !important;
        top: 100% !important;
        left: auto !important;
        right: 0 !important;
        transform: none !important;
    }
    
    /* Garantir que o dropdown apareça mesmo em containers com overflow hidden */
    .table-responsive .dropdown-menu {
        position: fixed !important;
        z-index: 99999 !important;
    }
    
    .dropdown-item {
        padding: 0.5rem 1rem;
        transition: all 0.2s ease;
        border: none;
        background: none;
        width: 100%;
        text-align: left;
        color: #212529;
        text-decoration: none;
        display: flex;
        align-items: center;
        white-space: nowrap;
    }
    
    .dropdown-item:hover {
        background-color: #f8f9fa;
        color: #1e2125;
    }
    
    .dropdown-item.text-danger:hover {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    /* Forçar todos os containers pais a permitir overflow visível */
    .table-responsive,
    .table-container,
    .card-body,
    .card,
    .table,
    .table td,
    .container-fluid {
        overflow: visible !important;
        position: static !important;
    }
    
    /* Garantir que o dropdown sempre apareça acima de tudo */
    .dropdown-menu {
        z-index: 999999 !important;
        position: absolute !important;
        will-change: transform !important;
    }
    
    .dropdown-menu.show {
        z-index: 999999 !important;
        position: absolute !important;
        display: block !important;
    }
    
    /* Forçar que elementos da tabela não interfiram */
    .table tbody tr {
        position: static !important;
        z-index: auto !important;
    }
    
    .table tbody tr td {
        position: static !important;
        z-index: auto !important;
    }
    
    /* Garantir que o botão dropdown tenha contexto de empilhamento */
    .btn-group.dropdown {
        position: relative !important;
        z-index: 1000 !important;
        isolation: isolate !important;
    }
    
    /* Remover qualquer transformação que possa interferir */
    .table-responsive * {
        transform: none !important;
    }
    
    /* Garantir que o dropdown seja visível mesmo em scroll */
    .dropdown-menu {
        position: fixed !important;
        z-index: 999999 !important;
        margin: 0 !important;
        padding: 0.5rem 0 !important;
    }
    
    /* Estilos específicos para a coluna de ações */
    .actions-column {
        min-width: 120px !important;
        width: 120px !important;
        padding: 8px 12px !important;
        vertical-align: middle !important;
    }
    
    .actions-column .d-flex {
        gap: 4px !important;
        flex-wrap: nowrap !important;
        justify-content: center !important;
        align-items: center !important;
    }
    
    .actions-column .btn {
        position: relative !important;
        flex-shrink: 0 !important;
        min-width: 32px !important;
        height: 32px !important;
        padding: 4px 8px !important;
        border-radius: 6px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    
    .actions-column .btn i {
        font-size: 12px !important;
    }
    
    .actions-column .dropdown {
        position: relative !important;
        z-index: 1000 !important;
    }
    
    .actions-column .dropdown-toggle::after {
        display: none !important;
    }
    
    /* Badge de notificação no botão de detalhes */
    .ver-detalhes {
        position: relative !important;
    }
    
    .ver-detalhes .badge {
        position: absolute !important;
        top: -4px !important;
        right: -4px !important;
        transform: none !important;
        font-size: 8px !important;
        padding: 2px 4px !important;
        border-radius: 50% !important;
        min-width: 16px !important;
        height: 16px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    
    /* Melhorar responsividade da tabela */
    @media (max-width: 768px) {
        .actions-column {
            min-width: 100px !important;
            width: 100px !important;
        }
        
        .actions-column .btn {
            min-width: 28px !important;
            height: 28px !important;
            padding: 2px 6px !important;
        }
        
        .actions-column .btn i {
            font-size: 10px !important;
        }
    }
    
    /* Garantir que os botões não se sobreponham */
    .table td.actions-column {
        overflow: visible !important;
        position: relative !important;
        z-index: 1 !important;
    }
    
    .table tbody tr:hover td.actions-column {
        z-index: 2 !important;
    }

    /* Estilos específicos para o modal de detalhes melhorado */
    .modal-xl {
        max-width: 1200px;
    }
    
    /* Header do modal com gradiente */
    .modal-header.bg-gradient-primary {
        background: linear-gradient(135deg, #1e3c72, #2a5298) !important;
        min-height: 120px;
        position: relative;
    }
    
    .modal-header .position-absolute {
        pointer-events: none;
    }
    
    /* Informações no header */
    #header-nome-cliente {
        font-size: 1.5rem;
        font-weight: 700;
        text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        line-height: 1.2;
    }
    
    .modal-header .text-white-50 {
        font-size: 0.9rem;
        opacity: 0.9;
        line-height: 1.4;
    }
    
    /* Cards internos do modal */
    .modal-body .card {
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        border: none;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    }
    
    .modal-body .card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.12);
    }
    
    /* Headers dos cards com gradientes */
    .card-header.bg-gradient-info {
        background: linear-gradient(135deg, #17a2b8, #138496) !important;
    }
    
    .card-header.bg-gradient-dark {
        background: linear-gradient(135deg, #343a40, #495057) !important;
    }
    
    .card-header.bg-gradient-success {
        background: linear-gradient(135deg, #28a745, #20c997) !important;
    }
    
    .card-header.bg-gradient-purple {
        background: linear-gradient(135deg, #6f42c1, #e83e8c) !important;
    }
    
    /* Alinhamento profissional dos ícones e labels */
    .info-item {
        transition: all 0.3s ease;
        margin-bottom: 1.5rem;
    }
    
    .info-item:last-child {
        margin-bottom: 0;
    }
    
    .info-item:hover {
        transform: translateX(3px);
    }
    
    .info-label {
        font-size: 0.875rem;
        color: #6c757d;
        font-weight: 600;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        line-height: 1.2;
    }
    
    .info-label i {
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 0.75rem;
        font-size: 0.875rem;
        flex-shrink: 0;
    }
    
    .info-value {
        font-weight: 500;
        color: #2c3e50;
        transition: all 0.3s ease;
        border: 1px solid rgba(0,0,0,0.05);
        padding: 1rem 1.25rem;
        border-radius: 12px;
        background: #f8f9fa;
        font-size: 0.95rem;
        line-height: 1.4;
        min-height: 50px;
        display: flex;
        align-items: center;
    }
    
    .info-value:hover {
        background-color: #fff !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        border-color: rgba(0,0,0,0.1);
    }
    
    .info-value-small {
        padding: 0.75rem 1rem;
        background-color: #f8f9fa;
        border-radius: 10px;
        border: 1px solid #e9ecef;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        min-height: 42px;
        display: flex;
        align-items: center;
        font-family: 'Segoe UI', system-ui, sans-serif;
        line-height: 1.3;
    }
    
    .info-value-small:hover {
        background-color: #fff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        border-color: rgba(0,0,0,0.15);
    }
    
    .icon-wrapper {
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    /* Barra de progresso visual */
    .progress {
        background-color: rgba(0,0,0,0.05);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        height: 12px;
    }
    
    .progress-bar.bg-gradient-success {
        background: linear-gradient(90deg, #28a745, #20c997, #17a2b8);
        background-size: 200% 100%;
        animation: progressShine 2s ease-in-out infinite;
    }
    
    @keyframes progressShine {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }
    
    .etapa-item {
        padding: 0.5rem 0.75rem;
        border-radius: 8px;
        transition: all 0.3s ease;
        cursor: pointer;
        font-size: 0.8rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        text-align: center;
        flex-direction: column;
    }
    
    .etapa-item i {
        font-size: 1rem;
        margin-bottom: 0.25rem;
    }
    
    .etapa-item:hover {
        background-color: rgba(0,0,0,0.05);
        transform: translateY(-2px);
    }
    
    .etapa-item.ativa {
        background-color: #28a745;
        color: white;
        font-weight: 600;
        box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
    }
    
    .etapa-item.concluida {
        background-color: #17a2b8;
        color: white;
        font-weight: 500;
        box-shadow: 0 2px 8px rgba(23, 162, 184, 0.3);
    }
    
    /* Timeline moderna - Jornada do Cliente */
    .timeline-container-modern {
        position: relative;
        padding: 1.5rem 0;
        margin: 0 1.5rem; /* Reduzido de 2rem para 1.5rem */
    }
    
    .timeline-item-modern {
        position: relative;
        padding: 1.5rem 1.5rem 1.5rem 4rem; /* Reduzido o padding */
        margin-bottom: 1.5rem;
        background: linear-gradient(135deg, #fff, #f8f9fa);
        border-radius: 12px; /* Reduzido de 16px para 12px */
        box-shadow: 0 3px 12px rgba(0,0,0,0.05); /* Sombra mais sutil */
        border-left: 3px solid #e9ecef; /* Borda mais fina */
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        overflow: visible;
    }
    
    .timeline-item-modern::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(45deg, rgba(255,255,255,0.1), transparent);
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .timeline-item-modern:hover::before {
        opacity: 1;
    }
    
    .timeline-item-modern.concluido {
        border-left-color: #28a745;
        background: linear-gradient(135deg, #f8fff9, #f0fff4);
    }
    
    .timeline-item-modern.pendente {
        opacity: 0.7;
        border-left-color: #dee2e6;
    }
    
    .timeline-item-modern:hover {
        transform: translateX(5px) translateY(-1px); /* Movimento mais sutil */
        box-shadow: 0 6px 20px rgba(0,0,0,0.08);
    }
    
    .timeline-icon-modern {
        position: absolute;
        left: -22px; /* Reduzido de -30px para -22px */
        top: 50%;
        transform: translateY(-50%);
        width: 44px; /* Reduzido de 60px para 44px */
        height: 44px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6c757d, #495057);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        box-shadow: 0 4px 15px rgba(0,0,0,0.15); /* Sombra mais sutil */
        border: 3px solid white; /* Borda mais fina */
        transition: all 0.3s ease;
        font-size: 1rem; /* Reduzido de 1.3rem para 1rem */
        z-index: 10;
    }
    
    .timeline-item-modern.concluido .timeline-icon-modern {
        background: linear-gradient(135deg, #28a745, #20c997);
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }
    
    .timeline-item-modern.pendente .timeline-icon-modern {
        background: linear-gradient(135deg, #dee2e6, #adb5bd);
        box-shadow: 0 3px 12px rgba(0,0,0,0.1);
    }
    
    .timeline-item-modern:hover .timeline-icon-modern {
        transform: translateY(-50%) scale(1.05); /* Escala menor no hover */
        box-shadow: 0 6px 20px rgba(0,0,0,0.2);
    }
    
    .timeline-content h6 {
        font-size: 1rem; /* Reduzido de 1.2rem para 1rem */
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 0.5rem;
        line-height: 1.3;
    }
    
    .timeline-content .text-muted {
        font-size: 0.85rem; /* Reduzido de 0.9rem para 0.85rem */
        line-height: 1.4;
        color: #6c757d;
    }
    
    .timeline-content .badge {
        font-size: 0.75rem;
        padding: 0.35rem 0.75rem; /* Reduzido o padding */
        font-weight: 500;
        border-radius: 15px; /* Reduzido de 20px para 15px */
        margin-top: 0.25rem;
    }
    
    /* Etapas da jornada - Barra de progresso visual */
    .etapas-container {
        padding: 1.25rem; /* Reduzido de 1.5rem para 1.25rem */
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border-radius: 12px; /* Reduzido de 16px para 12px */
        margin-bottom: 1.25rem;
        overflow: visible;
    }
    
    .etapas-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); /* Reduzido de 120px para 100px */
        gap: 0.75rem; /* Reduzido de 1rem para 0.75rem */
        margin-top: 0.75rem;
    }
    
    .etapa-item {
        padding: 0.75rem 0.5rem; /* Reduzido o padding */
        border-radius: 10px; /* Reduzido de 12px para 10px */
        transition: all 0.3s ease;
        cursor: pointer;
        font-size: 0.8rem; /* Reduzido de 0.85rem para 0.8rem */
        font-weight: 500;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem; /* Reduzido de 0.5rem para 0.4rem */
        text-align: center;
        flex-direction: column;
        min-height: 65px; /* Reduzido de 80px para 65px */
        background: white;
        border: 2px solid #e9ecef;
        position: relative;
        overflow: visible;
    }
    
    .etapa-item i {
        font-size: 1.1rem; /* Reduzido de 1.4rem para 1.1rem */
        margin-bottom: 0.35rem; /* Reduzido de 0.5rem para 0.35rem */
        width: 20px; /* Reduzido de 24px para 20px */
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    .etapa-item:hover {
        background-color: #f8f9fa;
        transform: translateY(-2px); /* Reduzido de -3px para -2px */
        box-shadow: 0 3px 12px rgba(0,0,0,0.08); /* Sombra mais sutil */
        border-color: #007bff;
    }
    
    .etapa-item.ativa {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        font-weight: 600;
        box-shadow: 0 3px 12px rgba(40, 167, 69, 0.25); /* Sombra mais sutil */
        border-color: #28a745;
        transform: scale(1.02); /* Escala menor */
    }
    
    .etapa-item.concluida {
        background: linear-gradient(135deg, #17a2b8, #20c997);
        color: white;
        font-weight: 500;
        box-shadow: 0 3px 12px rgba(23, 162, 184, 0.25); /* Sombra mais sutil */
        border-color: #17a2b8;
    }
    
    .etapa-item.pendente {
        background: #f8f9fa;
        color: #6c757d;
        border-color: #dee2e6;
        opacity: 0.8;
    }
    
    /* Conectores entre etapas */
    .etapa-item::after {
        content: '';
        position: absolute;
        top: 50%;
        right: -0.375rem; /* Reduzido de -0.5rem para -0.375rem */
        width: 0.75rem; /* Reduzido de 1rem para 0.75rem */
        height: 2px;
        background: #dee2e6;
        transform: translateY(-50%);
        z-index: 1;
    }
    
    .etapa-item:last-child::after {
        display: none;
    }
    
    .etapa-item.concluida::after,
    .etapa-item.ativa::after {
        background: #28a745;
    }
    
    /* Barra de progresso principal */
    .progress-jornada {
        background-color: rgba(0,0,0,0.05);
        border-radius: 12px; /* Reduzido de 15px para 12px */
        overflow: hidden;
        box-shadow: inset 0 1px 3px rgba(0,0,0,0.08); /* Sombra mais sutil */
        height: 12px; /* Reduzido de 16px para 12px */
        margin: 1.25rem 0; /* Reduzido de 1.5rem para 1.25rem */
        position: relative;
    }
    
    .progress-jornada .progress-bar {
        background: linear-gradient(90deg, #28a745, #20c997, #17a2b8);
        background-size: 200% 100%;
        animation: progressShine 3s ease-in-out infinite;
        border-radius: 12px;
        position: relative;
        overflow: hidden;
    }
    
    .progress-jornada .progress-bar::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(45deg, rgba(255,255,255,0.2), transparent);
        animation: progressGlow 2s ease-in-out infinite alternate;
    }
    
    @keyframes progressShine {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }
    
    @keyframes progressGlow {
        0% { opacity: 0.3; }
        100% { opacity: 0.7; }
    }
    
    /* Indicadores de progresso */
    .progresso-indicador {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.75rem; /* Reduzido de 1rem para 0.75rem */
        padding: 0.75rem; /* Reduzido de 1rem para 0.75rem */
        background: white;
        border-radius: 10px; /* Reduzido de 12px para 10px */
        box-shadow: 0 2px 6px rgba(0,0,0,0.04); /* Sombra mais sutil */
    }
    
    .progresso-texto {
        font-size: 0.85rem; /* Reduzido de 0.9rem para 0.85rem */
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 0.4rem; /* Reduzido de 0.5rem para 0.4rem */
    }
    
    .progresso-texto i {
        width: 16px; /* Reduzido de 18px para 16px */
        height: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #007bff;
    }
    
    .progresso-porcentagem {
        font-size: 1rem; /* Reduzido de 1.1rem para 1rem */
        font-weight: 700;
        color: #28a745;
        background: linear-gradient(135deg, #e8f5e9, #f0fff4);
        padding: 0.4rem 0.8rem; /* Reduzido o padding */
        border-radius: 15px; /* Reduzido de 20px para 15px */
        border: 1px solid rgba(40, 167, 69, 0.2);
    }
    
    /* Responsividade específica para jornada */
    @media (max-width: 1200px) {
        .timeline-container-modern {
            margin: 0 1rem;
        }
        
        .timeline-item-modern {
            padding: 1.25rem 1.25rem 1.25rem 3.5rem;
        }
        
        .timeline-icon-modern {
            width: 40px;
            height: 40px;
            left: -20px;
            font-size: 0.9rem;
        }
        
        .etapas-grid {
            grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
        }
    }
    
    @media (max-width: 768px) {
        .timeline-container-modern {
            margin: 0 0.5rem;
        }
        
        .timeline-item-modern {
            padding: 1rem 1rem 1rem 3rem;
            margin-bottom: 1.25rem;
        }
        
        .timeline-icon-modern {
            width: 36px;
            height: 36px;
            left: -18px;
            font-size: 0.85rem;
            border-width: 2px;
        }
        
        .timeline-content h6 {
            font-size: 0.9rem;
        }
        
        .timeline-content .text-muted {
            font-size: 0.8rem;
        }
        
        .etapas-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.6rem;
        }
        
        .etapa-item {
            padding: 0.6rem 0.4rem;
            font-size: 0.75rem;
            min-height: 55px;
        }
        
        .etapa-item i {
            font-size: 1rem;
            width: 18px;
            height: 18px;
        }
        
        .progress-jornada {
            height: 10px;
            margin: 1rem 0;
        }
        
        .progresso-indicador {
            padding: 0.6rem;
            flex-direction: column;
            gap: 0.4rem;
            text-align: center;
        }
        
        .progresso-texto {
            font-size: 0.8rem;
        }
        
        .progresso-porcentagem {
            font-size: 0.9rem;
        }
    }
    
    @media (max-width: 480px) {
        .timeline-container-modern {
            margin: 0;
        }
        
        .timeline-item-modern {
            padding: 0.85rem 0.75rem 0.85rem 2.5rem;
        }
        
        .timeline-icon-modern {
            width: 32px;
            height: 32px;
            left: -16px;
            font-size: 0.8rem;
            border-width: 2px;
        }
        
        .etapas-grid {
            grid-template-columns: 1fr;
        }
        
        .etapa-item {
            min-height: 50px;
            padding: 0.5rem;
        }
        
        .etapa-item i {
            font-size: 0.9rem;
            width: 16px;
            height: 16px;
        }
    }
    
    /* Melhorias específicas para evitar cortes */
    .card-body {
        overflow: visible !important;
        padding: 1.5rem 2rem; /* Mais padding para dar espaço aos ícones */
    }
    
    .modal-body {
        overflow-x: visible;
        overflow-y: auto;
    }
    
    /* Container específico para jornada */
    #timeline-marcos {
        overflow: visible !important;
        position: relative;
        z-index: 1;
    }
    
    /* Garantir que o card da jornada tenha espaço suficiente */
    .card:has(#timeline-marcos) {
        overflow: visible !important;
    }
    
    .card:has(#timeline-marcos) .card-body {
        overflow: visible !important;
        padding: 2rem 3rem 2rem 2rem; /* Espaço extra à direita para ícones */
    }

    /* Chat container moderno */
    .chat-container-modern {
        max-height: 500px;
        overflow-y: auto;
        padding: 1.5rem;
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border-radius: 12px;
    }
    
    .chat-message-modern {
        background: white;
        padding: 1.75rem;
        border-radius: 16px;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        border: 1px solid rgba(0,0,0,0.05);
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        position: relative;
        overflow: hidden;
    }
    
    .chat-message-modern::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: linear-gradient(135deg, #007bff, #6610f2);
    }
    
    .chat-message-modern:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    
    .chat-message-modern:last-child {
        margin-bottom: 0;
    }
    
    .message-header-modern {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #e9ecef;
    }
    
    .message-title-modern {
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 1.1rem;
        line-height: 1.3;
    }
    
    .message-title-modern i {
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    .message-time-modern {
        font-size: 0.875rem;
        color: #6c757d;
        background: #f8f9fa;
        padding: 0.4rem 0.9rem;
        border-radius: 20px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .message-content-modern {
        color: #495057;
        line-height: 1.6;
    }
    
    .message-content-modern .pergunta-box {
        background: linear-gradient(135deg, #e3f2fd, #f3e5f5);
        padding: 1.25rem;
        border-radius: 12px;
        border-left: 4px solid #2196f3;
        margin-bottom: 1.25rem;
    }
    
    .message-content-modern .pergunta-box .d-flex {
        align-items: center;
        margin-bottom: 0.75rem;
    }
    
    .message-content-modern .pergunta-box i {
        width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 0.5rem;
        flex-shrink: 0;
    }
    
    .message-content-modern .resposta-box {
        background: linear-gradient(135deg, #e8f5e9, #f1f8e9);
        padding: 1.25rem;
        border-radius: 12px;
        border-left: 4px solid #4caf50;
    }
    
    .message-content-modern .resposta-box .d-flex {
        align-items: center;
        margin-bottom: 0.75rem;
    }
    
    .message-content-modern .resposta-box i {
        width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 0.5rem;
        flex-shrink: 0;
    }
    
    /* Anotações melhoradas */
    .anotacao-item-modern {
        background: white;
        padding: 1.75rem;
        border-radius: 12px;
        margin-bottom: 1.25rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        border-left: 4px solid #007bff;
        transition: all 0.3s ease;
        position: relative;
    }
    
    .anotacao-item-modern:hover {
        transform: translateX(5px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    
    .anotacao-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #e9ecef;
    }
    
    .anotacao-autor {
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 1rem;
        line-height: 1.3;
    }
    
    .anotacao-autor i {
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 1.1rem;
    }
    
    .anotacao-data {
        font-size: 0.875rem;
        color: #6c757d;
        background: #f8f9fa;
        padding: 0.4rem 0.9rem;
        border-radius: 15px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .anotacao-data i {
        width: 14px;
        height: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    .anotacao-texto {
        color: #495057;
        line-height: 1.6;
        white-space: pre-wrap;
        font-size: 0.95rem;
    }
    
    /* Formulário de anotação melhorado */
    #form-anotacao textarea {
        background: linear-gradient(135deg, #fff, #f8f9fa);
        border: 2px solid #e9ecef;
        border-radius: 12px;
        transition: all 0.3s ease;
        font-size: 1rem;
        line-height: 1.5;
        padding: 1rem 1.25rem;
    }
    
    #form-anotacao textarea:focus {
        background: white;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        transform: translateY(-2px);
    }
    
    #form-anotacao .form-label {
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
    }
    
    #form-anotacao .form-label i {
        width: 16px;
        height: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    /* Contadores de badges */
    #contador-conversas, #contador-anotacoes {
        font-size: 0.75rem;
        padding: 0.35rem 0.7rem;
        border-radius: 12px;
        font-weight: 600;
        min-width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    /* Headers dos cards melhorados */
    .card-header h6 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        line-height: 1.3;
    }
    
    .card-header h6 i {
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 1rem;
    }
    
    /* Footer do modal */
    .modal-footer {
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        border-top: 1px solid rgba(0,0,0,0.05);
        padding: 1.5rem 2rem;
    }
    
    .modal-footer .btn {
        border-radius: 10px;
        padding: 0.75rem 1.5rem;
        font-weight: 500;
        transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.95rem;
    }
    
    .modal-footer .btn i {
        width: 16px;
        height: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    .modal-footer .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
    }
    
    .modal-footer .text-muted {
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .modal-footer .text-muted i {
        width: 14px;
        height: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    
    /* Badges melhorados */
    .badge.rounded-pill {
        padding: 0.5rem 1rem;
        font-size: 0.8rem;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        line-height: 1;
    }
    
    .badge.rounded-pill i {
        width: 14px;
        height: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 0.75rem;
    }
    
    /* Scrollbar personalizada para o modal */
    .modal-body::-webkit-scrollbar,
    .chat-container-modern::-webkit-scrollbar {
        width: 8px;
    }
    
    .modal-body::-webkit-scrollbar-track,
    .chat-container-modern::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.05);
        border-radius: 4px;
    }
    
    .modal-body::-webkit-scrollbar-thumb,
    .chat-container-modern::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, #007bff, #6610f2);
        border-radius: 4px;
    }
    
    .modal-body::-webkit-scrollbar-thumb:hover,
    .chat-container-modern::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, #0056b3, #520dc2);
    }
    
    /* Animações de entrada */
    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-50px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
    
    @keyframes cardFadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .modal.show .modal-content {
        animation: modalSlideIn 0.4s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
    }
    
    .modal-body .card {
        opacity: 0;
        animation: cardFadeIn 0.5s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
    }
    
    .modal-body .card:nth-child(1) { animation-delay: 0.1s; }
    .modal-body .card:nth-child(2) { animation-delay: 0.2s; }
    .modal-body .card:nth-child(3) { animation-delay: 0.3s; }
    .modal-body .card:nth-child(4) { animation-delay: 0.4s; }
    .modal-body .card:nth-child(5) { animation-delay: 0.5s; }
    
    /* Responsividade para o modal */
    @media (max-width: 1200px) {
        .modal-xl {
            max-width: 95%;
        }
    }
    
    @media (max-width: 768px) {
        .modal-header {
            min-height: 100px;
            padding: 1rem;
        }
        
        #header-nome-cliente {
            font-size: 1.25rem;
        }
        
        .modal-header .text-white-50 {
            font-size: 0.8rem;
        }
        
        .modal-body {
            padding: 1rem;
        }
        
        .card-body {
            padding: 1rem;
        }
        
        .info-value {
            padding: 0.75rem 1rem;
        }
        
        .chat-message-modern {
            padding: 1.25rem;
        }
        
        .modal-footer {
            padding: 1rem;
        }
    }
    
    /* Estados de loading melhorados */
    .loading-skeleton {
        background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
        border-radius: 8px;
        height: 20px;
    }
    
    @keyframes loading {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }
    
    /* Melhorias para badges no header */
    .modal-header .badge {
        font-size: 0.75rem;
        padding: 0.35rem 0.75rem;
        border-radius: 15px;
        font-weight: 500;
    }
    
    /* Tipografia profissional */
    .modal-content {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    }
    
    .modal-content h1, .modal-content h2, .modal-content h3, 
    .modal-content h4, .modal-content h5, .modal-content h6 {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        font-weight: 600;
        letter-spacing: -0.025em;
    }
    
    /* Melhorias gerais de alinhamento */
    .d-flex.align-items-center {
        align-items: center !important;
    }
    
    .d-flex.justify-content-between {
        justify-content: space-between !important;
    }
    
    /* Espaçamento consistente */
    .modal-body .row {
        margin-bottom: 0;
    }
    
    .modal-body .row + .row {
        margin-top: 1.5rem;
    }
    
    /* Melhorias nos spinners de loading */
    .spinner-border {
        width: 2rem;
        height: 2rem;
        border-width: 0.25em;
    }
    
    .spinner-border.text-primary {
        color: #007bff !important;
    }
    
    .spinner-border.text-success {
        color: #28a745 !important;
    }
</style>

<div class="container-fluid">
    <!-- Cabeçalho da página -->
    <div class="page-header">
        <h1><i class="fas fa-user-clock me-2"></i> Sistema de Recuperação de Clientes</h1>
        <p>Gerencie os leads que não completaram o processo de compra e reative clientes potenciais para aumentar sua taxa de conversão</p>
    </div>
    
    <?php if ($mensagem): ?>
    <div class="alert alert-<?php echo $tipo_mensagem; ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-info-circle me-2"></i> <?php echo $mensagem; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
    </div>
    <?php endif; ?>
    
    <div class="row mb-4 g-3">
        <div class="col-md-4">
            <div class="card dashboard-card shadow-sm border-0">
                <div class="card-body p-4 bg-danger text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title mb-1">Total de Clientes</h5>
                            <p class="text-white-50 mb-0">Pendentes de recuperação</p>
                        </div>
                        <h1 class="display-4 mb-0"><?php echo count($pedidos_recuperacao); ?></h1>
                    </div>
                    <div class="icon-bg">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <?php
        // Calcular clientes por origem
        $total_facebook = 0;
        $total_whatsapp = 0;
        
        foreach ($pedidos_recuperacao as $pedido) {
            if (isset($pedido['origem']) && $pedido['origem'] == 'facebook') {
                $total_facebook++;
            } elseif (isset($pedido['origem']) && $pedido['origem'] == 'whatsapp') {
                $total_whatsapp++;
            }
        }
        ?>
        
        <div class="col-md-4">
            <div class="card dashboard-card shadow-sm border-0">
                <div class="card-body p-4 bg-facebook text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title mb-1">Facebook</h5>
                            <p class="text-white-50 mb-0">Clientes abandonados</p>
                        </div>
                        <h1 class="display-4 mb-0"><?php echo $total_facebook; ?></h1>
                    </div>
                    <div class="icon-bg">
                        <i class="fab fa-facebook"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card dashboard-card shadow-sm border-0">
                <div class="card-body p-4 bg-whatsapp text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title mb-1">WhatsApp</h5>
                            <p class="text-white-50 mb-0">Clientes abandonados</p>
                        </div>
                        <h1 class="display-4 mb-0"><?php echo $total_whatsapp; ?></h1>
                    </div>
                    <div class="icon-bg">
                        <i class="fab fa-whatsapp"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card table-card shadow-sm">
                <div class="card-header table-header text-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i> Leads Abandonados
                        <span class="badge bg-white text-danger"><?php echo count($pedidos_recuperacao); ?></span>
                    </h5>
                    <div>
                        <?php if ($nivel_acesso == 'logistica'): ?>
                        <a href="pedidos.php" class="btn btn-sm btn-light me-2">
                            <i class="fas fa-shopping-cart me-1"></i> Ver Pedidos Completos
                        </a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-light btn-atualizar" id="btn-atualizar">
                            <i class="fas fa-sync me-1"></i> Atualizar Lista
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive table-container">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="border-0">Nome</th>
                                    <th class="border-0">Contato</th>
                                    <th class="border-0">Problema Relatado</th>
                                    <th class="border-0">Origem</th>
                                    <th class="border-0">Data Abandono</th>
                                    <th class="border-0">Etapa</th>
                                    <th class="border-0">Tempo</th>
                                    <th class="border-0 text-center actions-column">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pedidos_recuperacao)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-search fa-3x mb-3 d-block"></i>
                                            <p>Nenhum lead abandonado encontrado no momento.</p>
                                            <small>Quando um cliente iniciar, mas não completar o processo de compra, ele aparecerá aqui.</small>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($pedidos_recuperacao as $pedido): ?>
                                <tr>
                                    <td>
                                        <strong class="text-dark"><?php echo htmlspecialchars($pedido['NOME'] ?? 'Não informado'); ?></strong>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-phone-alt text-muted me-2"></i>
                                            <?php echo htmlspecialchars($pedido['CONTATO'] ?? 'Não informado'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        $problema = $pedido['PROBLEMA_RELATADO'] ?? 'Não informado';
                                        echo strlen($problema) > 50 ? htmlspecialchars(substr($problema, 0, 50) . '...') : htmlspecialchars($problema);
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (isset($pedido['origem']) && $pedido['origem'] == 'facebook'): ?>
                                            <span class="badge bg-primary rounded-pill"><i class="fab fa-facebook-f me-1"></i> Facebook</span>
                                        <?php elseif (isset($pedido['origem']) && $pedido['origem'] == 'whatsapp'): ?>
                                            <span class="badge bg-success rounded-pill"><i class="fab fa-whatsapp me-1"></i> WhatsApp</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill"><i class="fas fa-question-circle me-1"></i> Desconhecida</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo isset($pedido['timestamp']) ? date('d/m/Y H:i', strtotime($pedido['timestamp'])) : 'Desconhecida'; ?></td>
                                    <td>
                                        <?php
                                        // Determinar qual foi a última etapa concluída e próxima etapa
                                        $etapas = [
                                            'inicio' => [
                                                'texto' => 'Iniciou conversa',
                                                'classe' => 'bg-secondary',
                                                'icone' => 'fa-comment',
                                                'ordem' => 0
                                            ],
                                            'nome' => [
                                                'texto' => 'Forneceu nome',
                                                'classe' => 'bg-info',
                                                'icone' => 'fa-user',
                                                'ordem' => 1
                                            ],
                                            'contato' => [
                                                'texto' => 'Forneceu contato',
                                                'classe' => 'bg-primary',
                                                'icone' => 'fa-phone',
                                                'ordem' => 2
                                            ],
                                            'problema' => [
                                                'texto' => 'Relatou problema',
                                                'classe' => 'bg-success',
                                                'icone' => 'fa-comment-medical',
                                                'ordem' => 3
                                            ],
                                            'endereco' => [
                                                'texto' => 'Forneceu endereço',
                                                'classe' => 'bg-danger',
                                                'icone' => 'fa-map-marker-alt',
                                                'ordem' => 4
                                            ]
                                        ];

                                        // Determinar a última etapa concluída
                                        $ultima_etapa = 'inicio';
                                        $progresso = 0;

                                        if (!empty($pedido['NOME'])) {
                                            $ultima_etapa = 'nome';
                                            $progresso = 25;
                                        }
                                        if (!empty($pedido['CONTATO'])) {
                                            $ultima_etapa = 'contato';
                                            $progresso = 50;
                                        }
                                        if (!empty($pedido['PROBLEMA_RELATADO'])) {
                                            $ultima_etapa = 'problema';
                                            $progresso = 75;
                                        }
                                        if (!empty($pedido['ENDEREÇO'])) {
                                            $ultima_etapa = 'endereco';
                                            $progresso = 100;
                                        }

                                        $etapa_atual = $etapas[$ultima_etapa];
                                        ?>
                                        
                                        <!-- Badge da etapa atual -->
                                        <div class="d-flex align-items-center">
                                            <span class="badge <?php echo $etapa_atual['classe']; ?> badge-stage">
                                                <i class="fas <?php echo $etapa_atual['icone']; ?> me-1"></i>
                                                <?php echo $etapa_atual['texto']; ?>
                                                <?php if ($progresso < 100): ?>
                                                    <small class="ms-1">(<?php echo $progresso; ?>%)</small>
                                                <?php endif; ?>
                                        </span>
                                        </div>
                                        
                                        <!-- Barra de progresso -->
                                        <div class="progress mt-2" style="height: 5px;">
                                            <div class="progress-bar bg-success" role="progressbar" 
                                                 style="width: <?php echo $progresso; ?>%" 
                                                 aria-valuenow="<?php echo $progresso; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                            </div>
                                        </div>

                                        <?php if (isset($pedido['marcos_progresso']) && is_array($pedido['marcos_progresso']) && !empty($pedido['marcos_progresso'])): ?>
                                        <!-- Tooltips com marcos adicionais -->
                                        <div class="mt-2 small text-muted">
                                        <?php
                                            $marcos_especiais = [
                                                'audio_introdutorio' => ['texto' => 'Ouviu áudio introdutório', 'icone' => 'fa-headphones'],
                                                'perguntas' => ['texto' => 'Fez perguntas', 'icone' => 'fa-question-circle'],
                                                'entrega' => ['texto' => 'Viu informações de entrega', 'icone' => 'fa-truck']
                                            ];
                                            
                                            foreach ($pedido['marcos_progresso'] as $marco) {
                                                if (isset($marcos_especiais[$marco])) {
                                                    echo '<span class="badge bg-purple badge-sm me-1" data-bs-toggle="tooltip" title="' . 
                                                         $marcos_especiais[$marco]['texto'] . '">
                                                         <i class="fas ' . $marcos_especiais[$marco]['icone'] . '"></i>
                                                         </span>';
                                            }
                                            }
                                            
                                            // Adicionar badge de versão na mesma linha dos marcos
                                            if (isset($pedido['versao'])) {
                                                $versao_lower = strtolower($pedido['versao']);
                                                
                                                if ($versao_lower === 'v2-n8n') {
                                                    $versao_texto = 'main';
                                                    $versao_class = 'bg-primary';
                                                    $versao_tooltip = 'Versão principal N8N';
                                                } elseif ($versao_lower === 'v1-main') {
                                                    $versao_texto = 'OLD';
                                                    $versao_class = 'bg-secondary';
                                                    $versao_tooltip = 'Versão antiga';
                                                } else {
                                                    $versao_texto = 'EXP';
                                                    $versao_class = 'bg-info';
                                                    $versao_tooltip = 'Versão experimental';
                                                }
                                                
                                                echo '<span class="badge-divider mx-1"></span>';
                                                echo '<span class="badge ' . $versao_class . ' badge-sm" data-bs-toggle="tooltip" title="' . $versao_tooltip . '">
                                                      <i class="fas fa-code-branch me-1"></i> ' . $versao_texto . '
                                                      </span>';
                                            } else {
                                                echo '<span class="badge-divider mx-1"></span>';
                                                echo '<span class="badge bg-danger badge-sm incompativel" data-bs-toggle="tooltip" title="Versão Incompatível">
                                                      <i class="fas fa-exclamation-triangle me-1"></i> INC
                                                      </span>';
                                            }
                                            ?>
                                        </div>
                                        <?php else: ?>
                                        <!-- Badge de versão (quando não há marcos) -->
                                        <div class="mt-2 small text-muted">
                                        <?php
                                            if (isset($pedido['versao'])) {
                                                $versao_lower = strtolower($pedido['versao']);
                                                if ($versao_lower === 'v2-n8n') {
                                                    $versao_texto = 'main';
                                                    $versao_class = 'bg-primary';
                                                    $versao_tooltip = 'Versão principal N8N';
                                                } elseif ($versao_lower === 'v1-main') {
                                                    $versao_texto = 'OLD';
                                                    $versao_class = 'bg-secondary';
                                                    $versao_tooltip = 'Versão antiga';
                                                } else {
                                                    $versao_texto = 'EXP';
                                                    $versao_class = 'bg-info';
                                                    $versao_tooltip = 'Versão experimental';
                                                }
                                                echo '<span class="badge ' . $versao_class . ' badge-sm" data-bs-toggle="tooltip" title="' . $versao_tooltip . '">
                                                      <i class="fas fa-code-branch me-1"></i> ' . $versao_texto . '
                                                      </span>';
                                            } else {
                                                echo '<span class="badge bg-danger badge-sm incompativel" data-bs-toggle="tooltip" title="Versão Incompatível">
                                                      <i class="fas fa-exclamation-triangle me-1"></i> INC
                                                      </span>';
                                            }
                                        ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        // Formatar e colorir o tempo de abandono
                                        $tempo_classe = "text-info";
                                        $tempo_texto = "";
                                        $tempo_icon = "fas fa-hourglass-half";
                                        
                                        if (isset($pedido['tempo_abandono'])) {
                                            $minutos = $pedido['tempo_abandono'];
                                            
                                            if ($minutos < 60) {
                                                $tempo_texto = "$minutos min";
                                                $tempo_classe = "text-info";
                                            } else {
                                                $horas = floor($minutos / 60);
                                                $min_rest = $minutos % 60;
                                                
                                                if ($min_rest > 0) {
                                                    $tempo_texto = "$horas h $min_rest min";
                                                } else {
                                                    $tempo_texto = "$horas h";
                                                }
                                                
                                                if ($minutos > 180) {
                                                    $tempo_classe = "text-danger";
                                                    $tempo_icon = "fas fa-exclamation-circle";
                                                } else {
                                                    $tempo_classe = "text-warning";
                                                }
                                            }
                                        }
                                        ?>
                                        <span class="<?php echo $tempo_classe; ?>">
                                            <i class="<?php echo $tempo_icon; ?> me-1"></i>
                                            <strong><?php echo $tempo_texto; ?></strong>
                                        </span>
                                    </td>
                                    <td class="text-center actions-column">
                                        <div class="d-flex justify-content-center align-items-center gap-1">
                                            <!-- Botão principal de detalhes -->
                                            <button type="button" class="btn btn-sm btn-outline-primary ver-detalhes flex-shrink-0" 
                                                    data-bs-toggle="modal" data-bs-target="#modalDetalhes"
                                                    data-pedido='<?php echo htmlspecialchars(json_encode($pedido)); ?>'
                                                    title="Ver detalhes do pedido">
                                                <i class="fas fa-eye"></i>
                                                <?php if (!empty($pedido['CONVERSA_IA']) || !empty($pedido['PERGUNTAS_FEITAS'])): ?>
                                                <span class="badge bg-primary position-absolute top-0 start-100 translate-middle" style="font-size: 8px; padding: 2px 4px;">
                                                    <i class="fas fa-comments"></i>
                                                </span>
                                                <?php endif; ?>
                                            </button>
                                            
                                            <!-- Dropdown com ações secundárias -->
                                            <div class="btn-group dropdown" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                        data-bs-toggle="dropdown" aria-expanded="false" title="Mais ações">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <?php if (!empty($pedido['CONTATO'])): ?>
                                                    <li>
                                                        <a class="dropdown-item" 
                                                           href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $pedido['CONTATO']); ?>" 
                                                           target="_blank">
                                                            <i class="fab fa-whatsapp text-success me-2"></i>
                                                            Contatar via WhatsApp
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <?php endif; ?>
                                                    
                                                    <li>
                                                        <button type="button" class="dropdown-item btn-processar-pedido"
                                                                data-arquivo="<?php echo htmlspecialchars($pedido['arquivo']); ?>"
                                                                data-pedido='<?php echo htmlspecialchars(json_encode($pedido)); ?>'>
                                                            <i class="fas fa-cogs text-warning me-2"></i>
                                                            Processar Pedido
                                                        </button>
                                                    </li>
                                                    
                                                    <li><hr class="dropdown-divider"></li>
                                                    
                                                    <li>
                                                        <button type="button" class="dropdown-item text-danger btn-remover-pedido"
                                                                data-arquivo="<?php echo htmlspecialchars($pedido['arquivo']); ?>"
                                                                data-session-id="<?php echo htmlspecialchars($pedido['session_id'] ?? ''); ?>">
                                                            <i class="fas fa-trash-alt me-2"></i>
                                                            Remover Pedido
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Detalhes -->
<div class="modal fade" id="modalDetalhes" tabindex="-1" aria-labelledby="modalDetalhesLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <!-- Header com gradiente e informações principais -->
            <div class="modal-header bg-gradient-primary text-white border-0 position-relative overflow-hidden">
                <div class="position-absolute top-0 end-0 w-100 h-100 opacity-10">
                    <div class="position-absolute" style="top: -20px; right: -20px; width: 150px; height: 150px; background: rgba(255,255,255,0.1); border-radius: 50%; transform: rotate(45deg);"></div>
                    <div class="position-absolute" style="bottom: -30px; right: 50px; width: 100px; height: 100px; background: rgba(255,255,255,0.05); border-radius: 50%;"></div>
                </div>
                
                <div class="d-flex align-items-center w-100 position-relative z-1">
                    <div class="me-3">
                        <div class="bg-white bg-opacity-20 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                            <i class="fas fa-user-circle fa-2x text-white"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <h4 class="modal-title mb-1 fw-bold" id="modalDetalhesLabel">
                            <span id="header-nome-cliente">Detalhes do Lead</span>
                        </h4>
                        <div class="d-flex align-items-center gap-3 text-white-50">
                            <span id="header-origem-badge">
                                <i class="fas fa-tag me-1"></i> Carregando...
                            </span>
                            <span id="header-tempo-badge">
                                <i class="fas fa-clock me-1"></i> Carregando...
                            </span>
                            <span id="header-progresso-badge">
                                <i class="fas fa-chart-line me-1"></i> Carregando...
                            </span>
                        </div>
                    </div>
                </div>
                
                <button type="button" class="btn-close btn-close-white position-relative z-2" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            
            <div class="modal-body p-4 bg-light">
                <!-- Barra de progresso visual -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm bg-white">
                            <div class="card-body p-4">
                                <h6 class="card-title mb-3 d-flex align-items-center">
                                    <i class="fas fa-chart-line text-primary me-2"></i>
                                    Progresso do Lead
                                </h6>
                                <div class="progress mb-3" style="height: 12px; border-radius: 10px;">
                                    <div class="progress-bar bg-gradient-success progress-bar-striped progress-bar-animated" 
                                         role="progressbar" id="barra-progresso-lead" 
                                         style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between small text-muted" id="etapas-progresso">
                                    <span class="etapa-item" data-etapa="inicio">
                                        <i class="fas fa-comment"></i> Início
                                    </span>
                                    <span class="etapa-item" data-etapa="nome">
                                        <i class="fas fa-user"></i> Nome
                                    </span>
                                    <span class="etapa-item" data-etapa="contato">
                                        <i class="fas fa-phone"></i> Contato
                                    </span>
                                    <span class="etapa-item" data-etapa="problema">
                                        <i class="fas fa-comment-medical"></i> Problema
                                    </span>
                                    <span class="etapa-item" data-etapa="endereco">
                                        <i class="fas fa-map-marker-alt"></i> Endereço
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row g-4">
                    <!-- Informações Pessoais -->
                    <div class="col-lg-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-header bg-gradient-info text-white border-0">
                                <h6 class="mb-0 d-flex align-items-center">
                                    <i class="fas fa-user-circle me-2"></i>
                                    Informações Pessoais
                                </h6>
                            </div>
                            <div class="card-body p-4">
                                <div class="info-item mb-3">
                                    <div class="info-label d-flex align-items-center mb-2">
                                        <div class="icon-wrapper me-2">
                                            <i class="fas fa-user text-info"></i>
                                        </div>
                                        <span class="fw-semibold text-muted">Nome Completo</span>
                                    </div>
                                    <div class="info-value bg-light p-3 rounded-3 border-start border-info border-4" id="detalhe-nome">
                                        <i class="fas fa-spinner fa-spin text-muted"></i> Carregando...
                                    </div>
                                </div>
                                
                                <div class="info-item mb-3">
                                    <div class="info-label d-flex align-items-center mb-2">
                                        <div class="icon-wrapper me-2">
                                            <i class="fas fa-phone-alt text-success"></i>
                                        </div>
                                        <span class="fw-semibold text-muted">Contato</span>
                                    </div>
                                    <div class="info-value bg-light p-3 rounded-3 border-start border-success border-4" id="detalhe-contato">
                                        <i class="fas fa-spinner fa-spin text-muted"></i> Carregando...
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label d-flex align-items-center mb-2">
                                        <div class="icon-wrapper me-2">
                                            <i class="fas fa-comment-medical text-warning"></i>
                                        </div>
                                        <span class="fw-semibold text-muted">Problema Relatado</span>
                                    </div>
                                    <div class="info-value bg-light p-3 rounded-3 border-start border-warning border-4" id="detalhe-problema">
                                        <i class="fas fa-spinner fa-spin text-muted"></i> Carregando...
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Informações Técnicas -->
                    <div class="col-lg-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-header bg-gradient-dark text-white border-0">
                                <h6 class="mb-0 d-flex align-items-center">
                                    <i class="fas fa-cogs me-2"></i>
                                    Informações Técnicas
                                </h6>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="info-item">
                                            <div class="info-label d-flex align-items-center mb-2">
                                                <i class="fas fa-fingerprint text-primary me-2"></i>
                                                <span class="fw-semibold text-muted">ID da Sessão</span>
                                            </div>
                                            <div class="info-value-small bg-light p-2 rounded-2 font-monospace small" id="detalhe-sessao">
                                                Carregando...
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-6">
                                        <div class="info-item">
                                            <div class="info-label d-flex align-items-center mb-2">
                                                <i class="fas fa-tag text-info me-2"></i>
                                                <span class="fw-semibold text-muted">Origem</span>
                                            </div>
                                            <div class="info-value-small" id="detalhe-origem">
                                                Carregando...
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-6">
                                        <div class="info-item">
                                            <div class="info-label d-flex align-items-center mb-2">
                                                <i class="fas fa-code-branch text-secondary me-2"></i>
                                                <span class="fw-semibold text-muted">Versão</span>
                                            </div>
                                            <div class="info-value-small" id="detalhe-versao">
                                                Carregando...
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-6">
                                        <div class="info-item">
                                            <div class="info-label d-flex align-items-center mb-2">
                                                <i class="fas fa-bullseye text-warning me-2"></i>
                                                <span class="fw-semibold text-muted">Fonte</span>
                                            </div>
                                            <div class="info-value-small" id="detalhe-fonte">
                                                Carregando...
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-6">
                                        <div class="info-item">
                                            <div class="info-label d-flex align-items-center mb-2">
                                                <i class="fas fa-hourglass-half text-danger me-2"></i>
                                                <span class="fw-semibold text-muted">Tempo</span>
                                            </div>
                                            <div class="info-value-small" id="detalhe-tempo-abandono">
                                                Carregando...
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Timestamps -->
                                <div class="mt-4 pt-3 border-top">
                                    <div class="row g-2 small text-muted">
                                        <div class="col-6">
                                            <i class="fas fa-play-circle me-1"></i>
                                            <strong>Início:</strong>
                                            <div id="detalhe-data-inicio" class="mt-1">Carregando...</div>
                                        </div>
                                        <div class="col-6">
                                            <i class="fas fa-stop-circle me-1"></i>
                                            <strong>Abandono:</strong>
                                            <div id="detalhe-data-abandono" class="mt-1">Carregando...</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Timeline de Progresso -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-gradient-success text-white border-0">
                                <h6 class="mb-0 d-flex align-items-center">
                                    <i class="fas fa-route me-2"></i>
                                    Jornada do Cliente
                                </h6>
                            </div>
                            <div class="card-body p-4">
                                <div class="timeline-container-modern" id="timeline-marcos">
                                    <div class="text-center py-4">
                                        <div class="spinner-border text-success" role="status">
                                            <span class="visually-hidden">Carregando jornada...</span>
                                        </div>
                                        <p class="mt-2 text-muted">Carregando jornada do cliente...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Conversas e Interações -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-gradient-purple text-white border-0">
                                <h6 class="mb-0 d-flex align-items-center">
                                    <i class="fas fa-comments me-2"></i>
                                    Conversas com Dr. Bruno Celso
                                    <span class="badge bg-white bg-opacity-20 ms-2" id="contador-conversas">0</span>
                                </h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="chat-container-modern" id="detalhe-perguntas">
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-comment-slash fa-3x mb-3 opacity-50"></i>
                                        <p>Nenhuma conversa registrada</p>
                                        <small>As perguntas e respostas aparecerão aqui quando disponíveis</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Anotações -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-gradient-info text-white border-0">
                                <h6 class="mb-0 d-flex align-items-center">
                                    <i class="fas fa-sticky-note me-2"></i>
                                    Anotações da Equipe
                                    <span class="badge bg-white bg-opacity-20 ms-2" id="contador-anotacoes">0</span>
                                </h6>
                            </div>
                            <div class="card-body p-4">
                                <div id="anotacoes-container" class="mb-4">
                                    <div class="text-center text-muted py-3" id="sem-anotacoes">
                                        <i class="fas fa-clipboard fa-2x mb-2 opacity-50"></i>
                                        <p class="mb-0">Nenhuma anotação encontrada para este pedido.</p>
                                        <small>Adicione a primeira anotação abaixo</small>
                                    </div>
                                    <div id="lista-anotacoes">
                                        <!-- As anotações serão carregadas dinamicamente aqui -->
                                    </div>
                                </div>
                                
                                <!-- Formulário de nova anotação -->
                                <div class="border-top pt-4">
                                    <form id="form-anotacao">
                                        <input type="hidden" id="anotacao-pedido-id" name="pedido_id" value="">
                                        <div class="mb-3">
                                            <label for="nova-anotacao" class="form-label fw-semibold">
                                                <i class="fas fa-pen me-1"></i>
                                                Adicionar nova anotação:
                                            </label>
                                            <textarea class="form-control form-control-lg border-2" 
                                                      id="nova-anotacao" 
                                                      name="comentario" 
                                                      rows="3" 
                                                      placeholder="Digite seu comentário aqui..."
                                                      style="resize: vertical; min-height: 80px;"></textarea>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <button type="button" class="btn btn-outline-secondary" id="btn-debug-anotacoes">
                                                <i class="fas fa-bug me-1"></i> Diagnóstico
                                            </button>
                                            <button type="submit" class="btn btn-primary btn-lg px-4" id="btn-salvar-anotacao">
                                                <i class="fas fa-save me-2"></i> Salvar Anotação
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Footer com ações -->
            <div class="modal-footer bg-white border-top-0 p-4">
                <div class="d-flex justify-content-between w-100 align-items-center">
                    <div class="text-muted small">
                        <i class="fas fa-info-circle me-1"></i>
                        Última atualização: <span id="ultima-atualizacao">Agora</span>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>
                            Fechar
                        </button>
                        <a href="#" class="btn btn-success" id="btn-whatsapp" target="_blank">
                            <i class="fab fa-whatsapp me-1"></i>
                            Contatar Cliente
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Processamento de Pedido de Recuperação -->
<div class="modal fade" id="modalProcessarPedido" tabindex="-1" aria-labelledby="modalProcessarPedidoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="modalProcessarPedidoLabel"><i class="fas fa-cogs me-2"></i>Processar Pedido de Recuperação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-4">
                    <div class="d-flex">
                        <div class="me-3 fs-3">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div>
                            <h5 class="alert-heading">Instruções</h5>
                            <p class="mb-0">Complete as informações abaixo para processar este pedido de recuperação.
                            Os campos marcados com <span class="text-danger">*</span> são obrigatórios.</p>
                        </div>
                    </div>
                </div>
                
                <form id="formProcessarPedido">
                    <input type="hidden" id="processo-arquivo" name="arquivo" value="">
                    <input type="hidden" id="processo-session-id" name="session_id" value="">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="processo-nome" class="form-label">Nome <span class="text-danger">*</span></label>
                            <div class="input-group mb-3">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" class="form-control" id="processo-nome" name="nome" required>
                            </div>
                            <div class="invalid-feedback">Por favor, informe o nome do cliente.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="processo-contato" class="form-label">Contato <span class="text-danger">*</span></label>
                            <div class="input-group mb-3">
                                <span class="input-group-text"><i class="fas fa-phone-alt"></i></span>
                            <input type="text" class="form-control" id="processo-contato" name="contato" placeholder="Ex: +351 912345678" required>
                            </div>
                            <div class="invalid-feedback">Por favor, informe o contato do cliente.</div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="processo-endereco" class="form-label">Endereço <span class="text-danger">*</span></label>
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                        <textarea class="form-control" id="processo-endereco" name="endereco" rows="2" required></textarea>
                        </div>
                        <div class="invalid-feedback">Por favor, informe o endereço do cliente.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="processo-problema" class="form-label">Problema Relatado</label>
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="fas fa-comment-medical"></i></span>
                        <textarea class="form-control" id="processo-problema" name="problema" rows="3"></textarea>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="processo-tratamento" class="form-label">Tratamento</label>
                            <div class="input-group mb-3">
                                <span class="input-group-text"><i class="fas fa-medkit"></i></span>
                            <select class="form-select" id="processo-tratamento" name="tratamento">
                                <option value="1 Mês">1 Mês - 74,98€</option>
                                <option value="2 Meses">2 Meses - 119,98€</option>
                                <option value="3 Meses">3 Meses - 149,98€</option>
                                <option value="personalizado">Preço Personalizado</option>
                            </select>
                            </div>
                            <!-- Campo para preço personalizado -->
                            <div id="precoPersonalizadoRecContainer" style="display: none;">
                                <label for="precoPersonalizadoRec" class="form-label">Valor Personalizado</label>
                                <div class="input-group mb-3">
                                    <span class="input-group-text"><i class="fas fa-euro-sign"></i></span>
                                    <input type="number" class="form-control" id="precoPersonalizadoRec" 
                                           placeholder="0,00" step="0.01" min="0">
                                    <span class="input-group-text">€</span>
                                </div>
                                <small class="form-text text-muted">Digite o valor em euros (ex: 99,99)</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="processo-origem" class="form-label">Origem</label>
                            <div class="input-group mb-3">
                                <span class="input-group-text"><i class="fas fa-tag"></i></span>
                            <select class="form-select" id="processo-origem" name="origem">
                                <option value="whatsapp">WhatsApp</option>
                                <option value="facebook">Facebook</option>
                                <option value="site">Site</option>
                                <option value="outro">Outro</option>
                            </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="processo-versao" class="form-label">Versão</label>
                            <div class="input-group mb-3">
                                <span class="input-group-text"><i class="fas fa-code-branch"></i></span>
                                <select class="form-select" id="processo-versao" name="versao">
                                    <option value="v1-main">Main (OLD)</option>
                                    <option value="v2-n8n">N8N (Main)</option>
                                    <option value="v2-experimental">Experimental</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end mt-4">
                        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </button>
                        <button type="button" id="btn-salvar-pedido" class="btn btn-warning">
                            <i class="fas fa-save me-2"></i>Salvar e Processar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar tooltips do Bootstrap
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Configurar evento para o select de tratamento
    var tratamentoSelect = document.getElementById('processo-tratamento');
    if (tratamentoSelect) {
        tratamentoSelect.addEventListener('change', function() {
            var precoContainer = document.getElementById('precoPersonalizadoRecContainer');
            var precoInput = document.getElementById('precoPersonalizadoRec');
            
            if (this.value === 'personalizado') {
                precoContainer.style.display = 'block';
                precoInput.required = true;
                precoInput.focus();
            } else {
                precoContainer.style.display = 'none';
                precoInput.required = false;
                precoInput.value = '';
            }
        });
    }
    
    // Inicializar dropdowns do Bootstrap - VERSÃO SIMPLES E FUNCIONAL
    setTimeout(function() {
        console.log('Inicializando dropdowns...');
        
        // Verificar se Bootstrap está disponível
        if (typeof bootstrap === 'undefined') {
            console.error('Bootstrap não encontrado!');
            return;
        }
        
        // Encontrar todos os dropdowns
        const dropdownElements = document.querySelectorAll('[data-bs-toggle="dropdown"]');
        console.log('Encontrados', dropdownElements.length, 'dropdowns');
        
        // Inicializar cada dropdown
        dropdownElements.forEach(function(element, index) {
            try {
                // Verificar se já foi inicializado
                if (!bootstrap.Dropdown.getInstance(element)) {
                    const dropdown = new bootstrap.Dropdown(element);
                    console.log('Dropdown', index + 1, 'inicializado');
                }
            } catch (error) {
                console.error('Erro ao inicializar dropdown', index + 1, ':', error);
            }
        });
        
        console.log('Inicialização de dropdowns concluída');
    }, 200);
    
    // TESTE DE DEBUG PARA DROPDOWNS
    setTimeout(function() {
        console.log('=== TESTE DE DROPDOWNS ===');
        
        // Verificar se Bootstrap está carregado
        console.log('Bootstrap disponível:', typeof bootstrap !== 'undefined');
        
        // Contar dropdowns na página
        const dropdowns = document.querySelectorAll('[data-bs-toggle="dropdown"]');
        console.log('Dropdowns encontrados:', dropdowns.length);
        
        // Verificar cada dropdown
        dropdowns.forEach((dropdown, index) => {
            console.log(`Dropdown ${index + 1}:`, dropdown);
            console.log('- Classe:', dropdown.className);
            console.log('- Data-bs-toggle:', dropdown.getAttribute('data-bs-toggle'));
            console.log('- Instância Bootstrap:', bootstrap.Dropdown.getInstance(dropdown));
            
            // Adicionar evento de teste
            dropdown.addEventListener('click', function(e) {
                console.log(`Clique detectado no dropdown ${index + 1}`);
            });
        });
        
        console.log('=== FIM DO TESTE ===');
    }, 1000);
    
    // Função para ajustar posição do dropdown dinamicamente
    function ajustarPosicaoDropdown() {
        document.addEventListener('shown.bs.dropdown', function(e) {
            const dropdownMenu = e.target.nextElementSibling;
            if (dropdownMenu && dropdownMenu.classList.contains('dropdown-menu')) {
                const rect = e.target.getBoundingClientRect();
                const menuRect = dropdownMenu.getBoundingClientRect();
                const viewportHeight = window.innerHeight;
                const viewportWidth = window.innerWidth;
                
                // Resetar estilos
                dropdownMenu.style.position = 'absolute';
                dropdownMenu.style.top = '100%';
                dropdownMenu.style.left = 'auto';
                dropdownMenu.style.right = '0';
                dropdownMenu.style.transform = 'none';
                dropdownMenu.style.zIndex = '99999';
                
                // Verificar se precisa ajustar posição vertical
                if (rect.bottom + menuRect.height > viewportHeight - 20) {
                    dropdownMenu.style.top = 'auto';
                    dropdownMenu.style.bottom = '100%';
                }
                
                // Verificar se precisa ajustar posição horizontal
                if (rect.right - menuRect.width < 20) {
                    dropdownMenu.style.left = '0';
                    dropdownMenu.style.right = 'auto';
                }
                
                console.log('Dropdown posicionado:', {
                    top: dropdownMenu.style.top,
                    bottom: dropdownMenu.style.bottom,
                    left: dropdownMenu.style.left,
                    right: dropdownMenu.style.right,
                    zIndex: dropdownMenu.style.zIndex
                });
            }
        });
        
        // Adicionar evento para quando o dropdown for mostrado
        document.addEventListener('show.bs.dropdown', function(e) {
            console.log('Dropdown sendo aberto:', e.target);
            
            // Garantir que todos os containers pais tenham overflow visível
            let parent = e.target.closest('.table-responsive');
            if (parent) {
                parent.style.overflow = 'visible';
                parent.style.position = 'static';
            }
            
            parent = e.target.closest('.card-body');
            if (parent) {
                parent.style.overflow = 'visible';
                parent.style.position = 'static';
            }
            
            parent = e.target.closest('.card');
            if (parent) {
                parent.style.overflow = 'visible';
                parent.style.position = 'static';
            }
        });
    }
    
    // Chamar a função de ajuste
    ajustarPosicaoDropdown();
    
    // Solução adicional: Forçar position fixed em casos extremos
    setTimeout(function() {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function(button) {
            button.addEventListener('click', function(e) {
                setTimeout(function() {
                    const dropdownMenu = button.nextElementSibling;
                    if (dropdownMenu && dropdownMenu.classList.contains('dropdown-menu') && dropdownMenu.classList.contains('show')) {
                        const rect = button.getBoundingClientRect();
                        
                        // Se o dropdown não estiver visível, usar position fixed
                        const menuRect = dropdownMenu.getBoundingClientRect();
                        if (menuRect.top < 0 || menuRect.bottom > window.innerHeight || menuRect.left < 0 || menuRect.right > window.innerWidth) {
                            console.log('Dropdown fora da tela, aplicando position fixed');
                            
                            dropdownMenu.style.position = 'fixed';
                            dropdownMenu.style.top = (rect.bottom + 2) + 'px';
                            dropdownMenu.style.left = (rect.right - 200) + 'px'; // 200px é a largura mínima do dropdown
                            dropdownMenu.style.zIndex = '99999';
                            dropdownMenu.style.transform = 'none';
                            
                            // Ajustar se sair da tela
                            if (rect.right - 200 < 0) {
                                dropdownMenu.style.left = rect.left + 'px';
                            }
                            
                            if (rect.bottom + dropdownMenu.offsetHeight > window.innerHeight) {
                                dropdownMenu.style.top = (rect.top - dropdownMenu.offsetHeight - 2) + 'px';
                            }
                        }
                    }
                }, 10);
            });
        });
    }, 1500);
    
    // Função para fechar o modal com fallback para diferentes métodos
    function fecharModal(modalEl) {
        try {
            // Método 1: Usando a instância do Bootstrap
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) {
                modalInstance.hide();
                return;
            }
            
            // Método 2: Criando uma nova instância
            const modal = new bootstrap.Modal(modalEl);
            modal.hide();
        } catch (e) {
            console.warn('Erro ao fechar modal com Bootstrap:', e);
            
            try {
                // Método 3: Usando jQuery se disponível
                if (typeof $ !== 'undefined') {
                    $(modalEl).modal('hide');
                    return;
                }
            } catch (e) {
                console.warn('Erro ao fechar modal com jQuery:', e);
                
                // Método 4: Fallback para manipulação manual
                modalEl.style.display = 'none';
                modalEl.classList.remove('show');
                document.body.classList.remove('modal-open');
                
                // Remover backdrop manualmente
                const backdrop = document.querySelector('.modal-backdrop');
                if (backdrop) {
                    backdrop.remove();
                }
            }
        }
    }
    
    // Garantir que os botões de fechar dos modais funcionem corretamente
    document.querySelectorAll('.modal .btn-close, .modal .btn-secondary[data-bs-dismiss="modal"]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const modalEl = this.closest('.modal');
            fecharModal(modalEl);
        });
    });
    
    // Melhorar acessibilidade dos modais
    const modals = document.querySelectorAll('.modal');
    modals.forEach(function(modal) {
        modal.addEventListener('shown.bs.modal', function() {
            // Focar no primeiro elemento interativo ou no título
            const firstInput = modal.querySelector('input, select, textarea, button:not(.btn-close)');
            if (firstInput) {
                firstInput.focus();
            } else {
                const title = modal.querySelector('.modal-title');
                if (title) {
                    title.setAttribute('tabindex', '-1');
                    title.focus();
                }
            }
        });
        
        // Garantir que o ESC fecha o modal
        modal.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                fecharModal(modal);
            }
        });
        
        // Fechar o modal ao clicar fora dele
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                fecharModal(modal);
            }
        });
    });

    // Função para renderizar a timeline do progresso
    function renderizarTimeline(pedido) {
        const timelineContainer = document.getElementById('timeline-marcos');
        if (!timelineContainer) return;

        let html = '';
        
        // Definir as etapas e suas configurações
        const etapas = [
            {
                id: 'inicio',
                texto: 'Iniciou conversa',
                icone: 'fa-comment',
                classe: 'bg-secondary',
                timestamp: pedido.timestamp
            },
            {
                id: 'nome',
                texto: 'Forneceu nome',
                icone: 'fa-user',
                classe: 'bg-info',
                verificacao: pedido.NOME,
                timestamp: pedido.marcos_timestamp?.nome
            },
            {
                id: 'contato',
                texto: 'Forneceu contato',
                icone: 'fa-phone',
                classe: 'bg-primary',
                verificacao: pedido.CONTATO,
                timestamp: pedido.marcos_timestamp?.contato
            },
            {
                id: 'problema',
                texto: 'Relatou problema',
                icone: 'fa-comment-medical',
                classe: 'bg-success',
                verificacao: pedido.PROBLEMA_RELATADO,
                timestamp: pedido.marcos_timestamp?.problema
            },
            {
                id: 'endereco',
                texto: 'Forneceu endereço',
                icone: 'fa-map-marker-alt',
                classe: 'bg-danger',
                verificacao: pedido.ENDEREÇO,
                timestamp: pedido.marcos_timestamp?.endereco
            }
        ];

        // Encontrar a última etapa concluída
        let ultimaEtapaConcluida = -1;
        etapas.forEach((etapa, index) => {
            if (etapa.verificacao || index === 0) {
                ultimaEtapaConcluida = index;
            }
        });

        // Renderizar cada etapa
        etapas.forEach((etapa, index) => {
            const concluida = index <= ultimaEtapaConcluida;
            const timestamp = etapa.timestamp || pedido.timestamp;
            const dataFormatada = timestamp ? new Date(timestamp).toLocaleString() : 'Data não registrada';
            
            html += `
                <div class="timeline-item ${concluida ? 'concluido' : 'pendente'}">
                    <div class="timeline-icon ${etapa.classe}">
                        <i class="fas ${etapa.icone}"></i>
                    </div>
                    <div class="timeline-content">
                        <div class="timeline-title">
                            ${etapa.texto}
                            <span class="timeline-time">${dataFormatada}</span>
                        </div>
                        <div class="timeline-description">
                            ${concluida ? 
                                '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Concluído</span>' : 
                                '<span class="text-muted"><i class="fas fa-clock me-1"></i>Pendente</span>'}
                        </div>
                    </div>
                </div>
            `;
        });

        // Adicionar marcos especiais se existirem
        if (pedido.marcos_progresso && Array.isArray(pedido.marcos_progresso)) {
            const marcosEspeciais = {
                'audio_introdutorio': {
                    texto: 'Ouviu áudio introdutório',
                    icone: 'fa-headphones',
                    classe: 'bg-info'
                },
                'perguntas': {
                    texto: 'Fez perguntas sobre o produto',
                    icone: 'fa-question-circle',
                    classe: 'bg-purple'
                },
                'entrega': {
                    texto: 'Viu informações de entrega',
                    icone: 'fa-truck',
                    classe: 'bg-warning'
                }
            };

            pedido.marcos_progresso.forEach(marco => {
                if (marcosEspeciais[marco]) {
                    const config = marcosEspeciais[marco];
                    const timestamp = pedido.marcos_timestamp?.[marco] || pedido.timestamp;
                    const dataFormatada = timestamp ? new Date(timestamp).toLocaleString() : 'Data não registrada';

                    html += `
                        <div class="timeline-item marco-especial">
                            <div class="timeline-icon ${config.classe}">
                                <i class="fas ${config.icone}"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    ${config.texto}
                                    <span class="timeline-time">${dataFormatada}</span>
                                </div>
                                <div class="timeline-description">
                                    <span class="text-success">
                                        <i class="fas fa-check-circle me-1"></i>Marco adicional registrado
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                }
            });
        }

        // Se não houver nenhuma etapa além do início
        if (ultimaEtapaConcluida === 0 && (!pedido.marcos_progresso || pedido.marcos_progresso.length === 0)) {
            html += `
                <div class="text-center text-muted mt-3">
                    <p>Apenas iniciou a conversa, sem progressão adicional.</p>
                </div>
            `;
        }

        timelineContainer.innerHTML = html;
    }

    // Manipular clique no botão de detalhes
    document.querySelectorAll('.ver-detalhes').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const pedido = JSON.parse(this.getAttribute('data-pedido'));
            
            // Atualizar header do modal com informações dinâmicas
            atualizarHeaderModal(pedido);
            
            // Atualizar barra de progresso visual
            atualizarBarraProgresso(pedido);
            
            // Preencher os campos do modal
            document.getElementById('detalhe-nome').textContent = pedido.NOME || 'Não informado';
            document.getElementById('detalhe-contato').textContent = pedido.CONTATO || 'Não informado';
            document.getElementById('detalhe-problema').textContent = pedido.PROBLEMA_RELATADO || 'Nenhum problema relatado';
            
            // Adicionar formatação de acordo com a origem
            const origemEl = document.getElementById('detalhe-origem');
            if (pedido.origem === 'facebook') {
                origemEl.innerHTML = '<span class="badge bg-primary rounded-pill"><i class="fab fa-facebook-f me-1"></i> Facebook</span>';
            } else if (pedido.origem === 'whatsapp') {
                origemEl.innerHTML = '<span class="badge bg-success rounded-pill"><i class="fab fa-whatsapp me-1"></i> WhatsApp</span>';
            } else {
                origemEl.innerHTML = '<span class="badge bg-secondary rounded-pill"><i class="fas fa-question-circle me-1"></i> Desconhecida</span>';
            }
            
            // Identificar e exibir a fonte do lead
            const fonteEl = document.getElementById('detalhe-fonte');
            let fonte = 'Não identificada';
            let fonteBadgeClass = 'bg-secondary';
            let fonteIcon = 'fa-question-circle';
            
            if (pedido.fonte) {
                fonte = pedido.fonte;
                fonteBadgeClass = 'bg-info';
                fonteIcon = 'fa-bullseye';
            } else if (pedido.origem === 'facebook') {
                fonte = 'Anúncio Facebook';
                fonteBadgeClass = 'bg-primary';
                fonteIcon = 'fa-ad';
            } else if (pedido.origem === 'whatsapp') {
                fonte = 'Campanha WhatsApp';
                fonteBadgeClass = 'bg-success';
                fonteIcon = 'fa-bullhorn';
            }
            
            if (pedido.utm_source) fonte = pedido.utm_source;
            if (pedido.utm_campaign) fonte += ` (${pedido.utm_campaign})`;
            
            fonteEl.innerHTML = `<span class="badge ${fonteBadgeClass} rounded-pill"><i class="fas ${fonteIcon} me-1"></i> ${fonte}</span>`;
            
            // Exibir a versão do pedido
            const versaoEl = document.getElementById('detalhe-versao');
            if (pedido.versao) {
                let versaoTexto, versaoClass, versaoTooltip;
                const versaoLower = pedido.versao.toLowerCase();
                
                if (versaoLower === 'v2-n8n') {
                    versaoTexto = 'main';
                    versaoClass = 'bg-primary';
                    versaoTooltip = 'Versão principal N8N';
                } else if (versaoLower === 'v1-main') {
                    versaoTexto = 'OLD';
                    versaoClass = 'bg-secondary';
                    versaoTooltip = 'Versão antiga';
                } else {
                    versaoTexto = 'EXP';
                    versaoClass = 'bg-info';
                    versaoTooltip = 'Versão experimental';
                }
                
                versaoEl.innerHTML = `<span class="badge ${versaoClass} rounded-pill" data-bs-toggle="tooltip" title="${versaoTooltip}"><i class="fas fa-code-branch me-1"></i> ${versaoTexto}</span>`;
            } else {
                versaoEl.innerHTML = '<span class="badge bg-danger rounded-pill incompativel" data-bs-toggle="tooltip" title="Versão Incompatível"><i class="fas fa-exclamation-triangle me-1"></i> INC</span>';
            }
            
            // Preencher informações da sessão
            document.getElementById('detalhe-sessao').textContent = pedido.session_id || 'Não disponível';
            
            const dataInicio = pedido.timestamp ? new Date(pedido.timestamp).toLocaleString('pt-BR') : 'Não disponível';
            document.getElementById('detalhe-data-inicio').textContent = dataInicio;
            
            const dataAbandono = pedido.timestamp ? new Date(pedido.timestamp).toLocaleString('pt-BR') : 'Não disponível';
            document.getElementById('detalhe-data-abandono').textContent = dataAbandono;
            
            // Formatar tempo de abandono
            if (pedido.tempo_abandono) {
                let textoTempo = '';
                if (pedido.tempo_abandono < 60) {
                    textoTempo = `${pedido.tempo_abandono} minutos`;
                } else {
                    const horas = Math.floor(pedido.tempo_abandono / 60);
                    const minutos = pedido.tempo_abandono % 60;
                    textoTempo = `${horas} hora${horas !== 1 ? 's' : ''}`;
                    if (minutos > 0) {
                        textoTempo += ` e ${minutos} minuto${minutos !== 1 ? 's' : ''}`;
                    }
                }
                
                let classeTempo = 'text-info';
                if (pedido.tempo_abandono > 60) classeTempo = 'text-warning';
                if (pedido.tempo_abandono > 180) classeTempo = 'text-danger';
                
                document.getElementById('detalhe-tempo-abandono').innerHTML = 
                    `<span class="${classeTempo} fw-bold">${textoTempo}</span>`;
            } else {
                document.getElementById('detalhe-tempo-abandono').textContent = 'Não disponível';
            }
            
            // Renderizar a timeline de progresso com design moderno
            renderizarTimeline(pedido);
                
            // Renderizar conversas com design moderno
            renderizarConversas(pedido);
            
            // Configurar botão do WhatsApp
            const btnWhatsapp = document.getElementById('btn-whatsapp');
            if (pedido.CONTATO) {
                const numeroLimpo = pedido.CONTATO.replace(/[^0-9]/g, '');
                btnWhatsapp.href = 'https://wa.me/' + numeroLimpo;
                btnWhatsapp.classList.remove('disabled');
            } else {
                btnWhatsapp.href = '#';
                btnWhatsapp.classList.add('disabled');
            }
            
            // Carregar anotações do pedido
            const pedidoId = pedido.arquivo.replace('.json', '');
            carregarAnotacoes(pedidoId);
            
            // Atualizar timestamp de última atualização
            const ultimaAtualizacao = document.getElementById('ultima-atualizacao');
            if (ultimaAtualizacao) {
                ultimaAtualizacao.textContent = new Date().toLocaleString('pt-BR');
            }
            
            // Reinicializar tooltips após preencher o modal
            setTimeout(() => {
                const tooltips = document.querySelectorAll('#modalDetalhes [data-bs-toggle="tooltip"]');
                tooltips.forEach(tooltip => {
                    new bootstrap.Tooltip(tooltip);
                });
            }, 200);
        });
    });

    // Botão de atualizar lista
    document.getElementById('btn-atualizar').addEventListener('click', function(e) {
        // Primeiro previne o comportamento padrão para aplicar animação
        e.preventDefault();
        
        // Aplica a animação
        this.querySelector('i').classList.add('fa-spin');
        this.disabled = true;
        
        // Adiciona efeito de loading às linhas da tabela
        document.querySelectorAll('tbody tr').forEach(function(row, index) {
            setTimeout(() => {
                row.style.opacity = '0.7';
                row.classList.add('loading-shimmer');
            }, index * 50);
        });
        
        // Após a animação, recarrega a página
        setTimeout(() => {
            location.reload();
        }, 800);
    });

    // Manipular clique nos botões de processar pedido
    document.querySelectorAll('.btn-processar-pedido').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const arquivo = this.getAttribute('data-arquivo');
            const pedidoData = this.getAttribute('data-pedido');
            const pedido = JSON.parse(pedidoData);
            
            // Preparar o modal
            const modal = new bootstrap.Modal(document.getElementById('modalProcessarPedido'));
            
            // Preencher os campos do formulário com os dados existentes
            document.getElementById('processo-arquivo').value = arquivo;
            document.getElementById('processo-session-id').value = pedido.session_id || '';
            document.getElementById('processo-nome').value = pedido.NOME || '';
            document.getElementById('processo-contato').value = pedido.CONTATO || '';
            document.getElementById('processo-endereco').value = pedido.ENDEREÇO || '';
            document.getElementById('processo-problema').value = pedido.PROBLEMA_RELATADO || '';
            
            // Configurar origem
            if (pedido.origem) {
                const origemSelect = document.getElementById('processo-origem');
                for (let i = 0; i < origemSelect.options.length; i++) {
                    if (origemSelect.options[i].value === pedido.origem) {
                        origemSelect.selectedIndex = i;
                        break;
                    }
                }
            }
            
            // Configurar versão
            if (pedido.versao) {
                const versaoSelect = document.getElementById('processo-versao');
                for (let i = 0; i < versaoSelect.options.length; i++) {
                    if (versaoSelect.options[i].value === pedido.versao) {
                        versaoSelect.selectedIndex = i;
                        break;
                    }
                }
            }
            
            // Mostrar o modal
            modal.show();
        });
    });
    
    // Manipular o envio do formulário de processamento
    document.getElementById('btn-salvar-pedido').addEventListener('click', function() {
        const form = document.getElementById('formProcessarPedido');
        
        // Validar o formulário
        let isValid = true;
        
        // Verificar campos obrigatórios
        const requiredFields = ['processo-nome', 'processo-contato', 'processo-endereco'];
        requiredFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (!field.value.trim()) {
                field.classList.add('is-invalid');
                isValid = false;
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        // Validar preço personalizado se selecionado
        var tratamentoSelecionado = document.getElementById('processo-tratamento').value;
        var precoPersonalizado = '';
        
        if (tratamentoSelecionado === 'personalizado') {
            var precoInput = document.getElementById('precoPersonalizadoRec');
            if (!precoInput.value || parseFloat(precoInput.value) <= 0) {
                precoInput.classList.add('is-invalid');
                isValid = false;
                mostrarNotificacao('Por favor, insira um valor válido para o preço personalizado.', 'warning');
            } else {
                precoInput.classList.remove('is-invalid');
                precoPersonalizado = parseFloat(precoInput.value).toFixed(2);
                tratamentoSelecionado = 'Personalizado - ' + precoPersonalizado + '€';
            }
        }
        
        if (!isValid) {
            return; // Parar se há campos inválidos
        }
        
        // Mostrar indicador de carregamento
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processando...';
        
        // Preparar os dados para envio
        const formData = {
            arquivo: document.getElementById('processo-arquivo').value,
            tipo: 'recuperacao',
            dados: {
                NOME: document.getElementById('processo-nome').value,
                CONTATO: document.getElementById('processo-contato').value,
                ENDEREÇO: document.getElementById('processo-endereco').value,
                PROBLEMA_RELATADO: document.getElementById('processo-problema').value,
                tratamento: tratamentoSelecionado,
                origem: document.getElementById('processo-origem').value,
                versao: document.getElementById('processo-versao').value,
                Rec: true,
                processado: true,
                status: 'aguardando'
            }
        };
        
        // Adicionar preço personalizado se aplicável
        if (precoPersonalizado) {
            formData.dados.preco_personalizado = precoPersonalizado;
        }
        
        // Enviar dados para o servidor
        fetch('./processar_pedido_recuperacao.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(formData)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro na resposta do servidor: ' + response.status);
            }
            return response.json();
        })
        .then(result => {
            if (result.success) {
                // Fechar o modal
                const modalElement = document.getElementById('modalProcessarPedido');
                const modalInstance = bootstrap.Modal.getInstance(modalElement);
                modalInstance.hide();
                
                // Mostrar notificação de sucesso com informações do Trello
                let mensagem = 'Pedido processado com sucesso!';
                if (result.trello_id) {
                    mensagem += ` Card criado no Trello (ID: ${result.trello_id})`;
                }
                mostrarNotificacao(mensagem, 'success');
                
                // Recarregar a página após um breve delay
                setTimeout(() => {
                    location.reload();
                }, 2000);
            } else {
                throw new Error(result.error || 'Erro ao processar pedido');
            }
        })
        .catch(error => {
            console.error('Erro ao processar pedido:', error);
            mostrarNotificacao('Erro ao processar pedido: ' + error.message, 'danger');
            
            // Restaurar o botão
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-save me-2"></i>Salvar e Processar';
        });
    });
    
    // Manipular clique nos botões de remover pedido
    document.querySelectorAll('.btn-remover-pedido').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Tem certeza que deseja remover este pedido? Esta ação não pode ser desfeita.')) {
                return; // Cancelar se o usuário clicar em "Cancelar"
            }
            
            const arquivo = this.getAttribute('data-arquivo');
            const sessionId = this.getAttribute('data-session-id');
            const row = this.closest('tr'); // Linha da tabela a ser removida após sucesso
            
            // Mostrar um indicador de carregamento na linha
            row.style.opacity = '0.5';
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Removendo...';
            this.disabled = true;
            
            // Preparar dados para a requisição
            const data = {
                action: 'remover_pedido'
            };
            
            // Preferir usar session_id se disponível, caso contrário usar arquivo
            if (sessionId) {
                data.session_id = sessionId;
            } else {
                data.arquivo = arquivo;
            }
            
            // Fazer a requisição AJAX para o caminho correto
            fetch('../2025/pedidos.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erro na resposta do servidor: ' + response.status);
                }
                return response.json();
            })
            .then(result => {
                if (result.status === 'success') {
                    // Remover a linha da tabela com uma animação
                    row.style.transition = 'all 0.5s ease';
                    row.style.height = '0';
                    row.style.opacity = '0';
                    row.style.overflow = 'hidden';
                    
                    // Após a animação, remover o elemento do DOM
                    setTimeout(() => {
                        row.remove();
                        
                        // Atualizar contadores de pedidos
                        atualizarContadores();
                        
                        // Verificar se a tabela está vazia e mostrar mensagem se necessário
                        const tbody = document.querySelector('table tbody');
                        if (tbody.querySelectorAll('tr').length === 0) {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td colspan="8" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-search fa-3x mb-3 d-block"></i>
                                        <p>Nenhum lead abandonado encontrado no momento.</p>
                                        <small>Quando um cliente iniciar, mas não completar o processo de compra, ele aparecerá aqui.</small>
                                    </div>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        }
                        
                        // Mostrar notificação de sucesso
                        mostrarNotificacao('Pedido removido com sucesso!', 'success');
                    }, 500);
                } else {
                    throw new Error(result.message || 'Erro ao remover pedido');
                }
            })
            .catch(error => {
                console.error('Erro ao remover pedido:', error);
                row.style.opacity = '1';
                btn.innerHTML = '<i class="fas fa-trash-alt me-1"></i> Remover';
                btn.disabled = false;
                mostrarNotificacao('Erro ao remover pedido: ' + error.message, 'danger');
            });
        });
    });
    
    // Função para atualizar os contadores na página
    function atualizarContadores() {
        // Contar total de pedidos
        const totalPedidos = document.querySelectorAll('table tbody tr:not(.empty-row)').length;
        
        // Atualizar o contador total
        const totalSpan = document.querySelector('.card-title .badge');
        if (totalSpan) {
            totalSpan.textContent = totalPedidos;
        }
        
        // Atualizar o número total na primeira card
        const displayTotal = document.querySelector('.card-body h1.display-4');
        if (displayTotal) {
            displayTotal.textContent = totalPedidos;
        }
        
        // Contar pedidos por origem
        let totalFacebook = 0;
        let totalWhatsapp = 0;
        
        document.querySelectorAll('table tbody tr:not(.empty-row)').forEach(tr => {
            const origem = tr.querySelector('.badge');
            if (origem) {
                if (origem.textContent.includes('Facebook')) {
                    totalFacebook++;
                } else if (origem.textContent.includes('WhatsApp')) {
                    totalWhatsapp++;
                }
            }
        });
        
        // Atualizar contadores de origem
        const displayFacebook = document.querySelectorAll('.card-body h1.display-4')[1];
        const displayWhatsapp = document.querySelectorAll('.card-body h1.display-4')[2];
        
        if (displayFacebook) displayFacebook.textContent = totalFacebook;
        if (displayWhatsapp) displayWhatsapp.textContent = totalWhatsapp;
    }
    
    // Função para mostrar notificação
    window.mostrarNotificacao = function(mensagem, tipo) {
        // Cria o elemento de notificação
        const notificacao = document.createElement('div');
        notificacao.className = `alert alert-${tipo} notification-toast`;
        notificacao.innerHTML = `
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <i class="fas fa-${tipo === 'success' ? 'check-circle' : 'exclamation-circle'} fa-2x"></i>
                </div>
                <div>
                    ${mensagem}
                </div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        `;
        
        // Estilo para a notificação flutuante
        Object.assign(notificacao.style, {
            position: 'fixed',
            top: '20px',
            right: '20px',
            zIndex: '9999',
            minWidth: '300px',
            maxWidth: '400px',
            boxShadow: '0 10px 30px rgba(0,0,0,0.2)',
            borderRadius: '12px',
            opacity: '0',
            transform: 'translateY(-20px)',
            transition: 'all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1)'
        });
        
        // Adiciona ao corpo do documento
        document.body.appendChild(notificacao);
        
        // Anima a entrada
        setTimeout(() => {
            notificacao.style.opacity = '1';
            notificacao.style.transform = 'translateY(0)';
        }, 10);
        
        // Auto-remove após 5 segundos
        setTimeout(() => {
            notificacao.style.opacity = '0';
            notificacao.style.transform = 'translateY(-20px)';
            setTimeout(() => {
                notificacao.remove();
            }, 400);
        }, 5000);
        
        // Adiciona evento de clique para fechar
        notificacao.querySelector('.btn-close').addEventListener('click', function() {
            notificacao.style.opacity = '0';
            notificacao.style.transform = 'translateY(-20px)';
            setTimeout(() => {
                notificacao.remove();
            }, 400);
        });
    };

    // GERENCIAMENTO DE ANOTAÇÕES
    // ===========================

    // Carregar anotações de um pedido específico
    function carregarAnotacoes(pedidoId) {
        // Mostrar indicador de carregamento
        document.getElementById('lista-anotacoes').innerHTML = `
            <div class="text-center p-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Carregando...</span>
                </div>
                <p class="mt-2 text-muted">Carregando anotações...</p>
            </div>
        `;
        
        // Configurar ID do pedido para o formulário
        document.getElementById('anotacao-pedido-id').value = pedidoId;
        
        // Fazer requisição para carregar anotações
        fetch(`carregar_anotacoes.php?pedido_id=${encodeURIComponent(pedidoId)}`)
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        throw new Error(`Erro HTTP ${response.status}: ${text}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    renderizarAnotacoes(data.anotacoes);
                } else {
                    throw new Error(data.error || 'Erro desconhecido');
                }
            })
            .catch(error => {
                console.error('Erro ao carregar anotações:', error);
                document.getElementById('lista-anotacoes').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Erro ao carregar anotações: ${error.message}
                    </div>
                `;
                
                // Resetar contador
                const contadorAnotacoes = document.getElementById('contador-anotacoes');
                if (contadorAnotacoes) contadorAnotacoes.textContent = '0';
            });
    }

    // Renderizar lista de anotações
    function renderizarAnotacoes(anotacoes) {
        const container = document.getElementById('lista-anotacoes');
        const semAnotacoes = document.getElementById('sem-anotacoes');
        
        if (!anotacoes || anotacoes.length === 0) {
            container.innerHTML = '';
            semAnotacoes.style.display = 'block';
            return;
        }
        
        // Esconder mensagem "sem anotações"
        semAnotacoes.style.display = 'none';
        
        // Construir HTML das anotações
        let html = '';
        
        anotacoes.forEach(anotacao => {
            const data = new Date(anotacao.timestamp);
            const dataFormatada = data.toLocaleDateString('pt-BR') + ' ' + data.toLocaleTimeString('pt-BR');
            
            html += `
                <div class="anotacao-item mb-3 p-3 bg-light rounded">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <strong><i class="fas fa-user me-1"></i> ${anotacao.usuario_nome}</strong>
                        </div>
                        <div class="text-muted small">
                            <i class="fas fa-clock me-1"></i> ${dataFormatada}
                        </div>
                    </div>
                    <div class="comentario-texto">
                        ${anotacao.comentario.replace(/\n/g, '<br>')}
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }

    // Processar envio do formulário de anotação
    document.getElementById('form-anotacao').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const pedidoId = document.getElementById('anotacao-pedido-id').value;
        const comentario = document.getElementById('nova-anotacao').value.trim();
        
        if (!pedidoId || !comentario) {
            mostrarNotificacao('Por favor, digite um comentário.', 'warning');
            return;
        }
        
        // Desabilitar o botão durante o envio
        const btnSalvar = document.getElementById('btn-salvar-anotacao');
        const textoOriginal = btnSalvar.innerHTML;
        btnSalvar.disabled = true;
        btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Salvando...';
        
        // Preparar dados para envio
        const dados = {
            pedido_id: pedidoId,
            comentario: comentario
        };
        
        console.log('Enviando dados:', dados);
        
        // Enviar para o servidor
        fetch('salvar_anotacao.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(dados)
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    console.error('Resposta completa:', text);
                    try {
                        const jsonError = JSON.parse(text);
                        throw new Error(jsonError.error || `Erro HTTP ${response.status}`);
                    } catch (e) {
                        throw new Error(`Erro HTTP ${response.status}: ${text}`);
                    }
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Resposta recebida:', data);
            if (data.success) {
                // Limpar campo de texto
                document.getElementById('nova-anotacao').value = '';
                
                // Recarregar anotações
                carregarAnotacoes(pedidoId);
                
                // Mostrar notificação de sucesso
                mostrarNotificacao('Anotação salva com sucesso!', 'success');
            } else {
                throw new Error(data.error || 'Erro ao salvar anotação');
            }
        })
        .catch(error => {
            console.error('Erro ao salvar anotação:', error);
            mostrarNotificacao('Erro ao salvar anotação: ' + error.message, 'danger');
        })
        .finally(() => {
            // Restaurar estado do botão
            btnSalvar.disabled = false;
            btnSalvar.innerHTML = textoOriginal;
        });
    });

    // Botão de diagnóstico para anotações
    document.getElementById('btn-debug-anotacoes').addEventListener('click', function() {
        const debugInfo = document.createElement('div');
        debugInfo.className = 'alert alert-info mt-3';
        debugInfo.innerHTML = `
            <h6><i class="fas fa-cog fa-spin me-2"></i>Verificando sistema de anotações...</h6>
            <div id="debug-result" class="mt-2">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                    <span class="visually-hidden">Carregando...</span>
                </div>
                Executando diagnóstico completo...
            </div>
        `;
        
        // Inserir antes do formulário
        document.getElementById('form-anotacao').parentNode.insertBefore(debugInfo, document.getElementById('form-anotacao'));
        
        // Fazer requisição para o diagnóstico completo
        fetch('diagnostico_anotacoes.php')
            .then(response => response.json())
            .then(data => {
                const resultDiv = document.getElementById('debug-result');
                
                // Verificar status do banco de dados
                let dbStatus = '<div class="mt-3 mb-2"><strong>Status do Banco de Dados:</strong>';
                if (data.database_status.connected) {
                    dbStatus += `<div class="text-success"><i class="fas fa-check-circle me-1"></i>Conectado ao banco de dados</div>`;
                    
                    // Verificar tabela de usuários
                    if (data.database_status.user_table) {
                        const userTable = data.database_status.user_table;
                        dbStatus += `<div>Colunas encontradas: ${userTable.columns.join(', ')}</div>`;
                        
                        if (userTable.has_login_column) {
                            dbStatus += `<div class="text-success"><i class="fas fa-check-circle me-1"></i>Coluna 'login' encontrada</div>`;
                        } else {
                            dbStatus += `<div class="text-danger"><i class="fas fa-times-circle me-1"></i>Coluna 'login' não encontrada</div>`;
                        }
                    }
                } else {
                    dbStatus += `<div class="text-danger"><i class="fas fa-times-circle me-1"></i>Erro na conexão: ${data.database_status.error}</div>`;
                }
                dbStatus += '</div>';
                
                // Verificar diretórios
                let dirStatus = '<div class="mt-3 mb-2"><strong>Status dos Diretórios:</strong>';
                let dirOk = false;
                
                data.directories_status.forEach(dir => {
                    dirStatus += `<div class="mt-2 border-top pt-2"><code>${dir.path}</code>: `;
                    
                    if (dir.exists && dir.is_dir && dir.is_writable) {
                        dirStatus += `<span class="text-success"><i class="fas fa-check-circle me-1"></i>OK (gravável)</span>`;
                        if (dir.files_count !== undefined) {
                            dirStatus += `<div>Arquivos JSON: ${dir.files_count}</div>`;
                        }
                        dirOk = true;
                    } else if (dir.exists && dir.is_dir) {
                        dirStatus += `<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Existe mas não é gravável</span>`;
                    } else if (dir.create_result) {
                        dirStatus += `<span class="text-success"><i class="fas fa-check-circle me-1"></i>Criado com sucesso</span>`;
                        dirOk = true;
                    } else {
                        dirStatus += `<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Não existe ou não pode ser criado</span>`;
                    }
                    dirStatus += '</div>';
                });
                dirStatus += '</div>';
                
                // Verificar sessão
                let sessionStatus = '<div class="mt-3 mb-2"><strong>Status da Sessão:</strong>';
                if (data.session_status.active) {
                    sessionStatus += `<div class="text-success"><i class="fas fa-check-circle me-1"></i>Sessão ativa</div>`;
                    sessionStatus += `<div>ID do usuário: ${data.session_status.usuario_id}</div>`;
                    sessionStatus += `<div>Login: ${data.session_status.usuario_login}</div>`;
                } else {
                    sessionStatus += `<div class="text-danger"><i class="fas fa-times-circle me-1"></i>Sessão inativa ou inválida</div>`;
                }
                sessionStatus += '</div>';
                
                // Verificar informações do servidor
                let serverInfo = '<div class="mt-3 mb-2"><strong>Informações do Servidor:</strong>';
                serverInfo += `<div>PHP: ${data.server_info.php_version}</div>`;
                serverInfo += `<div>Usuário: ${data.server_info.user}</div>`;
                serverInfo += `<div>Diretório atual: ${data.server_info.current_directory}</div>`;
                serverInfo += '</div>';
                
                // Montar resultado final
                if (data.database_status.connected && dirOk && data.session_status.active) {
                    resultDiv.innerHTML = `
                        <div class="alert alert-success mb-2">
                            <strong><i class="fas fa-check-circle me-1"></i>Sistema de anotações funcionando corretamente!</strong>
                            <p class="mb-0">Timestamp: ${data.timestamp}</p>
                        </div>
                        <div class="accordion mt-3" id="debugAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDetails">
                                        Ver detalhes completos
                                    </button>
                                </h2>
                                <div id="collapseDetails" class="accordion-collapse collapse" data-bs-parent="#debugAccordion">
                                    <div class="accordion-body">
                                        ${dbStatus}
                                        ${dirStatus}
                                        ${sessionStatus}
                                        ${serverInfo}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    resultDiv.innerHTML = `
                        <div class="alert alert-warning mb-2">
                            <strong><i class="fas fa-exclamation-triangle me-1"></i>Problemas detectados no sistema de anotações</strong>
                        </div>
                        ${dbStatus}
                        ${dirStatus}
                        ${sessionStatus}
                        ${serverInfo}
                        <div class="mt-3">
                            <button class="btn btn-warning btn-sm" id="btn-criar-diretorio">
                                <i class="fas fa-folder-plus me-1"></i>Tentar Criar Diretório Manualmente
                            </button>
                        </div>
                    `;
                    
                    // Adicionar evento ao botão de criar diretório
                    document.getElementById('btn-criar-diretorio').addEventListener('click', function() {
                        this.disabled = true;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Criando diretório...';
                        
                        fetch('criar_diretorio.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({action: 'create_dir'})
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                mostrarNotificacao('Diretório criado com sucesso!', 'success');
                                setTimeout(() => {
                                    location.reload();
                                }, 1500);
                            } else {
                                this.disabled = false;
                                this.innerHTML = '<i class="fas fa-folder-plus me-1"></i>Tentar Novamente';
                                
                                let errorMsg = 'Falha ao criar diretório';
                                if (data.errors && data.errors.length > 0) {
                                    errorMsg += ': ' + data.errors.join('; ');
                                }
                                
                                mostrarNotificacao(errorMsg, 'danger');
                            }
                        })
                        .catch(error => {
                            this.disabled = false;
                            this.innerHTML = '<i class="fas fa-folder-plus me-1"></i>Tentar Novamente';
                            mostrarNotificacao('Erro: ' + error.message, 'danger');
                        });
                    });
                                }
            })
            .catch(error => {
                const resultDiv = document.getElementById('debug-result');
                resultDiv.innerHTML = `
                    <div class="alert alert-danger mb-2">
                        <strong><i class="fas fa-exclamation-circle me-1"></i>Erro ao executar diagnóstico</strong>
                        <p class="mb-0">${error.message}</p>
                    </div>
                `;
            });
    });

    // Implementação alternativa usando Bootstrap nativo
    function initializeDropdowns() {
        // Aguardar um pouco para garantir que o DOM esteja pronto
        setTimeout(function() {
            // Inicializar dropdowns do Bootstrap
            var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
            var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
                return new bootstrap.Dropdown(dropdownToggleEl, {
                    boundary: 'viewport',
                    display: 'dynamic'
                });
            });
            
            console.log('Dropdowns inicializados:', dropdownList.length);
        }, 500); // Aumentar o delay para garantir que tudo esteja carregado
    }
    
    // Chamar a inicialização
    initializeDropdowns();
    
    // Função específica para melhorar o comportamento dos dropdowns na nova estrutura
    function melhorarDropdownsNaTabela() {
        // Aguardar um pouco para garantir que tudo esteja carregado
        setTimeout(function() {
            // Encontrar todos os dropdowns na tabela
            const dropdownsTabela = document.querySelectorAll('.actions-column .dropdown');
            
            console.log('Dropdowns na tabela encontrados:', dropdownsTabela.length);
            
            dropdownsTabela.forEach(function(dropdown, index) {
                const button = dropdown.querySelector('.dropdown-toggle');
                const menu = dropdown.querySelector('.dropdown-menu');
                
                if (button && menu) {
                    // Adicionar ID único se não existir
                    if (!button.id) {
                        button.id = 'dropdown-btn-' + index;
                        menu.setAttribute('aria-labelledby', button.id);
                    }
                    
                    // Garantir que o menu tenha as classes corretas
                    menu.classList.add('dropdown-menu-end');
                    
                    // Adicionar evento personalizado para ajustar posição
                    button.addEventListener('click', function(e) {
                        console.log('Clique no dropdown da tabela:', index);
                        
                        // Pequeno delay para permitir que o Bootstrap processe
                        setTimeout(function() {
                            if (menu.classList.contains('show')) {
                                const buttonRect = button.getBoundingClientRect();
                                const menuRect = menu.getBoundingClientRect();
                                
                                // Ajustar posição se necessário
                                menu.style.position = 'fixed';
                                menu.style.top = (buttonRect.bottom + 2) + 'px';
                                menu.style.left = (buttonRect.right - menu.offsetWidth) + 'px';
                                menu.style.zIndex = '999999';
                                
                                // Verificar se sai da tela e ajustar
                                if (buttonRect.right - menu.offsetWidth < 10) {
                                    menu.style.left = buttonRect.left + 'px';
                                }
                                
                                if (buttonRect.bottom + menu.offsetHeight > window.innerHeight - 10) {
                                    menu.style.top = (buttonRect.top - menu.offsetHeight - 2) + 'px';
                                }
                                
                                console.log('Dropdown posicionado em:', {
                                    top: menu.style.top,
                                    left: menu.style.left,
                                    zIndex: menu.style.zIndex
                                });
                            }
                        }, 10);
                    });
                }
            });
        }, 2000);
    }
    
    // Chamar a função de melhoria
    melhorarDropdownsNaTabela();

    // Função para renderizar a timeline do progresso com design moderno
    function renderizarTimeline(pedido) {
        const timelineContainer = document.getElementById('timeline-marcos');
        if (!timelineContainer) return;

        let html = '';
        
        // Definir as etapas e suas configurações
        const etapas = [
            {
                id: 'inicio',
                texto: 'Iniciou conversa',
                icone: 'fa-comment',
                classe: 'bg-secondary',
                timestamp: pedido.timestamp
            },
            {
                id: 'nome',
                texto: 'Forneceu nome',
                icone: 'fa-user',
                classe: 'bg-info',
                verificacao: pedido.NOME,
                timestamp: pedido.marcos_timestamp?.nome
            },
            {
                id: 'contato',
                texto: 'Forneceu contato',
                icone: 'fa-phone',
                classe: 'bg-primary',
                verificacao: pedido.CONTATO,
                timestamp: pedido.marcos_timestamp?.contato
            },
            {
                id: 'problema',
                texto: 'Relatou problema',
                icone: 'fa-comment-medical',
                classe: 'bg-success',
                verificacao: pedido.PROBLEMA_RELATADO,
                timestamp: pedido.marcos_timestamp?.problema
            },
            {
                id: 'endereco',
                texto: 'Forneceu endereço',
                icone: 'fa-map-marker-alt',
                classe: 'bg-danger',
                verificacao: pedido.ENDEREÇO,
                timestamp: pedido.marcos_timestamp?.endereco
            }
        ];

        // Encontrar a última etapa concluída
        let ultimaEtapaConcluida = -1;
        etapas.forEach((etapa, index) => {
            if (etapa.verificacao || index === 0) {
                ultimaEtapaConcluida = index;
            }
        });

        // Renderizar cada etapa com design moderno
        etapas.forEach((etapa, index) => {
            const concluida = index <= ultimaEtapaConcluida;
            const timestamp = etapa.timestamp || pedido.timestamp;
            const dataFormatada = timestamp ? new Date(timestamp).toLocaleString('pt-BR') : 'Data não registrada';
            
            html += `
                <div class="timeline-item-modern ${concluida ? 'concluido' : 'pendente'}">
                    <div class="timeline-icon-modern">
                        <i class="fas ${etapa.icone}"></i>
                    </div>
                    <div class="timeline-content">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 fw-bold text-dark">${etapa.texto}</h6>
                            <span class="badge ${concluida ? 'bg-success' : 'bg-secondary'} rounded-pill">
                                ${concluida ? '<i class="fas fa-check me-1"></i>Concluído' : '<i class="fas fa-clock me-1"></i>Pendente'}
                            </span>
                        </div>
                        <div class="text-muted small mb-2">
                            <i class="fas fa-calendar-alt me-1"></i>
                            ${dataFormatada}
                        </div>
                        ${concluida && etapa.verificacao ? 
                            `<div class="mt-2 p-2 bg-light rounded small">
                                <strong>Informação:</strong> ${etapa.verificacao.length > 100 ? etapa.verificacao.substring(0, 100) + '...' : etapa.verificacao}
                            </div>` : ''}
                    </div>
                </div>
            `;
        });

        // Adicionar marcos especiais se existirem
        if (pedido.marcos_progresso && Array.isArray(pedido.marcos_progresso)) {
            const marcosEspeciais = {
                'audio_introdutorio': {
                    texto: 'Ouviu áudio introdutório',
                    icone: 'fa-headphones',
                    classe: 'bg-info'
                },
                'perguntas': {
                    texto: 'Fez perguntas sobre o produto',
                    icone: 'fa-question-circle',
                    classe: 'bg-purple'
                },
                'entrega': {
                    texto: 'Viu informações de entrega',
                    icone: 'fa-truck',
                    classe: 'bg-warning'
                }
            };

            pedido.marcos_progresso.forEach(marco => {
                if (marcosEspeciais[marco]) {
                    const config = marcosEspeciais[marco];
                    const timestamp = pedido.marcos_timestamp?.[marco] || pedido.timestamp;
                    const dataFormatada = timestamp ? new Date(timestamp).toLocaleString('pt-BR') : 'Data não registrada';

                    html += `
                        <div class="timeline-item-modern concluido" style="border-left-color: #6f42c1;">
                            <div class="timeline-icon-modern" style="background: linear-gradient(135deg, #6f42c1, #e83e8c);">
                                <i class="fas ${config.icone}"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0 fw-bold text-dark">${config.texto}</h6>
                                    <span class="badge bg-purple rounded-pill">
                                        <i class="fas fa-star me-1"></i>Marco Especial
                                    </span>
                                </div>
                                <div class="text-muted small">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    ${dataFormatada}
                                </div>
                            </div>
                        </div>
                    `;
                }
            });
        }

        // Se não houver nenhuma etapa além do início
        if (ultimaEtapaConcluida === 0 && (!pedido.marcos_progresso || pedido.marcos_progresso.length === 0)) {
            html += `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-info-circle fa-2x mb-3 opacity-50"></i>
                    <p class="mb-0">Apenas iniciou a conversa, sem progressão adicional.</p>
                    <small>O cliente ainda não forneceu informações adicionais.</small>
                </div>
            `;
        }

        timelineContainer.innerHTML = html;
    }

    // Função para atualizar o header do modal com informações dinâmicas
    function atualizarHeaderModal(pedido) {
        // Atualizar nome no header
        const headerNome = document.getElementById('header-nome-cliente');
        if (headerNome) {
            headerNome.textContent = pedido.NOME || 'Lead Anônimo';
        }

        // Atualizar badge de origem
        const headerOrigem = document.getElementById('header-origem-badge');
        if (headerOrigem) {
            if (pedido.origem === 'facebook') {
                headerOrigem.innerHTML = '<i class="fab fa-facebook-f me-1"></i> Facebook';
            } else if (pedido.origem === 'whatsapp') {
                headerOrigem.innerHTML = '<i class="fab fa-whatsapp me-1"></i> WhatsApp';
            } else {
                headerOrigem.innerHTML = '<i class="fas fa-question-circle me-1"></i> Desconhecida';
            }
        }

        // Atualizar badge de tempo
        const headerTempo = document.getElementById('header-tempo-badge');
        if (headerTempo && pedido.tempo_abandono) {
            let textoTempo = '';
            if (pedido.tempo_abandono < 60) {
                textoTempo = `${pedido.tempo_abandono} min`;
            } else {
                const horas = Math.floor(pedido.tempo_abandono / 60);
                const minutos = pedido.tempo_abandono % 60;
                textoTempo = `${horas}h${minutos > 0 ? ` ${minutos}m` : ''}`;
            }
            headerTempo.innerHTML = `<i class="fas fa-clock me-1"></i> ${textoTempo}`;
        }

        // Atualizar badge de progresso
        const headerProgresso = document.getElementById('header-progresso-badge');
        if (headerProgresso) {
            let progresso = 20; // Início
            if (pedido.NOME) progresso = 40;
            if (pedido.CONTATO) progresso = 60;
            if (pedido.PROBLEMA_RELATADO) progresso = 80;
            if (pedido.ENDEREÇO) progresso = 100;

            headerProgresso.innerHTML = `<i class="fas fa-chart-line me-1"></i> ${progresso}%`;
        }
    }

    // Função para atualizar a barra de progresso visual
    function atualizarBarraProgresso(pedido) {
        const barraProgresso = document.getElementById('barra-progresso-lead');
        const etapasProgresso = document.getElementById('etapas-progresso');
        
        if (!barraProgresso || !etapasProgresso) return;

        // Calcular progresso
        let progresso = 20; // Início sempre conta
        let etapaAtual = 'inicio';

        if (pedido.NOME) {
            progresso = 40;
            etapaAtual = 'nome';
        }
        if (pedido.CONTATO) {
            progresso = 60;
            etapaAtual = 'contato';
        }
        if (pedido.PROBLEMA_RELATADO) {
            progresso = 80;
            etapaAtual = 'problema';
        }
        if (pedido.ENDEREÇO) {
            progresso = 100;
            etapaAtual = 'endereco';
        }

        // Atualizar barra de progresso
        barraProgresso.style.width = progresso + '%';
        barraProgresso.setAttribute('aria-valuenow', progresso);

        // Atualizar etapas visuais
        const etapas = etapasProgresso.querySelectorAll('.etapa-item');
        etapas.forEach(etapa => {
            const etapaId = etapa.getAttribute('data-etapa');
            etapa.classList.remove('ativa', 'concluida');
            
            // Determinar status da etapa
            const etapasOrdem = ['inicio', 'nome', 'contato', 'problema', 'endereco'];
            const etapaIndex = etapasOrdem.indexOf(etapaId);
            const etapaAtualIndex = etapasOrdem.indexOf(etapaAtual);
            
            if (etapaIndex < etapaAtualIndex) {
                etapa.classList.add('concluida');
            } else if (etapaIndex === etapaAtualIndex) {
                etapa.classList.add('ativa');
            }
        });
    }

    // Função para renderizar conversas com design moderno
    function renderizarConversas(pedido) {
        const perguntasContainer = document.getElementById('detalhe-perguntas');
        const contadorConversas = document.getElementById('contador-conversas');
        
        if (!perguntasContainer) return;

        // Verificar se temos conversas com IA registradas
        if (pedido.CONVERSA_IA && Array.isArray(pedido.CONVERSA_IA) && pedido.CONVERSA_IA.length > 0) {
            let perguntasHtml = '';
            
            pedido.CONVERSA_IA.forEach((conversa, index) => {
                const timestamp = conversa.timestamp 
                    ? new Date(conversa.timestamp).toLocaleString('pt-BR') 
                    : (pedido.timestamp ? new Date(pedido.timestamp).toLocaleString('pt-BR') : 'Horário não registrado');
                
                perguntasHtml += `
                <div class="chat-message-modern">
                    <div class="message-header-modern">
                        <div class="message-title-modern">
                            <i class="fas fa-comment-dots text-primary"></i> 
                            Conversa ${index + 1}
                        </div>
                        <div class="message-time-modern">
                            <i class="fas fa-clock me-1"></i> ${timestamp}
                        </div>
                    </div>
                    <div class="message-content-modern">
                        <div class="pergunta-box mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-user text-primary me-2"></i>
                                <strong class="text-primary">Cliente perguntou:</strong>
                            </div>
                            <p class="mb-0">${conversa.pergunta}</p>
                        </div>
                        <div class="resposta-box">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-user-md text-success me-2"></i>
                                <strong class="text-success">Dr. Bruno Celso respondeu:</strong>
                            </div>
                            <p class="mb-0">${conversa.resposta}</p>
                        </div>
                        ${conversa.tipo === 'pos_venda' ? 
                            '<div class="mt-2"><span class="badge bg-info"><i class="fas fa-tag me-1"></i> Pós-venda</span></div>' : ''}
                    </div>
                </div>
                `;
            });
            
            perguntasContainer.innerHTML = perguntasHtml;
            
            // Atualizar contador
            if (contadorConversas) {
                contadorConversas.textContent = pedido.CONVERSA_IA.length;
            }
        } 
        // Se não temos conversas IA mas temos perguntas registradas (compatibilidade com dados antigos)
        else if (pedido.PERGUNTAS_FEITAS && Array.isArray(pedido.PERGUNTAS_FEITAS) && pedido.PERGUNTAS_FEITAS.length > 0) {
            let perguntasHtml = '';
            
            pedido.PERGUNTAS_FEITAS.forEach((pergunta, index) => {
                perguntasHtml += `
                <div class="chat-message-modern">
                    <div class="message-header-modern">
                        <div class="message-title-modern">
                            <i class="fas fa-question-circle text-warning"></i> 
                            Pergunta ${index + 1}
                        </div>
                        <div class="message-time-modern">
                            <i class="fas fa-clock me-1"></i>
                            ${pedido.timestamp ? new Date(pedido.timestamp).toLocaleString('pt-BR') : 'Horário não registrado'}
                        </div>
                    </div>
                    <div class="message-content-modern">
                        <div class="pergunta-box">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-user text-primary me-2"></i>
                                <strong class="text-primary">Cliente perguntou:</strong>
                            </div>
                            <p class="mb-2">${pergunta}</p>
                            <div class="alert alert-warning mb-0 py-2">
                                <small><i class="fas fa-info-circle me-1"></i> Resposta não registrada (versão antiga do sistema)</small>
                            </div>
                        </div>
                    </div>
                </div>
                `;
            });
            
            perguntasContainer.innerHTML = perguntasHtml;
            
            // Atualizar contador
            if (contadorConversas) {
                contadorConversas.textContent = pedido.PERGUNTAS_FEITAS.length;
            }
        } else {
            perguntasContainer.innerHTML = `
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-comment-slash fa-3x mb-3 opacity-50"></i>
                    <p class="mb-1">Nenhuma conversa registrada</p>
                    <small>As perguntas e respostas aparecerão aqui quando disponíveis</small>
                </div>
            `;
            
            // Atualizar contador
            if (contadorConversas) {
                contadorConversas.textContent = '0';
            }
        }
    }

    // Função para renderizar anotações com design moderno
    function renderizarAnotacoes(anotacoes) {
        const container = document.getElementById('lista-anotacoes');
        const semAnotacoes = document.getElementById('sem-anotacoes');
        const contadorAnotacoes = document.getElementById('contador-anotacoes');
        
        if (!anotacoes || anotacoes.length === 0) {
            container.innerHTML = '';
            if (semAnotacoes) semAnotacoes.style.display = 'block';
            if (contadorAnotacoes) contadorAnotacoes.textContent = '0';
            return;
        }
        
        // Esconder mensagem "sem anotações"
        if (semAnotacoes) semAnotacoes.style.display = 'none';
        
        // Atualizar contador
        if (contadorAnotacoes) contadorAnotacoes.textContent = anotacoes.length;
        
        // Construir HTML das anotações com design moderno
        let html = '';
        
        anotacoes.forEach(anotacao => {
            const data = new Date(anotacao.timestamp);
            const dataFormatada = data.toLocaleDateString('pt-BR') + ' às ' + data.toLocaleTimeString('pt-BR');
            
            html += `
                <div class="anotacao-item-modern">
                    <div class="anotacao-header">
                        <div class="anotacao-autor">
                            <i class="fas fa-user-circle text-primary"></i>
                            ${anotacao.usuario_nome}
                        </div>
                        <div class="anotacao-data">
                            <i class="fas fa-clock me-1"></i>
                            ${dataFormatada}
                        </div>
                    </div>
                    <div class="anotacao-texto">
                        ${anotacao.comentario.replace(/\n/g, '<br>')}
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }
});
</script>

<?php include 'footer.php'; ?> 