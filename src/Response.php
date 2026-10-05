<?php

declare(strict_types=1);

namespace Katastr;

final class Response
{
    public static function json(array $data, int $maxAge = 0): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header($maxAge > 0 ? "Cache-Control: public, max-age=$maxAge" : 'Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Dlaždice se nemění, dokud se nenaimportují nová data, takže je lze cachovat dlouho.
     * Prázdná dlaždice je legitimní odpověď (v okolí nejsou žádná data) a vrací se jako 204,
     * aby ji MapLibre nezkoušel parsovat.
     */
    public static function dlazdice(string $mvt, int $maxAge): void
    {
        if ($mvt === '') {
            header("Cache-Control: public, max-age=$maxAge");
            http_response_code(204);

            return;
        }

        header('Content-Type: application/vnd.mapbox-vector-tile');
        header("Cache-Control: public, max-age=$maxAge");
        header('Content-Length: ' . strlen($mvt));
        echo $mvt;
    }

    public static function error(int $status, string $message): void
    {
        http_response_code($status);
        self::json(['chyba' => $message]);
    }
}
