<?php 
// Incluir o header e verificar login
include 'header.php'; 

// Conectar ao banco
$conn = conectarBD();

// Verificar se a coluna nivel_acesso existe
$checkColumn = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'nivel_acesso'");
$columnExists = $checkColumn->num_rows > 0;

// Processar formulário de usuário
$mensagem = '';
$tipo_mensagem = '';

// Verificar se há mensagens na sessão
if (isset($_SESSION['mensagem']) && isset($_SESSION['tipo_mensagem'])) {
    $mensagem = $_SESSION['mensagem'];
    $tipo_mensagem = $_SESSION['tipo_mensagem'];
    // Limpar as mensagens da sessão
    unset($_SESSION['mensagem']);
    unset($_SESSION['tipo_mensagem']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['acao']) && $_POST['acao'] === 'novo_usuario') {
        // Verificar se o usuário tem nível de acesso de logística
        if ($nivel_acesso !== 'logistica') {
            $mensagem = 'Acesso negado. Apenas usuários de logística podem adicionar novos usuários.';
            $tipo_mensagem = 'danger';
        } else {
        $nome = $_POST['nome'] ?? '';
        $senha = $_POST['senha'] ?? '';
        $acesso = $_POST['acesso'] ?? 'logistica';
        
        // Verificar se o nome já existe
        $check = $conn->prepare("SELECT id FROM usuarios WHERE login = ?");
        $check->bind_param("s", $nome);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            $mensagem = 'Nome de usuário já existe!';
            $tipo_mensagem = 'danger';
        } else {
            // Inserir novo usuário - verificar se a coluna nivel_acesso existe
            if ($columnExists) {
                $stmt = $conn->prepare("INSERT INTO usuarios (login, senha, nivel_acesso) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $nome, $senha, $acesso);
            } else {
                $stmt = $conn->prepare("INSERT INTO usuarios (login, senha) VALUES (?, ?)");
                $stmt->bind_param("ss", $nome, $senha);
            }
            
            if ($stmt->execute()) {
                $mensagem = 'Usuário adicionado com sucesso!';
                $tipo_mensagem = 'success';
            } else {
                $mensagem = 'Erro ao adicionar usuário.';
                $tipo_mensagem = 'danger';
                }
            }
        }
    } elseif (isset($_POST['acao']) && $_POST['acao'] === 'alterar_senha') {
        $senha_atual = $_POST['senha_atual'] ?? '';
        $nova_senha = $_POST['nova_senha'] ?? '';
        $confirmar_senha = $_POST['confirmar_senha'] ?? '';
        
        // Verificar senha atual
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id = ? AND senha = ?");
        $stmt->bind_param("is", $_SESSION['usuario_id'], $senha_atual);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $mensagem = 'Senha atual incorreta!';
            $tipo_mensagem = 'danger';
        } elseif ($nova_senha !== $confirmar_senha) {
            $mensagem = 'As senhas não coincidem!';
            $tipo_mensagem = 'danger';
        } else {
            // Atualizar senha
            $stmt = $conn->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
            $stmt->bind_param("si", $nova_senha, $_SESSION['usuario_id']);
            
            if ($stmt->execute()) {
                $mensagem = 'Senha alterada com sucesso!';
                $tipo_mensagem = 'success';
            } else {
                $mensagem = 'Erro ao alterar a senha.';
                $tipo_mensagem = 'danger';
            }
        }
    } elseif (isset($_POST['acao']) && $_POST['acao'] === 'atualizar_config') {
        // Atualizar config.php manualmente
        $mensagem = 'Configurações atualizadas. Lembre-se de editar o arquivo config.php manualmente.';
        $tipo_mensagem = 'info';
    }
}

// Buscar usuários
if ($columnExists) {
    $usuarios = $conn->query("SELECT id, login, nivel_acesso FROM usuarios ORDER BY id");
} else {
    $usuarios = $conn->query("SELECT id, login FROM usuarios ORDER BY id");
}

// Buscar última atualização
$ultima_att = $conn->query("SELECT MAX(ultima_atualizacao) FROM entregas")->fetch_row()[0] ?? 'Nunca';
if ($ultima_att != 'Nunca') {
    $ultima_att = date('d/m/Y H:i', strtotime($ultima_att));
}

// Contagem de registros
$total_registros = $conn->query("SELECT COUNT(*) FROM entregas")->fetch_row()[0];
?>

