/**
 * WWU UI Kit — Minimal Markdown renderer
 *
 * Escape-first Markdown-to-HTML converter supporting a small subset:
 * headers (# ## ###), bold (**…**), italics (_…_), inline code (`…`),
 * fenced code blocks (```…```), ordered/unordered lists, paragraph
 * breaks. Everything else is HTML-escaped first so raw user/model input
 * is always safe to insert via innerHTML.
 *
 * Extracted from AM Pro admin.js:4650-4694 (AI Advisor renderer, Pro 1.3.7+).
 *
 * @since 0.3.0
 */
(function () {
    'use strict';

    if (!window.wwuUIKit) {
        if (console && console.error) {
            console.error('[wwu-ui-kit] markdown.js loaded before ui-kit.js — check enqueue deps');
        }
        return;
    }

    var escHtml = window.wwuUIKit.escHtml;

    /**
     * Hard input cap. Markdown rendering runs a handful of regex passes
     * which are bounded per-pattern, but a 100MB input still costs CPU.
     * Cap at 200KB — well above any reasonable AI Advisor / help text —
     * and return a truncation marker beyond that.
     *
     * @since 0.8.0
     */
    var MAX_INPUT_LENGTH = 200 * 1024;

    /**
     * Convert a Markdown string to HTML.
     * Safe to use with innerHTML — all user input is escaped first, only
     * the kit's own tag patterns are re-enabled after.
     *
     * Input is capped at 200KB as a ReDoS safety net. Longer input is
     * truncated with a marker (consumer can override by calling `toHtml`
     * in chunks).
     *
     * @param {string} src
     * @returns {string}
     */
    function toHtml(src) {
        if (!src) { return ''; }

        // Safety cap — reject / truncate runaway input.
        var str = String(src);
        var truncated = false;
        if (str.length > MAX_INPUT_LENGTH) {
            str = str.substring(0, MAX_INPUT_LENGTH);
            truncated = true;
        }

        // Escape all HTML first — nothing below this line introduces tags
        // from user input, only from our own transform patterns.
        var html = escHtml(str);

        // Fenced code blocks ```…``` — must run before inline `…` so
        // triple-backticks are consumed first.
        html = html.replace(/```([\s\S]*?)```/g, function (_, block) {
            // Preserve the literal block content (already escaped).
            return '<pre><code>' + block + '</code></pre>';
        });

        // Inline code `…`.
        html = html.replace(/`([^`\n]+)`/g, '<code>$1</code>');

        // Bold **…**.
        html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');

        // Italics _…_ — only when surrounded by word boundaries to avoid
        // mid-word underscores (e.g. snake_case variable names).
        html = html.replace(/(^|\s)_([^_\n]+)_(\s|$|[.,;:!?])/g, '$1<em>$2</em>$3');

        // Headers (# / ## / ###).
        html = html.replace(/^###\s+(.+)$/gm, '<h5>$1</h5>');
        html = html.replace(/^##\s+(.+)$/gm, '<h4>$1</h4>');
        html = html.replace(/^#\s+(.+)$/gm, '<h3>$1</h3>');

        // Ordered lists: collect contiguous "1. " / "2. " lines.
        html = html.replace(/(?:^\d+\.\s+.+(?:\n|$))+/gm, function (block) {
            var items = block.trim().split(/\n/).map(function (line) {
                return '<li>' + line.replace(/^\d+\.\s+/, '') + '</li>';
            }).join('');
            return '<ol>' + items + '</ol>';
        });

        // Unordered lists: "- " or "* ".
        html = html.replace(/(?:^[\-\*]\s+.+(?:\n|$))+/gm, function (block) {
            var items = block.trim().split(/\n/).map(function (line) {
                return '<li>' + line.replace(/^[\-\*]\s+/, '') + '</li>';
            }).join('');
            return '<ul>' + items + '</ul>';
        });

        // Paragraph breaks: blank lines → </p><p>.
        html = html.replace(/\n{2,}/g, '</p><p>');

        // Single newlines inside paragraphs → <br>.
        html = html.replace(/\n/g, '<br>');

        var result = '<p>' + html + '</p>';
        if (truncated) {
            result += '<p><em>[&hellip; output troncato a ' + Math.round(MAX_INPUT_LENGTH / 1024) + 'KB]</em></p>';
        }
        return result;
    }

    /**
     * Render Markdown directly into a DOM element.
     *
     * @param {HTMLElement|string} target
     * @param {string} src
     */
    function renderInto(target, src) {
        var el = typeof target === 'string' ? document.querySelector(target) : target;
        if (!el) { return; }
        el.innerHTML = toHtml(src);
    }

    window.wwuUIKit.markdown = {
        toHtml: toHtml,
        renderInto: renderInto,
    };
}());
