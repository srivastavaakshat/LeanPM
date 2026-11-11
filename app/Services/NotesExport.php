<?php
declare(strict_types=1);

namespace App\Services;

/**
 * A meeting-notes doc as a file: Markdown, plain text, Word (.doc, Word-flavoured HTML) or a
 * print view the browser saves as PDF. Entry bodies were cleaned by RichText::clean() when saved;
 * task chips become a checkbox line with the task's current stage and project.
 */
final class NotesExport
{
    /**
     * @param array $doc     notes_docs row
     * @param array $entries notes_entries rows (+ author), in the order to export
     * @param array $chips   task summaries keyed by id (NotesController::chips)
     * @param ?array $task   the task the doc is connected to, if any
     */
    public function __construct(private array $doc, private array $entries, private array $chips, private ?array $task) {}

    // ---------- Markdown ----------
    public function markdown(): string
    {
        $out = '# ' . $this->doc['title'] . "\n\n" . '_' . $this->subtitle() . "_\n";
        foreach ($this->entries as $e) {
            $out .= "\n---\n\n## " . $this->when($e) . ($e['title'] ? ' · ' . $e['title'] : '') . "\n\n";
            $out .= '_by ' . $e['author'] . "_\n\n";
            $out .= trim($this->blocks($e['body'], 'md')) . "\n";
        }
        return $out;
    }

    // ---------- plain text ----------
    public function text(): string
    {
        $out = $this->doc['title'] . "\n" . str_repeat('=', mb_strlen($this->doc['title'])) . "\n" . $this->subtitle() . "\n";
        foreach ($this->entries as $e) {
            $head = $this->when($e) . ($e['title'] ? ' · ' . $e['title'] : '');
            $out .= "\n\n" . $head . "\n" . str_repeat('-', mb_strlen($head)) . "\nby " . $e['author'] . "\n\n";
            $out .= trim($this->blocks($e['body'], 'txt')) . "\n";
        }
        return $out;
    }

    // ---------- Word and print (HTML) ----------
    public function word(): string
    {
        return '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8"><title>'
            . e($this->doc['title']) . '</title><style>' . $this->css() . '</style></head><body>' . $this->html() . '</body></html>';
    }