<div class="row">
<?php if (!empty($mensagem)): ?>
    <div class="col-12 mb-4">
        <div class="alert alert-<?php echo $tipo_mensagem; ?> alert-dismissible fade show" role="alert">
            <?php echo $mensagem; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        </div>
    </div>
<?php endif; ?>

<?php if (!$columnExists): ?>
    <div class="col-12 mb-4">
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <strong>Atenção!</strong> É necessário atualizar a estrutura do banco de dados para suportar níveis de acesso.
            <a href="atualizar_tabela_usuarios.php" class="btn btn-sm btn-warning ms-2">Atualizar Agora</a>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        </div>
    </div>
<?php endif; ?>

<div class="col-md-6 mb-4">
    <div class="card h-100">
        <div class="card-header">
            <h5 class="mb-0">Informações do Sistema</h5>
        </div>
        <div class="card-body">
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span>Última Atualização</span>
                    <span class="badge bg-primary rounded-pill"><?php echo $ultima_att; ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span>Total de Registros</span>
                    <span class="badge bg-primary rounded-pill"><?php echo $total_registros; ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span>Versão do Sistema</span>
                    <span class="badge bg-primary rounded-pill">2.0</span>
                </li>
            </ul>
            
            <div class="mt-4">
                <h6>Configurações do Trello</h6>
                <table class="table table-sm">
                    <tr>
                        <th width="30%">API Key:</th>
                        <td>
                            <code><?php echo substr(TRELLO_API_KEY, 0, 8) . '...'; ?></code>
                            <i class="fas fa-eye-slash"></i>
                        </td>
                    </tr>
                    <tr>
                        <th>Token:</th>
                        <td>
                            <code><?php echo substr(TRELLO_TOKEN, 0, 8) . '...'; ?></code>
                            <i class="fas fa-eye-slash"></i>
                        </td>
                    </tr>
                    <tr>
                        <th>Board ID:</th>
                        <td>
                            <code><?php echo TRELLO_BOARD_ID; ?></code>
                        </td>
                    </tr>
                </table>
                <small class="text-muted">Para alterar estas configurações, edite o arquivo config.php</small>
            </div>
        </div>
    </div>
</div>

<div class="col-md-6 mb-4">
    <div class="card h-100">
        <div class="card-header">
            <h5 class="mb-0">Alterar Senha</h5>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="acao" value="alterar_senha">
                
                <div class="mb-3">
                    <label class="form-label">Senha Atual</label>
                    <input type="password" name="senha_atual" class="form-control" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Nova Senha</label>
                    <input type="password" name="nova_senha" class="form-control" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Confirmar Nova Senha</label>
                    <input type="password" name="confirmar_senha" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Alterar Senha</button>
            </form>
        </div>
    </div>
</div>

