/**
 * pp-ai-chat.js — PromptingPress AI Chat UI
 *
 * Uses fetch() + ReadableStream for POST-based SSE streaming.
 * Nonce sent in POST body, never in URL.
 * Falls back to standard AJAX if SSE streaming fails.
 * Conversation persists in localStorage across reloads.
 */
(function () {
    'use strict';

    var config = window.ppAiChat || {};
    if (!config.configured) return;

    var messagesEl = document.getElementById('pp-ai-messages');
    var inputEl    = document.getElementById('pp-ai-input');
    var sendBtn    = document.getElementById('pp-ai-send');
    var newChatBtn = document.getElementById('pp-ai-new-chat');

    if (!messagesEl || !inputEl || !sendBtn) return;

    // ── Persistence ───────────────────────────────────────────────────

    var STORAGE_KEY = 'pp_ai_chat_' + (config.siteUrl || 'default');

    function saveState() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                conversation: conversation,
                activePageId: activePageId
            }));
        } catch (e) {
            // Storage full or unavailable — continue without persistence
        }
    }

    function loadState() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
                var state = JSON.parse(raw);
                return state;
            }
        } catch (e) {
            // Corrupted data — start fresh
        }
        return null;
    }

    function clearState() {
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // Ignore
        }
    }

    // ── State ─────────────────────────────────────────────────────────

    var conversation = [];
    var isStreaming = false;
    var activePageId = null;

    // ── Message Rendering ──────────────────────────────────────────────

    function addMessage(role, content) {
        var div = document.createElement('div');
        div.className = 'pp-ai-msg pp-ai-msg-' + role;

        var label = document.createElement('div');
        label.className = 'pp-ai-msg-role';
        label.textContent = role === 'user' ? 'You' : 'Assistant';

        var body = document.createElement('div');
        body.className = 'pp-ai-msg-body';
        body.textContent = content;

        div.appendChild(label);
        div.appendChild(body);
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;

        return body;
    }

    function createStreamingMessage() {
        var div = document.createElement('div');
        div.className = 'pp-ai-msg pp-ai-msg-assistant';

        var label = document.createElement('div');
        label.className = 'pp-ai-msg-role';
        label.textContent = 'Assistant';

        var body = document.createElement('div');
        body.className = 'pp-ai-msg-body pp-ai-msg-streaming';

        div.appendChild(label);
        div.appendChild(body);
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;

        return body;
    }

    // ── Proposal Card Rendering ────────────────────────────────────────

    function renderProposal(proposal) {
        var card = document.createElement('div');
        card.className = 'pp-ai-proposal-card';

        var title = document.createElement('div');
        title.className = 'pp-ai-proposal-title';
        title.textContent = 'Proposed Changes';
        card.appendChild(title);

        // Show rejected steps as unsupported
        var rejected = proposal.rejected || [];
        rejected.forEach(function (step) {
            var rejDiv = document.createElement('div');
            rejDiv.className = 'pp-ai-proposal-step pp-ai-step-rejected';

            var rejLabel = document.createElement('div');
            rejLabel.className = 'pp-ai-proposal-step-label';
            rejLabel.textContent = (step.description || step.name) + ' (unsupported)';
            rejDiv.appendChild(rejLabel);

            var rejMeta = document.createElement('div');
            rejMeta.className = 'pp-ai-proposal-step-meta';
            rejMeta.textContent = step.type + ' "' + step.name + '" is not a registered capability.';
            rejDiv.appendChild(rejMeta);

            card.appendChild(rejDiv);
        });

        var steps = proposal.steps || [];
        var stepElements = [];

        steps.forEach(function (step, i) {
            var stepDiv = document.createElement('div');
            stepDiv.className = 'pp-ai-proposal-step';

            var stepLabel = document.createElement('div');
            stepLabel.className = 'pp-ai-proposal-step-label';
            stepLabel.textContent = (i + 1) + '. ' + (step.description || step.name);
            stepDiv.appendChild(stepLabel);

            var stepMeta = document.createElement('div');
            stepMeta.className = 'pp-ai-proposal-step-meta';
            stepMeta.textContent = step.type + ': ' + step.name;
            stepDiv.appendChild(stepMeta);

            card.appendChild(stepDiv);
            stepElements.push(stepDiv);
        });

        // Only show Apply/Cancel if there are valid steps
        if (steps.length > 0) {
            var actions = document.createElement('div');
            actions.className = 'pp-ai-proposal-actions';

            var applyBtn = document.createElement('button');
            applyBtn.className = 'button button-primary pp-ai-proposal-apply';
            applyBtn.textContent = steps.length > 1 ? 'Apply All' : 'Apply';
            applyBtn.addEventListener('click', function () {
                executeProposal(steps, stepElements, applyBtn, cancelBtn);
            });

            var cancelBtn = document.createElement('button');
            cancelBtn.className = 'button pp-ai-proposal-cancel';
            cancelBtn.textContent = 'Cancel';
            cancelBtn.addEventListener('click', function () {
                card.classList.add('pp-ai-proposal-cancelled');
                applyBtn.disabled = true;
                cancelBtn.disabled = true;
                addStatusMessage('Proposal cancelled.');
            });

            actions.appendChild(applyBtn);
            actions.appendChild(cancelBtn);
            card.appendChild(actions);
        }

        messagesEl.appendChild(card);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function addStatusMessage(text, isError) {
        var div = document.createElement('div');
        div.className = 'pp-ai-status' + (isError ? ' pp-ai-status-error' : '');
        div.textContent = text;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    // ── Proposal Execution ─────────────────────────────────────────────

    function executeProposal(steps, stepElements, applyBtn, cancelBtn) {
        applyBtn.disabled = true;
        cancelBtn.disabled = true;

        var applied = [];
        executeStep(steps, stepElements, 0, applied);
    }

    function executeStep(steps, stepElements, index, applied) {
        if (index >= steps.length) {
            addStatusMessage('All changes applied successfully.');
            // Inject confirmation into conversation so the AI knows mutations were applied
            var summary = applied.map(function (s) { return s.description || s.name; }).join('; ');
            conversation.push({ role: 'user', content: '[Applied changes: ' + summary + ']' });
            conversation.push({ role: 'assistant', content: 'Changes applied successfully.' });
            saveState();
            return;
        }

        var step = steps[index];
        stepElements[index].classList.add('pp-ai-step-executing');

        var data = new FormData();
        data.append('action', 'pp_ai_execute');
        data.append('nonce', config.executeNonce);
        data.append('type', step.type);
        data.append('name', step.name);

        // Flatten params for FormData
        var params = step.params || {};
        Object.keys(params).forEach(function (key) {
            var val = params[key];
            if (typeof val === 'object') {
                data.append('params[' + key + ']', JSON.stringify(val));
            } else {
                data.append('params[' + key + ']', val);
            }
        });

        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (resp.success) {
                stepElements[index].classList.remove('pp-ai-step-executing');
                stepElements[index].classList.add('pp-ai-step-done');
                addStatusMessage('Applied: ' + (step.description || step.name));
                applied.push(step);
                executeStep(steps, stepElements, index + 1, applied);
            } else {
                stepElements[index].classList.remove('pp-ai-step-executing');
                stepElements[index].classList.add('pp-ai-step-failed');
                addStatusMessage('Error on step ' + (index + 1) + ': ' + (resp.data || 'Unknown error'), true);
            }
        })
        .catch(function (err) {
            stepElements[index].classList.remove('pp-ai-step-executing');
            stepElements[index].classList.add('pp-ai-step-failed');
            addStatusMessage('Error on step ' + (index + 1) + ': ' + err.message, true);
        });
    }

    // ── SSE Streaming via fetch + ReadableStream ───────────────────────

    function sendMessage(text) {
        if (isStreaming || !text.trim()) return;

        isStreaming = true;
        sendBtn.disabled = true;
        inputEl.disabled = true;

        conversation.push({ role: 'user', content: text.trim() });
        addMessage('user', text.trim());
        inputEl.value = '';
        saveState();

        streamChat(conversation);
    }

    function streamChat(messages) {
        var detected = detectPageId(messages);
        if (detected) {
            activePageId = detected;
            saveState();
        }

        var body = JSON.stringify({
            messages: messages,
            nonce: config.streamNonce,
            page_id: activePageId
        });

        var msgBody = createStreamingMessage();
        var fullText = '';
        var proposalReceived = false;

        fetch(config.streamUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: body
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';

            function pump() {
                return reader.read().then(function (result) {
                    if (result.done) {
                        finishStream(msgBody, fullText, proposalReceived);
                        return;
                    }

                    buffer += decoder.decode(result.value, { stream: true });

                    // Process complete SSE lines
                    var lines = buffer.split('\n');
                    buffer = lines.pop(); // keep incomplete line in buffer

                    lines.forEach(function (line) {
                        line = line.trim();
                        if (!line || line.charAt(0) === ':') return; // keepalive or comment
                        if (line === 'data: [DONE]') return;
                        if (line.indexOf('data: ') !== 0) return;

                        var jsonStr = line.substring(6);
                        try {
                            var data = JSON.parse(jsonStr);

                            if (data.error) {
                                handleStreamError(msgBody, data.error);
                                return;
                            }

                            if (data.content) {
                                fullText += data.content;
                                msgBody.textContent = fullText;
                                messagesEl.scrollTop = messagesEl.scrollHeight;
                            }

                            if (data.done && data.proposal) {
                                proposalReceived = true;
                                renderProposal(data.proposal);
                            }

                            if (data.done && data.truncated && !data.proposal) {
                                addStatusMessage(
                                    'The response was cut short before the proposal could be generated. Try sending your request again, or simplify it.',
                                    false
                                );
                            }
                        } catch (e) {
                            // Skip malformed JSON chunks
                        }
                    });

                    return pump();
                });
            }

            return pump();
        })
        .catch(function (err) {
            // SSE failed, try AJAX fallback
            ajaxFallback(messages, msgBody);
        });
    }

    function stripProposalJson(text) {
        // Remove markdown-fenced JSON proposal blocks (```json ... ```)
        var stripped = text.replace(/```(?:json)?\s*\n[\s\S]*?"proposal"\s*:\s*true[\s\S]*?```/g, '');
        // Remove bare JSON proposal blocks
        stripped = stripped.replace(/\{"proposal"\s*:\s*true[\s\S]*?"steps"\s*:\s*\[[\s\S]*?\]\s*\}/g, '');
        return stripped.trim();
    }

    function finishStream(msgBody, fullText, proposalReceived) {
        msgBody.classList.remove('pp-ai-msg-streaming');
        if (fullText) {
            // Store full text in conversation for context, but display without raw JSON
            conversation.push({ role: 'assistant', content: fullText });
            var displayText = stripProposalJson(fullText);
            if (displayText !== fullText) {
                msgBody.textContent = displayText;
            }

            // Detect truncated responses: prose suggests a proposal was coming
            // but no proposal was received from the server
            if (!proposalReceived && looksLikeIncompleteProposal(fullText)) {
                addStatusMessage(
                    'The response may have been cut short before the proposal could be generated. Try sending your request again, or simplify it.',
                    false
                );
            }
        }
        saveState();
        isStreaming = false;
        sendBtn.disabled = false;
        inputEl.disabled = false;
        inputEl.focus();
    }

    function looksLikeIncompleteProposal(text) {
        // Check if the text contains language that typically precedes a proposal
        // but ends without one. These patterns indicate the AI started to propose
        // something but the response was truncated before the JSON was emitted.
        var proposalIndicators = [
            /here(?:'|')s (?:the |my |what I )?propos/i,
            /here(?:'|')s (?:the |my )?plan/i,
            /proposed (?:changes|update|step)/i,
            /I(?:'|')ll propose/i,
            /proposal.*:/i
        ];
        var hasIndicator = proposalIndicators.some(function (re) {
            return re.test(text);
        });
        if (!hasIndicator) {
            return false;
        }

        // The text has proposal language but no actual proposal JSON was parsed.
        // Check that the text doesn't end with a complete conversational response
        // (if it ends mid-sentence or with a colon, it's more likely truncated).
        var trimmed = text.trim();
        var lastChar = trimmed.charAt(trimmed.length - 1);
        // Ends with colon, incomplete sentence, or mid-word — likely truncated
        if (lastChar === ':' || lastChar === ',') {
            return true;
        }
        // If text has proposal indicators and is relatively short (the JSON
        // block that should follow was never emitted), flag it
        var afterLastIndicator = text.split(/propos|plan/i).pop();
        if (afterLastIndicator && afterLastIndicator.trim().length < 50) {
            return true;
        }
        return false;
    }

    function handleStreamError(msgBody, errorText) {
        msgBody.classList.remove('pp-ai-msg-streaming');
        msgBody.classList.add('pp-ai-msg-error');

        msgBody.textContent = errorText;

        if (errorText.indexOf('API key') !== -1 || errorText.indexOf('not configured') !== -1) {
            var sep = document.createTextNode(' ');
            var link = document.createElement('a');
            link.href = config.settingsUrl;
            link.textContent = 'AI Settings';
            msgBody.appendChild(sep);
            msgBody.appendChild(link);
        }

        isStreaming = false;
        sendBtn.disabled = false;
        inputEl.disabled = false;
    }

    // ── AJAX Fallback ──────────────────────────────────────────────────

    function ajaxFallback(messages, msgBody) {
        var data = new FormData();
        data.append('action', 'pp_ai_chat');
        data.append('nonce', config.streamNonce);

        messages.forEach(function (msg, i) {
            data.append('messages[' + i + '][role]', msg.role);
            data.append('messages[' + i + '][content]', msg.content);
        });

        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (resp.success) {
                msgBody.textContent = resp.data.content;
                msgBody.classList.remove('pp-ai-msg-streaming');
                conversation.push({ role: 'assistant', content: resp.data.content });

                if (resp.data.proposal) {
                    renderProposal(resp.data.proposal);
                } else if (looksLikeIncompleteProposal(resp.data.content)) {
                    addStatusMessage(
                        'The response may have been cut short before the proposal could be generated. Try sending your request again, or simplify it.',
                        false
                    );
                }
            } else {
                handleStreamError(msgBody, resp.data || 'Chat request failed.');
            }

            saveState();
            isStreaming = false;
            sendBtn.disabled = false;
            inputEl.disabled = false;
            inputEl.focus();
        })
        .catch(function () {
            handleStreamError(msgBody, 'Connection failed. Please try again.');
        });
    }

    // ── Page Detection ─────────────────────────────────────────────────

    function detectPageId(messages) {
        if (!config.pages || !config.pages.length) return null;

        var lastMsg = messages[messages.length - 1];
        if (!lastMsg || lastMsg.role !== 'user') return null;

        var text = lastMsg.content.toLowerCase();
        var bestMatch = null;
        var bestLen = 0;

        for (var i = 0; i < config.pages.length; i++) {
            var page = config.pages[i];
            var title = (page.title || '').toLowerCase();
            if (!title) continue; // skip untitled pages
            if (text.indexOf(title) !== -1 && title.length > bestLen) {
                bestMatch = page.id;
                bestLen = title.length;
            }
        }

        return bestMatch;
    }

    // ── Restore Previous Conversation ─────────────────────────────────

    function restoreConversation() {
        var state = loadState();
        if (!state || !state.conversation || !state.conversation.length) return;

        conversation = state.conversation;
        activePageId = state.activePageId || null;

        // Re-render messages from conversation history
        conversation.forEach(function (msg) {
            if (msg.role === 'user') {
                // Skip internal apply-confirmation messages in display
                if (msg.content.charAt(0) === '[') return;
                addMessage('user', msg.content);
            } else if (msg.role === 'assistant') {
                // Skip internal apply-confirmation messages in display
                if (msg.content === 'Changes applied successfully.') return;
                var displayText = stripProposalJson(msg.content);
                if (displayText) {
                    addMessage('assistant', displayText);
                }
            }
        });
    }

    // ── New Chat ──────────────────────────────────────────────────────

    function resetChat() {
        conversation = [];
        activePageId = null;
        isStreaming = false;
        clearState();
        messagesEl.innerHTML = '';
        sendBtn.disabled = false;
        inputEl.disabled = false;
        inputEl.value = '';
        inputEl.focus();
    }

    // ── Event Handlers ─────────────────────────────────────────────────

    sendBtn.addEventListener('click', function () {
        sendMessage(inputEl.value);
    });

    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(inputEl.value);
        }
    });

    if (newChatBtn) {
        newChatBtn.addEventListener('click', resetChat);
    }

    // ── Init ──────────────────────────────────────────────────────────

    restoreConversation();
    inputEl.focus();

})();
