<?php

use App\Core\Csrf;
use App\Core\I18n;

$i18n = app(I18n::class);
?>
<?= json_script('eth-config', [
    'basePath' => rtrim((string) config('app.base_path', ''), '/'),
    'csrf' => app(Csrf::class)->token(),
    'locale' => $i18n->locale(),
    // Exports exécutifs (tableaux) : en-tête des documents, bibliothèques chargées au premier export.
    'company' => setting('company_name'),
    'logoUrl' => (int) setting('company_logo') > 0 ? url('/files/' . (int) setting('company_logo')) : '',
    'user' => app(\App\Security\Auth::class)->user()?->name ?? '',
    'vendor' => [
        'exceljs' => asset('vendor/exceljs/exceljs.min.js'),
        'pdfmake' => asset('vendor/pdfmake/pdfmake.min.js'),
        'pdfmakeFonts' => asset('vendor/pdfmake/vfs_fonts.js'),
    ],
]) ?>
<?= json_script('eth-i18n', $i18n->forJs(['common.', 'dt.', 'filters.', 'tables.', 'preview.', 'grid.', 'ajax.'])) ?>
<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/datatables/dataTables.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/lucide/lucide.min.js')) ?>"></script>
<script src="<?= e(asset('js/eth-core.js')) ?>"></script>
<script src="<?= e(asset('js/eth-tables.js')) ?>"></script>
<script src="<?= e(asset('js/eth-pricing.js')) ?>"></script>
<script src="<?= e(asset('js/eth-export.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
