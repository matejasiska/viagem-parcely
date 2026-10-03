<?php

declare(strict_types=1);

namespace Katastr;

final class DlazdiceRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Geometrie je v databázi uložená v EPSG:3857, takže ST_AsMVTGeom pracuje přímo
     * s obálkou dlaždice a při každém požadavku se nic nepřepočítává mezi souřadnicovými
     * systémy. Buffer 16 jednotek zabrání vzniku švů na hranicích dlaždic.
     */
    public function parcely(int $z, int $x, int $y): string
    {
        $sql = <<<'SQL'
            WITH obalka AS (
                SELECT ST_TileEnvelope(:z, :x, :y) AS geom
            )
            SELECT encode(ST_AsMVT(d, 'parcely', 4096, 'geom'), 'base64')
            FROM (
                SELECT p.id,
                       p.cislo,
                       p.druh_pozemku_kod,
                       ST_AsMVTGeom(p.geom, o.geom, 4096, 16, true) AS geom
                FROM parcela p, obalka o
                WHERE p.geom && o.geom
            ) AS d
            WHERE d.geom IS NOT NULL
            SQL;

        return $this->mvt($sql, $z, $x, $y);
    }

    public function katastralniUzemi(int $z, int $x, int $y): string
    {
        $sql = <<<'SQL'
            WITH obalka AS (
                SELECT ST_TileEnvelope(:z, :x, :y) AS geom
            )
            SELECT encode(ST_AsMVT(d, 'katastralni_uzemi', 4096, 'geom'), 'base64')
            FROM (
                SELECT k.kod,
                       k.nazev,
                       ST_AsMVTGeom(k.geom, o.geom, 4096, 16, true) AS geom
                FROM katastralni_uzemi k, obalka o
                WHERE k.geom && o.geom
            ) AS d
            WHERE d.geom IS NOT NULL
            SQL;

        return $this->mvt($sql, $z, $x, $y);
    }

    /**
     * ST_AsMVT vrací bytea. Přenáší se jako base64, protože zacházení s bytea se mezi
     * verzemi PDO liší. Prázdný výsledek znamená, že v dlaždici nejsou žádná data.
     */
    private function mvt(string $sql, int $z, int $x, int $y): string
    {
        $base64 = $this->db->fetchValue($sql, ['z' => $z, 'x' => $x, 'y' => $y]);

        if ($base64 === null) {
            return '';
        }

        $mvt = base64_decode((string) $base64, true);

        if ($mvt === false) {
            throw new \RuntimeException('Dlaždici se nepovedlo dekódovat z base64.');
        }

        return $mvt;
    }
}
