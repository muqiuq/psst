<?php

declare(strict_types=1);

/**
 * Small, dependency-free Markdown renderer: a CommonMark subset plus the GitHub
 * extensions tables, task lists, strikethrough and bare URLs.
 *
 * Safe by construction: all text is HTML-escaped before inline formatting is applied,
 * raw HTML in the input is shown as text, and links and images only accept http(s),
 * mailto, in-page (#) and data:image targets. Pages that show the result also send a
 * strict Content-Security-Policy as a second line of defence.
 */

// Marks placeholders for finished HTML fragments; removed from the input first.
const PSST_MD_TOKEN = "\x1A";

function psst_markdown(string $markdown): string
{
    $markdown = str_replace(["\r\n", "\r", PSST_MD_TOKEN, "\0", "\t"], ["\n", "\n", '', '', '    '], $markdown);

    $context = ['refs' => [], 'tokens' => [], 'slugs' => []];
    $lines = psst_markdown_collect_references(explode("\n", $markdown), $context);

    return psst_markdown_restore(psst_markdown_blocks($lines, $context, false), $context);
}

function psst_markdown_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * preg_* return null when PCRE gives up (e.g. backtrack limit on huge input); keep the
 * text unformatted rather than failing.
 *
 * @param string|callable $replacement
 */
function psst_markdown_replace(string $pattern, $replacement, string $subject): string
{
    $result = is_callable($replacement)
        ? preg_replace_callback($pattern, $replacement, $subject)
        : preg_replace($pattern, $replacement, $subject);

    return $result ?? $subject;
}

function psst_markdown_store(array &$context, string $html): string
{
    $context['tokens'][] = $html;

    return PSST_MD_TOKEN . (count($context['tokens']) - 1) . PSST_MD_TOKEN;
}

function psst_markdown_restore(string $html, array $context): string
{
    $pattern = '/' . PSST_MD_TOKEN . '(\d+)' . PSST_MD_TOKEN . '/';

    // Fragments can contain placeholders of their own (a link label with code in it).
    for ($depth = 0; $depth < 32 && strpos($html, PSST_MD_TOKEN) !== false; $depth += 1) {
        $html = psst_markdown_replace($pattern, static function (array $match) use ($context): string {
            return $context['tokens'][(int) $match[1]] ?? '';
        }, $html);
    }

    return str_replace(PSST_MD_TOKEN, '', $html);
}

/**
 * Removes "[label]: url" definitions and remembers them for reference links.
 */
function psst_markdown_collect_references(array $lines, array &$context): array
{
    $kept = [];
    $inFence = false;

    foreach ($lines as $line) {
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1) {
            $inFence = !$inFence;
        }

        if (!$inFence && preg_match('/^ {0,3}\[([^\]]+)\]:[ ]*<?([^\s>]+)>?(?:[ ]+(?:"([^"]*)"|\'([^\']*)\'))?[ ]*$/', $line, $match) === 1) {
            $label = psst_markdown_reference_label($match[1]);
            if (!isset($context['refs'][$label])) {
                $title = ($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '');
                $context['refs'][$label] = ['url' => $match[2], 'title' => $title];
            }
            continue;
        }

        $kept[] = $line;
    }

    return $kept;
}

function psst_markdown_reference_label(string $label): string
{
    return strtolower((string) preg_replace('/\s+/', ' ', trim($label)));
}

function psst_markdown_list_item(string $line): ?array
{
    if (preg_match('/^( {0,3})([-*+]|(\d{1,9})([.)]))( *)(.*)$/', $line, $match) !== 1) {
        return null;
    }

    // "-foo" is text, not an item: the marker needs a space or the end of the line.
    if ($match[5] === '' && $match[6] !== '') {
        return null;
    }

    return [
        'indent' => strlen($match[1]),
        'marker' => $match[2],
        'number' => $match[3],
        'delimiter' => $match[4] ?? '',
        'spaces' => strlen($match[5]),
        'content' => $match[6],
    ];
}

function psst_markdown_is_rule(string $line): bool
{
    return preg_match('/^ {0,3}([-*_])(?:[ ]*\1){2,}[ ]*$/', $line) === 1;
}

function psst_markdown_starts_block(string $line): bool
{
    return preg_match('/^ {0,3}(?:#{1,6}(?: |$)|`{3,}|~{3,}|>)/', $line) === 1
        || psst_markdown_is_rule($line)
        || psst_markdown_list_item($line) !== null;
}

