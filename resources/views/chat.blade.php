<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ucfirst($department) }} Chat - Factory AI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f4f5f7;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem 1rem;
        }

        .chat-container {
            width: 100%;
            max-width: 640px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            height: 80vh;
        }

        header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        header h1 {
            font-size: 1.25rem;
            color: #1f2937;
            text-transform: capitalize;
        }

        header a {
            color: #2563eb;
            text-decoration: none;
            font-size: 0.9rem;
        }

        #messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .bubble {
            max-width: 75%;
            padding: 0.6rem 1rem;
            border-radius: 16px;
            line-height: 1.4;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .bubble.user {
            align-self: flex-end;
            background: #2563eb;
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .bubble.ai {
            align-self: flex-start;
            background: #e5e7eb;
            color: #1f2937;
            border-bottom-left-radius: 4px;
        }

        .bubble.loading {
            align-self: flex-start;
            background: #e5e7eb;
            color: #6b7280;
            font-style: italic;
        }

        .bubble.error {
            align-self: flex-start;
            background: #fee2e2;
            color: #991b1b;
        }

        form {
            display: flex;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            border-top: 1px solid #e5e7eb;
        }

        input[type="text"] {
            flex: 1;
            padding: 0.6rem 0.9rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #2563eb;
        }

        button {
            padding: 0.6rem 1.4rem;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
        }

        button:hover:not(:disabled) {
            background: #1d4ed8;
        }

        button:disabled {
            background: #93c5fd;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <header>
            <h1>{{ $department }}</h1>
            <a href="{{ route('public.dashboard') }}">&larr; Dashboard</a>
        </header>
        <div id="messages" aria-live="polite"></div>
        <form id="chat-form">
            <label for="message-input" class="visually-hidden">Question</label>
            <input type="text" id="message-input" placeholder="Ask a question..." autocomplete="off" maxlength="2000" required>
            <button type="submit">Send</button>
        </form>
    </div>

    <script>
        const department = @json($department);
        const messagesEl = document.getElementById('messages');
        const form = document.getElementById('chat-form');
        const input = document.getElementById('message-input');
        const chatUrl = @json(route('api.chat', absolute: false));

        function addBubble(text, className) {
            const bubble = document.createElement('div');
            bubble.className = 'bubble ' + className;
            bubble.textContent = text;
            messagesEl.appendChild(bubble);
            messagesEl.scrollTop = messagesEl.scrollHeight;
            return bubble;
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            const message = input.value.trim();
            if (!message) {
                return;
            }

            addBubble(message, 'user');
            input.value = '';

            const loadingBubble = addBubble('Thinking...', 'loading');
            const submitButton = form.querySelector('button');
            submitButton.disabled = true;

            try {
                const response = await fetch(chatUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ department: department, message: message }),
                });

                const data = await response.json().catch(() => ({}));
                loadingBubble.remove();

                if (!response.ok) {
                    const validationError = data.errors?.message?.[0] ?? data.errors?.department?.[0];
                    addBubble(validationError ?? data.error ?? data.message ?? `Request failed (${response.status}).`, 'error');
                    return;
                }

                if (typeof data.answer !== 'string' || data.answer.trim() === '') {
                    addBubble('The AI service returned an empty response.', 'error');
                    return;
                }

                addBubble(data.answer, 'ai');
            } catch (error) {
                loadingBubble.remove();
                addBubble('Could not reach the application server. Please try again.', 'error');
            } finally {
                submitButton.disabled = false;
                input.focus();
            }
        });
    </script>
</body>
</html>
