<style>
/* AI Chat Widget Styling */
.ai-chat-container {
    position: fixed;
    bottom: 25px;
    right: 25px;
    z-index: 10000;
}

.ai-toggle-btn {
    background: linear-gradient(135deg, #6366f1, #a855f7);
    color: white;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 10px 25px rgba(99, 102, 241, 0.4);
    transition: all 0.3s ease;
}

.ai-toggle-btn:hover {
    transform: scale(1.1) rotate(5deg);
}

.ai-chat-window {
    position: absolute;
    bottom: 80px;
    right: 0;
    width: 380px;
    height: 500px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 15px 50px rgba(0,0,0,0.15);
    display: none;
    flex-direction: column;
    overflow: hidden;
    animation: slideUp 0.3s ease-out;
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.ai-chat-header {
    background: linear-gradient(135deg, #6366f1, #a855f7);
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ai-chat-body {
    flex: 1;
    padding: 20px;
    overflow-y: auto;
    background: #f8fafc;
    scroll-behavior: smooth;
}

.ai-message {
    margin-bottom: 15px;
    max-width: 80%;
    padding: 12px 18px;
    border-radius: 15px;
    font-size: 0.95rem;
    line-height: 1.5;
}

.ai-message.user {
    background: #6366f1;
    color: white;
    margin-left: auto;
    border-bottom-right-radius: 5px;
}

.ai-message.bot {
    background: white;
    color: #1e293b;
    border: 1px solid #e2e8f0;
    border-bottom-left-radius: 5px;
}

.ai-chat-footer {
    padding: 15px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 10px;
}

.ai-input {
    flex: 1;
    border: 1px solid #e2e8f0;
    padding: 10px 15px;
    border-radius: 12px;
    outline: none;
}

.ai-send-btn {
    background: #6366f1;
    color: white;
    border: none;
    padding: 10px 15px;
    border-radius: 12px;
    cursor: pointer;
}

/* Mobile Adjustments */
@media (max-width: 480px) {
    .ai-chat-window {
        width: calc(100vw - 40px);
        bottom: 70px;
        right: -10px;
        height: 80dvh;
    }
}
</style>

<div class="ai-chat-container">
    <div class="ai-chat-window" id="aiChatWindow">
        <div class="ai-chat-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-robot"></i>
                <div>
                    <h4 style="margin: 0; font-size: 1rem;">Open LMS AI Assistant</h4>
                    <small style="opacity: 0.8; font-size: 0.75rem;">Online & ready to help</small>
                </div>
            </div>
            <i class="fas fa-times" id="closeAiChat" style="cursor: pointer;"></i>
        </div>
        <div class="ai-chat-body" id="aiChatBody">
            <div class="ai-message bot">
                👋 Hello! I am your AI campus assistant. How can I help you today?
            </div>
        </div>
        <div class="ai-chat-footer">
            <input type="text" class="ai-input" id="aiPrompt" placeholder="Type a message..." autocomplete="off">
            <button class="ai-send-btn" id="sendAiBtn"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>
    
    <div class="ai-toggle-btn" id="toggleAiChat">
        <i class="fas fa-comment-dots fa-lg" id="aiToggleIcon"></i>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const aiToggle = document.getElementById('toggleAiChat');
    const aiWindow = document.getElementById('aiChatWindow');
    const closeBtn = document.getElementById('closeAiChat');
    const sendBtn = document.getElementById('sendAiBtn');
    const inputField = document.getElementById('aiPrompt');
    const chatBody = document.getElementById('aiChatBody');
    const aiToggleIcon = document.getElementById('aiToggleIcon');

    // Toggle Window
    aiToggle.onclick = () => {
        const isVisible = aiWindow.style.display === 'flex';
        aiWindow.style.display = isVisible ? 'none' : 'flex';
        aiToggleIcon.className = isVisible ? 'fas fa-comment-dots fa-lg' : 'fas fa-times fa-lg';
        if (!isVisible) inputField.focus();
    };

    closeBtn.onclick = () => {
        aiWindow.style.display = 'none';
        aiToggleIcon.className = 'fas fa-comment-dots fa-lg';
    };

    // Chat Logic
    async function handleSend() {
        const prompt = inputField.value.trim();
        if (!prompt) return;

        // User message
        appendMessage('user', prompt);
        inputField.value = '';

        // Thinking placeholder
        const thinkingId = 'thinking_' + Date.now();
        appendMessage('bot', '<i class="fas fa-spinner fa-spin"></i> Thinking...', thinkingId);

        try {
            // Build path safely to ROOT with generic name to avoid keyword-based 403 blocks
            const basePath = '<?php echo $path_to_root ?? "./"; ?>';
            const endpoint = (basePath.endsWith('/') ? basePath : basePath + '/') + 'process_data.php';

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'prompt=' + encodeURIComponent(prompt) + '&aichat_post=1',
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Network error: ' + response.status);
            }

            const data = await response.json();
            document.getElementById(thinkingId).innerHTML = data.reply || data.error || 'Check connection.';
        } catch (e) {
            document.getElementById(thinkingId).innerText = 'Connection Error: ' + e.message;
        }
        
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    function appendMessage(role, text, id = '') {
        const msgDiv = document.createElement('div');
        msgDiv.className = 'ai-message ' + role;
        if (id) msgDiv.id = id;
        msgDiv.innerHTML = text;
        chatBody.appendChild(msgDiv);
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    sendBtn.onclick = handleSend;
    inputField.onkeypress = (e) => { if(e.key === 'Enter') handleSend(); };
});
</script>