/**
 * @param string[] $lines
 * @param bool $tight list items without blank lines render paragraphs without <p>
 */
function psst_markdown_blocks(array $lines, array &$context, bool $tight): string
{
    $html = [];
    $paragraph = [];
    $count = count($lines);
    $index = 0;

    $flush = static function () use (&$paragraph, &$html, &$context, $tight): void {
        if ($paragraph === []) {
            return;
        }

        $inline = psst_markdown_inline(rtrim(implode("\n", $paragraph)), $context);
        $html[] = $tight ? $inline : '<p>' . $inline . '</p>';
        $paragraph = [];
    };

    while ($index < $count) {
        $line = $lines[$index];

        if (trim($line) === '') {
            $flush();
            $index += 1;
            continue;
        }

        if (preg_match('/^( {0,3})(`{3,}|~{3,})[ ]*([^`\s]*)[^`]*$/', $line, $match) === 1) {
            $flush();
            $fence = $match[2];
            $indent = strlen($match[1]);
            $language = (string) preg_replace('/[^A-Za-z0-9_+#.-]/', '', $match[3]);
            $closing = '/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ ]*$/';
            $code = [];
            $index += 1;

            while ($index < $count) {
                if (preg_match($closing, $lines[$index]) === 1) {
                    $index += 1;
                    break;
                }
                $code[] = (string) preg_replace('/^ {0,' . $indent . '}/', '', $lines[$index]);
                $index += 1;
            }

            $class = $language !== '' ? ' class="language-' . psst_markdown_escape($language) . '"' : '';
            $html[] = '<pre><code' . $class . '>' . psst_markdown_escape(implode("\n", $code)) . '</code></pre>';
            continue;
        }

        if (preg_match('/^ {0,3}(#{1,6})(?:[ ]+(.*?))?(?:[ ]+#+)?[ ]*$/', $line, $match) === 1) {
            $flush();
            $html[] = psst_markdown_heading(strlen($match[1]), $match[2] ?? '', $context);
            $index += 1;
            continue;
        }

        // Setext heading: a paragraph underlined with === or ---.
        if ($paragraph !== [] && preg_match('/^ {0,3}(=+|-+)[ ]*$/', $line, $match) === 1) {
            $text = implode(' ', $paragraph);
            $paragraph = [];
            $html[] = psst_markdown_heading($match[1][0] === '=' ? 1 : 2, $text, $context);
            $index += 1;
            continue;
        }

        if (psst_markdown_is_rule($line)) {
            $flush();
            $html[] = '<hr>';
            $index += 1;
            continue;
        }

        if (preg_match('/^ {0,3}>/', $line) === 1) {
            $flush();
            $quote = [];
            while ($index < $count) {
                $current = $lines[$index];
                if (preg_match('/^ {0,3}> ?(.*)$/', $current, $match) === 1) {
                    $quote[] = $match[1];
                } elseif (trim($current) !== '' && $quote !== [] && trim((string) end($quote)) !== '' && !psst_markdown_starts_block($current)) {
                    // Lazy continuation of the quoted paragraph.
                    $quote[] = $current;
                } else {
                    break;
                }
                $index += 1;
            }
            $html[] = '<blockquote>' . psst_markdown_blocks($quote, $context, false) . '</blockquote>';
            continue;
        }

        if (strpos($line, '|') !== false && $index + 1 < $count) {
            $header = psst_markdown_table_cells($line);
            $alignments = psst_markdown_table_alignments($lines[$index + 1], count($header));
            if ($alignments !== null) {
                $flush();
                $rows = [];
                $index += 2;
                while ($index < $count && trim($lines[$index]) !== '' && strpos($lines[$index], '|') !== false) {
                    $rows[] = psst_markdown_table_cells($lines[$index]);
                    $index += 1;
                }
                $html[] = psst_markdown_table($header, $alignments, $rows, $context);
                continue;
            }
        }

        $item = psst_markdown_list_item($line);
        // Inside a paragraph only "1." or a bullet starts a list, so "2024. was" stays text.
        $interrupts = $paragraph === [] || ($item !== null && $item['content'] !== '' && ($item['number'] === '' || $item['number'] === '1'));
        if ($item !== null && $interrupts) {
            $flush();
            $html[] = psst_markdown_list($lines, $index, $context);
            continue;
        }

        if ($paragraph === [] && strncmp($line, '    ', 4) === 0) {
            $code = [];
            while ($index < $count && (strncmp($lines[$index], '    ', 4) === 0 || trim($lines[$index]) === '')) {
                $code[] = (string) substr($lines[$index], 4);
                $index += 1;
            }
            while ($code !== [] && trim((string) end($code)) === '') {
                array_pop($code);
            }
            $html[] = '<pre><code>' . psst_markdown_escape(implode("\n", $code)) . '</code></pre>';
            continue;
        }

        $paragraph[] = ltrim($line, ' ');
        $index += 1;
    }

    $flush();

    return implode("\n", $html);
}

