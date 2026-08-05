<x-app-layout>
    <x-slot name="header">
        <h2 class="fs-4 fw-semibold text-dark">
            {{ __('Chat') }}
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="container" style="max-width: 48rem;">
            <div class="bg-white shadow-sm rounded d-flex flex-column" style="height: 70vh;" x-data="chatPage()">

                <!-- Message history -->
                <div class="flex-grow-1 overflow-auto p-4" x-ref="scrollArea">
                    <template x-if="messages.length === 0">
                        <p class="text-secondary text-center mt-5">
                            {{ __('Ask the assistant anything to get started.') }}
                        </p>
                    </template>

                    <template x-for="(message, index) in messages" :key="index">
                        <div class="d-flex mb-3" :class="message.role === 'user' ? 'justify-content-end' : 'justify-content-start'">
                            <div
                                class="px-3 py-2 rounded-3"
                                :class="message.role === 'user' ? 'bg-primary text-white' : 'bg-light text-dark border'"
                                style="max-width: 80%; white-space: pre-wrap;"
                                x-text="message.content"
                            ></div>
                        </div>
                    </template>

                    <!-- Typing indicator. x-show is on this plain wrapper, not
                         an element carrying Bootstrap's "d-flex" - that
                         utility's "display: flex !important" would otherwise
                         beat Alpine's inline display:none toggle. -->
                    <div x-show="sending" x-cloak>
                        <div class="d-flex justify-content-start mb-3">
                            <div class="px-3 py-2 rounded-3 bg-light border d-flex gap-1 align-items-center">
                                <span class="typing-dot"></span>
                                <span class="typing-dot"></span>
                                <span class="typing-dot"></span>
                            </div>
                        </div>
                    </div>

                    <div x-show="error" x-cloak class="alert alert-danger py-2 px-3 small mb-0" x-text="error"></div>
                </div>

                <!-- Composer -->
                <form class="border-top p-3 d-flex gap-2" @submit.prevent="send()">
                    <textarea
                        x-model="input"
                        @keydown.enter.prevent="if (! $event.shiftKey) send()"
                        class="form-control"
                        rows="1"
                        placeholder="{{ __('Type a message...') }}"
                        :disabled="sending"
                    ></textarea>
                    <button type="submit" class="btn btn-primary" :disabled="sending || input.trim() === ''">
                        {{ __('Send') }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    <style>
        .typing-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background-color: #adb5bd;
            animation: typing-bounce 1s infinite ease-in-out;
        }
        .typing-dot:nth-child(2) { animation-delay: 0.15s; }
        .typing-dot:nth-child(3) { animation-delay: 0.3s; }

        @keyframes typing-bounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-0.25rem); opacity: 1; }
        }
    </style>

    <script>
        function chatPage() {
            return {
                messages: [],
                input: '',
                sending: false,
                error: null,

                async send() {
                    const message = this.input.trim();

                    if (message === '' || this.sending) {
                        return;
                    }

                    // Snapshot history before pushing the new message - the
                    // backend expects prior turns separately from the new one.
                    const history = this.messages.map(({ role, content }) => ({ role, content }));

                    this.messages.push({ role: 'user', content: message });
                    this.input = '';
                    this.error = null;
                    this.sending = true;
                    this.scrollToBottom();

                    try {
                        const response = await fetch('{{ route('chat.send') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ message, history }),
                        });

                        const data = await response.json();

                        if (! response.ok) {
                            this.error = data.error ?? 'Something went wrong. Please try again.';
                            return;
                        }

                        // Streaming placeholder: the backend currently returns
                        // one full response. When Ollama's streaming API is
                        // wired up later, incremental chunks would be appended
                        // to this message's content here instead of a single
                        // push of the finished answer.
                        this.messages.push({ role: 'assistant', content: data.answer });
                    } catch (e) {
                        this.error = 'Could not reach the server. Please try again.';
                    } finally {
                        this.sending = false;
                        this.scrollToBottom();
                    }
                },

                scrollToBottom() {
                    this.$nextTick(() => {
                        this.$refs.scrollArea.scrollTop = this.$refs.scrollArea.scrollHeight;
                    });
                },
            };
        }
    </script>
</x-app-layout>
