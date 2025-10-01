<?php
// index.php
declare(strict_types=1);

// ---------- CSV -> JSON Funktion aus dem vorigen Schritt (leicht gekürzt) ----------
function csv_to_json(string $source, array $opts = []): string
{
    $o = array_merge([
        'delimiter'  => null,
        'enclosure'  => '"',
        'escape'     => '\\',
        'headers'    => true,
        'cast'       => true,
        'skip_empty' => true,
        'pretty'     => false,
    ], $opts);

    $makeReader = function(string $csv): SplFileObject {
        $tmp = new SplTempFileObject();
        $tmp->fwrite($csv);
        $tmp->rewind();
        return $tmp;
    };

    if (is_file($source)) {
        $fo = new SplFileObject($source, 'r');
    } else {
        $fo = $makeReader($source);
    }

    $fo->setFlags(
        SplFileObject::READ_CSV
        | SplFileObject::READ_AHEAD
        | ($o['skip_empty'] ? SplFileObject::SKIP_EMPTY : 0)
        | SplFileObject::DROP_NEW_LINE
    );
    $fo->setCsvControl(',', $o['enclosure'], $o['escape']);

    // Erste nicht-leere Zeile für Delimiter-Check
    $firstLine = null;
    foreach ($fo as $row) {
        if ($row === null || $row === [null]) continue;
        $raw = is_array($row) ? implode(",", $row) : (string)$row;
        if ($raw !== '') { $firstLine = $raw; break; }
    }
    if ($firstLine !== null && str_starts_with($firstLine, "\xEF\xBB\xBF")) {
        $firstLine = substr($firstLine, 3);
    }
    if ($o['delimiter'] === null) {
        $candidates = [",", ";", "\t", "|"];
        $counts = [];
        foreach ($candidates as $c) { $counts[$c] = substr_count($firstLine ?? '', $c); }
        arsort($counts);
        $o['delimiter'] = key($counts);
    }
    $fo->setCsvControl($o['delimiter'], $o['enclosure'], $o['escape']);
    $fo->rewind();

    $cast = function ($v) use ($o) {
        if (!$o['cast'] || !is_string($v)) return $v;
        $t = trim($v);
        if ($t === '') return '';
        $low = strtolower($t);
        if ($low === 'null')  return null;
        if ($low === 'true')  return true;
        if ($low === 'false') return false;
        if (is_numeric(str_replace(',', '.', $t))) {
            $tNorm = str_replace(',', '.', $t);
            return str_contains($tNorm, '.') ? (float)$tNorm : (int)$tNorm;
        }
        return $v;
    };

    $headers = [];
    $data = [];
    $rowIndex = 0;

    foreach ($fo as $row) {
        if ($row === null || $row === [null]) continue;
        if ($rowIndex === 0 && isset($row[0]) && is_string($row[0])) {
            $row[0] = ltrim($row[0], "\xEF\xBB\xBF");
        }

        if ($o['headers'] && $rowIndex === 0) {
            $headers = array_map(fn($h) => ($h = trim((string)$h)) === '' ? null : $h, $row);
            $seen = [];
            foreach ($headers as $i => $h) {
                if ($h === null) $h = "col" . ($i + 1);
                $base = $h; $k = 1;
                while (isset($seen[$h])) { $h = $base . "_" . (++$k); }
                $headers[$i] = $h; $seen[$h] = true;
            }
            $rowIndex++; continue;
        }
        if (!$o['headers'] && $rowIndex === 0) {
            $headers = array_map(fn($i) => "col" . ($i + 1), array_keys($row));
        }

        $colCount = max(count($headers), count($row));
        $row = array_pad($row, $colCount, null);
        $headers = array_pad($headers, $colCount, null);
        foreach ($headers as $i => $h) { if ($h === null) $headers[$i] = "col" . ($i + 1); }

        $assoc = [];
        foreach ($headers as $i => $h) { $assoc[$h] = $cast($row[$i]); }
        $data[] = $assoc;
        $rowIndex++;
    }

    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if ($o['pretty']) $jsonFlags |= JSON_PRETTY_PRINT;
    return json_encode($data, $jsonFlags);
}

