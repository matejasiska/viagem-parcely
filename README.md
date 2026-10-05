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

Hesla k databázi jsou přímo v `docker-compose.yml` vědomě, aby aplikace šla lokálně spustit
bez dalších kroků. V produkci by patřila do Docker secrets nebo do `.env` mimo repozitář.

### Jak dlouho to trvá

Měřeno na Windows 11, Docker Desktop s WSL2, od `docker compose down -v` (2026-10-05):

| Krok | Čas |
|---|---|
| od `docker compose up` do konce importu | 134 s |
| z toho samotný import (240 KÚ ze sítě) | 125 s |
| opakovaný import z lokální cache | 67 s |

Čas závisí hlavně na odezvě ČÚZK. Stejný postup jindy trval 149 s, z toho import 134 s.

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

- **do zoomu 13** hranice a názvy katastrálních území. Celý okres je 9 dlaždic a 116 kB
  přenosu (192 kB před gzipem), takže se otevře hned. Kreslit 272 tisíc parcel v tomhle zoomu nemá smysl, byly by menší
  než pixel.
- **od zoomu 14** parcely, obarvené podle druhu pozemku. Kliknutím na parcelu se vpravo
  otevře detail s výměrou, druhem pozemku, způsobem využití, katastrálním územím a obcí,
  tedy názvy z číselníků, ne kódy. Od zoomu 17 se vykresluje i číslo parcely.

Vlevo nahoře je hledání podle čísla parcely, volitelně zúžené na katastrální území.
Zadání `941` najde i `941/9`, protože kmenové číslo bez poddělení je běžný vstup.

Dlaždice se generují v PostGIS přes `ST_AsMVT` a ukládají se na disk. Díky tomu jde změnit
styl nebo filtr bez přegenerování celé sady, a druhé zobrazení téhož místa je z cache.

### Výkon

Měřeno lokálně, jedním procesem `curl` přes celou sadu dlaždic, s `Accept-Encoding: gzip`
jako v prohlížeči. Studená cache znamená smazanou cache dlaždic na disku, databáze je zahřátá.
Pohled 5×5 dlaždic je obrazovka 1280 × 1280 px kolem středu Jičína.

| Pohled | dlaždic | přenos (po gzipu) | před gzipem | studená cache | teplá cache |
|---|---|---|---|---|---|
| celý okres (hranice KÚ, z10) | 9 | 116 kB | 192 kB | 431–453 ms | 47–49 ms |
| Jičín, parcely, z14 (5×5) | 25 | 1 291 kB | 1 989 kB | 1 405–1 441 ms | 95–96 ms |
| Jičín, parcely, z16 (5×5) | 25 | 304 kB | 453 kB | 675–692 ms | 69–71 ms |

Jedna dlaždice z cache trvá 2,0 ms s gzipem a 1,0 ms bez něj (medián ze 125 požadavků).
Z cache se dlaždice vydá bez připojení k databázi, takže funguje i při jejím výpadku.

Zjednodušení geometrie podle zoomu jsem změřil a nepoužil: ubralo 12 až 18 % objemu
(před gzipem), ale
zároveň z dlaždice vypadly drobné parcely. Podrobně v [NOTES.md](NOTES.md).

## Výklad zadání

### Celý okres zobrazuje hranice katastrálních území, ne parcely

Zadání chce, aby aplikace plynule fungovala i při zobrazení celého okresu. Vykládám to tak,
že celý okres musí jít zobrazit a posouvat bez čekání, ale ne že se v tom měřítku musí kreslit
jednotlivé parcely. Parcela by tam byla menší než pixel.

Plocha parcely spočítaná z polygonů v S-JTSK, všech 272 111 parcel:

| | plocha |
|---|---|
| průměr | 3 259 m² |
| medián | 557 m² |
| 25. percentil | 149 m² |
| 75. percentil | 2 274 m² |

Průměr táhnou nahoru velké lány polí a lesů, typickou parcelu popisuje spíš medián.

Rozlišení mapy pro zeměpisnou šířku středu okresu (50,41° s. š.). MapLibre počítá zoom
s dlaždicí 512 px, takže m/px = 40 075 017 · cos(φ) / (512 · 2^z). Strana parcely je strana
čtverce se stejnou plochou:

| Zoom | m/px | strana mediánové parcely | strana průměrné parcely |
|---|---|---|---|
| z10 | 48,7 | 0,5 px | 1,2 px |
| z11 | 24,4 | 1,0 px | 2,3 px |
| z12 | 12,2 | 1,9 px | 4,7 px |
| z13 | 6,1 | 3,9 px | 9,4 px |
| z14 | 3,0 | 7,7 px | 18,8 px |

Celý okres (bbox 45 × 30 km) se na obrazovku Full HD vejde zhruba v zoomu 10. Tam má
mediánová parcela půl pixelu a i průměrná jen jeden. Teprve od zoomu 13 až 14 je typická
parcela útvar o straně několika pixelů, na který jde kliknout. Proto se na celém okrese
kreslí hranice 240 katastrálních území (9 dlaždic, 116 kB po gzipu) a parcely od zoomu 14.
Zkoušel jsem kreslit parcely už od z13 a z12. Zamítnul jsem to podle objemu dlaždic,
podrobně v [NOTES.md](NOTES.md).

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

Podkladová mapa se načítá z `tile.openstreetmap.org`. Pro lokální demo je to v pořádku,
pro produkci by byl kvůli podmínkám užití OSM potřeba vlastní nebo komerční zdroj dlaždic
(např. Mapy.cz API, MapTiler).

## Co v datech není

Vlastníci parcel nejsou v otevřených datech ČÚZK. Detail parcely proto odkazuje na
veřejný detail ve Veřejném dálkovém přístupu k RÚIAN.

## Známá omezení

- **Cache dlaždic nezávisí na verzi dat.** Dlaždice leží na disku pod `{vrstva}/{z}/{x}/{y}.pbf`
  bez data importu v cestě. Nový import proto počítá s `docker compose down -v`, které smaže
  databázi i cache dlaždic najednou. Samotné přeimportování dat bez smazání volume
  `tile-cache` by nechalo na disku staré dlaždice.
- **Výsledky hledání se řadí podle čísla parcely jako text**, takže `1000` je před `2`.
  Číslo parcely je ve zdroji SHP jen zobrazovací řetězec (`941/9`, `st. 4528`). Číselné řazení
  by potřebovalo kmenové číslo a poddělení zvlášť. Ty má strukturovaně VFR, viz
  [NOTES.md](NOTES.md). Výsledků je nejvýš 50 a jsou seskupené podle katastrálního území,
  takže na použitelnost to má malý vliv.

## Struktura

```
docker-compose.yml   databáze, import, PHP-FPM, nginx
db/init/             schéma, spustí se při prvním startu databáze
import/              stahování z ČÚZK a import do PostGIS (GDAL + psql)
src/                 backend: router, repozitáře, controllery
public/              front controller a mapa (MapLibre GL)
```
