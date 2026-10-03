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

## Jak to funguje

Mapa má dvě vrstvy a přepíná je podle zoomu:

- **do zoomu 13** hranice a názvy katastrálních území. Celý okres je 9 dlaždic a 187 kB,
  takže se otevře hned. Kreslit 272 tisíc parcel v tomhle zoomu nemá smysl, byly by menší
  než pixel.
- **od zoomu 14** parcely, obarvené podle druhu pozemku. Kliknutím na parcelu se vpravo
  otevře detail s výměrou, druhem pozemku, způsobem využití, katastrálním územím a obcí,
  tedy názvy z číselníků, ne kódy. Od zoomu 17 se vykresluje i číslo parcely.

Vlevo nahoře je hledání podle čísla parcely, volitelně zúžené na katastrální území.
Zadání `941` najde i `941/9`, protože kmenové číslo bez poddělení je běžný vstup.

Dlaždice se generují v PostGIS přes `ST_AsMVT` a ukládají se na disk. Díky tomu jde změnit
styl nebo filtr bez přegenerování celé sady, a druhé zobrazení téhož místa je z cache.

### Výkon

Měřeno lokálně, jedním procesem `curl` přes celou sadu dlaždic:

| Pohled | dlaždic | objem | studená cache | teplá cache |
|---|---|---|---|---|
| celý okres (hranice KÚ, z10) | 9 | 187 kB | 409 ms | 53 ms |
| Jičín, parcely, z14 (5×5) | 25 | 1 932 kB | 1 378 ms | 145 ms |
| Jičín, parcely, z16 (5×5) | 25 | 508 kB | 675 ms | 142 ms |

Z cache vydá server dlaždici za 5,8 ms bez ohledu na vrstvu.

Zjednodušení geometrie podle zoomu jsem změřil a nepoužil: ubralo 12 až 18 % objemu, ale
zároveň z dlaždice vypadly drobné parcely. Podrobně v [NOTES.md](NOTES.md).

## Endpointy

| Endpoint | Co vrací |
|---|---|
| `GET /api/okres` | rozsah okresu, počty, datum dat |
| `GET /api/parcela/{id}` | detail parcely, `id` je identifikátor parcely v ISKN/RÚIAN |
| `GET /api/parcely?cislo=&ku=` | hledání parcely, nejvýš 50 výsledků |
| `GET /dlazdice/parcely/{z}/{x}/{y}.pbf` | vektorové dlaždice parcel, zoom 14 až 16 |
| `GET /dlazdice/katastralni-uzemi/{z}/{x}/{y}.pbf` | hranice KÚ, zoom 0 až 12 |

Nad horní hranicí zoomu si dlaždice dopočítá MapLibre přeskalováním, server stejný obsah
negeneruje znovu. Prázdná dlaždice vrací `204`.

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
src/                 backend: router, repozitáře, controllery
public/              front controller a mapa (MapLibre GL)
```
