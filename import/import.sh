#!/bin/sh
# Jednorázový import parcel okresu Jičín z katastrální mapy ČÚZK (SHP po katastrálních územích)
# do PostGIS. Při opakovaném spuštění se ukončí, pokud už jsou parcely naimportované.
set -eu

CIS_URL="https://services.cuzk.gov.cz/sestavy/cis"
SHP_URL="https://services.cuzk.gov.cz/shp/ku/epsg-5514"
OKRES="${OKRES_KOD:-3604}"
WORK=/data
SQL=/import/sql
PG="PG:host=$PGHOST dbname=$PGDATABASE user=$PGUSER password=$PGPASSWORD"

q() { psql -qtAX -v ON_ERROR_STOP=1 "$@"; }

naimportovano=$(q -c "SELECT count(*) FROM parcela" 2>/dev/null || echo 0)
if [ "$naimportovano" -gt 0 ]; then
    echo "V databázi už je $naimportovano parcel, import přeskočen."
    echo "Pro nový import: docker compose down -v && docker compose up"
    exit 0
fi

mkdir -p "$WORK/cis" "$WORK/ku"
zacatek=$(date +%s)

echo "== Číselníky RÚIAN a ISKN =="
for f in UI_OBEC UI_KATASTRALNI_UZEMI SC_D_POZEMKU SC_ZP_VYUZITI_POZ; do
    if [ ! -f "$WORK/cis/$f.csv" ]; then
        curl -fsS --retry 3 -o "$WORK/cis/$f.zip" "$CIS_URL/$f.zip"
        unzip -oq "$WORK/cis/$f.zip" -d "$WORK/cis"
    fi
    echo "  $f.csv"
done

psql -q -v ON_ERROR_STOP=1 -v okres="$OKRES" -f "$SQL/01_ciselniky.sql"
psql -q -v ON_ERROR_STOP=1 -f "$SQL/02_staging.sql"

kody=$(q -c "SELECT kod FROM ku_okresu ORDER BY kod")
pocet_ku=$(echo "$kody" | grep -c .)
echo "Okres $OKRES: $pocet_ku katastrálních území"

if [ -n "${KU_LIMIT:-}" ]; then
    kody=$(echo "$kody" | head -n "$KU_LIMIT")
    pocet_ku=$(echo "$kody" | grep -c .)
    echo "KU_LIMIT=$KU_LIMIT -> importuji jen $pocet_ku KÚ"
fi

echo "== Katastrální mapa po KÚ =="
i=0
chybna=""
for kod in $kody; do
    i=$((i + 1))
    zip="$WORK/ku/$kod.zip"
    dir="$WORK/ku/$kod"

    if [ ! -s "$zip" ]; then
        if ! curl -fsS --retry 3 -o "$zip" "$SHP_URL/$kod.zip"; then
            echo "  [$i/$pocet_ku] $kod: stažení selhalo"
            rm -f "$zip"
            chybna="$chybna $kod"
            continue
        fi
    fi

    rm -rf "$dir"
    unzip -oq "$zip" -d "$WORK/ku"

    ogr2ogr -f PostgreSQL "$PG" "$dir/PARCELY_KN_P.shp" \
        -nln stg_parcela_geom -append -nlt PROMOTE_TO_MULTI \
        -t_srs EPSG:3857 -select ID,ID_2,KATUZE_KOD \
        --config PG_USE_COPY YES

    ogr2ogr -f PostgreSQL "$PG" "$dir/PARCELY_KN_DEF.shp" \
        -nln stg_parcela_atr -append -nlt NONE \
        -select ID,KATUZE_KOD,TEXT_KM,PAR_VYMERA,DRUPOZ_KOD,ZPVYPA_KOD \
        --config PG_USE_COPY YES

    ogr2ogr -f PostgreSQL "$PG" "$dir/KATASTRALNI_UZEMI_P.shp" \
        -nln stg_ku_geom -append -nlt PROMOTE_TO_MULTI \
        -t_srs EPSG:3857 -select KATUZE_KOD \
        --config PG_USE_COPY YES

    rm -rf "$dir"
    echo "  [$i/$pocet_ku] $kod"
done

echo "== Sestavení cílových tabulek =="
psql -q -v ON_ERROR_STOP=1 -f "$SQL/03_build.sql"

konec=$(date +%s)
echo "== Kontrola =="
psql -v ON_ERROR_STOP=1 -f "$SQL/04_kontrola.sql"
echo "Import trval $((konec - zacatek)) s."
[ -n "$chybna" ] && echo "Nestažená KÚ:$chybna"
exit 0
