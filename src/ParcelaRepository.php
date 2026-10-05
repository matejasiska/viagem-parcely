<?php

declare(strict_types=1);

namespace Katastr;

final class ParcelaRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Kódy z katastru se v detailu nevracejí, překládají se číselníky na názvy.
     * Výměra se bere z atributu ČÚZK, ne z geometrie: zapsaná výměra je právní údaj
     * z původního měření a od plochy polygonu se u drobných parcel legitimně liší.
     */
    public function detail(int $id): ?array
    {
        $sql = <<<'SQL'
            SELECT p.id,
                   p.cislo,
                   p.vymera,
                   dp.nazev AS druh_pozemku,
                   zv.nazev AS zpusob_vyuziti,
                   k.kod    AS katastralni_uzemi_kod,
                   k.nazev  AS katastralni_uzemi,
                   k.obec_nazev AS obec,
                   ST_Y(ST_Transform(ST_PointOnSurface(p.geom), 4326)) AS lat,
                   ST_X(ST_Transform(ST_PointOnSurface(p.geom), 4326)) AS lon
            FROM parcela p
            JOIN katastralni_uzemi k ON k.kod = p.katastralni_uzemi_kod
            LEFT JOIN druh_pozemku dp ON dp.kod = p.druh_pozemku_kod
            LEFT JOIN zpusob_vyuziti_pozemku zv ON zv.kod = p.zpusob_vyuziti_kod
            WHERE p.id = :id
            SQL;

        $parcela = $this->db->fetchRow($sql, ['id' => $id]);

        if ($parcela === null) {
            return null;
        }

        $parcela['id'] = (int) $parcela['id'];
        $parcela['vymera'] = self::vymera($parcela['vymera']);
        $parcela['katastralni_uzemi_kod'] = (int) $parcela['katastralni_uzemi_kod'];
        $parcela['lat'] = (float) $parcela['lat'];
        $parcela['lon'] = (float) $parcela['lon'];
        $parcela['odkaz_ruian'] = 'https://vdp.cuzk.gov.cz/vdp/ruian/parcely/' . $parcela['id'];

        return $parcela;
    }

    /**
     * Hledá podle čísla parcely, volitelně omezeně na katastrální území (kód nebo část názvu).
     * Zadání '941' najde i '941/9', protože kmenové číslo bez poddělení je běžný vstup.
     * Prázdné katastrální území dá vzor '%%', který vyhoví každému názvu, takže SQL je
     * pro oba případy stejné.
     */
    public function hledej(string $cislo, string $katastralniUzemi, int $limit): array
    {
        $sql = <<<'SQL'
            SELECT p.id,
                   p.cislo,
                   p.vymera,
                   dp.nazev AS druh_pozemku,
                   k.nazev  AS katastralni_uzemi,
                   k.obec_nazev AS obec,
                   ST_Y(ST_Transform(ST_PointOnSurface(p.geom), 4326)) AS lat,
                   ST_X(ST_Transform(ST_PointOnSurface(p.geom), 4326)) AS lon
            FROM parcela p
            JOIN katastralni_uzemi k ON k.kod = p.katastralni_uzemi_kod
            LEFT JOIN druh_pozemku dp ON dp.kod = p.druh_pozemku_kod
            WHERE (p.cislo = :cislo OR p.cislo ILIKE :cislo_s_poddelenim)
              AND (k.kod::text = :ku OR k.nazev ILIKE :ku_nazev)
            ORDER BY k.nazev, p.cislo
            LIMIT :limit
            SQL;

        $parametry = [
            'cislo' => $cislo,
            'cislo_s_poddelenim' => self::escapujLike($cislo) . '/%',
            'ku' => $katastralniUzemi,
            'ku_nazev' => '%' . self::escapujLike($katastralniUzemi) . '%',
            'limit' => $limit,
        ];

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'cislo' => $r['cislo'],
                'vymera' => self::vymera($r['vymera']),
                'druh_pozemku' => $r['druh_pozemku'],
                'katastralni_uzemi' => $r['katastralni_uzemi'],
                'obec' => $r['obec'],
                'lat' => (float) $r['lat'],
                'lon' => (float) $r['lon'],
            ],
            $this->db->fetchAll($sql, $parametry),
        );
    }

    /** Výměra není ve schématu povinná. Chybějící se vrací jako null, ne jako 0 m². */
    private static function vymera(mixed $hodnota): ?int
    {
        return $hodnota === null ? null : (int) $hodnota;
    }

    /**
     * Uživatel hledá text, ne vzor: bez escapování by '%' nebo '_' v zadání našly libovolné
     * parcely. Zpětné lomítko je výchozí escape znak LIKE v PostgreSQL.
     */
    private static function escapujLike(string $text): string
    {
        return addcslashes($text, '%_\\');
    }
}
