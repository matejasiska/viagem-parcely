\echo '--- katastrální území ---'
SELECT count(*) AS ku_celkem,
       count(geom) AS ku_s_geometrii
FROM katastralni_uzemi;

\echo '--- parcely ---'
SELECT count(*) AS parcel_celkem,
       count(DISTINCT katastralni_uzemi_kod) AS ku_s_parcelami,
       count(*) FILTER (WHERE druh_pozemku_kod IS NULL) AS bez_druhu_pozemku,
       count(*) FILTER (WHERE vymera IS NULL) AS bez_vymery
FROM parcela;

\echo '--- parcely nenapárované na atributy (mělo by být 0) ---'
SELECT count(*) AS geom_bez_atributu
FROM stg_parcela_geom g
LEFT JOIN stg_parcela_atr a ON a.katuze_kod = g.katuze_kod AND a.id = g.id
WHERE a.id IS NULL;

\echo '--- atributy bez geometrie (mělo by být 0) ---'
SELECT count(*) AS atr_bez_geom
FROM stg_parcela_atr a
LEFT JOIN stg_parcela_geom g ON g.katuze_kod = a.katuze_kod AND g.id = a.id
WHERE g.id IS NULL;

\echo '--- 10 největších KÚ podle počtu parcel ---'
SELECT k.kod, k.nazev, count(p.id) AS parcel
FROM katastralni_uzemi k
LEFT JOIN parcela p ON p.katastralni_uzemi_kod = k.kod
GROUP BY k.kod, k.nazev
ORDER BY parcel DESC
LIMIT 10;
