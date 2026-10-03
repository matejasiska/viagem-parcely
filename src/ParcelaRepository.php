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
        $parcela['vymera'] = (int) $parcela['vymera'];
        $parcela['katastralni_uzemi_kod'] = (int) $parcela['katastralni_uzemi_kod'];
        $parcela['lat'] = (float) $parcela['lat'];
        $parcela['lon'] = (float) $parcela['lon'];
        $parcela['odkaz_ruian'] = 'https://vdp.cuzk.gov.cz/vdp/ruian/parcely/' . $parcela['id'];

        return $parcela;
    }

    /**
     * Hledá podle čísla parcely, volitelně omezeně na katastrální území (kód nebo část názvu).
     * Zadání '941' najde i '941/9', protože kmenové číslo bez poddělení je běžný vstup.
     */
    public function hledej(string $cislo, string $katastralniUzemi, int $limit): array
    {
        $podminky = ['(p.cislo = :cislo OR p.cislo ILIKE :cislo_s_poddelenim)'];
        $parametry = [
            'cislo' => $cislo,
            'cislo_s_poddelenim' => $cislo . '/%',
        ];

        if ($katastralniUzemi !== '') {
            $podminky[] = '(k.kod::text = :ku OR k.nazev ILIKE :ku_nazev)';
            $parametry['ku'] = $katastralniUzemi;
            $parametry['ku_nazev'] = '%' . $katastralniUzemi . '%';
        }

        $sql = sprintf(
            <<<'SQL'
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
                WHERE %s
                ORDER BY k.nazev, p.cislo
                LIMIT %d
                SQL,
            implode(' AND ', $podminky),
            $limit,
        );

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'cislo' => $r['cislo'],
                'vymera' => (int) $r['vymera'],
                'druh_pozemku' => $r['druh_pozemku'],
                'katastralni_uzemi' => $r['katastralni_uzemi'],
                'obec' => $r['obec'],
                'lat' => (float) $r['lat'],
                'lon' => (float) $r['lon'],
            ],
            $this->db->fetchAll($sql, $parametry),
        );
    }
}
