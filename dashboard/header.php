<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}
require_once 'config.php';

// Determinar página atual usando o nome do arquivo
$current_page = basename($_SERVER['PHP_SELF']);

// Conectar ao banco
$conn = conectarBD();

// Verificar se a coluna nivel_acesso existe
$checkColumn = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'nivel_acesso'");
$columnExists = $checkColumn->num_rows > 0;

// Obter o nível de acesso do usuário apenas se a coluna existir
$nivel_acesso = 'logistica'; // Padrão é logística
if ($columnExists) {
    $stmt = $conn->prepare("SELECT nivel_acesso FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['usuario_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $usuario = $result->fetch_assoc();
    $nivel_acesso = $usuario['nivel_acesso'] ?? 'logistica';
}

// DEBUG: Verificar o nível de acesso
error_log('Header.php - Nível de acesso: ' . $nivel_acesso);

// Definir restrições de acesso por página com base no nível
$acesso_permitido = true;

// Verifica as permissões baseadas no nível de acesso
switch($nivel_acesso) {
    case 'ltv':
        // LTV pode ver: dashboard, pedidos (para ver entregas e devoluções), configuracoes, logout
        $paginas_permitidas = ['dashboard.php', 'pedidos.php', 'entregas.php', 'configuracoes.php', 'logout.php'];
        $acesso_permitido = in_array($current_page, $paginas_permitidas);
        break;
    case 'vendas':
        // Vendas tem acesso a: dashboard, pedidos, entregas, métricas, experimentos, configurações, logout
        $paginas_permitidas = ['dashboard.php', 'pedidos.php', 'entregas.php', 'metricas.php', 'experimentos.php', 'configuracoes.php', 'logout.php'];
        $acesso_permitido = in_array($current_page, $paginas_permitidas);
        break;
    case 'recuperacao':
        // Recuperação tem acesso a: dashboard, pedidos_recuperacao, métricas, experimentos, configurações, logout
        $paginas_permitidas = ['dashboard.php', 'pedidos_recuperacao.php', 'metricas.php', 'experimentos.php', 'configuracoes.php', 'logout.php'];
        $acesso_permitido = in_array($current_page, $paginas_permitidas);
        break;
    case 'logistica':
    default:
        // Logística tem acesso total
        $acesso_permitido = true;
        break;
}

// Redirecionar se não tiver permissão
if (!$acesso_permitido && $current_page !== 'dashboard.php') {
    $_SESSION['erro_mensagem'] = "Você não tem permissão para acessar esta área.";
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="https://suplements.tech/Assets/logo.jpg" type="image/jpeg">
    <title>4Life Nutri - Logística</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Estilos gerais */
        :root {
            --primary-color: #2E7D32;  /* Verde escuro */
            --secondary-color: #1B5E20; /* Verde mais escuro para sidebar */
            --accent-color: #81C784; /* Verde claro para destaques */
            --hover-color: rgba(129, 199, 132, 0.2); /* Verde claro com transparência para hover */
            --bg-light: #f5f8f5; /* Cinza esverdeado suave para o fundo */
            --text-light: #e8f5e9; /* Texto claro para sidebar */
            --shadow-color: rgba(0, 77, 64, 0.15); /* Sombra esverdeada */
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-light);
            color: #333;
        }
        
        .sidebar {
            background: linear-gradient(to bottom, var(--secondary-color), #0D3B0D);
            color: var(--text-light);
            width: 270px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: all 0.3s;
            overflow-y: auto;
            box-shadow: 3px 0 8px var(--shadow-color);
            border-right: 1px solid rgba(255,255,255,0.05);
            display: flex;
            flex-direction: column;
        }
        
        .sidebar .logo {
            padding: 0;
            text-align: center;
            background-color: #fff;
            margin: 15px;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 10px var(--shadow-color);
            flex-shrink: 0;
        }
        
        .sidebar .logo img {
            width: 100%;
            max-width: 100%;
            display: block;
        }
        
        .sidebar .nav-item {
            padding: 12px 22px;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            color: var(--text-light);
            text-decoration: none;
            border-radius: 0 30px 30px 0;
            margin: 5px 0 5px 0;
            font-weight: 500;
            letter-spacing: 0.3px;
        }
        
        .sidebar .nav-item:hover {
            background-color: var(--hover-color);
            transform: translateX(5px);
        }
        
        .sidebar .nav-item i {
            margin-right: 15px;
            width: 20px;
            text-align: center;
            font-size: 1.1em;
        }
        
        .sidebar .nav-item.active {
            background-color: var(--accent-color);
            color: var(--secondary-color);
            font-weight: 600;
            box-shadow: 0 4px 8px var(--shadow-color);
        }
        
        /* Espaçador para separar os itens de menu */
        .sidebar-spacer {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 30px 20px 30px 20px;
        }
        
        /* Estilo para os itens de menu no rodapé da sidebar */
        .sidebar .nav-item.footer-item {
            border-left: 4px solid transparent;
            padding-left: 18px;
        }
        
        .sidebar .nav-item.footer-item:hover {
            border-left-color: var(--accent-color);
            background-color: rgba(0, 0, 0, 0.15);
        }
        
        /* Efeito especial no item de Sair */
        .sidebar .nav-item[href="logout.php"] {
            color: #ffcccb;
            transition: all 0.3s ease;
        }
        
        .sidebar .nav-item[href="logout.php"]:hover {
            background-color: rgba(255, 99, 71, 0.2);
            color: #ff6b6b;
        }
        
        .content-wrapper {
            margin-left: 270px;
            padding: 25px;
            transition: all 0.3s;
            max-width: calc(100% - 270px);
            box-sizing: border-box;
        }
        
        .header {
            background-color: white;
            padding: 15px 25px;
            box-shadow: 0 3px 10px var(--shadow-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-radius: 12px;
        }
        
        .card {
            border-radius: 12px;
            box-shadow: 0 5px 15px var(--shadow-color);
            margin-bottom: 25px;
            border: none;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px var(--shadow-color);
        }
        
        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 18px 25px;
            font-weight: 600;
            color: var(--secondary-color);
        }
        
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .status-novo { background-color: #fff3cd; color: #856404; }
        .status-transito { background-color: #cce5ff; color: #004085; }
        .status-entregue { background-color: #d4edda; color: #155724; }
        .status-devolvido { background-color: #f8d7da; color: #721c24; }
        
        .data-card {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: white;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s;
            height: 100%;
            box-shadow: 0 5px 15px var(--shadow-color);
        }
        
        .data-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 20px var(--shadow-color);
        }
        
        .data-number {
            font-size: 38px;
            font-weight: 700;
            margin: 15px 0;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
        }
        
        .data-label {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            opacity: 0.9;
            font-weight: 500;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            box-shadow: 0 2px 5px var(--shadow-color);
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            background-color: #225f26;
            border-color: #225f26;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px var(--shadow-color);
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }
            
            .sidebar .logo span, .sidebar .nav-item span {
                display: none;
            }
            
            .sidebar .nav-item.footer-item {
                padding-left: 22px;
            }
            
            .content-wrapper {
                margin-left: 80px;
                padding: 15px;
                max-width: calc(100% - 80px);
            }
        }
        
        .sidebar nav {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar .menu-principal {
            flex: 1;
        }
        
        .sidebar .menu-footer {
            margin-top: auto;
            padding-bottom: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 15px;
            margin-bottom: 5px;
        }
        
        .sidebar .menu-footer .nav-item {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <img src="https://suplements.tech/Assets/banner.png" alt="4Life Nutri Banner">
        </div>
        <nav>
            <div class="menu-principal">
                <a href="dashboard.php" class="nav-item <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">
                    <i class="fas fa-tachometer-alt"></i> <span>Dashboard</span>
                </a>
                
                <?php if ($nivel_acesso == 'logistica' || $nivel_acesso == 'vendas' || $nivel_acesso == 'ltv'): ?>
                <a href="pedidos.php" class="nav-item <?= ($current_page == 'pedidos.php') ? 'active' : '' ?>">
                    <i class="fas fa-shopping-cart"></i> <span>Pedidos</span>
                </a>
                <?php endif; ?>
                
                <?php if ($nivel_acesso == 'logistica' || $nivel_acesso == 'recuperacao'): ?>
                <a href="pedidos_recuperacao.php" class="nav-item <?= ($current_page == 'pedidos_recuperacao.php') ? 'active' : '' ?>">
                    <i class="fas fa-sync-alt"></i> <span>Recuperação</span>
                    <?php
                    // Contar o número de pedidos de recuperação
                    $diretorio_recuperacao = '../pedidos_recuperacao';
                    $contagem_recuperacao = 0;
                    
                    if (is_dir($diretorio_recuperacao)) {
                        $arquivos = scandir($diretorio_recuperacao);
                        foreach ($arquivos as $arquivo) {
                            if ($arquivo != '.' && $arquivo != '..' && pathinfo($arquivo, PATHINFO_EXTENSION) == 'json') {
                                $contagem_recuperacao++;
                            }
                        }
                    }
                    
                    if ($contagem_recuperacao > 0) {
                        echo ' <small><span class="badge bg-danger">' . $contagem_recuperacao . '</span></small>';
                    }
                    ?>
                </a>
                <?php endif; ?>
                
                <?php if ($nivel_acesso == 'logistica' || $nivel_acesso == 'vendas' || $nivel_acesso == 'ltv'): ?>
                <a href="entregas.php" class="nav-item <?= ($current_page == 'entregas.php') ? 'active' : '' ?>">
                    <i class="fas fa-box"></i> <span>Entregas</span>
                </a>
                <?php endif; ?>
                
                <?php if ($nivel_acesso == 'logistica' || $nivel_acesso == 'vendas' || $nivel_acesso == 'recuperacao'): ?>
                <a href="metricas.php" class="nav-item <?= ($current_page == 'metricas.php') ? 'active' : '' ?>">
                    <i class="fas fa-chart-line"></i> <span>Métricas</span>
                </a>
                <?php endif; ?>

                <?php if ($nivel_acesso == 'logistica' || $nivel_acesso == 'vendas' || $nivel_acesso == 'recuperacao'): ?>
                <a href="relatorios.php" class="nav-item <?= ($current_page == 'relatorios.php') ? 'active' : '' ?>">
                    <i class="fas fa-file-chart-line"></i> <span>Relatórios</span>
                </a>
                <?php endif; ?>

                <?php if ($nivel_acesso == 'vendas'): ?>
                <a href="experimentos.php" class="nav-item <?= ($current_page == 'experimentos.php') ? 'active' : '' ?>">
                    <i class="fas fa-flask"></i> Experimentos
                </a>
                <?php endif; ?>
            </div>
            
            <div class="menu-footer">
                <!-- Itens de menu no rodapé -->
                <a href="configuracoes.php" class="nav-item footer-item <?= ($current_page == 'configuracoes.php') ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> <span>Configurações</span>
                </a>
                
                <a href="logout.php" class="nav-item footer-item">
                    <i class="fas fa-sign-out-alt"></i> <span>Sair</span>
                </a>
            </div>
        </nav>
    </div>
    <!-- Main Content - fixed -->
    <div class="content-wrapper">  
        <div class="header" style="display: none;">
            <h4>
            </h4>
            <div>
                <?php if ($nivel_acesso == 'logistica'): ?>
                <a href="atualizar.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-sync-alt"></i> Atualizar Dados
                </a>
                <?php endif; ?>
                
                <span class="badge bg-<?php 
                    echo match($nivel_acesso) {
                        'ltv' => 'info',
                        'logistica' => 'primary',
                        'vendas' => 'success',
                        'recuperacao' => 'warning',
                        default => 'secondary'
                    };
                ?>">
                    <?php echo ucfirst($nivel_acesso); ?>
                </span>
            </div>
        </div>
        
        <div class="container-fluid">