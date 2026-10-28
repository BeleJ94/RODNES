<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Security\SecurityHeaders;
use App\Services\FileStorage;

/**
 * Fichiers servis après contrôle d'accès (route protégée), jamais depuis la racine web.
 * Affichage en ligne pour l'aperçu (images isolées par CSP « sandbox », PDF sans aucune ressource externe),
 * encadrable uniquement par ETHAN ; ?download=1 force le téléchargement.
 */
final class FileController extends Controller
{
    private const PREVIEWABLE = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /** Fichiers rattachés à un document plus sensible que la route : permission supplémentaire exigée. */
    private const RESTRICTED = ['supplier_invoices' => 'supplier_invoices.view', 'rfq_files' => 'rfqs.view', 'supplier_quote_files' => 'rfqs.view', 'client_order_files' => 'client_orders.view'];

    public function __construct(private FileStorage $files, private Database $db)
    {
    }

    public function show(Request $request, string $id): Response
    {
        $file = $this->files->get((int) $id);
        foreach (self::RESTRICTED as $table => $permission) {
            if (!can($permission) && $this->db->fetchValue("SELECT 1 FROM {$table} WHERE file_id = :id LIMIT 1", ['id' => (int) $id])) {
                throw new HttpException(403);
            }
        }
        $path = $this->files->path($file);
        if (!is_file($path)) {
            throw new HttpException(404);
        }
        $mime = (string) $file['mime'];
        $inline = in_array($mime, self::PREVIEWABLE, true) && $request->str('download') !== '1';
        $name = (string) $file['original_name'];
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'document';
        $response = new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'Cache-Control' => 'private, no-store',
        ]);
        // Le lecteur PDF du navigateur ne fonctionne pas sous « sandbox » : le PDF n'a droit à aucune ressource, les images sont isolées.
        $csp = $mime === 'application/pdf'
            ? "default-src 'none'; frame-ancestors 'none'"
            : "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox; frame-ancestors 'none'";
        return SecurityHeaders::framableBySelf($response, $csp);
    }
}
