<?php
session_start();

// Verificar se já está logado
if (isset($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Incluir configurações
require_once 'config.php';

$erro = '';

// Processar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = $_POST['login'] ?? '';
    $senha = $_POST['senha'] ?? '';
    
    // Conexão com o banco
    $conn = conectarBD();
    
    // Verificar login
    $sql = "SELECT * FROM usuarios WHERE login = ? AND senha = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $login, $senha);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $usuario = $result->fetch_assoc();
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_login'] = $usuario['login'];
        
        // Redirecionar para o dashboard
        header('Location: dashboard.php');
        exit;
    } else {
        $erro = 'Login ou senha incorretos';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <title>4Life Nutri - Logística</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-green: #568C1C;
            --light-green: #E8F3E1;
            --dark-green: #3A6014;
            --accent-green: #7DB03B;
            --light-text: #FFFFFF;
            --dark-text: #333333;
            --card-background: #FFFFFF;
            --background-light: #F5F5F5;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Montserrat', Arial, sans-serif;
        }
        
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap');
        
        body {
            background-color: var(--background-light);
            margin: 0;
            padding: 0;
            overflow: hidden;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }
        
        /* Elementos de fundo animados */
        .bg-element {
            position: absolute;
            pointer-events: none;
            z-index: 1;
        }
        
        /* Pílula */
        .pill {
            width: 40px;
            height: 15px;
            background: linear-gradient(135deg, var(--primary-green), var(--accent-green));
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        
        /* Login Box */
        .login-box {
            position: relative;
            background: var(--card-background);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            width: 380px;
            z-index: 10;
            overflow: hidden;
        }
        
        .login-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: var(--primary-green);
            border-radius: 15px 15px 0 0;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-logo {
            margin: 0 auto 20px;
            width: 120px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .login-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 5px;
        }
        
        .login-subtitle {
            font-size: 16px;
            color: var(--dark-text);
            opacity: 0.8;
        }
        
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--dark-text);
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 15px 14px 40px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s ease;
            color: var(--dark-text);
            background-color: #f8f8f8;
        }
        
        .form-group input:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(86, 140, 28, 0.15);
            background-color: #fff;
            outline: none;
        }
        
        .form-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            transition: color 0.3s ease;
        }
        
        .form-group input:focus + i {
            color: var(--primary-green);
        }
        
        .btn {
            background-color: var(--primary-green);
            color: var(--light-text);
            border: none;
            padding: 16px;
            width: 100%;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 16px;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn:hover {
            background-color: var(--dark-green);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(86, 140, 28, 0.3);
        }
        
        .btn::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: -100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: all 0.5s ease;
        }
        
        .btn:hover::after {
            left: 100%;
        }
        
        .error {
            background-color: rgba(255, 82, 82, 0.1);
            color: #ff5252;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
            border-left: 3px solid #ff5252;
            font-size: 14px;
        }
        
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 13px;
            color: #888;
        }
        
        /* Loader */
        .loader-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: var(--background-light);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.5s ease;
        }
        
        .loader {
            text-align: center;
        }
        
        .loader-logo {
            width: 100px;
            height: 100px;
            margin: 0 auto 20px;
            overflow: hidden;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .loader-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(86, 140, 28, 0.2);
            border-radius: 50%;
            border-top-color: var(--primary-green);
            margin: 0 auto 15px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loader-text {
            color: var(--primary-green);
            font-size: 16px;
            font-weight: 500;
        }
        
        /* Animações */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Novas animações suaves para as pílulas */
        @keyframes moveLeftToRight {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(calc(100vw + 100px)); }
        }
        
        @keyframes moveRightToLeft {
            0% { transform: translateX(calc(100vw + 100px)); }
            100% { transform: translateX(-100%); }
        }
        
        @keyframes moveTopToBottom {
            0% { transform: translateY(-100%); }
            100% { transform: translateY(calc(100vh + 100px)); }
        }
        
        @keyframes moveBottomToTop {
            0% { transform: translateY(calc(100vh + 100px)); }
            100% { transform: translateY(-100%); }
        }
        
        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Aplicando animações aos elementos */
        .login-box {
            animation: fadeIn 0.8s ease forwards;
        }
        
        .login-header {
            animation: slideDown 0.8s ease forwards;
        }
        
        .form-group:nth-child(1) {
            animation: slideUp 0.8s ease forwards 0.2s;
            opacity: 0;
            animation-fill-mode: forwards;
        }
        
        .form-group:nth-child(2) {
            animation: slideUp 0.8s ease forwards 0.3s;
            opacity: 0;
            animation-fill-mode: forwards;
        }
        
        .btn {
            animation: slideUp 0.8s ease forwards 0.4s;
            opacity: 0;
            animation-fill-mode: forwards;
        }
        
        .footer {
            animation: fadeIn 0.8s ease forwards 0.5s;
            opacity: 0;
            animation-fill-mode: forwards;
        }
        
        /* Responsividade */
        @media (max-width: 500px) {
            .login-box {
                width: 90%;
                padding: 30px 20px;
            }
            
            .login-logo {
                width: 100px;
                height: 100px;
            }
            
            .login-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <!-- Loader que vai sumir automaticamente -->
    <div class="loader-container" id="loader">
        <div class="loader">
            <div class="loader-logo">
                <img src="https://suplements.tech/Assets/logo.jpg" alt="4Life Nutrition Logo" class="logo-img">
            </div>
            <div class="loader-spinner"></div>
            <div class="loader-text">Carregando sistema...</div>
        </div>
    </div>
    
    <!-- Login box -->
    <div class="login-box" id="login-box">
        <div class="login-header">
            <div class="login-logo">
                <img src="https://suplements.tech/Assets/logo.jpg" alt="4Life Nutrition Logo" class="logo-img">
            </div>
            <h1 class="login-title">4Life Nutri</h1>
            <p class="login-subtitle">Sistema de Logística</p>
        </div>
        
        <?php if ($erro): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?= $erro ?>
            </div>
        <?php endif; ?>
        
        <form method="post" id="login-form">
            <div class="form-group">
                <label for="login">Usuário</label>
                <div class="input-wrapper">
                    <input type="text" id="login" name="login" required autocomplete="off">
                    <i class="fas fa-user"></i>
                </div>
            </div>
            <div class="form-group">
                <label for="senha">Senha</label>
                <div class="input-wrapper">
                    <input type="password" id="senha" name="senha" required>
                    <i class="fas fa-lock"></i>
                </div>
            </div>
            <button type="submit" class="btn" id="login-btn">ACESSAR SISTEMA</button>
        </form>
        
        <div class="footer">
            <p>&copy; <?= date('Y') ?> 4Life Nutri - Todos os direitos reservados</p>
        </div>
    </div>

    <!-- Container para elementos de fundo -->
    <div id="background-elements"></div>
    
    <script>
        // Script principal - executa quando a página carrega
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializa elementos
            setupFormInteraction();
            
            // Segurança: garantir que o loader vai desaparecer mesmo se houver erros
            setTimeout(function() {
                var loader = document.getElementById('loader');
                if (loader) {
                    loader.style.opacity = '0';
                    setTimeout(function() {
                        loader.style.display = 'none';
                        
                        // Só começar a criar as pílulas depois que o loader sumir
                        // e o login aparecer (criação gradual, sem surgir tudo de uma vez)
                        startPillAnimation();
                    }, 500);
                }
            }, 1200);
        });
        
        // Iniciar animação de pílulas gradualmente
        function startPillAnimation() {
            var container = document.getElementById('background-elements');
            
            // Criar as primeiras 5 pílulas imediatamente, com opacidade inicial 0
            for (var i = 0; i < 5; i++) {
                createPill(container, i * 0.2);
            }
            
            // Adicionar mais pílulas gradualmente (uma a cada 200ms)
            var count = 5;
            var interval = setInterval(function() {
                createPill(container);
                count++;
                
                // Quando atingir 30 pílulas, parar de criar novas e começar 
                // a manter um fluxo constante (removendo antigas, adicionando novas)
                if (count >= 30) {
                    clearInterval(interval);
                    
                    // Manter fluxo contínuo de novas pílulas
                    setInterval(function() {
                        createPill(container);
                    }, 1000); // Criar uma nova pílula a cada segundo
                }
            }, 200);
        }
        
        // Criar uma pílula com animação suave
        function createPill(container, delay) {
            // Criar elemento da pílula
            var pill = document.createElement('div');
            pill.className = 'bg-element pill';
            
            // Variação no tamanho
            var width = 30 + Math.random() * 20;
            var height = width * 0.4;
            pill.style.width = width + 'px';
            pill.style.height = height + 'px';
            
            // Escolher direção de movimento aleatória
            var directions = ['left-to-right', 'right-to-left', 'top-to-bottom', 'bottom-to-top'];
            var direction = directions[Math.floor(Math.random() * directions.length)];
            
            // Configurar posição inicial baseada na direção
            var speed = 15 + Math.random() * 20; // Velocidade em segundos
            var position = {};
            var animation = '';
            
            switch(direction) {
                case 'left-to-right':
                    position = { 
                        left: '0', 
                        top: (10 + Math.random() * 80) + '%'
                    };
                    animation = 'moveLeftToRight ' + speed + 's linear';
                    break;
                    
                case 'right-to-left':
                    position = { 
                        right: '0', 
                        top: (10 + Math.random() * 80) + '%'
                    };
                    animation = 'moveRightToLeft ' + speed + 's linear';
                    break;
                    
                case 'top-to-bottom':
                    position = { 
                        top: '0', 
                        left: (10 + Math.random() * 80) + '%'
                    };
                    animation = 'moveTopToBottom ' + speed + 's linear';
                    break;
                    
                case 'bottom-to-top':
                    position = { 
                        bottom: '0', 
                        left: (10 + Math.random() * 80) + '%'
                    };
                    animation = 'moveBottomToTop ' + speed + 's linear';
                    break;
            }
            
            // Aplicar posição
            for (var prop in position) {
                pill.style[prop] = position[prop];
            }
            
            // Adicionar rotação aleatória
            if (Math.random() > 0.5) {
                animation += ', rotate ' + (speed * 2) + 's linear';
            }
            
            // Aplicar animação
            pill.style.animation = animation;
            
            // Adicionar ao container
            container.appendChild(pill);
            
            // Mostrar a pílula gradualmente (com atraso se especificado)
            setTimeout(function() {
                pill.style.opacity = (Math.random() * 0.3 + 0.3).toString();
            }, delay ? delay * 1000 : 0);
            
            // Remover a pílula quando a animação terminar
            pill.addEventListener('animationend', function(event) {
                // Se for a animação de movimento
                if (event.animationName.includes('move')) {
                    pill.remove();
                }
            });
        }
        
        // Configurar interações do formulário
        function setupFormInteraction() {
            // Efeito nos campos de input
            var inputs = document.querySelectorAll('input');
            inputs.forEach(function(input) {
                // Eventos de foco
                input.addEventListener('focus', function() {
                    var label = this.parentNode.parentNode.querySelector('label');
                    var icon = this.nextElementSibling;
                    
                    if (label) label.style.color = '#568C1C';
                    if (icon) icon.style.color = '#568C1C';
                });
                
                // Eventos de saída de foco
                input.addEventListener('blur', function() {
                    var label = this.parentNode.parentNode.querySelector('label');
                    var icon = this.nextElementSibling;
                    
                    if (label) label.style.color = '';
                    if (icon && !this.value) icon.style.color = '';
                });
            });
            
            // Efeito no botão
            var button = document.querySelector('.btn');
            if (button) {
                button.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 5px 15px rgba(86, 140, 28, 0.3)';
                });
                
                button.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                    this.style.boxShadow = '';
                });
                
                button.addEventListener('mousedown', function() {
                    this.style.transform = 'translateY(1px)';
                });
                
                button.addEventListener('mouseup', function() {
                    this.style.transform = '';
                });
            }
        }
    </script>
</body>
</html>