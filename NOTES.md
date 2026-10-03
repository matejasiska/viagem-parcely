# Zápisník

Fakta, měření a to, co se při práci ukázalo jinak, než jsem čekal. Vlastní zápisník píšu z tohoto.

## Datové zdroje ČÚZK

### Co se změnilo proti zadání

Zadání odkazuje na `services.cuzk.cz`. Ten dnes vrací `HTTP 301` na **`services.cuzk.gov.cz`**.
ČÚZK přešel na domému `.gov.cz`, staré odkazy fungují jen přes přesměrování. V kódu používám
cílovou adresu, ne přesměrování.

### Rozsah okresu Jičín (změřeno z číselníků RÚIAN, generovaných 2026-10-02)

Zdroj: `https://services.cuzk.gov.cz/sestavy/cis/` — `UI_OKRES`, `UI_OBEC`, `UI_KATASTRALNI_UZEMI`
(CSV, CP1250, oddělovač `;`). Okres jsem dohledal jako kód **3604**, NUTS LAU **CZ0522**.

| Údaj | Hodnota |
|---|---|
| obcí v okrese | 111 |
| katastrálních území | 240 |
| katastrální pracoviště | jedno (Jičín 604) |

Stav digitalizace (`SC_STAV_DIG_KU`): **všech 240 KÚ je digitalizováno na 100 %**
(122× KMD 100 %, 88× DKM 100 %, 30× kombinace KMD+DKM se součtem 100 %).
Pro okres Jičín tedy neexistuje KÚ bez digitální mapy a geometrie je dostupná na celém okrese.

### Kontrolní počet parcel

Sestava `https://services.cuzk.gov.cz/sestavy/objekty/OBJEKTY-20250101.zip` dává počty objektů KN
po jednotlivých KÚ. Součet za 240 KÚ okresu: **276 683 parcel KN**, 0 parcel ZE.

Tohle je stav k 1. 1. 2025, ne k dnešku — nejnovější sestava v adresáři je z roku 2025.
Používám ji jako řádovou kontrolu importu, ne jako přesnou cílovou hodnotu. Měřený rozdíl proti
dnešním datům je níže (+0,65 % na vzorku obce Jičín za 19 měsíců).

## Výběr zdroje geometrie parcel

Porovnával jsem tři zdroje. Všechny tři jsem skutečně stáhl a změřil, ne jen přečetl dokumentaci.

### A) SHP katastrální mapy po KÚ

`https://services.cuzk.gov.cz/shp/ku/epsg-5514/<kod_ku>.zip`

- EPSG:5514 (S-JTSK Krovak East North), kódování CP1250, licence CC-BY 4.0
- generováno **týdně**, pokrytí 99,52 % území ČR (k 28. 9. 2026)
- KÚ Jičín (659541): ZIP 5 759 111 B
- GDAL čte nativně driverem `ESRI Shapefile`, geometrie `MULTIPOLYGON`, SRS rozpoznán správně

Vrstvy relevantní pro úlohu:

| Vrstva | Záznamů (KÚ Jičín) | Obsah |
|---|---|---|
| `PARCELY_KN_P` | 12 288 | polygony parcel, atributy jen `ID, ID_2, TYPPPD_KOD, KATUZE_KOD, OBEC_KOD` |
| `PARCELY_KN_DEF` | 12 288 | definiční body + popisné atributy `TEXT_KM, PAR_VYMERA, DRUPOZ_KOD, ZPVYPA_KOD, BUD_ID, STAV_PARC` |
| `KATASTRALNI_UZEMI_P` | 1 | hranice KÚ |

**Překvapení:** geometrie parcely a její popisné atributy jsou ve dvou různých vrstvách.
Polygon sám o sobě nenese ani číslo parcely, ani výměru. Ověřil jsem, že join na `ID` je přesně
1:1 — 12 288 ∩ 12 288, nula sirotků na obou stranách.

