<?php

/**
 * Regenerates tests/Fixtures/player_titles.php from the real WordPress SQL
 * dump, wordpress_sql/entrered_wp257.sql.
 *
 * This is a data-generation tool, not application code: it has no
 * dependency-injection seam, no tests of its own, and is reviewed by
 * re-running it and diffing the output — not by reading it line by line
 * (design §9/§10, tasks 2.2).
 *
 * Filters `wp_posts` rows to `post_type = 'sp_player' AND post_status =
 * 'publish'`, extracting only `ID` and `post_title`. Attachment rows whose
 * title happens to echo a player's name (e.g. ids 11919, 21559) are
 * excluded here because their post_type is `attachment`, not because of
 * their post_status — no `sp_player` row in the directory has a status
 * other than `publish` (see design §3, "sixth shape" correction).
 *
 * Usage: php tools/extract-player-titles.php
 */

declare(strict_types=1);

$root       = dirname(__DIR__, 3);
$sqlPath    = $root . '/wordpress_sql/entrered_wp257.sql';
$outputPath = __DIR__ . '/../tests/Fixtures/player_titles.php';

if (!is_file($sqlPath)) {
    fwrite(STDERR, "SQL dump not found at {$sqlPath}\n");
    exit(1);
}

/**
 * Column order of every `wp_posts` INSERT statement in this dump, verified
 * against the dump's own INSERT header line.
 */
const WP_POSTS_COLUMNS = [
    'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content',
    'post_title', 'post_excerpt', 'post_status', 'comment_status',
    'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged',
    'post_modified', 'post_modified_gmt', 'post_content_filtered',
    'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type',
    'comment_count',
];

/**
 * Parses one MySQL row-tuple line, e.g. "(1, 'a', NULL, 'b'),", respecting
 * quoted strings and backslash escaping, and returns the raw field values
 * in column order (quotes stripped, escapes resolved). Trailing `),` or
 * `);` must already be stripped by the caller along with the leading `(`.
 *
 * @return string[]|null
 */
function parseRowTuple(string $body): ?array {
    $fields  = [];
    $current = '';
    $inQuote = false;
    $len     = strlen($body);

    for ($i = 0; $i < $len; $i++) {
        $char = $body[$i];

        if ($inQuote) {
            if ($char === '\\' && $i + 1 < $len) {
                $current .= $body[$i + 1];
                $i++;
                continue;
            }
            if ($char === "'") {
                $inQuote = false;
                continue;
            }
            $current .= $char;
            continue;
        }

        if ($char === "'") {
            $inQuote = true;
            continue;
        }

        if ($char === ',') {
            $fields[] = trim($current);
            $current  = '';
            continue;
        }

        $current .= $char;
    }
    $fields[] = trim($current);

    return $fields;
}

$idIndex       = array_search('ID', WP_POSTS_COLUMNS, true);
$titleIndex    = array_search('post_title', WP_POSTS_COLUMNS, true);
$statusIndex   = array_search('post_status', WP_POSTS_COLUMNS, true);
$typeIndex     = array_search('post_type', WP_POSTS_COLUMNS, true);
$expectedCols  = count(WP_POSTS_COLUMNS);

$players  = [];
$inBlock  = false;

$handle = fopen($sqlPath, 'r');
if ($handle === false) {
    fwrite(STDERR, "Could not open {$sqlPath}\n");
    exit(1);
}

while (($line = fgets($handle)) !== false) {
    if (!$inBlock) {
        if (str_starts_with($line, 'INSERT INTO `wp_posts`')) {
            $inBlock = true;
        }
        continue;
    }

    $trimmed = rtrim($line, "\r\n");
    $trimmed = rtrim($trimmed);

    if ($trimmed === '' ) {
        continue;
    }

    $endsStatement = str_ends_with($trimmed, ');');
    $endsRow       = str_ends_with($trimmed, '),');

    if (!$endsStatement && !$endsRow) {
        // A new INSERT INTO wp_posts (...) VALUES header line, or something
        // unexpected — either way this line is not a row tuple, and the
        // block continues only if it is itself a new header.
        if (str_starts_with($trimmed, 'INSERT INTO `wp_posts`')) {
            continue;
        }
        $inBlock = false;
        continue;
    }

    if ($endsStatement) {
        $inBlock = false;
    }

    // Both the mid-statement row terminator ")," and the final-row
    // terminator ");" end in exactly one character after the row's own
    // closing paren — strip only that one trailing character either way.
    $body = substr($trimmed, 0, -1);

    if (!str_starts_with($body, '(') || !str_ends_with($body, ')')) {
        continue;
    }
    $body = substr($body, 1, -1);

    $fields = parseRowTuple($body);
    if ($fields === null || count($fields) !== $expectedCols) {
        continue;
    }

    if ($fields[$typeIndex] !== 'sp_player' || $fields[$statusIndex] !== 'publish') {
        continue;
    }

    $players[] = [
        'id'    => (int) $fields[$idIndex],
        'title' => $fields[$titleIndex],
    ];
}

fclose($handle);

usort($players, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

$count = count($players);

$export = "<?php\n\n";
$export .= "declare(strict_types=1);\n\n";
$export .= "/**\n";
$export .= " * Generated fixture — every published sp_player title in the real directory.\n";
$export .= " *\n";
$export .= " * Regenerate with: php tools/extract-player-titles.php\n";
$export .= " * Source: wordpress_sql/entrered_wp257.sql (post_type = 'sp_player' AND post_status = 'publish')\n";
$export .= " * Row count: {$count}\n";
$export .= " *\n";
$export .= " * This is generated data, reviewed by regenerating and diffing — not read line by line.\n";
$export .= " *\n";
$export .= " * @return array<int, array{id: int, title: string}>\n";
$export .= " */\n";
$export .= "return [\n";
foreach ($players as $player) {
    $export .= '    [\'id\' => ' . $player['id'] . ', \'title\' => ' . var_export($player['title'], true) . "],\n";
}
$export .= "];\n";

file_put_contents($outputPath, $export);

fwrite(STDOUT, "Wrote {$count} players to {$outputPath}\n");
