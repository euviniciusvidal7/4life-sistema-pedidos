// Assistente de IA - 4Life Nutri
class AIAssistant {
    constructor() {
        this.isOpen = false;
        this.messages = [];
        this.isLoading = false;
        this.init();
    }

    init() {
        this.createFloatingButton();
        this.createChatModal();
        this.bindEvents();
    }

    createFloatingButton() {
        const button = document.createElement('div');
        button.id = 'ai-floating-btn';
        button.innerHTML = `
            <i class="fas fa-robot"></i>
            <span class="ai-tooltip">Assistente IA</span>
        `;
        document.body.appendChild(button);
    }

    createChatModal() {
        const modal = document.createElement('div');
        modal.id = 'ai-chat-modal';
        modal.innerHTML = `
            <div class="ai-chat-container">
                <div class="ai-chat-header">
                    <div class="ai-header-info">
                        <i class="fas fa-robot"></i>
                        <div>
                            <h3>Assistente IA</h3>
                            <span class="ai-status">Online</span>
                        </div>
                    </div>
                    <button class="ai-close-btn" id="ai-close-chat">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="ai-chat-messages" id="ai-messages">
                    <div class="ai-message ai-bot">
                        <div class="ai-message-content">
                            <p>Olá! Sou seu assistente de IA da 4Life Nutri. Posso ajudar você com:</p>
                            <ul>
                                <li>📅 Gerenciar eventos do calendário</li>
                                <li>👥 Informações sobre clientes</li>
                                <li>📊 Dados do dashboard</li>
                                <li>📦 Status de entregas</li>
                            </ul>
                            <p>Como posso ajudar você hoje?</p>
                        </div>
                    </div>
                </div>
                <div class="ai-chat-input">
                    <div class="ai-input-container">
                        <input type="text" id="ai-message-input" placeholder="Digite sua mensagem..." />
                        <button id="ai-send-btn" class="ai-send-btn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                    <div class="ai-quick-actions">
                        <button class="ai-quick-btn" data-action="calendario">📅 Calendário</button>
                        <button class="ai-quick-btn" data-action="clientes">👥 Clientes</button>
                        <button class="ai-quick-btn" data-action="dashboard">📊 Dashboard</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    bindEvents() {
        // Botão flutuante
        document.getElementById('ai-floating-btn').addEventListener('click', () => {
            this.toggleChat();
        });

        // Fechar chat
        document.getElementById('ai-close-chat').addEventListener('click', () => {
            this.closeChat();
        });

        // Enviar mensagem
        document.getElementById('ai-send-btn').addEventListener('click', () => {
            this.sendMessage();
        });

        // Enter para enviar
        document.getElementById('ai-message-input').addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });

        // Ações rápidas
        document.querySelectorAll('.ai-quick-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const action = e.target.dataset.action;
                this.handleQuickAction(action);
            });
        });

        // Fechar ao clicar fora
        document.getElementById('ai-chat-modal').addEventListener('click', (e) => {
            if (e.target.id === 'ai-chat-modal') {
                this.closeChat();
            }
        });
    }

    toggleChat() {
        if (this.isOpen) {
            this.closeChat();
        } else {
            this.openChat();
        }
    }

    openChat() {
        this.isOpen = true;
        document.getElementById('ai-chat-modal').classList.add('active');
        document.getElementById('ai-floating-btn').classList.add('active');
        document.getElementById('ai-message-input').focus();
    }

    closeChat() {
        this.isOpen = false;
        document.getElementById('ai-chat-modal').classList.remove('active');
        document.getElementById('ai-floating-btn').classList.remove('active');
    }

    async sendMessage() {
        const input = document.getElementById('ai-message-input');
        const message = input.value.trim();

        if (!message || this.isLoading) return;

        // Adicionar mensagem do usuário
        this.addMessage(message, 'user');
        input.value = '';

        // Mostrar loading
        this.showLoading();

        try {
            const response = await fetch('ai_assistant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    message: message,
                    action: 'chat'
                })
            });

            const data = await response.json();

            if (data.success) {
                this.addMessage(data.response, 'bot');
            } else {
                this.addMessage('Desculpe, ocorreu um erro: ' + data.message, 'bot', true);
            }
        } catch (error) {
            console.error('Erro ao enviar mensagem:', error);
            this.addMessage('Desculpe, não foi possível processar sua mensagem. Tente novamente.', 'bot', true);
        } finally {
            this.hideLoading();
        }
    }

    addMessage(content, sender, isError = false) {
        const messagesContainer = document.getElementById('ai-messages');
        const messageDiv = document.createElement('div');
        messageDiv.className = `ai-message ai-${sender}${isError ? ' ai-error' : ''}`;

        const timestamp = new Date().toLocaleTimeString('pt-BR', { 
            hour: '2-digit', 
            minute: '2-digit' 
        });

        messageDiv.innerHTML = `
            <div class="ai-message-content">
                ${this.formatMessage(content)}
                <span class="ai-message-time">${timestamp}</span>
            </div>
        `;

        messagesContainer.appendChild(messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;

        this.messages.push({ content, sender, timestamp: new Date() });
    }

    formatMessage(content) {
        // Converter quebras de linha em <br>
        content = content.replace(/\n/g, '<br>');
        
        // Converter markdown básico
        content = content.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        content = content.replace(/\*(.*?)\*/g, '<em>$1</em>');
        
        // Converter listas
        content = content.replace(/^- (.*$)/gim, '<li>$1</li>');
        content = content.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');

        return content;
    }

    showLoading() {
        this.isLoading = true;
        const messagesContainer = document.getElementById('ai-messages');
        const loadingDiv = document.createElement('div');
        loadingDiv.id = 'ai-loading';
        loadingDiv.className = 'ai-message ai-bot';
        loadingDiv.innerHTML = `
            <div class="ai-message-content">
                <div class="ai-typing">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        `;
        messagesContainer.appendChild(loadingDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    hideLoading() {
        this.isLoading = false;
        const loadingElement = document.getElementById('ai-loading');
        if (loadingElement) {
            loadingElement.remove();
        }
    }

    handleQuickAction(action) {
        const messages = {
            'calendario': 'Mostre-me os próximos eventos do calendário',
            'clientes': 'Quantos novos clientes temos hoje?',
            'dashboard': 'Mostre-me um resumo das métricas do dashboard'
        };

        const message = messages[action];
        if (message) {
            document.getElementById('ai-message-input').value = message;
            this.sendMessage();
        }
    }

    // Métodos para ações específicas do calendário
    async createEvent(eventData) {
        try {
            const response = await fetch('ai_calendar_actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'criar_evento',
                    ...eventData
                })
            });

            const data = await response.json();
            return data;
        } catch (error) {
            console.error('Erro ao criar evento:', error);
            return { success: false, message: 'Erro ao criar evento' };
        }
    }

    async editEvent(eventId, eventData) {
        try {
            const response = await fetch('ai_calendar_actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'editar_evento',
                    id: eventId,
                    ...eventData
                })
            });

            const data = await response.json();
            return data;
        } catch (error) {
            console.error('Erro ao editar evento:', error);
            return { success: false, message: 'Erro ao editar evento' };
        }
    }

    async deleteEvent(eventId) {
        try {
            const response = await fetch('ai_calendar_actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'deletar_evento',
                    id: eventId
                })
            });

            const data = await response.json();
            return data;
        } catch (error) {
            console.error('Erro ao deletar evento:', error);
            return { success: false, message: 'Erro ao deletar evento' };
        }
    }
}

// Inicializar quando o DOM estiver carregado
document.addEventListener('DOMContentLoaded', () => {
    window.aiAssistant = new AIAssistant();
});

// Expor globalmente para uso em outras partes do sistema
window.AIAssistant = AIAssistant; 