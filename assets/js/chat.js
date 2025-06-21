import '../styles/chat.scss';

class ChatBot {
    constructor() {
        this.chatForm = document.getElementById('chatForm');
        this.messageInput = document.getElementById('messageInput');
        this.sendButton = document.getElementById('sendButton');
        this.chatMessages = document.getElementById('chatMessages');
        this.typingIndicator = document.getElementById('typingIndicator');
        this.sessionId = this.generateSessionId();

        this.init();
    }

    init() {
        if (!this.chatForm || !this.messageInput || !this.sendButton || !this.chatMessages) {
            console.error('Chat elements not found');
            return;
        }

        // Form submission
        this.chatForm.addEventListener('submit', (e) => this.handleSubmit(e));

        // Auto-resize textarea
        this.messageInput.addEventListener('input', () => this.autoResize());

        // Suggestion chips
        this.initSuggestionChips();

        // Focus on input
        this.messageInput.focus();
    }

    generateSessionId() {
        return Date.now().toString(36) + Math.random().toString(36).substr(2);
    }

    initSuggestionChips() {
        const suggestionChips = document.querySelectorAll('.suggestion-chip');
        suggestionChips.forEach(chip => {
            chip.addEventListener('click', () => {
                const suggestion = chip.getAttribute('data-suggestion');
                this.messageInput.value = suggestion;
                this.autoResize();
                this.messageInput.focus();
            });
        });
    }

    handleSubmit(e) {
        e.preventDefault();

        const message = this.messageInput.value.trim();
        if (!message || this.sendButton.disabled) {
            return;
        }

        this.sendMessage(message);
    }

    sendMessage(message) {
        // Add user message
        this.addMessage(message, 'user');

        // Clear input and disable form
        this.messageInput.value = '';
        this.autoResize();
        this.setFormDisabled(true);

        // Show typing indicator
        this.showTypingIndicator();

        // Call real API
        fetch('/api/chatbot/ask', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                question: message,
                session_id: this.sessionId
            })
        })
        .then(response => response.json())
        .then(data => {
            this.hideTypingIndicator();

            if (data.success) {
                // Successful response
                this.addMessage(
                    data.response,
                    'bot',
                    data.metadata,
                    false,
                    data.download || null
                );
            } else {
                // Server or security error
                const errorMessage = data.error || "Une erreur s'est produite.";
                this.addMessage(errorMessage, 'bot', null, true);

                // Log for debugging
                console.warn('API Error:', data);
            }
        })
        .catch(error => {
            this.hideTypingIndicator();
            console.error('Network Error:', error);
            this.addMessage(
                'Erreur de connexion au serveur. Veuillez réessayer.',
                'bot',
                null,
                true
            );
        })
        .finally(() => {
            this.setFormDisabled(false);
            this.messageInput.focus();
        });
    }

    /**
     *
     * @param {string} content
     * @param {string} sender
     * @param {object|null} metadata
     * @param {boolean} isError
     * @param {object|null} downloadInfo
     */
    addMessage(
        content,
        sender,
        metadata = null,
        isError = false,
        downloadInfo = null
    ) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${sender}-message`;

        const time = new Date().toLocaleTimeString('fr-FR', {
            hour: '2-digit',
            minute: '2-digit'
        });

        let metadataHtml = '';
        if (metadata && sender === 'bot') {
            metadataHtml = `
                <div class="message-metadata">
                    <small class="text-muted">
                        <i class="fas fa-clock me-1"></i>${metadata.execution_time}s
                        <i class="fas fa-database ms-2 me-1"></i>${metadata.result_count} résultat(s)
                    </small>
                </div>
            `;
        }

        let downloadHtml = '';
        if (downloadInfo && downloadInfo.available && sender === 'bot') {
            const statusIcon = downloadInfo.status === 'pending' ? 'fas fa-spinner fa-spin' :
                downloadInfo.status === 'ready' ? 'fas fa-check-circle text-success' :
                    'fas fa-exclamation-triangle text-warning';

            let downloadButton = '';

            if (downloadInfo.status === 'ready' && downloadInfo?.download_url) {
                downloadButton = `<a href="${downloadInfo.download_url}" class="btn btn-primary btn-sm mt-2" download>
                    <i class="fas fa-download me-1"></i>Télécharger l'archive
                </a>`
            }

            downloadHtml = `
                <div class="download-section mt-3">
                    <div class="download-header d-flex flex-column">
                        <div class="mb-3">
                            <i class="${statusIcon} me-2"></i>
                            <strong>Téléchargement</strong><br>
                        </div>
                        <small class="text-muted">${downloadInfo.message}</small>
                    </div>
                    <div class="download-info d-flex flex-row gap-2">
                        <div class="download-file">
                            <small class="text-muted">Fichiers: ${downloadInfo.file_count}</small>
                        </div>
                        <div class="download-size">
                            <small class="text-muted">Taille estimée: ${downloadInfo.estimated_size}</small>
                        </div>
                    </div>
                    ${downloadButton}
                </div>
            `;
        }

        messageDiv.innerHTML = `
            <div class="message-avatar">
                <i class="fas fa-${sender === 'user' ? 'user' : 'robot'}"></i>
            </div>
            <div class="message-content">
                <div class="message-bubble ${isError ? 'error' : ''}">
                    ${sender === 'bot' ? this.convertMarkdownToHtml(content) : content}
                </div>
                <div class="message-time">${time}</div>
                ${metadataHtml}
                ${downloadHtml}
            </div>
        `;

        this.chatMessages.appendChild(messageDiv);
        this.scrollToBottom();
    }

    showTypingIndicator() {
        if (this.typingIndicator) {
            this.typingIndicator.classList.remove('d-none');
            this.scrollToBottom();
        }
    }

    hideTypingIndicator() {
        if (this.typingIndicator) {
            this.typingIndicator.classList.add('d-none');
        }
    }

    setFormDisabled(disabled) {
        this.messageInput.disabled = disabled;
        this.sendButton.disabled = disabled;

        if (disabled) {
            this.sendButton.classList.add('loading');
        } else {
            this.sendButton.classList.remove('loading');
        }
    }

    autoResize() {
        this.messageInput.style.height = 'auto';
        this.messageInput.style.height = Math.min(this.messageInput.scrollHeight, 120) + 'px';
    }

    scrollToBottom() {
        this.chatMessages.scrollTop = this.chatMessages.scrollHeight;
    }

    convertMarkdownToHtml(text) {
        return text
            // Gras : **texte** → <strong>texte</strong>
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            // Italique : *texte* → <em>texte</em>
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            // Listes : - item → <li>item</li>
            .replace(/^- (.*$)/gm, '<li>$1</li>')
            // Remplacer les groupes de <li> par <ul>
            .replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>')
            // Sauts de ligne
            .replace(/\n/g, '<br>');
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new ChatBot();
});