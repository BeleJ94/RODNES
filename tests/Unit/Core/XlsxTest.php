<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Xlsx;
use PHPUnit\Framework\TestCase;

final class XlsxTest extends TestCase
{
    // Index de style : 5 + rang du type (text, int, money, qty, rate, percent, date) × 3 + variante (0 normale, 1 zébrée, 2 total).
    private const TEXT = 5;
    private const MONEY = 11;
    private const QTY = 14;
    private const RATE = 17;
    private const PERCENT = 20;
    private const DATE = 23;

    public function testColumnLetters(): void
    {
        self::assertSame(['A', 'Z', 'AA', 'AZ', 'BA', 'ZZ', 'AAA'], array_map(Xlsx::col(...), [0, 25, 26, 51, 52, 701, 702]));
    }

    public function testCellsAreTypedAndTextIsNeverAFormula(): void
    {
        $xml = $this->sheetXml((new Xlsx())->sheet('Test', [
            ['label' => 'Réf'], ['label' => 'Montant', 'type' => Xlsx::MONEY], ['label' => 'Qté', 'type' => Xlsx::QTY],
            ['label' => 'Date', 'type' => Xlsx::DATE], ['label' => 'Marge', 'type' => Xlsx::PERCENT], ['label' => 'Taux', 'type' => Xlsx::RATE],
        ], [
            ['=HYPERLINK("x") & <b>', 123456, 2500, '2026-10-27', 1250, 930_000],
            ['vide', null, 0, 'pas une date', null, null],
        ])->build());

        self::assertStringContainsString('<c r="B2" s="' . self::MONEY . '"><v>1234.56</v></c>', $xml);
        self::assertStringContainsString('<c r="C2" s="' . self::QTY . '"><v>2.5</v></c>', $xml);
        self::assertStringContainsString('<c r="D2" s="' . self::DATE . '"><v>46322</v></c>', $xml); // 27/10/2026 en numéro de série Excel
        self::assertStringContainsString('<c r="E2" s="' . self::PERCENT . '"><v>0.125</v></c>', $xml);
        self::assertStringContainsString('<c r="F2" s="' . self::RATE . '"><v>0.93</v></c>', $xml);
        self::assertStringContainsString('<c r="A2" t="inlineStr" s="' . self::TEXT . '"><is><t xml:space="preserve">=HYPERLINK(&quot;x&quot;) &amp; &lt;b&gt;</t>', $xml);
        self::assertStringContainsString('<c r="C3" s="' . (self::QTY + 1) . '"><v>0</v></c>', $xml); // zéro conservé, ligne zébrée
        self::assertStringContainsString('<c r="B3" s="' . (self::MONEY + 1) . '"/>', $xml); // cellule vide : seulement le fond zébré
        self::assertStringContainsString('<t xml:space="preserve">pas une date</t>', $xml); // date illisible gardée en texte
        // Ligne de total : formule SOUS.TOTAL (suit le filtre) sur les montants seulement, valeur en cache.
        self::assertStringContainsString('<c r="B4" s="' . (self::MONEY + 2) . '"><f>SUBTOTAL(109,B2:B3)</f><v>1234.56</v></c>', $xml);
        self::assertStringNotContainsString('SUBTOTAL(109,C2', $xml);
        self::assertSame(1, substr_count($xml, '<f>'));
        self::assertStringContainsString('<autoFilter ref="A1:F3"/>', $xml);
        self::assertStringContainsString('orientation="landscape"', $xml);
        self::assertNotFalse(simplexml_load_string($xml));
    }

    public function testExecutiveBandPushesTheTableDown(): void
    {
        $xml = $this->sheetXml((new Xlsx())->sheet('Factures', [['label' => 'Réf'], ['label' => 'TTC', 'type' => Xlsx::MONEY]], [['F1', 1000], ['F2', 2550]], [
            'company' => 'ACME SARL', 'title' => 'Factures', 'subtitle' => 'Période du 01/10/2026 au 31/10/2026', 'generated' => 'Édité le 27/10/2026', 'total_label' => 'Total',
        ])->build());

        // Société, titre, sous-titre, mention, ligne vide, puis l'en-tête en ligne 6.
        self::assertStringContainsString('<t xml:space="preserve">ACME SARL</t>', $xml);
        self::assertStringContainsString('<mergeCell ref="A2:B2"/>', $xml);
        self::assertStringContainsString('<c r="A6" t="inlineStr" s="1"><is><t xml:space="preserve">Réf</t>', $xml);
        self::assertStringContainsString('<pane ySplit="6" topLeftCell="A7"', $xml);
        self::assertStringContainsString('<f>SUBTOTAL(109,B7:B8)</f><v>35.5</v>', $xml);
        self::assertStringContainsString('<t xml:space="preserve">Total</t>', $xml);
        self::assertStringContainsString('<autoFilter ref="A6:B8"/>', $xml);
    }

    private function sheetXml(string $book): string
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($file, $book);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/_rels/workbook.xml.rels', '_rels/.rels'] as $part) {
            self::assertNotFalse(simplexml_load_string((string) $zip->getFromName($part)), $part);
        }
        $zip->close();
        unlink($file);
        return $xml;
    }
}