<div class="col-md-6 mb-4">
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Gerenciar Usuários</h5>
        </div>
        <div class="card-body">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Acesso</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($usuario = $usuarios->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $usuario['id']; ?></td>
                            <td><?php echo htmlspecialchars($usuario['login']); ?></td>
                            <td>
                                <?php if (isset($usuario['nivel_acesso'])): ?>
                                <span class="badge bg-<?php 
                                    echo match($usuario['nivel_acesso'] ?? 'logistica') {
                                        'ltv' => 'info',
                                        'logistica' => 'primary',
                                        'vendas' => 'success',
                                        'recuperacao' => 'warning',
                                        default => 'secondary'
                                    };
                                ?>">
                                    <?php echo ucfirst($usuario['nivel_acesso'] ?? 'logistica'); ?>
                                </span>
                                <?php else: ?>
                                <span class="badge bg-primary">Logística</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($usuario['id'] != $_SESSION['usuario_id']): ?>
                                    <?php if ($nivel_acesso === 'logistica'): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                        onclick="confirmarRemocao(<?php echo $usuario['id']; ?>, '<?php echo $usuario['login']; ?>')">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Sem permissão</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-info">Você</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            
            <hr>
            
            <h6>Criar Novo Usuário</h6>
            <?php if ($nivel_acesso === 'logistica'): ?>
            <form method="post">
                <input type="hidden" name="acao" value="novo_usuario">
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Nome</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">Senha</label>
                        <input type="password" name="senha" class="form-control" required>
                    </div>
                </div>
                
                <?php if ($columnExists): ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Acesso</label>
                        <select name="acesso" id="acesso" class="form-select" required>
                            <option value="logistica" selected>Logística</option>
                            <option value="ltv">LTV</option>
                            <option value="vendas">Vendas</option>
                            <option value="recuperacao">Recuperação</option>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mt-4" id="permissoes-info">
                            <p class="mb-1">Permissões:</p>
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between align-items-center access-ltv">
                                    Clientes Entregues
                                    <i class="fas fa-check-circle text-success"></i>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center access-ltv">
                                    Devoluções
                                    <i class="fas fa-check-circle text-success"></i>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center access-ltv">
                                    Disponível para Levantamento
                                    <i class="fas fa-check-circle text-success"></i>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center access-logistica">
                                    Total (Acesso Completo)
                                    <i class="fas fa-check-circle text-success"></i>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center access-vendas">
                                    Novos Clientes
                                    <i class="fas fa-check-circle text-success"></i>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center access-vendas">
                                    Devoluções
                                    <i class="fas fa-check-circle text-success"></i>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center access-vendas">
                                    Entregas
                                    <i class="fas fa-check-circle text-success"></i>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center access-recuperacao">
                                    Clientes que não completaram
                                    <i class="fas fa-check-circle text-success"></i>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="text-end">
                    <button type="submit" class="btn btn-primary">Criar Usuário</button>
                </div>
            </form>
            <?php else: ?>
            <div class="alert alert-info">
                <p>Apenas usuários com nível de acesso de "Logística" podem adicionar ou remover usuários.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="col-md-6 mb-4">
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Manutenção do Sistema</h5>
        </div>
        <div class="card-body">
            <div class="list-group">
                <a href="atualizar.php" class="list-group-item list-group-item-action">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Atualizar Dados</h6>
                            <p class="mb-1 text-muted">Busca dados atualizados do Trello</p>
                        </div>
                        <span class="btn btn-sm btn-primary">
                            <i class="fas fa-sync-alt"></i>
                        </span>
                    </div>
                </a>
                <a href="backup.php" class="list-group-item list-group-item-action">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Fazer Backup</h6>
                            <p class="mb-1 text-muted">Exporta todos os dados do sistema</p>
                        </div>
                        <span class="btn btn-sm btn-success">
                            <i class="fas fa-download"></i>
                        </span>
                    </div>
                </a>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Configurar Atualização Automática</h6>
                            <p class="mb-1 text-muted">Comando para cron: <code>php /public_html/atualizar.php</code></p>
                        </div>
                        <span class="btn btn-sm btn-info">
                            <i class="fas fa-clock"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Modal Confirmação -->
<div class="modal fade" id="removerUsuarioModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">Confirmar Remoção</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <p>Tem certeza que deseja remover o usuário <strong id="nomeUsuario"></strong>?</p>
            <p class="text-danger">Esta ação não pode ser desfeita.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <a href="#" id="linkRemover" class="btn btn-danger">Remover</a>
        </div>
    </div>
</div>
</div>

<script>
function confirmarRemocao(id, login) {
document.getElementById('nomeUsuario').textContent = login;
document.getElementById('linkRemover').href = 'remover_usuario.php?id=' + id;

const modal = new bootstrap.Modal(document.getElementById('removerUsuarioModal'));
modal.show();
}

// Script para atualizar a exibição das permissões com base no acesso selecionado
document.addEventListener('DOMContentLoaded', function() {
const acessoSelect = document.getElementById('acesso');

// Só executa se o elemento existir (coluna nivel_acesso existe no banco)
if (acessoSelect) {
    // Função para atualizar a visualização das permissões
    function atualizarPermissoes() {
        const acessoSelecionado = acessoSelect.value;
        
        // Esconde todas as permissões
        document.querySelectorAll('[class*="access-"]').forEach(el => {
            el.querySelector('i').classList.remove('fa-check-circle', 'text-success', 'fa-times-circle', 'text-danger');
            el.querySelector('i').classList.add('fa-times-circle', 'text-danger');
        });
        
        // Mostra apenas as permissões do acesso selecionado
        if (acessoSelecionado === 'ltv') {
            document.querySelectorAll('.access-ltv i').forEach(icon => {
                icon.classList.remove('fa-times-circle', 'text-danger');
                icon.classList.add('fa-check-circle', 'text-success');
            });
        } else if (acessoSelecionado === 'logistica') {
            document.querySelectorAll('.access-logistica i').forEach(icon => {
                icon.classList.remove('fa-times-circle', 'text-danger');
                icon.classList.add('fa-check-circle', 'text-success');
            });
        } else if (acessoSelecionado === 'vendas') {
            document.querySelectorAll('.access-vendas i').forEach(icon => {
                icon.classList.remove('fa-times-circle', 'text-danger');
                icon.classList.add('fa-check-circle', 'text-success');
            });
        } else if (acessoSelecionado === 'recuperacao') {
            document.querySelectorAll('.access-recuperacao i').forEach(icon => {
                icon.classList.remove('fa-times-circle', 'text-danger');
                icon.classList.add('fa-check-circle', 'text-success');
            });
        }
    }
    
    // Atualizar permissões no carregamento da página
    atualizarPermissoes();
    
    // Atualizar permissões quando o acesso for alterado
    acessoSelect.addEventListener('change', atualizarPermissoes);
}
});

document.addEventListener('DOMContentLoaded', function() {
// Destacar o item da navegação atual
document.querySelectorAll('.sidebar .nav-item').forEach(function(item) {
    if (item.getAttribute('href') === 'configuracoes.php') {
        item.classList.add('active');
    }
});

// Botão de atualizar
document.getElementById('refreshBtn').addEventListener('click', function() {
    location.reload();
});
});
</script>

<style>
/* Ajustes para garantir que os cards não saiam da tela */
.row {
    display: flex;
    flex-wrap: wrap;
    margin-right: -10px;
    margin-left: -10px;
}

/* Ajuste nos cards do primeiro row */
.row .col-md-6 {
    flex: 0 0 auto;
    width: calc(50% - 20px);
    margin: 0 10px 20px;
}

.row .col-12 {
    flex: 0 0 auto;
    width: calc(100% - 20px);
    margin: 0 10px 20px;
}

/* Cards */
.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transition: transform 0.3s, box-shadow 0.3s;
    overflow: hidden;
    margin-bottom: 20px;
    width: 100%;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
}