function psst_markdown_heading(int $level, string $text, array &$context): string
{
    $inline = psst_markdown_inline(trim($text), $context);

    // GitHub-style anchor, prefixed so it cannot clash with ids of the page itself.
    $plain = strtolower(html_entity_decode(strip_tags(psst_markdown_restore($inline, $context)), ENT_QUOTES, 'UTF-8'));
    $slug = trim((string) preg_replace('/[^a-z0-9\x80-\xff_ -]/', '', $plain));
    $slug = (string) preg_replace('/ +/', '-', $slug);
    $slug = $slug !== '' ? $slug : 'section';

    if (isset($context['slugs'][$slug])) {
        $context['slugs'][$slug] += 1;
        $slug .= '-' . $context['slugs'][$slug];
    } else {
        $context['slugs'][$slug] = 0;
    }

    return sprintf('<h%d id="md-%s">%s</h%d>', $level, psst_markdown_escape($slug), $inline, $level);
}

function psst_markdown_list(array $lines, int &$index, array &$context): string
{
    $count = count($lines);
    $first = psst_markdown_list_item($lines[$index]);
    $ordered = $first['number'] !== '';
    $start = $ordered ? (int) $first['number'] : 1;
    $items = [];
    $loose = false;

    while ($index < $count) {
        $item = psst_markdown_list_item($lines[$index]);
        $sameList = $item !== null && ($ordered
            ? $item['number'] !== '' && $item['delimiter'] === $first['delimiter']
            : $item['number'] === '' && $item['marker'] === $first['marker']);
        if (!$sameList) {
            break;
        }

        $spaces = $item['spaces'] >= 1 && $item['spaces'] <= 4 ? $item['spaces'] : 1;
        $contentIndent = $item['indent'] + strlen($item['marker']) + $spaces;
        $itemLines = [$item['content']];
        $previousBlank = false;
        $index += 1;

        while ($index < $count) {
            $line = $lines[$index];
            if (trim($line) === '') {
                $itemLines[] = '';
                $previousBlank = true;
                $index += 1;
                continue;
            }

            if (strlen($line) - strlen(ltrim($line, ' ')) >= $contentIndent) {
                $itemLines[] = substr($line, $contentIndent);
                $previousBlank = false;
                $index += 1;
                continue;
            }

            if (!$previousBlank && !psst_markdown_starts_block($line)) {
                $itemLines[] = ltrim($line, ' ');
                $index += 1;
                continue;
            }

            break;
        }

        // Blank lines at the end separate items; they do not belong to this one.
        $trailingBlank = false;
        while ($itemLines !== [] && trim((string) end($itemLines)) === '') {
            array_pop($itemLines);
            $trailingBlank = true;
        }
        if (in_array('', $itemLines, true)) {
            $loose = true;
        }
        $items[] = $itemLines;

        if ($trailingBlank) {
            $next = $index < $count ? psst_markdown_list_item($lines[$index]) : null;
            $continues = $next !== null && ($ordered
                ? $next['number'] !== '' && $next['delimiter'] === $first['delimiter']
                : $next['number'] === '' && $next['marker'] === $first['marker']);
            if (!$continues) {
                break;
            }
            $loose = true;
        }
    }

    $tag = $ordered ? 'ol' : 'ul';
    $html = $ordered && $start !== 1 ? '<ol start="' . $start . '">' : '<' . $tag . '>';

    foreach ($items as $itemLines) {
        $checkbox = '';
        if (preg_match('/^\[([ xX])\][ ]+(.*)$/', $itemLines[0] ?? '', $match) === 1) {
            $checkbox = '<input type="checkbox" disabled' . ($match[1] !== ' ' ? ' checked' : '') . '> ';
            $itemLines[0] = $match[2];
        }

        $html .= '<li' . ($checkbox !== '' ? ' class="task-list-item"' : '') . '>'
            . $checkbox . psst_markdown_blocks($itemLines, $context, !$loose) . '</li>';
    }

    return $html . '</' . $tag . '>';
}

