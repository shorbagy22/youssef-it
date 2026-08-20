import katex from 'katex';
import 'katex/dist/katex.min.css';

/**
 * Splits an AI chat message into plain-text and math segments, and
 * renders each math segment via KaTeX's own katex.render(element, ...)
 * - never string concatenation/innerHTML with untrusted text. Plain-text
 * segments are inserted as real text nodes (document.createTextNode),
 * exactly as the caller did before this existed (see chat.blade.php's
 * previous plain `bubble.textContent = text`), so nothing about this
 * page's existing XSS-safety changes: an AI response can still only ever
 * produce a KaTeX-rendered formula or literal displayed text, never
 * arbitrary injected HTML/script.
 *
 * Delimiters: "$$...$$" for a block/display equation, "\(...\)" for an
 * inline one - deliberately NOT single "$...$" for inline, which would
 * misfire on a literal dollar amount appearing twice in one message.
 * Matches ChatDataService::SYSTEM_PROMPT's own instruction to the AI on
 * exactly these two delimiters - if that prompt ever changes format,
 * this must change with it.
 *
 * @param {HTMLElement} container - emptied and (re)populated with the rendered content.
 * @param {string} text - the raw AI message text.
 */
export function renderChatMessage(container, text) {
    container.textContent = '';

    const pattern = /\$\$([\s\S]+?)\$\$|\\\(([\s\S]+?)\\\)/g;
    let lastIndex = 0;
    let match;

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > lastIndex) {
            container.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
        }

        const isBlock = match[1] !== undefined;
        const formula = (isBlock ? match[1] : match[2]).trim();
        const el = document.createElement(isBlock ? 'div' : 'span');

        try {
            katex.render(formula, el, { throwOnError: false, displayMode: isBlock });
        } catch {
            el.textContent = match[0];
        }

        container.appendChild(el);
        lastIndex = pattern.lastIndex;
    }

    if (lastIndex < text.length) {
        container.appendChild(document.createTextNode(text.slice(lastIndex)));
    }
}

// chat.blade.php is a standalone page (its own inline <script>, not part
// of the app.js module graph) - exposed on window so that plain script
// can call it directly rather than needing its own module wiring.
window.renderChatMessage = renderChatMessage;