**Druhé překvapení:** atribut `ID` je jen pořadové číslo v rámci jednoho souboru (1..N).
Na třech KÚ okresu (659541, 724572, 725838; 14 543 parcel) dalo **1 475 kolizí**, takže jako
globální klíč je nepoužitelný. Unikátní je `ID_2`: 14 543 hodnot, **0 kolizí**.

Číslo parcely je tu jen jako zobrazovací řetězec `TEXT_KM` (`941/9`, `2346`, `st. 4528`).
Kmenové číslo a poddělení by se z něj musely parsovat.

### B) INSPIRE Cadastral Parcels, GML po KÚ

`https://services.cuzk.gov.cz/gml/inspire/cp/epsg-4258/<kod_ku>.zip`

**Zamítnuto na objemu.** KÚ Jičín: ZIP 5 285 726 B se rozbalí na **144 657 328 B XML** (144 MB)
pro jedno jediné KÚ. Hlavička souboru uvádí `Hits=83823`, tedy prvků mnohonásobně víc než parcel —
soubor nese i hranice a katastrální zóny. Pro 240 KÚ by to byly desítky GB rozbalených dat.
Pro tuhle úlohu nepřináší nic, co nemají zdroje A a C.

### C) Výměnný formát RÚIAN (VFR), po obcích

`https://services.cuzk.gov.cz/vfr/<YYYYMM>/<YYYYMMDD>_OB_<kod_obce>_UKSH.xml.zip`

Adresu jsem musel dohledat; stránka ČÚZK o VFR predikovatelný vzor neuvádí, jen říká, že URL
„lze predikovat z data generování“. Skutečný vzor jsem našel až v listingu `/vfr/`.

- obec Jičín (572659), stav 2026-07-31: ZIP 3 838 579 B → **38 004 807 B XML** (38 MB)
- jeden soubor pokrývá celou obec, tedy několik KÚ (obec Jičín = 5 KÚ)

**Překvapení:** čekal jsem, že VFR bude potřebovat vlastní `.gfs` nebo nástroj typu `gdal-vfr`.
GDAL ho přečte rovnou driverem `GML` a nabídne pojmenované vrstvy `Obce`, `KatastralniUzemi`,
`Parcely`, `StavebniObjekty`, `AdresniMista` a další.

Vrstva `Parcely` má tři geometrické sloupce (`DefinicniBod` Point, `OriginalniHranice` Polygon,
`OriginalniHraniceOmpv` MultiPolygon) a popisné atributy v **jedné** vrstvě s geometrií:
`Id, KmenoveCislo, PododdeleniCisla, VymeraParcely, DruhCislovaniKod, DruhPozemkuKod,`
`ZpusobyVyuzitiPozemku, KatastralniUzemiKod, PlatiOd, PlatiDo`, k tomu seznamy
`ZpusobOchranyKod` a bonitované díly (BPEJ).

Pokrytí geometrií na obci Jičín (16 950 parcel): **16 950 s definičním bodem, 16 950
s originální hranicí, 0 bez polygonu.** Polygony mají i vnitřní prstence (`gml:interior`).

### Křížová kontrola A proti C

`pai:Id` ve VFR má stejný formát i stejné hodnoty jako `ID_2` v shapefilu — `ID_2` je tedy
RÚIAN/ISKN identifikátor parcely, ne jen pořadové číslo souboru. Porovnání množin ID na KÚ,
která mám z oba zdrojů:

| KÚ | VFR (2026-07-31) | SHP (2026-10-02) | společných ID | jen VFR | jen SHP |
|---|---|---|---|---|---|
| 659541 Jičín | 12 284 | 12 288 | 12 279 | 5 | 9 |
| 725838 Popovice u Jičína | 1 475 | 1 475 | 1 475 | 0 | 0 |

Zdroje se shodují. Na Popovicích identicky, na Jičíně se rozdíl 14 parcel vysvětluje dvěma měsíci
mezi stavy (dělení a scelování parcel). Křížová kontrola proti sestavě `OBJEKTY` (stav 2025-01-01)
na stejných pěti KÚ obce Jičín: 16 840 → 16 950, tedy **+110 parcel (+0,65 %) za 19 měsíců**.

