CREATE EXTENSION IF NOT EXISTS postgis;

CREATE TABLE druh_pozemku (
    kod   smallint PRIMARY KEY,
    nazev text NOT NULL
);

CREATE TABLE zpusob_vyuziti_pozemku (
    kod   smallint PRIMARY KEY,
    nazev text NOT NULL
);

CREATE TABLE katastralni_uzemi (
    kod        integer PRIMARY KEY,
    nazev      text NOT NULL,
    obec_kod   integer NOT NULL,
    obec_nazev text NOT NULL,
    geom       geometry(MultiPolygon, 3857)
);

-- id je ID_2 ze shapefilu, tedy identifikátor parcely v ISKN/RÚIAN.
-- Atribut ID ze shapefilu je jen pořadové číslo v rámci jednoho souboru a mezi KÚ koliduje.
CREATE TABLE parcela (
    id                    bigint PRIMARY KEY,
    katastralni_uzemi_kod integer NOT NULL REFERENCES katastralni_uzemi (kod),
    cislo                 text NOT NULL,
    vymera                integer,
    druh_pozemku_kod      smallint REFERENCES druh_pozemku (kod),
    zpusob_vyuziti_kod    smallint REFERENCES zpusob_vyuziti_pozemku (kod),
    geom                  geometry(MultiPolygon, 3857) NOT NULL
);

CREATE INDEX parcela_geom_idx ON parcela USING gist (geom);
CREATE INDEX parcela_ku_idx ON parcela (katastralni_uzemi_kod);
CREATE INDEX katastralni_uzemi_geom_idx ON katastralni_uzemi USING gist (geom);

-- Jeden řádek na import. Drží verzi vstupních dat (nejnovější Last-Modified stažených
-- souborů ČÚZK), ať je v aplikaci vidět, k jakému stavu katastru data patří.
CREATE TABLE datova_sada (
    zdroj        text NOT NULL,
    datum_dat    date NOT NULL,
    importovano  timestamptz NOT NULL DEFAULT now(),
    pocet_ku     integer NOT NULL,
    pocet_parcel integer NOT NULL
);
