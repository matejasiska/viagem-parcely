-- psql neinterpoluje :'promenne' v -c, jen v souboru nebo na stdin.
INSERT INTO datova_sada (zdroj, datum_dat, pocet_ku, pocet_parcel)
SELECT :'zdroj',
       :'datum'::date,
       (SELECT count(*) FROM katastralni_uzemi WHERE geom IS NOT NULL),
       (SELECT count(*) FROM parcela);
