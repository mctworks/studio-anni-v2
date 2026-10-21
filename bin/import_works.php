<?php

declare(strict_types=1);

// php bin/import_works.php            dry run: prints the report, writes nothing
// php bin/import_works.php --commit   writes pieces, piece_images and piece_categories
// Reads the V1 dump from storage/works.sql (or the path given as the first non-flag argument).
// Images must already be in public/media/pieces/.

use App\Config;
use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv::createImmutable($root)->safeLoad();
$pdo = Database::connect(new Config($root, $root . '/public', $_ENV));
$commit = in_array('--commit', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => ! str_starts_with($a, '--')));
$dumpFile = $args[0] ?? $root . '/storage/works.sql';
$publicDir = is_dir($root . '/../public') ? (string) realpath($root . '/../public') : $root . '/public';
$imageDir = $publicDir . '/media/pieces';

const TYPE_MAP = [
    'animal' => ['pets-animals'],
    'animals' => ['pets-animals'],
    'pet' => ['pets-animals'],
    'fantasy' => ['fantasy'],
    'human portrait' => ['people'],
    'portrait' => ['people'],
    'figure' => ['people', 'hands'],
    'cityscape' => ['land-cityscapes'],
    'landscape' => ['land-cityscapes'],
    'nature' => ['space-nature'],
    'discount' => [],
    'surreal' => [],
];

/** @return list<array<string, ?string>> one row per tuple in the dump's INSERT statement */
function readDump(string $file): array
{
    $cols = ['pieceID', 'name', 'size', 'canvas_size', 'medium', 'price', 'shipping', 'special', 'type', 'date', 'slideimg', 'image', 'side_a', 'side_b', 'specialstatus', 'society6'];
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Cannot read $file");
    }

    $rows = [];
    foreach ($lines as $line) {
        $line = rtrim($line, "\r");
        if (! preg_match('/^\(\d+, /', $line)) {
            continue;
        }
        $line = substr($line, 1, -2);
        $n = strlen($line);
        $vals = [];
        $i = 0;
        while ($i < $n) {
            if ($line[$i] === "'") {
                $i++;
                $s = '';
                while ($i < $n && $line[$i] !== "'") {
                    if ($line[$i] === '\\' && $i + 1 < $n) {
                        $i++;
                        $s .= ['n' => "\n", 'r' => "\r", '0' => "\0"][$line[$i]] ?? $line[$i];
                    } else {
                        $s .= $line[$i];
                    }
                    $i++;
                }
                $i++;
                $vals[] = $s;
            } else {
                $j = strpos($line, ',', $i);
                $j = $j === false ? $n : $j;
                $t = trim(substr($line, $i, $j - $i));
                $vals[] = $t === 'NULL' ? null : $t;
                $i = $j;
            }
            while ($i < $n && ($line[$i] === ',' || $line[$i] === ' ')) {
                $i++;
            }
        }
        if (count($vals) !== count($cols)) {
            throw new RuntimeException('Unexpected column count in dump row: ' . substr($line, 0, 60));
        }
        $rows[] = array_combine($cols, $vals);
    }
    if ($rows === []) {
        throw new RuntimeException("No rows found in $file");
    }

    return $rows;
}

/** @return array{0: string, 1: string, 2: ?string}|null status, origin, status_note */
function mapStatus(int $special, ?string $raw): ?array
{
    $s = trim((string) $raw);
    if ($s === '') {
        return $special === 0 ? ['available', 'original', null] : null;
    }
    if (strcasecmp($s, 'Privately Owned') === 0) {
        return ['sold', 'original', null];
    }
    if (preg_match('/^past\s+"?comm?ission"?\s*[,(]?\s*(.*?)\)?$/i', $s, $m)) {
        return ['sold', 'commission', $m[1] !== '' ? $m[1] : null];
    }
    if (strcasecmp($s, 'Pet Portrait') === 0 && $special === 1) {
        return ['sold', 'commission', null];
    }
    if (preg_match('/^licensed to /i', $s)) {
        return ['sold', 'original', $s];
    }
    if (strcasecmp($s, 'In Studio') === 0) {
        return ['in_studio', 'original', null];
    }
    if (preg_match('/^in studio\s*-\s*(.+)$/i', $s, $m)) {
        return ['in_studio', 'original', $m[1]];
    }
    if (preg_match('/^currently on display at\s+(.+?)\.?$/i', $s, $m)) {
        return ['on_display', 'original', $m[1]];
    }

    return null;
}

/** @return array{0: float, 1: float}|null long, short */
function parseSize(?string $s): ?array
{
    if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*"?\s*x\s*(\d+(?:\.\d+)?)\s*"?\s*$/i', (string) $s, $m)) {
        return null;
    }

    return [max((float) $m[1], (float) $m[2]), min((float) $m[1], (float) $m[2])];
}

function num(float $v): string
{
    return rtrim(rtrim(sprintf('%.2f', $v), '0'), '.');
}

/** "100.00" to 10000 without touching floats. */
function cents(string $price): int
{
    [$d, $f] = array_pad(explode('.', $price), 2, '0');

    return (int) $d * 100 + (int) str_pad(substr($f, 0, 2), 2, '0');
}

function slugify(string $s): string
{
    $s = strtolower(str_replace("'", '', $s));
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');

    return substr($s !== '' ? $s : 'piece', 0, 100);
}

function medium(?string $m): ?string
{
    $m = trim((string) $m);

    return $m === '' ? null : str_replace(' On ', ' on ', (string) preg_replace('/Acrylicc/i', 'Acrylic', $m));
}

$sizes = [];
foreach ($pdo->query('SELECT id, long_in, short_in FROM canvas_sizes') as $r) {
    $sizes[sprintf('%.2f|%.2f', $r['long_in'], $r['short_in'])] = (int) $r['id'];
}