/**
 * @return string[]
 */
function psst_markdown_table_cells(string $line): array
{
    $line = trim($line);
    if ($line !== '' && $line[0] === '|') {
        $line = substr($line, 1);
    }
    if ($line !== '' && substr($line, -1) === '|' && substr($line, -2) !== '\\|') {
        $line = substr($line, 0, -1);
    }

    return array_map(static function (string $cell): string {
        return trim(str_replace('\\|', '|', $cell));
    }, preg_split('/(?<!\\\\)\|/', $line) ?: ['']);
}

/**
 * @return string[]|null alignment per column, or null if the line is not a delimiter row
 */
function psst_markdown_table_alignments(string $line, int $columns): ?array
{
    if (strpos($line, '-') === false) {
        return null;
    }

    $cells = psst_markdown_table_cells($line);
    if (count($cells) !== $columns) {
        return null;
    }

    $alignments = [];
    foreach ($cells as $cell) {
        if (preg_match('/^(:?)-+(:?)$/', $cell, $match) !== 1) {
            return null;
        }
        $alignments[] = $match[1] !== '' && $match[2] !== '' ? 'center' : ($match[2] !== '' ? 'right' : ($match[1] !== '' ? 'left' : ''));
    }

    return $alignments;
}

function psst_markdown_table(array $header, array $alignments, array $rows, array &$context): string
{
    // Classes instead of style attributes: the page's CSP does not allow inline styles.
    $classes = ['left' => ' class="text-start"', 'center' => ' class="text-center"', 'right' => ' class="text-end"', '' => ''];

    $html = '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr>';
    foreach ($header as $column => $cell) {
        $html .= '<th' . $classes[$alignments[$column]] . '>' . psst_markdown_inline($cell, $context) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($alignments as $column => $alignment) {
            $html .= '<td' . $classes[$alignment] . '>' . psst_markdown_inline($row[$column] ?? '', $context) . '</td>';
        }
        $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
}

/**
 * Only targets that cannot run script: http(s), mailto, in-page anchors, and raster
 * data: images. Anything else is rendered as plain text.
 */
function psst_markdown_safe_url(string $url, bool $image): ?string
{
    $url = trim($url);

    if (preg_match('#^https?://[^\s]+$#i', $url) === 1) {
        return $url;
    }

    if ($image) {
        return preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#i', $url) === 1 ? $url : null;
    }

    if (preg_match('/^mailto:[^\s]+$/i', $url) === 1) {
        return $url;
    }

    if ($url !== '' && $url[0] === '#') {
        return '#md-' . substr($url, 1);
    }

    return null;
}

function psst_markdown_link(string $url, string $labelHtml, string $title): string
{
    $safe = psst_markdown_safe_url($url, false);
    if ($safe === null) {
        return $labelHtml;
    }

    $attributes = ' href="' . psst_markdown_escape($safe) . '"';
    if ($title !== '') {
        $attributes .= ' title="' . psst_markdown_escape($title) . '"';
    }
    if ($safe[0] !== '#') {
        $attributes .= ' target="_blank" rel="nofollow noopener noreferrer"';
    }

    return '<a' . $attributes . '>' . $labelHtml . '</a>';
}

function psst_markdown_image(string $url, string $alt, string $title): string
{
    $alt = trim((string) preg_replace('/' . PSST_MD_TOKEN . '\d+' . PSST_MD_TOKEN . '|[*_`~]/', '', $alt));
    $safe = psst_markdown_safe_url($url, true);
    if ($safe === null) {
        return psst_markdown_escape($alt);
    }

    $titleAttribute = $title !== '' ? ' title="' . psst_markdown_escape($title) . '"' : '';

    return '<img src="' . psst_markdown_escape($safe) . '" alt="' . psst_markdown_escape($alt) . '"' . $titleAttribute
        . ' loading="lazy" referrerpolicy="no-referrer">';
}

function psst_markdown_inline(string $text, array &$context): string
{
    $store = static function (string $html) use (&$context): string {
        return psst_markdown_store($context, $html);
    };

    // Hard line break: two trailing spaces or a backslash before the newline.
    $text = psst_markdown_replace('/(?: {2,}|\\\\)\n/', $store('<br>') . "\n", $text);

    // Code spans first: nothing inside them is formatted.
    $text = psst_markdown_replace('/(`+)(.+?)(?<!`)\1(?!`)/s', static function (array $match) use ($store): string {
        $code = str_replace("\n", ' ', $match[2]);
        if (strlen($code) > 2 && $code[0] === ' ' && substr($code, -1) === ' ' && trim($code) !== '') {
            $code = substr($code, 1, -1);
        }
        return $store('<code>' . psst_markdown_escape($code) . '</code>');
    }, $text);

    $text = psst_markdown_replace('/\\\\([!"#$%&\'()*+,\-.\/:;<=>?@\[\\\\\]^_`{|}~])/', static function (array $match) use ($store): string {
        return $store(psst_markdown_escape($match[1]));
    }, $text);

    $text = psst_markdown_replace('/<((?:https?:\/\/|mailto:)[^\s<>]+)>/i', static function (array $match) use ($store): string {
        return $store(psst_markdown_link($match[1], psst_markdown_escape($match[1]), ''));
    }, $text);

    $label = '\[((?:[^\[\]]|\[[^\[\]]*\])*)\]';
    $destination = '\(\s*<?((?:[^\s()<>]|\([^\s()<>]*\))*)>?(?:\s+(?:"([^"]*)"|\'([^\']*)\'))?\s*\)';
    $title = static function (array $match): string {
        return ($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '');
    };

    $text = psst_markdown_replace('/!' . $label . $destination . '/', static function (array $match) use ($store, $title): string {
        return $store(psst_markdown_image($match[2], $match[1], $title($match)));
    }, $text);

    $text = psst_markdown_replace('/!' . $label . '\[([^\[\]]*)\]/', static function (array $match) use ($store, &$context): string {
        $reference = $context['refs'][psst_markdown_reference_label($match[2] !== '' ? $match[2] : $match[1])] ?? null;
        return $reference === null ? $match[0] : $store(psst_markdown_image($reference['url'], $match[1], $reference['title']));
    }, $text);

    $text = psst_markdown_replace('/' . $label . $destination . '/', static function (array $match) use ($store, $title, &$context): string {
        return $store(psst_markdown_link($match[2], psst_markdown_inline($match[1], $context), $title($match)));
    }, $text);

    $text = psst_markdown_replace('/' . $label . '\[([^\[\]]*)\]/', static function (array $match) use ($store, &$context): string {
        $reference = $context['refs'][psst_markdown_reference_label($match[2] !== '' ? $match[2] : $match[1])] ?? null;
        return $reference === null
            ? $match[0]
            : $store(psst_markdown_link($reference['url'], psst_markdown_inline($match[1], $context), $reference['title']));
    }, $text);

    // Bare URLs; trailing punctuation belongs to the sentence, not the link.
    $text = psst_markdown_replace(
        '/(?<![\w\/@:."\'=-])(https?:\/\/[^\s<' . PSST_MD_TOKEN . ']*[^\s<.,:;"\')\]!?*_~' . PSST_MD_TOKEN . '])/i',
        static function (array $match) use ($store): string {
            return $store(psst_markdown_link($match[1], psst_markdown_escape($match[1]), ''));
        },
        $text
    );

    // From here on the text is escaped HTML; the patterns below only wrap it in tags.
    $text = psst_markdown_escape($text);
    $text = psst_markdown_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text);
    $text = psst_markdown_replace('/(?<![A-Za-z0-9])__(?=\S)(.+?)(?<=\S)__(?![A-Za-z0-9])/s', '<strong>$1</strong>', $text);
    $text = psst_markdown_replace('/\*(?=\S)(.+?)(?<=\S)\*/s', '<em>$1</em>', $text);
    $text = psst_markdown_replace('/(?<![A-Za-z0-9])_(?=\S)(.+?)(?<=\S)_(?![A-Za-z0-9])/s', '<em>$1</em>', $text);

    return psst_markdown_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $text);
}