### Stáří dat: zjištěné omezení VFR

Archiv `/vfr/` sahá od `201508` do **`202607`**. Měsíce `202608`, `202609` a `202610` vracejí 404,
přesto že dnes je říjen 2026. Úplné kopie VFR se generují měsíčně, ale tenhle veřejný adresář je
archiv — aktuální soubory se vydávají přes aplikaci VDP, která není čistý strojový endpoint.

Predikovatelnou adresou se tedy z VFR dostanu jen na data **2 měsíce stará**, zatímco SHP je
týdenní. Pro reprodukovatelný `docker compose up` je to rozdíl, který stojí za zvážení.

## Nástroje a prostředí (ověřeno)

- Docker 28.5.1, Docker Compose v2.40.0-desktop.1, server linux/x86_64 (WSL2)
- `ghcr.io/osgeo/gdal:alpine-small-latest` — GDAL 3.14.0dev, 148 MB
- `postgis/postgis:17-3.5-alpine` — 729 MB

## Číselníky pro detail parcely

Z `https://services.cuzk.gov.cz/sestavy/cis/`:

- `SC_D_POZEMKU` — 11 druhů pozemku (2 orná půda, 5 zahrada, 10 lesní pozemek, 11 vodní plocha,
  13 zastavěná plocha a nádvoří, 14 ostatní plocha, …), s příznakem `STAVEBNI_PARCELA`
- `SC_ZP_VYUZITI_POZ` — 30 způsobů využití pozemku
- `CS_DRUH_CISLOVANI_PARCEL` — 1 stavební, 2 pozemková

Druh pozemku 13 má `STAVEBNI_PARCELA = a`; odtud pochází prefix `st.` v `TEXT_KM`.

## Rozhodnutí: zdroj dat je SHP po KÚ

Vybral jsem **A) SHP katastrální mapy po katastrálních územích**.

Důvody, v pořadí důležitosti:

1. **Aktuálnost.** SHP se generuje týdně a má stabilní predikovatelnou URL. VFR se přes
   predikovatelnou adresu dá stáhnout jen 2 měsíce staré (archiv končí u `202607`), aktuální
   soubory jdou přes aplikaci VDP, což není strojový endpoint. Pro reprodukovatelný
   `docker compose up` je stabilní adresa důležitější než bohatší atributy.
2. **Objem a rychlost importu.** SHP je nativní driver GDAL. VFR by znamenal ~38 MB XML na obec,
   tedy jednotky GB rozbaleného XML na okres, a parsování GML je pomalejší než shapefile.
   Hodnotitelé spouštějí import u sebe, takže na jeho délce záleží.
3. **Hranice KÚ jsou ve stejném ZIPu** (`KATASTRALNI_UZEMI_P`), takže vrstvu pro nízké zoomy
   mám bez dalšího zdroje.
4. Jediná slabina SHP — atributy ve vedlejší vrstvě — je měřením ověřený přesný join 1:1.

Co tím ztrácím a kdy bych volil VFR: VFR má způsob ochrany pozemku a bonitované díly (BPEJ)
a číslo parcely strukturovaně (`KmenoveCislo` + `PododdeleniCisla` + `DruhCislovaniKod`) místo
zobrazovacího řetězce `TEXT_KM`. Kdyby aplikace měla řešit cenu nebo ochranu pozemků, sáhnu
po VFR, nebo oba zdroje spojím přes `ID_2` = `pai:Id`, což je ověřeně tentýž identifikátor.

## Výsledek importu celého okresu (měřeno)

Jeden běh, `docker compose run --rm import`, data z 2026-10-02:

| Údaj | Hodnota |
|---|---|
| katastrálních území | 240 z 240, všechna s geometrií |
| parcel | 272 111 |
| doba importu | 112 s |
| nestažená KÚ | 0 |
| geometrie bez atributů | 0 |
| atributy bez geometrie | 0 |
| parcel bez druhu pozemku | 0 |
| parcel bez výměry | 0 |
| nevalidních geometrií (`ST_IsValid`) | 0 |
| parcel bez způsobu využití | 203 493 (75 %, ve zdroji `****`) |
| vrcholů celkem | 3 424 415 (průměr 12,6 na parcelu, maximum 562) |
| tabulka `parcela` | 81 MB dat + 12 MB GiST index |
| celá databáze | 226 MB |
| bbox okresu (WGS84) | 15,1042–15,7416 E, 50,2753–50,5470 N |

Při importu GDAL 14× ohlásil `Warning 1: Non closed ring detected` a prstenec sám uzavřel.
Výsledek to nepoškodilo, `ST_IsValid` je po importu bez jediné chyby.

### Počet parcel proti statistice ČÚZK

Naimportováno 272 111, sestava `OBJEKTY` k 1. 1. 2025 uvádí 276 683, tedy **−4 572 (−1,65 %)**.
Nejdřív to vypadalo na chybějící data, protože na vzorku obce Jičín šel trend opačně (+0,65 %).
Rozpad po KÚ: 127 KÚ má parcel víc, 83 stejně, 30 méně. Nárůsty jsou malé (+0,3 až +2,8 %),
propady velké a soustředěné do jedné části okresu (Zliv u Libáně 53 %, Psinice 64 %,
Křešice u Psinic 67 %, Nadslav 70 %, Libáň 72 %, Bašnice 78 %) — to je podpis komplexních
pozemkových úprav, které parcely scelují.

Ověřil jsem to geometricky. První pokus byl ale slabý: porovnával jsem součet **atributových
výměr** s plochou polygonu KÚ. Polygony parcel v tom nebyly vůbec, takže to nemohlo doložit
ani správnost transformace geometrie, ani že atributy patří ke správnému polygonu. Doměřeno:

### Součet ploch polygonů parcel proti ploše KÚ

Obojí přepočteno do S-JTSK (EPSG:5514), kde je plocha v metrech, ne zkreslená Web Mercatorem:

| KÚ | parcel | součet polygonů parcel | plocha polygonu KÚ | pokrytí |
|---|---|---|---|---|
| Zliv u Libáně | 491 | 387,23 ha | 387,23 ha | 100,000 % |
| Psinice | 854 | 547,93 ha | 547,93 ha | 100,000 % |
| Křešice u Psinic | 558 | 348,83 ha | 348,83 ha | 100,000 % |
| Nadslav | 692 | 580,26 ha | 580,26 ha | 100,000 % |
| Libáň | 2 819 | 683,25 ha | 683,25 ha | 100,000 % |
| Hřmenín | 653 | 360,83 ha | 360,83 ha | 100,000 % |
| Bašnice | 1 465 | 612,56 ha | 612,56 ha | 100,000 % |
| Jičín | 12 288 | 1 208,21 ha | 1 208,21 ha | 100,000 % |
| Nová Paka | 8 231 | 718,88 ha | 718,88 ha | 100,000 % |
| Hořice v Podkrkonoší | 8 363 | 843,42 ha | 843,42 ha | 100,000 % |

Parcely tedy katastrální území vyplní beze zbytku a bez přesahů. Pokrytí 100,000 % i u KÚ
s největším propadem počtu (Zliv u Libáně má 53 % parcel roku 2025) znamená, že data nechybí —
parcel je méně, protože jsou větší.

### Plocha polygonu proti zapsané výměře, po parcelách

Relativní odchylka `|ST_Area(ST_Transform(geom, 5514)) − vymera| / vymera` na všech 272 111
parcelách (žádná nemá výměru NULL ani 0):

| Metrika | Relativně | Absolutně |
|---|---|---|
| medián | 0,323 % | 0,46 m² |
| 95. percentil | 9,771 % | 77,39 m² |
| 99. percentil | 26,737 % | 171,12 m² |
| maximum | 1 988 % | 2 283,8 m² |
| parcel s odchylkou > 5 % | 29 738 (10,9 %) | |

Odchylka silně závisí na velikosti parcely:

