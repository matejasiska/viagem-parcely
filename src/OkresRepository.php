<?php

declare(strict_types=1);

namespace Katastr;

final class OkresRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Rozsah okresu a počty se čtou z databáze, aby mapa neměla souřadnice zadrátované
     * v kódu a fungovala i po přesměrování importu na jiný okres.
     */
    public function prehled(): array
    {
        $sql = <<<'SQL'
            SELECT ds.datum_dat,
                   ds.pocet_ku,
                   ds.pocet_parcel,
                   ST_XMin(r.rozsah) AS min_lon,
                   ST_YMin(r.rozsah) AS min_lat,
                   ST_XMax(r.rozsah) AS max_lon,
                   ST_YMax(r.rozsah) AS max_lat
            FROM (SELECT ST_Extent(ST_Transform(geom, 4326))::geometry AS rozsah
                  FROM katastralni_uzemi) r
            LEFT JOIN (SELECT datum_dat, pocet_ku, pocet_parcel
                       FROM datova_sada
                       ORDER BY importovano DESC
                       LIMIT 1) ds ON true
            SQL;

        $radek = $this->db->fetchRow($sql);

        // Bez geometrie nebo bez záznamu o importu nemá mapa co zobrazit.
        if ($radek === null || $radek['min_lon'] === null || $radek['datum_dat'] === null) {
            return ['naimportovano' => false];
        }

        return [
            'naimportovano' => true,
            'datum_dat' => $radek['datum_dat'],
            'pocet_ku' => (int) $radek['pocet_ku'],
            'pocet_parcel' => (int) $radek['pocet_parcel'],
            'rozsah' => [
                (float) $radek['min_lon'],
                (float) $radek['min_lat'],
                (float) $radek['max_lon'],
                (float) $radek['max_lat'],
            ],
        ];
    }
}
