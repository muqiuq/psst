<?php

declare(strict_types=1);

// Markdown renderer, including XSS attempts. Run through tests/run.sh.

require '/var/www/html/functions.php';

// [description, markdown, must contain[], must not contain[]]
$cases = [
    ['heading', '# Title', ['<h1 id="md-title">Title</h1>'], []],
    ['setext h2 wins over hr', "Sub\n---", ['<h2 id="md-sub">Sub</h2>'], ['<hr>']],
    ['hr', "a\n\n---", ['<hr>'], []],
    ['inline formats', '**b** *i* ~~s~~ `c`', ['<strong>b</strong>', '<em>i</em>', '<del>s</del>', '<code>c</code>'], []],
    ['snake_case stays text', 'a_b_c and 5 * 3 * 2', ['a_b_c', '5 * 3 * 2'], ['<em>']],
    ['raw script escaped', '<script>alert(1)</script>', ['&lt;script&gt;'], ['<script']],
    ['raw img escaped', '<img src=x onerror=alert(1)>', ['&lt;img'], ['<img src=x']],
    ['javascript: link dropped', '[x](javascript:alert(1))', ['x'], ['href', '<a ']],
    ['mixed-case javascript: dropped', '[x](JaVaScRiPt:alert(1))', [], ['<a ']],
    ['data:text/html link dropped', '[x](data:text/html;base64,PHNjcmlwdD4=)', [], ['<a ']],
    ['javascript autolink not linked', '<javascript:alert(1)>', ['&lt;javascript'], ['<a ']],
    ['javascript image dropped', '![x](javascript:alert(1))', [], ['<img']],
    ['svg data image dropped', '![x](data:image/svg+xml;base64,PHN2Zz4=)', [], ['<img']],
    ['png data image kept', '![x](data:image/png;base64,iVBORw0KGgo=)', ['<img src="data:image/png;base64,iVBORw0KGgo="'], []],
    ['https link with title', '[PSST](https://example.com/a?b=1&c=2 "T")', ['<a href="https://example.com/a?b=1&amp;c=2" title="T" target="_blank" rel="nofollow noopener noreferrer">PSST</a>'], []],
    ['title cannot break out', '[a](https://x.y "q\\" onmouseover=\\"x")', [], ['onmouseover="']],
    ['url cannot break out', '[a](https://x.y/"onmouseover="alert(1))', [], ['"onmouseover']],
    ['bare url', 'see https://example.com/x.', ['<a href="https://example.com/x" target="_blank"'], []],
    ['bare url cannot inject', 'https://x.y/"><script>', [], ['<script', '"><']],
    ['fenced code escaped', "```html\n<b>x</b>\n```", ['<pre><code class="language-html">&lt;b&gt;x&lt;/b&gt;</code></pre>'], ['<b>']],
    ['code span escaped', '`<b>`', ['<code>&lt;b&gt;</code>'], []],
    ['no formatting inside code', '`**x**`', ['<code>**x**</code>'], ['<strong>']],
    ['backslash escapes', '\\*not em\\*', ['*not em*'], ['<em>']],
    ['tight list', "- a\n- b", ['<ul><li>a</li><li>b</li></ul>'], ['<p>']],
    ['loose list', "- a\n\n- b", ['<li><p>a</p></li>'], []],
    ['nested list', "- a\n  - b\n- c", ["<li>a\n<ul><li>b</li></ul></li><li>c</li>"], []],
    ['ordered list start', "3. x\n4. y", ['<ol start="3"><li>x</li><li>y</li></ol>'], []],
    ['year in paragraph is no list', "In\n2024. was", ["<p>In\n2024. was</p>"], ['<ol']],
    ['task list', "- [x] done\n- [ ] todo", ['<input type="checkbox" disabled checked> done', '<input type="checkbox" disabled> todo'], []],
    ['blockquote with lazy line', "> q\nlazy", ["<blockquote><p>q\nlazy</p></blockquote>"], []],
    ['table with alignment', "| a | b |\n|:--|--:|\n| 1 | 2 |", ['<th class="text-start">a</th>', '<td class="text-end">2</td>'], []],
    ['escaped pipe in table', "| a |\n|---|\n| x \\| y |", ['<td>x | y</td>'], []],
    ['hard line break', "a  \nb", ["a<br>\nb"], []],
    ['reference link', "[t][r]\n\n[r]: https://e.com", ['<a href="https://e.com"'], ['[r]:']],
    ['anchor link', '[s](#setup)', ['href="#md-setup"'], ['target=']],
    ['duplicate heading ids', "# A\n# A", ['id="md-a"', 'id="md-a-1"'], []],
    ['indented code', '    <x>', ['<pre><code>&lt;x&gt;</code></pre>'], []],
    ['entity shown literally', '&copy;', ['&amp;copy;'], []],
    ['image inside link', '[![b](https://i.x/b.png)](https://x.y)', ['<a href="https://x.y"', '<img src="https://i.x/b.png" alt="b"'], []],
    ['mailto link', '[m](mailto:a@b.c)', ['href="mailto:a@b.c"'], []],
    ['token character stripped', "a\x1A0\x1Ab", ['a0b'], ["\x1A"]],
    ['invalid UTF-8 does not fail', "\xff\xfe **x**", ['<strong>x</strong>'], []],
];

foreach ($cases as [$name, $markdown, $contains, $lacks]) {
    $html = psst_markdown($markdown);
    $problems = [];
    foreach ($contains as $needle) {
        if (strpos($html, $needle) === false) {
            $problems[] = 'missing ' . json_encode($needle);
        }
    }
    foreach ($lacks as $needle) {
        if (strpos($html, $needle) !== false) {
            $problems[] = 'unexpected ' . json_encode($needle);
        }
    }
    echo $problems === [] ? "  ok   markdown: $name\n" : "  FAIL markdown: $name (" . implode('; ', $problems) . ")\n         got: " . json_encode($html) . "\n";
}

$started = microtime(true);
$big = psst_markdown(str_repeat("**a** _b_ [c](https://d.e) `f` | x\n", 5000));
$seconds = microtime(true) - $started;
echo strpos($big, '<strong>a</strong>') !== false && $seconds < 5
    ? sprintf("  ok   markdown: 5000-line document in %.2fs\n", $seconds)
    : sprintf("  FAIL markdown: 5000-line document (%.2fs)\n", $seconds);
