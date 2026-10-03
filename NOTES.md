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