// ---------- Request-Handling ----------
$error = null;
$jsonOut = '';
$csvPreview = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $delimiterMap = [
            'auto' => null, 'comma' => ',', 'semicolon' => ';', 'tab' => "\t", 'pipe' => '|'
        ];
        $delimiterKey = $_POST['delimiter'] ?? 'auto';
        $opts = [
            'delimiter'  => $delimiterMap[$delimiterKey] ?? null,
            'headers'    => isset($_POST['headers']),
            'cast'       => isset($_POST['cast']),
            'pretty'     => isset($_POST['pretty']),
            'skip_empty' => true,
        ];

        $csvText = trim($_POST['csv_text'] ?? '');
        $useUpload = isset($_FILES['csv_file']) && ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) && $_FILES['csv_file']['size'] > 0;

        if (!$useUpload && $csvText === '') {
            throw new RuntimeException('Bitte lade eine CSV-Datei hoch oder füge CSV-Text ein.');
        }

        if ($useUpload) {
            // Optional: einfache Größenbremse (10 MB)
            if ($_FILES['csv_file']['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('Datei ist größer als 10 MB.');
            }
            $tmpPath = $_FILES['csv_file']['tmp_name'];
            $jsonOut = csv_to_json($tmpPath, $opts);
            // Fürs Preview: nur die ersten Zeilen lesen
            $csvPreview = file_get_contents($tmpPath, false, null, 0, 2000) ?: '';
        } else {
            $jsonOut = csv_to_json($csvText, $opts);
            $csvPreview = mb_substr($csvText, 0, 2000);
        }

        if ($jsonOut === false) {
            throw new RuntimeException('JSON-Erzeugung ist fehlgeschlagen.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CSV → JSON Konverter (PHP)</title>
<style>
    :root { color-scheme: light dark; }
    body { font-family: system-ui, sans-serif; margin: 2rem; line-height: 1.4; }
    .wrap { max-width: 980px; margin: 0 auto; }
    h1 { margin-bottom: .25rem; }
    .card { border: 1px solid var(--line, #ccc); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .row > div { display: flex; flex-direction: column; }
    label { font-weight: 600; margin: .25rem 0; }
    input[type="file"], select, textarea { padding: .5rem; font: inherit; }
    textarea { width: 100%; min-height: 200px; white-space: pre; }
    .opts { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: .5rem; }
    .opts label { font-weight: 500; }
    .actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .5rem; }
    button, .btn { padding: .6rem .9rem; border-radius: 8px; border: 1px solid #999; background: #f6f6f6; cursor: pointer; }
    button:hover, .btn:hover { filter: brightness(0.97); }
    .error { color: #b00020; font-weight: 600; }
    .muted { color: #666; font-size: .9rem; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
</style>
</head>
<body>
<div class="wrap">
    <h1>CSV → JSON Konverter</h1>
    <p class="muted">Upload ODER CSV-Text einfügen. Einstellungen wählen, dann <b>Konvertieren</b>.</p>

    <form class="card" method="post" enctype="multipart/form-data">
        <div class="row">
            <div>
                <label for="csv_file">CSV-Datei hochladen</label>
                <input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv">
                <small class="muted">Max. 10 MB. Alternativ: Text rechts einfügen.</small>
            </div>
            <div>
                <label for="csv_text">…oder CSV-Text</label>
                <textarea id="csv_text" name="csv_text" placeholder="id;name;active;price&#10;1;Apfel;true;1,99&#10;2;Birne;false;2,49"><?= htmlspecialchars($csvPreview ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </div>
        </div>

        <div class="row">
            <div>
                <label for="delimiter">Trennzeichen</label>
                <select id="delimiter" name="delimiter">
                    <?php
                    $sel = $_POST['delimiter'] ?? 'auto';
                    $opts = ['auto'=>'Auto erkennen', 'comma'=>'Komma (,)', 'semicolon'=>'Semikolon (;)', 'tab'=>'Tab', 'pipe'=>'Pipe (|)'];
                    foreach ($opts as $k=>$v) {
                        $s = $sel === $k ? 'selected' : '';
                        echo "<option value=\"$k\" $s>$v</option>";
                    }
                    ?>
                </select>
                <div class="opts">
                    <label><input type="checkbox" name="headers" <?= isset($_POST['headers']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>> Erste Zeile sind Spaltennamen</label>
                    <label><input type="checkbox" name="cast"    <?= isset($_POST['cast'])    || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>> Werte typisieren (true/false/null/Zahlen)</label>
                    <label><input type="checkbox" name="pretty"  <?= isset($_POST['pretty'])  || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>> Pretty-Print</label>
                </div>
            </div>
            <div class="actions" style="align-items:flex-end; justify-content:flex-start;">
                <button type="submit">Konvertieren</button>
                <button type="button" id="downloadBtn"<?= $jsonOut ? '' : ' disabled' ?>>JSON herunterladen</button>
                <button type="button" id="copyBtn"<?= $jsonOut ? '' : ' disabled' ?>>JSON kopieren</button>
            </div>
        </div>

        <?php if ($error): ?>
            <p class="error">⚠️ <?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
    </form>

    <div class="card">
        <label for="json_out">Ergebnis (JSON)</label>
        <textarea id="json_out" class="mono" readonly placeholder="Hier erscheint das JSON …"><?= htmlspecialchars($jsonOut ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        <small class="muted">Tipp: Mit Pretty-Print liest sich das Ergebnis leichter.</small>
    </div>
</div>

<script>
(function(){
    const $json = document.getElementById('json_out');
    const $dl = document.getElementById('downloadBtn');
    const $cp = document.getElementById('copyBtn');

    function downloadJSON() {
        const blob = new Blob([$json.value || '[]'], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'converted.json';
        document.body.appendChild(a);
        a.click();
        URL.revokeObjectURL(url);
        a.remove();
    }
    function copyJSON() {
        navigator.clipboard.writeText($json.value || '[]')
            .then(() => { $cp.textContent = 'Kopiert!'; setTimeout(()=> $cp.textContent='JSON kopieren', 1200); })
            .catch(() => { alert('Konnte nicht kopieren.'); });
    }
    if ($dl) $dl.addEventListener('click', downloadJSON);
    if ($cp) $cp.addEventListener('click', copyJSON);
})();
</script>
</body>
</html>