/* Espaço interno nos cards */
.card-body {
    padding: 20px;
    background-color: #ffffff;
}

.card-header {
    background-color: #ffffff;
    padding: 18px 20px;
    border-bottom: 1px solid #f0f0f0;
}

.card-header h5 {
    font-size: 1.2rem;
    font-weight: 600;
    color: #2E7D32;
    display: flex;
    align-items: center;
    margin-bottom: 0;
}

/* Melhorias para botões e formulários */
.btn {
    padding: 8px 16px;
    font-size: 0.95rem;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s;
}

.form-control, .form-select {
    border-radius: 8px;
    padding: 10px 12px;
    border: 1px solid #e0e0e0;
    transition: border-color 0.3s, box-shadow 0.3s;
}

/* Tabelas */
.table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
}

.table th {
    font-weight: 600;
    color: #2E7D32;
    border-bottom: 2px solid #f0f0f0;
    background-color: #f9f9f9;
}

.table td {
    vertical-align: middle;
}

/* Lista de itens */
.list-group-item {
    padding: 12px 15px;
    border-color: #f0f0f0;
}

/* Badges */
.badge {
    font-size: 0.85rem;
    padding: 6px 12px;
    font-weight: 500;
    border-radius: 30px;
}

/* Área de permissões */
#permissoes-info {
    border: 1px solid #e0e0e0;
    border-radius: 10px;
    padding: 15px;
    background-color: #f9f9f9;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05);
}

#permissoes-info ul.list-group {
    max-height: 200px;
    overflow-y: auto;
}

/* Responsividade */
@media (max-width: 1199px) {
    .row .col-md-6 {
        width: calc(100% - 20px);
    }
}

@media (max-width: 768px) {
    .card-body {
        padding: 15px;
    }
    
    .form-control, .form-select {
        padding: 8px 10px;
    }
}
</style>

<!-- Adicionando os ícones nos cabeçalhos dos cards -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Adicionar ícones aos cabeçalhos dos cards
    const cardHeaders = document.querySelectorAll('.card-header h5');
    
    if (cardHeaders.length >= 1) {
        cardHeaders[0].innerHTML = '<i class="fas fa-info-circle me-2"></i>' + cardHeaders[0].innerHTML;
    }
    
    if (cardHeaders.length >= 2) {
        cardHeaders[1].innerHTML = '<i class="fas fa-key me-2"></i>' + cardHeaders[1].innerHTML;
    }
    
    if (cardHeaders.length >= 3) {
        cardHeaders[2].innerHTML = '<i class="fas fa-users me-2"></i>' + cardHeaders[2].innerHTML;
    }
    
    if (cardHeaders.length >= 4) {
        cardHeaders[3].innerHTML = '<i class="fas fa-tools me-2"></i>' + cardHeaders[3].innerHTML;
    }
});
</script>

<?php include 'footer.php'; ?>