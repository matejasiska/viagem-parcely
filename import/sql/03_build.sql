-- Join geometrie na atributy je na (katuze_kod, id): ID je unikátní jen v rámci jednoho
-- souboru KÚ. Globální klíč je ID_2 = identifikátor parcely v ISKN.
CREATE INDEX stg_parcela_atr_idx ON stg_parcela_atr (katuze_kod, id);

INSERT INTO katastralni_uzemi (kod, nazev, obec_kod, obec_nazev, geom)
SELECT k.kod, k.nazev, k.obec_kod, k.obec_nazev, g.geom
FROM ku_okresu k
LEFT JOIN stg_ku_geom g ON g.katuze_kod = k.kod;

INSERT INTO parcela (id, katastralni_uzemi_kod, cislo, vymera,
                     druh_pozemku_kod, zpusob_vyuziti_kod, geom)
SELECT g.id_2::bigint,
       g.katuze_kod,
       a.text_km,
       a.par_vymera,
       dp.kod,
       zv.kod,
       g.geom
FROM stg_parcela_geom g
JOIN stg_parcela_atr a ON a.katuze_kod = g.katuze_kod AND a.id = g.id
LEFT JOIN druh_pozemku dp ON dp.kod = a.drupoz_kod
LEFT JOIN zpusob_vyuziti_pozemku zv ON zv.kod = a.zpvypa_kod
WHERE g.geom IS NOT NULL;

ANALYZE katastralni_uzemi;
ANALYZE parcela;