| Výměra | parcel | medián | 95. percentil | nad 5 % |
|---|---|---|---|---|
| pod 10 m² | 5 011 | 5,629 % | 51,96 % | 2 757 |
| 10–100 m² | 45 459 | 0,908 % | 21,78 % | 7 192 |
| 100–1 000 m² | 116 666 | 0,210 % | 10,94 % | 17 834 |
| 0,1–1 ha | 86 479 | 0,148 % | 3,82 % | 1 955 |
| nad 1 ha | 18 496 | 0,005 % | 1,33 % | 0 |

Nejhorší případy jsou bez výjimky drobné zbytkové parcely, kde několik m² dělá stovky procent:

| id | parcela | KÚ | výměra | plocha polygonu | odchylka |
|---|---|---|---|---|---|
| 2789935604 | 410/3 | Údrnice | 1 m² | 20,9 m² | 1 988 % |
| 42104377010 | 376/9 | Horní Javoří | 3 m² | 30,6 m² | 920 % |
| 1917891604 | 422/9 | Úlibice | 2 m² | 17,1 m² | 756 % |

Zapsaná výměra je právní údaj z původního měření, ne plocha dopočítaná z mapy. U území
digitalizovaných z analogové mapy (KMD, kterých je v okrese většina) se obojí legitimně
rozchází a výměra je navíc zaokrouhlená na celé m². Původní tvrzení „shoda na 0,1 %“ tedy
po parcelách **neplatí** — platí jen v součtu, kde se odchylky opačných znamének vyruší.

### Patří atributy ke správnému polygonu

Odchylky výměr tohle nedokazují, mají vlastní vysvětlení výše. Rozhodující test je definiční
bod: v `PARCELY_KN_DEF` má každá parcela bod, který musí ležet uvnitř svého polygonu.
Napároval jsem ho nezávisle na `(katuze_kod, číslo parcely)`, tedy jiným klíčem, než jakým
běží import (`katuze_kod, ID`). KÚ Jičín:

| Kontrola | Výsledek |
|---|---|
| definičních bodů | 12 288 |
| bod leží ve svém polygonu | 12 288 |
| bod mimo polygon | 0 |
| nenapárovaných | 0 |
| jiné číslo parcely než v atributech | 0 |
| jiná výměra než v atributech | 0 |

Párování atributů na geometrii je správné. Nepřímo to podporuje i to, že u 18 496 parcel
nad 1 ha je medián odchylky 0,005 % a ani jedna nepřekročí 5 %: při chybném joinu by se
rozcházely i velké parcely, kde zaokrouhlení nehraje roli.

KÚ Jičín má 12 288 parcel, což přesně odpovídá samostatnému měření shapefilu před importem.

## Čeho jsem si všiml při stavbě importu

- `ogr2ogr` neumí `-select` společně s `-append` („if -append is specified, -select cannot be
  used“). Sloupce se musí vybrat přes `-sql`.
- `COPY ... WITH (FORMAT csv)` mapuje prázdné pole na NULL, ne na prázdný řetězec. Filtr
  ukončených záznamů `plati_do = ''` proto nevybral nic a seznam KÚ vyšel prázdný.
  Správně je `plati_do IS NULL`. Z 6 259 obcí má `plati_do` vyplněno jediná.
- `COPY` umí `ENCODING 'WIN1250'`, takže CSV od ČÚZK jde načíst bez převodu přes `iconv`.
- Seznam 240 KÚ není v repozitáři vypsaný ručně. Dopočítá se v SQL z číselníků RÚIAN podle
  kódu okresu, takže import jde přesměrovat na jiný okres změnou jedné proměnné.
- Odkaz na Nahlížení do KN se zatím nepovedlo ověřit jako stabilní. `ZobrazObjekt.aspx?typ=
  parcela&id=<id>` vrátil jednou HTTP 200, při druhém pokusu HTTP 302 na ochrannou stránku,
  takže deep link nejspíš potřebuje session. Do UI ho nedám, dokud ho neověřím.
