-- Sloupce se jmenují stejně jako atributy v DBF (GDAL je při zápisu do Postgresu zmenší),
-- aby `ogr2ogr -append` napároval pole podle jména.

DROP TABLE IF EXISTS stg_parcela_geom, stg_parcela_atr, stg_ku_geom;

CREATE TABLE stg_parcela_geom (
    id         text,
    id_2       text,
    katuze_kod integer,
    geom       geometry(MultiPolygon, 3857)
);

CREATE TABLE stg_parcela_atr (
    id         text,
    katuze_kod integer,
    text_km    text,
    par_vymera integer,
    drupoz_kod integer,
    zpvypa_kod integer
);

CREATE TABLE stg_ku_geom (
    katuze_kod integer,
    geom       geometry(MultiPolygon, 3857)
);
