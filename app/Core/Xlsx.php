<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Classeur Excel (.xlsx) « exécutif », sans dépendance : une ou plusieurs feuilles de tableau.
 * - Cellules typées (montants en centimes → nombre à 2 décimales, négatifs en rouge ; quantités, taux, %, vraies dates).
 * - Bandeau facultatif (société, titre, sous-titre, mention d'édition), en-tête coloré figé, zébrage, filets,
 *   filtre automatique, ligne de total en formules SOUS.TOTAL (suivent le filtre), mise en page paysage
 *   ajustée à la largeur, en-tête répété à l'impression, pied de page numéroté ; logo de la société (PNG ou JPEG)
 *   en haut à droite du bandeau.
 * Le texte est toujours écrit comme texte, jamais comme formule.
 */
final class Xlsx
{
    public const TEXT = 'text';
    public const INT = 'int';
    public const MONEY = 'money';    // entier en centimes
    public const QTY = 'qty';        // entier en millièmes
    public const RATE = 'rate';      // entier en millionièmes (taux de change)
    public const PERCENT = 'percent'; // entier en points de base (1 % = 100)
    public const DATE = 'date';      // « Y-m-d » ou « Y-m-d H:i:s »

    private const TYPES = [self::TEXT, self::INT, self::MONEY, self::QTY, self::RATE, self::PERCENT, self::DATE];
    private const NUMFMT = [self::TEXT => 0, self::INT => 3, self::MONEY => 164, self::QTY => 165, self::RATE => 166, self::PERCENT => 167, self::DATE => 168];

    // Styles fixes, puis pour chaque type : normal, zébré, total (voir styles()).
    private const S_HEADER = 1;
    private const S_TITLE = 2;
    private const S_COMPANY = 3;
    private const S_META = 4;
    private const S_FIRST_TYPED = 5;

    private const BRAND = 'FF0E4D64';
    private const ZEBRA = 'FFF6F8FA';
    private const SOFT = 'FFE8EEF6';
    private const RULE = 'FFD5DBE3';

    /** @var list<array{name: string, columns: list<array{label: string, type: string, width?: int, total?: bool}>, rows: list<list<mixed>>, meta: array<string, string>}> */
    private array $sheets = [];

    /** @var array{bytes: string, ext: string, width: int, height: int}|null */
    private ?array $logo = null;

    /** Logo affiché dans le bandeau des feuilles ; ignoré si le format n'est pas lu par Excel (PNG, JPEG). */
    public function logo(string $bytes, string $mime): self
    {
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpeg'][$mime] ?? null;
        $size = $ext !== null ? @getimagesizefromstring($bytes) : false;
        if ($ext !== null && $size !== false && $size[0] > 0 && $size[1] > 0) {
            $this->logo = ['bytes' => $bytes, 'ext' => $ext, 'width' => (int) $size[0], 'height' => (int) $size[1]];
        }
        return $this;
    }

    /**
     * @param list<array{label: string, type?: string, width?: int, total?: bool}> $columns total : ligne de total (par défaut pour les montants)
     * @param iterable<list<mixed>> $rows valeurs brutes, dans l'ordre des colonnes ; null = cellule vide
     * @param array{company?: string, title?: string, subtitle?: string, generated?: string, total_label?: string} $meta bandeau
     */
    public function sheet(string $name, array $columns, iterable $rows, array $meta = []): self
    {
        $name = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name), 0, 31);
        $this->sheets[] = [
            'name' => $name !== '' ? $name : 'Feuille ' . (count($this->sheets) + 1),
            'columns' => array_map(static fn (array $c): array => $c + ['type' => self::TEXT, 'total' => ($c['type'] ?? self::TEXT) === self::MONEY], $columns),
            'rows' => is_array($rows) ? array_values($rows) : iterator_to_array($rows, false),
            'meta' => $meta,
        ];
        return $this;
    }

    /** Contenu binaire du fichier .xlsx. */
    public function build(): string
    {
        if ($this->sheets === []) {
            $this->sheet('Feuille 1', [], []);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer le classeur Excel.');
        }
        $layouts = array_map(self::layout(...), $this->sheets);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook($layouts));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        foreach ($this->sheets as $i => $sheet) {
            $drawing = $this->hasLogo($layouts[$i]);
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', self::worksheet($sheet, $layouts[$i], $drawing));
            if ($drawing) {
                $no = $i + 1;
                $zip->addFromString('xl/worksheets/_rels/sheet' . $no . '.xml.rels', self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing' . $no . '.xml"/></Relationships>');
                $zip->addFromString('xl/drawings/drawing' . $no . '.xml', $this->drawing(count($sheet['columns'])));
                $zip->addFromString('xl/drawings/_rels/drawing' . $no . '.xml.rels', self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.' . $this->logo['ext'] . '"/></Relationships>');
            }
        }
        if ($this->logo !== null) {
            $zip->addFromString('xl/media/logo.' . $this->logo['ext'], $this->logo['bytes']);
        }
        $zip->close();
        $body = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $body;
    }

    private const XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    /**
     * Position des blocs d'une feuille : lignes du bandeau, ligne d'en-tête, première / dernière ligne de données.
     *
     * @return array{band: list<array{text: string, style: int, height: int}>, header: int, first: int, last: int, totals: bool}
     */
    private static function layout(array $sheet): array
    {
        $meta = $sheet['meta'];
        $band = [];
        if (($meta['title'] ?? '') !== '') {
            if (($meta['company'] ?? '') !== '') {
                $band[] = ['text' => $meta['company'], 'style' => self::S_COMPANY, 'height' => 16];
            }
            $band[] = ['text' => $meta['title'], 'style' => self::S_TITLE, 'height' => 26];
            foreach (['subtitle', 'generated'] as $key) {
                if (($meta[$key] ?? '') !== '') {
                    $band[] = ['text' => $meta[$key], 'style' => self::S_META, 'height' => 15];
                }
            }
        }
        $header = $band === [] ? 1 : count($band) + 2; // une ligne vide sous le bandeau
        $count = count($sheet['rows']);
        $totals = $count > 0 && in_array(true, array_column($sheet['columns'], 'total'), true);
        return ['band' => $band, 'header' => $header, 'first' => $header + 1, 'last' => $header + $count, 'totals' => $totals];
    }

    /** Le logo n'est posé que sur une feuille à bandeau (titre). */
    private function hasLogo(array $layout): bool
    {
        return $this->logo !== null && $layout['band'] !== [];
    }

    /** Dessin de la feuille : le logo, 40 px de haut, ancré à droite de la première ligne du bandeau. */
    private function drawing(int $columns): string
    {
        $h = 40;
        $w = min(160, (int) round($h * $this->logo['width'] / $this->logo['height']));
        $emu = 9525; // EMU par pixel
        return self::XML . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<xdr:oneCellAnchor><xdr:from><xdr:col>' . max(0, $columns - 2) . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>' . (2 * $emu) . '</xdr:rowOff></xdr:from>'
            . '<xdr:ext cx="' . ($w * $emu) . '" cy="' . ($h * $emu) . '"/>'
            . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . ($w * $emu) . '" cy="' . ($h * $emu) . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
            . '<xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach (array_keys($this->sheets) as $i) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            if ($this->hasLogo(self::layout($this->sheets[$i]))) {
                $sheets .= '<Override PartName="/xl/drawings/drawing' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
            }
        }
        if ($this->logo !== null) {
            $sheets .= '<Default Extension="' . $this->logo['ext'] . '" ContentType="image/' . $this->logo['ext'] . '"/>';
        }
        return self::XML . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheets . '</Types>';
    }

    /** @param list<array<string, mixed>> $layouts */
    private function workbook(array $layouts): string
    {
        $sheets = '';
        $names = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= '<sheet name="' . self::esc($sheet['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
            if ($sheet['columns'] !== []) {
                $quoted = "'" . str_replace("'", "''", $sheet['name']) . "'";
                $h = $layouts[$i]['header'];
                // Plage du filtre automatique et ligne d'en-tête répétée à l'impression (noms réservés d'Excel).
                $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">'
                    . self::esc($quoted . '!$A$' . $h . ':$' . self::col(count($sheet['columns']) - 1) . '$' . max($h, $layouts[$i]['last'])) . '</definedName>'
                    . '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">' . self::esc($quoted . '!$' . $h . ':$' . $h) . '</definedName>';
            }
        }
        return self::XML . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets>' . ($names !== '' ? '<definedNames>' . $names . '</definedNames>' : '') . '</workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach (array_keys($this->sheets) as $i) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    /** Index du style d'une cellule typée : variante 0 = normale, 1 = zébrée, 2 = total. */
    private static function style(string $type, int $variant = 0): int
    {
        return self::S_FIRST_TYPED + (int) array_search($type, self::TYPES, true) * 3 + $variant;
    }

    private static function styles(): string
    {
        $xf = static fn (int $fmt, int $font, int $fill, int $border, string $align = ''): string => '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
            . ' applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"' . ($align !== '' ? ' applyAlignment="1"><alignment ' . $align . '/></xf>' : '/>');
        $xfs = $xf(0, 0, 0, 0)                                                  // 0 : défaut
            . $xf(0, 1, 2, 0, 'vertical="center" wrapText="1"')                 // 1 : en-tête
            . $xf(0, 2, 0, 0, 'vertical="center"')                              // 2 : titre
            . $xf(0, 3, 0, 0)                                                   // 3 : société
            . $xf(0, 4, 0, 0);                                                  // 4 : mention
        foreach (self::TYPES as $type) {
            $fmt = self::NUMFMT[$type];
            $align = $type === self::TEXT ? 'vertical="top" wrapText="1"' : 'vertical="top"';
            $xfs .= $xf($fmt, 0, 0, 1, $align) . $xf($fmt, 0, 3, 1, $align) . $xf($fmt, 5, 4, 2, 'vertical="top"');
        }
        $count = self::S_FIRST_TYPED + count(self::TYPES) * 3;
        return self::XML . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="5"><numFmt numFmtId="164" formatCode="#,##0.00;[Red]\-#,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.###"/>'
            . '<numFmt numFmtId="166" formatCode="#,##0.000000"/><numFmt numFmtId="167" formatCode="0.0%"/><numFmt numFmtId="168" formatCode="dd/mm/yyyy"/></numFmts>'
            . '<fonts count="6"><font><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="16"/><color rgb="FF1D2939"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="9"/><color rgb="' . self::BRAND . '"/><name val="Calibri"/></font>'
            . '<font><i/><sz val="9"/><color rgb="FF5B6573"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><name val="Calibri"/></font></fonts>'
            . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="' . self::BRAND . '"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="' . self::ZEBRA . '"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="' . self::SOFT . '"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="hair"><color rgb="' . self::RULE . '"/></bottom><diagonal/></border>'
            . '<border><left/><right/><top style="thin"><color rgb="' . self::BRAND . '"/></top><bottom style="double"><color rgb="' . self::BRAND . '"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . $count . '">' . $xfs . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    /**
     * @param array{name: string, columns: list<array{label: string, type: string, width?: int, total?: bool}>, rows: list<list<mixed>>, meta: array<string, string>} $sheet
     * @param array{band: list<array{text: string, style: int, height: int}>, header: int, first: int, last: int, totals: bool} $layout
     * @param bool $drawing la feuille porte le logo (relation rId1 vers son dessin)
     */
    private static function worksheet(array $sheet, array $layout, bool $drawing = false): string
    {
        $columns = $sheet['columns'];
        $n = count($columns);
        $lastCol = $n > 0 ? self::col($n - 1) : 'A';
        $data = '';
        $merges = '';
        foreach ($layout['band'] as $i => $line) {
            $r = $i + 1;
            $data .= '<row r="' . $r . '" ht="' . $line['height'] . '" customHeight="1">' . self::text('A' . $r, $line['text'], $line['style']) . '</row>';
            if ($n > 1) {
                $merges .= '<mergeCell ref="A' . $r . ':' . $lastCol . $r . '"/>';
            }
        }
        $h = $layout['header'];
        $data .= '<row r="' . $h . '" ht="20" customHeight="1">';
        foreach ($columns as $i => $c) {
            $data .= self::text(self::col($i) . $h, $c['label'], self::S_HEADER);
        }
        $data .= '</row>';
        foreach ($sheet['rows'] as $k => $row) {
            $r = $layout['first'] + $k;
            $data .= '<row r="' . $r . '">';
            foreach ($columns as $i => $c) {
                $data .= self::cell(self::col($i) . $r, $c['type'], $row[$i] ?? null, $k % 2);
            }
            $data .= '</row>';
        }
        if ($layout['totals']) {
            $r = $layout['last'] + 1;
            $data .= '<row r="' . $r . '">';
            foreach ($columns as $i => $c) {
                $ref = self::col($i) . $r;
                if ($i === 0) {
                    $data .= self::text($ref, $sheet['meta']['total_label'] ?? 'Total', self::style(self::TEXT, 2));
                } elseif (!empty($c['total']) && $c['type'] !== self::TEXT) {
                    $sum = 0.0;
                    foreach ($sheet['rows'] as $row) {
                        $sum += self::number($c['type'], $row[$i] ?? null) ?? 0;
                    }
                    $range = self::col($i) . $layout['first'] . ':' . self::col($i) . $layout['last'];
                    $data .= '<c r="' . $ref . '" s="' . self::style($c['type'], 2) . '"><f>SUBTOTAL(109,' . $range . ')</f><v>' . self::num(round($sum, 6)) . '</v></c>';
                } else {
                    $data .= '<c r="' . $ref . '" s="' . self::style(self::TEXT, 2) . '"/>';
                }
            }
            $data .= '</row>';
        }

        $cols = '';
        foreach ($columns as $i => $c) {
            $width = $c['width'] ?? self::width($c, array_column($sheet['rows'], $i));
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $title = $sheet['meta']['title'] ?? $sheet['name'];
        $footer = '&amp;L&amp;8' . self::esc(trim(($sheet['meta']['company'] ?? '') . ' — ' . $title, ' —')) . '&amp;R&amp;8&amp;P / &amp;N';
        return self::XML . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="' . $h . '" topLeftCell="A' . ($h + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $data . '</sheetData>'
            . ($n > 0 ? '<autoFilter ref="A' . $h . ':' . $lastCol . max($h, $layout['last']) . '"/>' : '')
            . ($merges !== '' ? '<mergeCells count="' . count($layout['band']) . '">' . $merges . '</mergeCells>' : '')
            . '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            . '<headerFooter><oddFooter>' . $footer . '</oddFooter></headerFooter>'
            . ($drawing ? '<drawing r:id="rId1"/>' : '')
            . '</worksheet>';
    }

    /** @param list<mixed> $values */
    private static function width(array $column, array $values): int
    {
        if ($column['type'] === self::DATE) {
            return 12;
        }
        $len = mb_strlen($column['label']);
        foreach ($values as $v) {
            $len = max($len, $column['type'] === self::TEXT ? mb_strlen((string) $v) : 12);
        }
        return max(10, min($len + 2, $column['type'] === self::TEXT ? 45 : 18));
    }

    private static function number(string $type, mixed $value): float|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        return match ($type) {
            self::INT => (int) $value,
            self::MONEY => (int) $value / 100,
            self::QTY => (int) $value / 1000,
            self::RATE => (int) $value / 1_000_000,
            self::PERCENT => (int) $value / 10_000,
            self::DATE => self::serial((string) $value),
            default => null,
        };
    }

    private static function cell(string $ref, string $type, mixed $value, int $variant): string
    {
        if ($value === null || $value === '') {
            return $variant === 1 ? '<c r="' . $ref . '" s="' . self::style($type, 1) . '"/>' : '';
        }
        $number = self::number($type, $value);
        if ($number === null) {
            return self::text($ref, (string) $value, self::style(self::TEXT, $variant));
        }
        return '<c r="' . $ref . '" s="' . self::style($type, $variant) . '"><v>' . self::num($number) . '</v></c>';
    }

    private static function text(string $ref, string $value, int $style): string
    {
        // Retire les caractères de contrôle interdits en XML ; une formule saisie reste du texte (pas d'injection).
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        return '<c r="' . $ref . '" t="inlineStr"' . ($style > 0 ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . self::esc($value) . '</t></is></c>';
    }

    /** Numéro de série Excel d'une date (1900, jours depuis le 30/12/1899), heure en fraction ; null si illisible. */
    private static function serial(string $value): ?float
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', strlen($value) === 10 ? $value . ' 00:00:00' : substr($value, 0, 19), new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }
        return ($date->getTimestamp() / 86400) + 25569;
    }

    private static function num(int|float $n): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        return rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    public static function col(int $index): string
    {
        $name = '';
        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + ($i - 1) % 26) . $name;
        }
        return $name;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
