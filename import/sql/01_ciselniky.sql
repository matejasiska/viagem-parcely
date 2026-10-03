-- Číselníky RÚIAN/ISKN. CSV od ČÚZK jsou v kódování Windows-1250, COPY to umí přečíst přímo.
-- Všechny sloupce beru jako text a přetypovávám až při vkládání, ať import nespadne na
-- prázdné hodnotě v nečekaném poli.

DROP TABLE IF EXISTS stg_obec, stg_ku, stg_druh_pozemku, stg_zpusob_vyuziti, ku_okresu;

CREATE TABLE stg_obec (
    kod text, nazev text, status_kod text, pou_kod text, okres_kod text,
    cleneni_sm_rozsah_kod text, cleneni_sm_typ_kod text,
    plati_od text, plati_do text, datum_vzniku text
);
\copy stg_obec FROM '/data/cis/UI_OBEC.csv' WITH (FORMAT csv, HEADER true, DELIMITER ';', ENCODING 'WIN1250')

CREATE TABLE stg_ku (
    kod text, nazev text, obec_kod text,
    plati_od text, plati_do text, datum_vzniku text
);
\copy stg_ku FROM '/data/cis/UI_KATASTRALNI_UZEMI.csv' WITH (FORMAT csv, HEADER true, DELIMITER ';', ENCODING 'WIN1250')

CREATE TABLE stg_druh_pozemku (
    kod text, nazev text, zemedelska_kultura text, platnost_od text, platnost_do text,
    zkratka text, typppd_kod text, stavebni_parcela text,
    povinna_ochrana_poz text, povinny_zpusob_vyuz text
);
\copy stg_druh_pozemku FROM '/data/cis/SC_D_POZEMKU.csv' WITH (FORMAT csv, HEADER true, DELIMITER ';', ENCODING 'WIN1250')

CREATE TABLE stg_zpusob_vyuziti (
    kod text, nazev text, platnost_od text, platnost_do text, typppd_kod text, zkratka text
);
\copy stg_zpusob_vyuziti FROM '/data/cis/SC_ZP_VYUZITI_POZ.csv' WITH (FORMAT csv, HEADER true, DELIMITER ';', ENCODING 'WIN1250')

INSERT INTO druh_pozemku (kod, nazev)
SELECT kod::smallint, nazev FROM stg_druh_pozemku
ON CONFLICT (kod) DO NOTHING;

INSERT INTO zpusob_vyuziti_pozemku (kod, nazev)
SELECT kod::smallint, nazev FROM stg_zpusob_vyuziti
ON CONFLICT (kod) DO NOTHING;

-- Katastrální území okresu. Okres je dán kódem (Jičín = 3604), zbytek se dopočítá z číselníků,
-- takže v repozitáři není ručně vypsaný seznam 240 kódů.
CREATE TABLE ku_okresu AS
SELECT k.kod::integer AS kod,
       k.nazev,
       o.kod::integer AS obec_kod,
       o.nazev AS obec_nazev
FROM stg_ku k
JOIN stg_obec o ON o.kod = k.obec_kod
-- COPY ve formátu CSV mapuje prázdné pole na NULL, ne na prázdný řetězec,
-- takže ukončené záznamy se filtrují přes IS NULL.
WHERE o.okres_kod = :'okres'
  AND k.plati_do IS NULL
  AND o.plati_do IS NULL;

ALTER TABLE ku_okresu ADD PRIMARY KEY (kod);
