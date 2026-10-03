# Mapa parcel okresu Jičín

Webová aplikace, která zobrazuje parcely katastru nemovitostí v okrese Jičín na mapě.
Data jsou předstažená z ČÚZK a uložená v PostGIS. Backend je v čistém PHP s PDO, bez frameworku.

Okres Jičín má 240 katastrálních území a 272 111 parcel. Povinné minimum zadání byla
4 katastrální území, celý okres je nad rámec.

## Předpoklady

- Docker a Docker Compose (vyvíjeno na Docker 28.5.1 a Compose v2.40.0, Docker Desktop s WSL2)
- **Přístup k internetu.** První spuštění stahuje data z `services.cuzk.gov.cz`.
  Aplikace nemá data přibalená v repozitáři.
- Asi 400 MB místa na disku (105 MB stažených dat, 226 MB databáze)

## Spuštění

```
docker compose up
```

Tím se postaví databáze, naimportují data a nastartuje web. Mapa pak běží na
<http://localhost:8080>.

### Jak dlouho to trvá

Měřeno na Windows 11, Docker Desktop s WSL2, od `docker compose down -v`:

| Krok | Čas |
|---|---|
| od `docker compose up` do konce importu | 149 s |
| z toho samotný import (240 KÚ ze sítě) | 134 s |
| opakovaný import z lokální cache | 67 s |

Import stahuje 240 ZIP souborů, celkem 105 MB. Postup vypisuje průběžně.
Pokud ČÚZK není dostupné, skončí srozumitelnou chybou a to, co už stáhl, si nechá v cache —
další spuštění dostahuje jen zbytek.

Při dalších spuštěních `docker compose up` se import přeskočí, protože data už v databázi jsou.
Vynutit nový import jde přes:

```
docker compose down -v && docker compose up
```

## Ověření importu

Import si na konci sám vypíše kontroly. Ručně:

```
docker compose exec db psql -U katastr -d katastr -c "SELECT * FROM datova_sada"
docker compose exec db psql -U katastr -d katastr -c "SELECT count(*) FROM parcela"
```

`datova_sada` drží verzi vstupních dat, tedy nejnovější `Last-Modified` stažených souborů ČÚZK.

## Zdroj dat

Katastrální mapa ve formátu SHP po katastrálních územích,
`https://services.cuzk.gov.cz/shp/ku/epsg-5514/<kod_ku>.zip`, licence CC-BY 4.0,
ČÚZK ji generuje týdně. Seznam katastrálních území okresu se dopočítá z číselníků RÚIAN
podle kódu okresu, takže v repozitáři není ručně vypsaný.

Proč tenhle zdroj a ne RÚIAN VFR nebo INSPIRE, včetně měření, je v [NOTES.md](NOTES.md).

Import jde přesměrovat na jiný okres změnou `OKRES_KOD` v `docker-compose.yml`
(Jičín je 3604).

## Co v datech není

Vlastníci parcel nejsou v otevřených datech ČÚZK. Detail parcely proto odkazuje na
veřejný detail ve Veřejném dálkovém přístupu k RÚIAN.

## Struktura

```
docker-compose.yml   databáze, import, PHP-FPM, nginx
db/init/             schéma, spustí se při prvním startu databáze
import/              stahování z ČÚZK a import do PostGIS (GDAL + psql)
src/                 backend: router, repozitáře, dlaždice
public/              mapa a front controller
```