$plan = [];
$problems = [];
$warnings = [];
$nonAscii = [];
$tally = [];
$newSizes = [];
$slugs = [];
$skipped = 0;
$untagged = 0;

foreach (readDump($dumpFile) as $w) {
    $id = (int) $w['pieceID'];
    if ($w['type'] === 'Placeholder' || $w['canvas_size'] === 'N/A') {
        $skipped++;
        continue;
    }

    if (preg_match('/[^\x00-\x7F]/', $w['name'] . (string) $w['medium'] . (string) $w['specialstatus'])) {
        $nonAscii[] = $id;
    }

    $state = mapStatus((int) $w['special'], $w['specialstatus']);
    if ($state === null) {
        $problems[] = "#$id unmapped status: " . var_export($w['specialstatus'], true);
        continue;
    }
    [$status, $origin, $note] = $state;

    $cats = TYPE_MAP[strtolower(trim($w['type']))] ?? null;
    if ($cats === null) {
        $problems[] = "#$id unmapped type: {$w['type']}";
        continue;
    }
    if ($cats === []) {
        $untagged++;
    }

    $dims = parseSize($w['canvas_size']);
    if ($dims === null) {
        $problems[] = "#$id unparseable size: {$w['canvas_size']}";
        continue;
    }
    $key = sprintf('%.2f|%.2f', ...$dims);
    if (! isset($sizes[$key])) {
        $newSizes[$key] = $dims;
    }

    $files = [['main', (string) $w['image']]];
    foreach (['side_a', 'side_b'] as $col) {
        if (trim((string) $w[$col]) !== '') {
            $files[] = ['angle', (string) $w[$col]];
        }
    }
    $images = [];
    foreach ($files as $order => [$kind, $base]) {
        $name = trim($base) . '.jpg';
        if (is_file("$imageDir/$name")) {
            $images[] = [$name, $kind, $order];
        } elseif ($kind === 'main') {
            $problems[] = "#$id missing main image: $name";
        } else {
            $warnings[] = "#$id missing extra image, skipped: $name";
        }
    }

    $slug = slugify($w['name']);
    if (isset($slugs[$slug])) {
        $slug .= "-$id";
    }
    $slugs[$slug] = true;

    $plan[] = [
        'id' => $id,
        'slug' => $slug,
        'title' => trim($w['name']),
        'medium' => medium($w['medium']),
        'size' => $key,
        'price' => $status === 'available' ? cents((string) $w['price']) : 0,
        'status' => $status,
        'note' => $note,
        'origin' => $origin,
        'date' => (string) $w['date'],
        'cats' => $cats,
        'images' => $images,
    ];
    $tally["$status/$origin"] = ($tally["$status/$origin"] ?? 0) + 1;
}

echo count($plan) . " pieces to import, $skipped skipped (placeholder)\n";
ksort($tally);
foreach ($tally as $k => $n) {
    echo "  $k: $n\n";
}
echo "untagged (no category): $untagged\n";
echo 'new sizes (offered = 0): ' . ($newSizes === [] ? 'none' : implode(', ', array_keys($newSizes))) . "\n";
echo 'rows with non-ASCII text: ' . ($nonAscii === [] ? 'none' : implode(', ', $nonAscii)) . "\n";
foreach ($warnings as $line) {
    echo "warning: $line\n";
}
foreach ($problems as $line) {
    echo "PROBLEM: $line\n";
}

if ($problems !== []) {
    echo count($problems) . " problem(s). Nothing is written until these are resolved.\n";
    exit(1);
}
if (! $commit) {
    echo "Dry run only. Re-run with --commit to write.\n";
    exit(0);
}
if ((int) $pdo->query('SELECT COUNT(*) FROM pieces')->fetchColumn() > 0) {
    fwrite(STDERR, "pieces is not empty; refusing to import.\n");
    exit(1);
}

$catIds = [];
foreach ($pdo->query('SELECT slug, id FROM categories') as $r) {
    $catIds[$r['slug']] = (int) $r['id'];
}

$pdo->beginTransaction();
try {
    $addSize = $pdo->prepare('INSERT INTO canvas_sizes (long_in, short_in, label, offered) VALUES (?, ?, ?, 0)');
    foreach ($newSizes as $key => [$long, $short]) {
        $addSize->execute([$long, $short, num($short) . '" x ' . num($long) . '"']);
        $sizes[$key] = (int) $pdo->lastInsertId();
    }

    $addPiece = $pdo->prepare(
        'INSERT INTO pieces (id, slug, title, medium, canvas_size_id, price_cents, status, status_note, origin, painted_on, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $addImage = $pdo->prepare('INSERT INTO piece_images (piece_id, filename, kind, alt_text, sort_order) VALUES (?, ?, ?, ?, ?)');
    $addCat = $pdo->prepare('INSERT INTO piece_categories (piece_id, category_id) VALUES (?, ?)');

    foreach ($plan as $p) {
        $addPiece->execute([
            $p['id'], $p['slug'], $p['title'], $p['medium'], $sizes[$p['size']], $p['price'],
            $p['status'], $p['note'], $p['origin'], $p['date'], $p['date'] . ' 00:00:00',
        ]);
        foreach ($p['images'] as [$name, $kind, $order]) {
            $addImage->execute([$p['id'], $name, $kind, $p['title'], $order]);
        }
        foreach ($p['cats'] as $slug) {
            if (! isset($catIds[$slug])) {
                throw new RuntimeException("Missing category: $slug");
            }
            $addCat->execute([$p['id'], $catIds[$slug]]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

echo 'Imported ' . count($plan) . " pieces.\n";