    public function printView(): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . e($this->doc['title']) . '</title><style>' . $this->css()
            . '.bar{position:sticky;top:0;display:flex;gap:10px;align-items:center;justify-content:space-between;padding:12px 16px;margin:-40px -40px 30px;background:#14213D;color:#fff;font:14px system-ui,sans-serif}'
            . '.bar button{font:600 14px system-ui,sans-serif;border:0;border-radius:999px;padding:9px 18px;background:#B7F34A;color:#14213D;cursor:pointer}'
            . '@media print{.bar{display:none}body{margin:0}}</style></head><body>'
            . '<div class="bar"><span>Choose <strong>Save as PDF</strong> as the printer.</span><button onclick="print()">Save as PDF</button></div>'
            . $this->html() . '<script>addEventListener("load",()=>setTimeout(()=>print(),300))</script></body></html>';
    }

    private function html(): string
    {
        $out = '<h1>' . e($this->doc['title']) . '</h1><p class="sub">' . e($this->subtitle()) . '</p>';
        foreach ($this->entries as $e) {
            $out .= '<div class="entry"><h2>' . e($this->when($e)) . ($e['title'] ? ' · ' . e($e['title']) : '') . '</h2>'
                . '<p class="by">by ' . e($e['author']) . '</p>' . $this->blocks($e['body'], 'html') . '</div>';
        }
        return $out;
    }

    private function css(): string
    {
        return 'body{font:15px/1.6 -apple-system,"Segoe UI",Calibri,Arial,sans-serif;color:#14213D;max-width:760px;margin:40px auto;padding:0 40px}'
            . 'h1{font-size:28px;margin:0 0 4px}h2{font-size:18px;margin:0 0 2px}h3,h4{font-size:15px;margin:14px 0 4px}'
            . '.sub,.by{color:#5f6779;margin:0 0 6px}.entry{border-top:1px solid #dde1e8;padding-top:18px;margin-top:22px;page-break-inside:avoid}'
            . '.task{display:inline-block;border:1px solid #cfd4de;border-radius:6px;padding:1px 8px;font-weight:600}.task .meta{font-weight:400;color:#5f6779}'
            . 'a{color:#14213D}blockquote{border-left:3px solid #B7F34A;margin:0 0 8px;padding-left:12px;color:#5f6779}';
    }

    // ---------- shared ----------
    private function subtitle(): string
    {
        $n = count($this->entries);
        return 'Meeting notes · ' . $n . ($n === 1 ? ' entry' : ' entries')
            . ($this->task ? ' · connected to the task “' . $this->task['title'] . '”' : '')
            . ' · exported ' . date('j M Y, g:i a');
    }

    private function when(array $e): string
    {
        return date('l, j F Y · g:i a', strtotime($e['meeting_at']));
    }

    /** Turn an entry body into md / txt / html. */
    private function blocks(?string $html, string $as): string
    {
        if (trim((string) $html) === '') {
            return $as === 'html' ? '<p class="by">(empty)</p>' : "(empty)\n";
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $dom->getElementById('root') ?? $dom->documentElement;
        return $this->nodes($root, $as, 0);
    }

    private function nodes(\DOMNode $parent, string $as, int $depth): string
    {
        $out = '';
        $n = 0;
        foreach ($parent->childNodes as $node) {
            $out .= $this->node($node, $as, $depth, $parent instanceof \DOMElement && $parent->tagName === 'ol' ? ++$n : 0);
        }
        return $out;
    }

    private function node(\DOMNode $node, string $as, int $depth, int $index): string
    {
        if ($node instanceof \DOMText) {
            $t = preg_replace('/\s+/u', ' ', $node->textContent);
            return $as === 'html' ? e($t) : $t;
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        $inner = fn () => $this->nodes($node, $as, $depth);
        $cls = ' ' . $node->getAttribute('class') . ' ';

        if ($tag === 'span' && str_contains($cls, ' task-ref ')) {
            return $this->chip((int) $node->getAttribute('data-task'), trim($node->textContent), $as);
        }
        if ($tag === 'br') {
            return $as === 'html' ? '<br>' : ($as === 'md' ? "  \n" : "\n");
        }
        if ($as === 'html') {
            return match ($tag) {
                'p', 'ul', 'ol', 'li', 'strong', 'em', 's', 'blockquote' => "<{$tag}>" . $inner() . "</{$tag}>",
                'b' => '<strong>' . $inner() . '</strong>',
                'i' => '<em>' . $inner() . '</em>',
                'h2', 'h3' => '<h3>' . $inner() . '</h3>',
                'a' => '<a href="' . e($node->getAttribute('href')) . '">' . $inner() . '</a>',
                default => $inner(),
            };
        }
        $md = $as === 'md';
        return match ($tag) {
            'p', 'div' => trim($inner()) . "\n\n",
            'h2', 'h3' => ($md ? '### ' : '') . trim($inner()) . "\n\n",
            'ul', 'ol' => $this->nodes($node, $as, $depth + 1) . ($depth === 0 ? "\n" : ''),
            'li' => str_repeat('  ', max(0, $depth - 1)) . ($index ? "{$index}. " : '- ') . trim($inner()) . "\n",
            'strong', 'b' => $md ? '**' . $inner() . '**' : $inner(),
            'em', 'i' => $md ? '*' . $inner() . '*' : $inner(),
            's' => $md ? '~~' . $inner() . '~~' : $inner(),
            'blockquote' => '> ' . trim($inner()) . "\n\n",
            'a' => $md ? '[' . $inner() . '](' . $node->getAttribute('href') . ')' : $inner() . ' (' . $node->getAttribute('href') . ')',
            default => $inner(),
        };
    }

    /** A task chip as text: its current title, stage and project (or the saved title if it's gone). */
    private function chip(int $id, string $fallback, string $as): string
    {
        $c = $this->chips[$id] ?? null;
        $done = $c && $c['status']['type'] === 'done';
        $title = $c['title'] ?? $fallback;
        $meta = $c ? $c['status']['name'] . ' · ' . ($c['project']['inbox'] ? 'Inbox' : $c['project']['name']) : 'no longer available';
        return match ($as) {
            'html' => '<span class="task">' . ($done ? '☑' : '☐') . ' ' . e($title) . ' <span class="meta">(' . e($meta) . ')</span></span>',
            'md' => ($done ? '[x] ' : '[ ] ') . '**' . $title . '** (' . $meta . ')',
            default => ($done ? '[x] ' : '[ ] ') . $title . ' (' . $meta . ')',
        };
    }
}
