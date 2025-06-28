import '../styles/chat_stream.scss';

class ChatBotStream {
    constructor() {
        this.chatForm = document.getElementById('chatForm');
        this.messageInput = document.getElementById('messageInput');
        this.sendButton = document.getElementById('sendButton');
        this.chatMessages = document.getElementById('chatMessages');
        this.typingIndicator = document.getElementById('typingIndicator');
        this.sessionId = this.generateSessionId();
        this.messageContents = new Map(); // ⭐ Map pour stocker le contenu de chaque message

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

        // ⭐ Utiliser le streaming LLM
        this.sendMessageWithLLMStreaming(message);
    }

    sendMessageWithLLMStreaming(message) {
        // Ajouter le message utilisateur
        this.addMessage(message, 'user');

        // Vider l'input et désactiver le formulaire
        this.messageInput.value = '';
        this.autoResize();
        this.setFormDisabled(true);

        // Créer le message bot qui va se remplir progressivement
        const streamingBotMessage = this.addStreamingBotMessage();
        
        // ⭐ Initialiser le contenu pour ce message spécifique
        this.messageContents.set(streamingBotMessage, '');
        let currentEvent = null;

        // Envoyer la requête POST pour démarrer le streaming
        fetch('/api/chatbot/ask-stream-llm', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                question: message,
                session_id: this.sessionId
            })
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network error');
                }

                // Utiliser le streaming response
                const reader = response.body.getReader();
                const decoder = new TextDecoder();

                const readChunk = () => {
                    reader.read().then(({ done, value }) => {
                        if (done) {
                            this.finalizeStreamingMessage(streamingBotMessage);
                            this.messageContents.delete(streamingBotMessage); // ⭐ Nettoyer
                            this.setFormDisabled(false);
                            this.messageInput.focus();
                            return;
                        }

                        const chunk = decoder.decode(value);
                        const lines = chunk.split('\n');

                        lines.forEach(line => {
                            // ⭐ Capturer les événements
                            if (line.startsWith('event: ')) {
                                currentEvent = line.slice(7);
                                return;
                            }

                            if (line.startsWith('data: ')) {
                                const data = line.slice(6);

                                if (data === '[DONE]') {
                                    this.finalizeStreamingMessage(streamingBotMessage);
                                    this.messageContents.delete(streamingBotMessage);
                                    this.setFormDisabled(false);
                                    this.messageInput.focus();
                                    return;
                                }

                                try {
                                    const parsed = JSON.parse(data);

                                    this.handleStreamingEvent(streamingBotMessage, currentEvent, parsed);

                                } catch (e) {
                                    // Ignorer les lignes qui ne sont pas du JSON valide
                                }
                            }
                        });

                        readChunk(); // Continuer à lire
                    });
                };

                readChunk();
            })
            .catch(error => {
                console.error('Streaming error:', error);
                this.replaceStreamingMessage(streamingBotMessage, '❌ Erreur de connexion au streaming');
                this.messageContents.delete(streamingBotMessage); // ⭐ Nettoyer même en cas d'erreur
                this.setFormDisabled(false);
                this.messageInput.focus();
            });
    }

    addMessage(content, sender, metadata = null, isError = false, downloadInfo = null) {
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
                        ${metadata.intent ? `<i class="fas fa-tag ms-2 me-1"></i>${metadata.intent}` : ''}
                    </small>
                </div>
            `;
        }

        // Download section HTML
        let downloadHtml = '';
        if (downloadInfo && downloadInfo.available && sender === 'bot') {
            const statusIcon = downloadInfo.status === 'pending' ? 'fas fa-spinner fa-spin' :
                downloadInfo.status === 'ready' ? 'fas fa-check-circle text-success' :
                    downloadInfo.status === 'error' ? 'fas fa-exclamation-triangle text-danger' :
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

    addStreamingBotMessage() {
        const messageId = 'streaming-bot-' + Date.now();
        const messageDiv = document.createElement('div');
        messageDiv.id = messageId;
        messageDiv.className = 'message bot-message streaming';

        const time = new Date().toLocaleTimeString('fr-FR', {
            hour: '2-digit',
            minute: '2-digit'
        });

        messageDiv.innerHTML = `
            <div class="message-avatar">
                <i class="fas fa-robot"></i>
            </div>
            <div class="message-content">
                <div class="message-bubble">
                    <div class="streaming-text">
                        <span class="typing-cursor animate-pulse">|</span>
                    </div>
                </div>
                <div class="message-time">${time}</div>
            </div>
        `;

        this.chatMessages.appendChild(messageDiv);
        this.scrollToBottom();
        return messageId;
    }

    updateStreamingText(messageId, content) {
        const messageDiv = document.getElementById(messageId);
        if (messageDiv) {
            const textDiv = messageDiv.querySelector('.streaming-text');
            if (textDiv) {
                // Convertir le markdown et ajouter le curseur
                textDiv.innerHTML = this.convertMarkdownToHtml(content) + '<span class="typing-cursor animate-pulse">|</span>';
            }
        }
        this.scrollToBottom();
    }

    finalizeStreamingMessage(messageId) {
        const messageDiv = document.getElementById(messageId);
        if (messageDiv) {
            // Supprimer le curseur clignotant et la classe streaming
            const cursor = messageDiv.querySelector('.typing-cursor');
            if (cursor) cursor.remove();

            messageDiv.classList.remove('streaming');
        }
    }

    replaceStreamingMessage(messageId, finalText) {
        const messageDiv = document.getElementById(messageId);
        if (messageDiv) {
            const textDiv = messageDiv.querySelector('.streaming-text');
            if (textDiv) {
                textDiv.innerHTML = this.convertMarkdownToHtml(finalText);
            }
            messageDiv.classList.remove('streaming');
        }
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

    /**
     * Afficher erreur de téléchargement
     */
    showDownloadError(messageId, errorMessage) {
        const messageDiv = document.getElementById(messageId);
        if (!messageDiv) return;

        // Supprimer les étapes de progression
        const stepSection = messageDiv.querySelector('.download-steps');
        if (stepSection) stepSection.remove();

        const errorHtml = `
            <div class="download-error mt-3 p-3 bg-danger bg-opacity-10 border border-danger rounded">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                    <span>${errorMessage}</span>
                </div>
            </div>
        `;

        messageDiv.querySelector('.message-content').insertAdjacentHTML('beforeend', errorHtml);
    }

    /**
     * Afficher l'archive prête avec bouton
     */
    showDownloadReady(messageId, downloadInfo) {
        const messageDiv = document.getElementById(messageId);
        if (!messageDiv) return;

        // Supprimer les étapes de progression
        const stepSection = messageDiv.querySelector('.download-steps');
        if (stepSection) stepSection.remove();

        // Ajouter le bouton de téléchargement
        const downloadReadyHtml = `
            <div class="download-ready mt-3 p-3 bg-success bg-opacity-10 border border-success rounded">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="d-flex align-items-center mb-1">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <strong>Archive prête !</strong>
                        </div>
                        <small class="text-muted">
                            ${downloadInfo.file_count} fichiers • ${downloadInfo.estimated_size}<br>
                            ${downloadInfo.error_count > 0 ?
                                `⚠️ ${downloadInfo.error_count} fichier(s) non trouvé(s)` :
                                '✅ Tous les fichiers récupérés'
                            }
                        </small>
                    </div>
                    <a href="${downloadInfo.download_url}" 
                       class="btn btn-success btn-sm download-btn" 
                       download>
                        <i class="fas fa-download me-1"></i>Télécharger
                    </a>
                </div>
                
                <div>
                    ${downloadInfo.error_count > 0 ? `
                            <div class="mt-2">
                                <button class="btn btn-sm btn-light" type="button" 
                                        data-bs-toggle="collapse" 
                                        data-bs-target="#errorDetails" 
                                        aria-expanded="false">
                                    Voir les erreurs
                                </button>
                                <div class="collapse mt-2" id="errorDetails">
                                    <div class="alert alert-light alert-sm">
                                        <strong>Fichiers non trouvés :</strong>
                                        <ul class="mb-0 mt-1" style="font-size: 0.85em;">
                                            ${downloadInfo.error_messages.map(error => `<li>${error}</li>`).join('')}
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        ` : ''}
                </div>
            </div>
        `;

        messageDiv.querySelector('.message-content').insertAdjacentHTML('beforeend', downloadReadyHtml);

        // Animation d'apparition du bouton
        const downloadBtn = messageDiv.querySelector('.download-btn');
        if (downloadBtn) {
            downloadBtn.style.opacity = '0';
            downloadBtn.style.transform = 'scale(0.8)';

            setTimeout(() => {
                downloadBtn.style.transition = 'all 0.3s ease';
                downloadBtn.style.opacity = '1';
                downloadBtn.style.transform = 'scale(1)';
            }, 100);
        }
    }

    /**
     * Gérer les différents événements streaming
     */
    handleStreamingEvent(messageId, event, data) {
        switch (event) {
            case 'llm_chunk':
                if (data.content) {
                    // ⭐ Récupérer et mettre à jour le contenu de ce message spécifique
                    let currentContent = this.messageContents.get(messageId) || '';
                    currentContent += data.content;
                    this.messageContents.set(messageId, currentContent);
                    
                    console.log(`Message ${messageId}: "${currentContent}"`); // ⭐ Debug
                    this.updateStreamingText(messageId, currentContent);
                }
                break;
                
            case 'llm_complete':
                const finalContent = this.messageContents.get(messageId) || '';
                console.log('Texte final:', finalContent);
                break;
                
            case 'download_step':
                this.showDownloadStep(messageId, data.message);
                break;
                
            case 'download_ready':
                this.showDownloadReady(messageId, data.download);
                break;
                
            case 'download_error':
                this.showDownloadError(messageId, data.download.message);
                break;
                
            case 'end':
                this.finalizeStreamingMessage(messageId);
                this.messageContents.delete(messageId); // ⭐ Nettoyer
                this.setFormDisabled(false);
                this.messageInput.focus();
                break;
                
            case 'error':
                this.replaceStreamingMessage(messageId, data.content || 'Erreur inconnue');
                this.messageContents.delete(messageId);
                this.setFormDisabled(false);
                this.messageInput.focus();
                break;
        }
    }

    /**
     * Afficher une étape de téléchargement
     */
    showDownloadStep(messageId, message) {
        const messageDiv = document.getElementById(messageId);
        if (!messageDiv) return;

        // Ajouter ou mettre à jour la section de progression
        let stepSection = messageDiv.querySelector('.download-steps');
        if (!stepSection) {
            stepSection = document.createElement('div');
            stepSection.className = 'download-steps mt-3 p-2 bg-light rounded';
            stepSection.innerHTML = `
                <div class="d-flex align-items-center mb-2">
                    <i class="fas fa-cog fa-spin me-2"></i>
                    <strong>Préparation du téléchargement</strong>
                </div>
                <div class="step-messages"></div>
            `;
            messageDiv.querySelector('.message-content').appendChild(stepSection);
        }

        // Ajouter le nouveau message d'étape
        const stepMessages = stepSection.querySelector('.step-messages');
        if (stepMessages) {
            stepMessages.innerHTML += `<small class="text-muted d-block">${message}</small>`;
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new ChatBotStream();
});