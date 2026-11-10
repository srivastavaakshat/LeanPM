<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Task descriptions are edited as rich text in the browser. Before saving, keep only a short
 * list of harmless tags and safe links, so nothing anyone pastes can run script for others.
 */
final class RichText
{
    private const ALLOWED = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'ul', 'ol', 'li', 'a', 'h2', 'h3', 'blockquote', 'code', 'pre', 'div', 'span'];

    public static function clean(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '' || trim(strip_tags($html)) === '' && !str_contains($html, '<li')) {
            return null;
        }
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return null;
        }
        self::walk($body);
        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        $out = trim($out);
        return $out === '' ? null : mb_substr($out, 0, 60000);
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                self::walk($child);
                if (!in_array($tag, self::ALLOWED, true)) {
                    // Unknown tag (span, font, h1 …): keep its text, drop the tag.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                // Spans kept: an @mention chip <span class="mention" data-user="12">@Name</span>, and a task chip
                // from meeting notes <span class="task-ref" data-task="34">Title</span>. Anything else is unwrapped.
                $isMention = $child->getAttribute('class') === 'mention' && ctype_digit($child->getAttribute('data-user'));
                $isTask = $child->getAttribute('class') === 'task-ref' && ctype_digit($child->getAttribute('data-task'));
                if ($tag === 'span' && !$isMention && !$isTask) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                $user = $tag === 'span' ? $child->getAttribute('data-user') : '';
                $taskRef = $tag === 'span' ? $child->getAttribute('data-task') : '';
                $href = $tag === 'a' ? $child->getAttribute('href') : '';
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $child->removeAttribute($attr->name);
                }
                if ($tag === 'span' && $user !== '') {
                    $child->setAttribute('class', 'mention');
                    $child->setAttribute('data-user', $user);
                } elseif ($tag === 'span') {
                    $child->setAttribute('class', 'task-ref');
                    $child->setAttribute('data-task', $taskRef);
                }
                if ($tag === 'a') {
                    if (preg_match('#^(https?://|mailto:)#i', trim($href))) {
                        $child->setAttribute('href', trim($href));
                        $child->setAttribute('target', '_blank');
                        $child->setAttribute('rel', 'noopener noreferrer');
                    }
                }
            } elseif ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
            }
        }
    }

    /** Plain-text comment → safe HTML with clickable links, highlighted @mentions and line breaks. */
    public static function comment(string $text, array $mentionable = []): string
    {
        $html = e($text);
        $html = preg_replace('#\bhttps?://[^\s<]+[^\s<.,;:!?)\]\'"]#i', '<a href="$0" target="_blank" rel="noopener noreferrer">$0</a>', $html) ?? $html;
        $html = Mentions::highlight($html, $mentionable);
        return nl2br($html);
    }
}
