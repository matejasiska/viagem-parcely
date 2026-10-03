#!/bin/sh
# Jednorázový import parcel okresu Jičín z katastrální mapy ČÚZK (SHP po katastrálních územích)
# do PostGIS. Potřebuje přístup k services.cuzk.gov.cz. Při opakovaném spuštění se ukončí,
# pokud už jsou parcely naimportované.
set -eu

CIS_URL="${CIS_URL:-https://services.cuzk.gov.cz/sestavy/cis}"
SHP_URL="${SHP_URL:-https://services.cuzk.gov.cz/shp/ku/epsg-5514}"
OKRES="${OKRES_KOD:-3604}"
WORK=/data
SQL=/import/sql
PG="PG:host=$PGHOST dbname=$PGDATABASE user=$PGUSER password=$PGPASSWORD"

q() { psql -qtAX -v ON_ERROR_STOP=1 "$@"; }

# -R zachová Last-Modified do mtime souboru, z toho se pak určí verze dat.
stahni() {
    if curl -fsS -R --retry 3 --retry-delay 2 --connect-timeout 15 -o "$2" "$1"; then
        return 0
    fi
    rm -f "$2"
    return 1
}

nedostupne_cuzk() {
    echo >&2
    echo "CHYBA: nepovedlo se stáhnout $1" >&2
    echo >&2
    echo "Import potřebuje přístup k internetu a k serveru services.cuzk.gov.cz." >&2
    echo "Zkontroluj připojení a dostupnost ČÚZK a spusť 'docker compose up' znovu." >&2
    echo "Už stažené soubory zůstávají v cache, import bude pokračovat tam, kde skončil." >&2
    exit 1
}

# ogr2ogr neumí -select společně s -append, proto se sloupce vybírají přes -sql.
nahraj() {
    src="$1"; tabulka="$2"; dotaz="$3"; shift 3
    ogr2ogr -f PostgreSQL "$PG" "$src" -nln "$tabulka" -append --config PG_USE_COPY YES -sql "$dotaz" "$@"
}

naimportovano=$(q -c "SELECT count(*) FROM parcela" 2>/dev/null || echo 0)
if [ "$naimportovano" -gt 0 ]; then
    echo "V databázi už je $naimportovano parcel, import přeskočen."
    q -c "SELECT 'Data ČÚZK k ' || datum_dat || ', naimportováno ' || importovano FROM datova_sada ORDER BY importovano DESC LIMIT 1" 2>/dev/null || true
    echo "Pro nový import: docker compose down -v && docker compose up"
    exit 0
fi

mkdir -p "$WORK/cis" "$WORK/ku"
zacatek=$(date +%s)

echo "== Číselníky RÚIAN a ISKN =="
for f in UI_OBEC UI_KATASTRALNI_UZEMI SC_D_POZEMKU SC_ZP_VYUZITI_POZ; do
    if [ ! -f "$WORK/cis/$f.csv" ]; then
        stahni "$CIS_URL/$f.zip" "$WORK/cis/$f.zip" || nedostupne_cuzk "číselník $f z $CIS_URL"
        unzip -oq "$WORK/cis/$f.zip" -d "$WORK/cis"
    fi
    echo "  $f.csv"
done

psql -q -v ON_ERROR_STOP=1 -v okres="$OKRES" -f "$SQL/01_ciselniky.sql"
psql -q -v ON_ERROR_STOP=1 -f "$SQL/02_staging.sql"

kody=$(q -c "SELECT kod FROM ku_okresu ORDER BY kod")
if [ -z "$kody" ]; then
    echo "CHYBA: pro okres $OKRES nevyšlo z číselníků RÚIAN žádné katastrální území." >&2
    echo "Zkontroluj, že OKRES_KOD je platný kód okresu (Jičín = 3604)." >&2
    exit 1
fi
pocet_ku=$(echo "$kody" | grep -c .)
echo "Okres $OKRES: $pocet_ku katastrálních území"

if [ -n "${KU_LIMIT:-}" ]; then
    kody=$(echo "$kody" | head -n "$KU_LIMIT")
    pocet_ku=$(echo "$kody" | grep -c .)
    echo "KU_LIMIT=$KU_LIMIT -> importuji jen $pocet_ku KÚ"
fi

echo "== Katastrální mapa po KÚ (stahuje se z ČÚZK, trvá jednotky minut) =="
i=0
nestazeno=0
chybna=""
for kod in $kody; do
    i=$((i + 1))
    zip="$WORK/ku/$kod.zip"
    dir="$WORK/ku/$kod"

    if [ ! -s "$zip" ] && ! stahni "$SHP_URL/$kod.zip" "$zip"; then
        echo "  [$i/$pocet_ku] $kod: stažení selhalo, pokračuji dál"
        nestazeno=$((nestazeno + 1))
        chybna="$chybna $kod"
        continue
    fi

    rm -rf "$dir"
    unzip -oq "$zip" -d "$WORK/ku"

    nahraj "$dir/PARCELY_KN_P.shp" stg_parcela_geom \
        "SELECT ID, ID_2, KATUZE_KOD FROM PARCELY_KN_P" \
        -nlt PROMOTE_TO_MULTI -t_srs EPSG:3857

    nahraj "$dir/PARCELY_KN_DEF.shp" stg_parcela_atr \
        "SELECT ID, KATUZE_KOD, TEXT_KM, PAR_VYMERA, DRUPOZ_KOD, ZPVYPA_KOD FROM PARCELY_KN_DEF" \
        -nlt NONE

    nahraj "$dir/KATASTRALNI_UZEMI_P.shp" stg_ku_geom \
        "SELECT KATUZE_KOD FROM KATASTRALNI_UZEMI_P" \
        -nlt PROMOTE_TO_MULTI -t_srs EPSG:3857

    rm -rf "$dir"
    echo "  [$i/$pocet_ku] $kod"
done

if [ "$nestazeno" -eq "$pocet_ku" ]; then
    nedostupne_cuzk "ani jedno katastrální území z $SHP_URL"
fi

echo "== Sestavení cílových tabulek =="
psql -q -v ON_ERROR_STOP=1 -f "$SQL/03_build.sql"

# Verze dat = nejnovější Last-Modified mezi staženými soubory katastrální mapy.
datum_dat=$(ls -t "$WORK"/ku/*.zip | head -n 1 | xargs stat -c %y | cut -c1-10)
psql -q -v ON_ERROR_STOP=1 -v zdroj="$SHP_URL" -v datum="$datum_dat" -f "$SQL/05_datova_sada.sql"

konec=$(date +%s)
echo "== Kontrola =="
psql -v ON_ERROR_STOP=1 -f "$SQL/04_kontrola.sql"
echo "Data ČÚZK k $datum_dat. Import trval $((konec - zacatek)) s."
if [ "$nestazeno" -gt 0 ]; then
    echo "POZOR: $nestazeno KÚ se nepodařilo stáhnout:$chybna" >&2
    echo "Spusť import znovu, dostahuje jen chybějící." >&2
fi
exit 0
