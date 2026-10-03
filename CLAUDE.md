# Viagem – mapa parcel okresu Jičín (interview úloha)

## Kontext

Pohovorová úloha na pozici programátora ve firmě Viagem a.s. (výkup pozemků). Odhad rozsahu 4–12 h.
Termín odevzdání: nejpozději v úterý 6. 10. 2026. Termín pohovoru dají vědět po odevzdání.
Na pohovoru se aplikace společně spustí, projde se kód a diskutují se rozhodnutí. Proto musím každému řádku rozumět a umět ho obhájit.

### Zadání

- Webová aplikace, která zobrazí parcely v okrese Jičín na mapě. Po kliknutí na parcelu se zobrazí informace o ní.
- **Musí plynule fungovat i při zobrazení celého okresu.** To je pro ně hlavní kritérium.
- Backend v PHP, nejlépe čisté PHP. Frontend a ostatní technologie jsou volné.
- Povinné minimum: KÚ Jičín + alespoň 3 další KÚ v okrese. Celý okres je vlastní iniciativa, kterou jsme se rozhodli udělat.
- Stačí spuštění lokálně, musí to běžet v Chrome a Firefoxu.
- Zdroje dat: ČÚZK (services.cuzk.cz – WFS/WMS, výdejní služby RÚIAN, INSPIRE), Nahlížení do KN pro orientaci v údajích, OSM / Mapy.cz / Mapbox jako podklad. Najít správný endpoint a rozhodnout mezi live a předstaženými daty je součást úlohy.
- Odevzdává se repozitář (commit history je vítaná), README (spuštění, předpoklady) a zápisník (rozhodnutí, co překvapilo, co bych s víc časem dělal jinak).
- Hodnotí se: postup a rozhodování, funkčnost, plynulost a výkon, kvalita PHP kódu, vlastní iniciativa.
- Co je v zadání nejednoznačné, rozhodnu sám a zdůvodním v README.

## Rozhodnutí (zatím)

1. **Data předstažená, ne live.** Live WFS má limity a nad celým okresem by nebylo plynulé. Katastr se mění zřídka, takže jednorázový import stačí.
   - Kandidáti na zdroj: RÚIAN VFR, nebo SHP katastrálních map po jednotlivých KÚ z services.cuzk.cz.
   - **Ještě ověřit**, který zdroj má pro okres Jičín úplnou geometrii parcel a jaké atributy obsahuje.
2. **Docker Compose + PostGIS** (moje volba). Zvažovaná alternativa byla předpočítané MBTiles + SQLite bez Dockeru. Obě varianty i důvod volby uvést v zápisníku.
3. **Vektorové dlaždice generované živě z PostGIS** (`ST_AsMVT`) přes PHP endpoint, s cache na disku. Důvod: styl, filtry i aktualizace dat jdou změnit bez přegenerování.
4. **Výkon:**
   - GiST index,
   - zjednodušení geometrie podle zoomu,
   - na nízkých zoomech jen hranice KÚ místo parcel,
   - cache dlaždic a správné HTTP cache hlavičky.
   - Všechno změřit a do README psát jen naměřená čísla.
5. **Frontend:** MapLibre GL, podklad OSM nebo Mapy.cz, panel s detailem parcely.
6. **Vlastníci nejsou v open datech.** V detailu bude odkaz na Nahlížení do KN.

### Plánovaná struktura

- `docker-compose.yml` s kontejnery:
  - `db`: PostGIS,
  - `php`: PHP-FPM + nginx,
  - `import`: jednorázový GDAL (ogr2ogr) pro stažení a import dat.
- `public/index.php`: front controller.
- `src/`: router, controllery, repository. Čisté PHP a PDO, bez frameworku.
- Endpointy:
  - `GET /tiles/{z}/{x}/{y}.pbf`: dlaždice.
  - `GET /api/parcel/{id}`: detail parcely.
  - `GET /api/search?ku=…&cislo=…`: vyhledání parcely.

## Způsob práce

Čas je krátký (4 dny), takže **pracuj samostatně, bez krokování a bez čekání na schválení každého souboru.**

- Rozhodnutí jako výběr zdroje dat nebo detaily schématu udělej sám. Zapiš je do `NOTES.md` (důvod a zvažované alternativy), ať z toho pak napíšu zápisník.
- Zastav se a zeptej se jen u věcí, které mění zadání nebo architekturu výše.
- **Commituj po logických celcích:** malé commity s normálními zprávami (např. „Import parcel do PostGIS“). Commit history je součást hodnocení.
- Po každém dokončeném milníku mi dej krátké shrnutí (pár bodů): co je hotové, jak to spustit a ověřit, klíčová rozhodnutí.
- Backend je nejdůležitější část hodnocení. PHP piš tak, abych ho na pohovoru dokázal přečíst a obhájit: jednoduše a čitelně.
- Před odevzdáním mi uděláš průchod kódem: hlavní soubory, tok požadavku od kliknutí po SQL a otázky, které můžu na pohovoru čekat.

## Pravidla: žádný AI slop

- Žádné zbytečné abstrakce: rozhraní s jedinou implementací, factory pro triviální věci, vrstvy „do budoucna“.
- Komentáře jen tam, kde vysvětlují proč, ne co. Žádné hlavičkové bloky typu „This class handles…“.
- Žádný mrtvý kód, TODO výplně, placeholder hodnoty ani emoji.
- Pojmenování podle domény a terminologie ČÚZK (`parcela`, `katastralni_uzemi`, `kmenove_cislo`…), konzistentně.
- README krátké a věcné. Žádné „🚀 Features“ ani marketingové věty.
- Zápisník píšu vlastními slovy. Claude průběžně vede `NOTES.md` jen s fakty: co se zkoušelo, co nefungovalo, co překvapilo, měření.
- Nic netvrdit bez ověření: počty parcel, časy i endpointy se ověří a změří.

## Plán (4 dny, sobota 3. 10. – úterý 6. 10.)

| Den | Co |
|---|---|
| So | Repo, Docker Compose (PostGIS, PHP-FPM + nginx, GDAL import), výběr zdroje dat, import celého okresu Jičín |
| Ne | Backend (router, dlaždice s `ST_AsMVT` a cache, detail parcely) + frontend MapLibre s detailem po kliknutí → funkční MVP |
| Po | Výkon (zjednodušení podle zoomu, hranice KÚ na nízkých zoomech, měření) + navíc: hledání parcely, barvy podle druhu pozemku, odkaz na Nahlížení do KN |
| Út | README, podklady pro zápisník, úklid kódu, test čistého spuštění (`docker compose up` od nuly), průchod kódem se mnou |

Priorita, kdyby nebyl čas: funkční MVP nad celým okresem a plynulost > README > extra funkce.

## Prostředí

- Windows PC, Docker Desktop (WSL2). Hodnotitelé spustí aplikaci přes `docker compose up`.
- První krok: ověřit `docker --version` a `docker compose version`.
