'use strict';

// Zoom, od kterého server generuje dlaždice parcel. Pod ním se kreslí jen hranice KÚ,
// protože 272 tisíc parcel nad celým okresem by byly podpixelové útvary.
// Musí odpovídat ZOOM_PARCELY v src/DlazdiceController.php, stejně jako maxzoom zdrojů níže.
const ZOOM_PARCELY_OD = 14;

// Kód, název a barva druhu pozemku. Kódy jsou z číselníku ČÚZK SC_D_POZEMKU,
// v okrese Jičín se vyskytuje jen těchto devět.
const DRUHY_POZEMKU = [
    [2, 'orná půda', '#e0cf9a'],
    [7, 'trvalý travní porost', '#dcecc4'],
    [5, 'zahrada', '#c9e3a4'],
    [6, 'ovocný sad', '#b4d98c'],
    [10, 'lesní pozemek', '#8fbc8f'],
    [11, 'vodní plocha', '#a6cde4'],
    [13, 'zastavěná plocha a nádvoří', '#e2a79e'],
    [14, 'ostatní plocha', '#dcdcd4'],
    [4, 'vinice', '#c9b2d6'],
];

const BARVA_NEZNAMEHO_DRUHU = '#d8d8d0';

const panel = document.getElementById('panel');
const vysledky = document.getElementById('vysledky');
const napovedaHledani = document.getElementById('napoveda-hledani');

let mapa;

async function start() {
    const okres = await nactiJson('/api/okres');

    if (!okres || !okres.naimportovano) {
        napovedaHledani.textContent = 'V databázi nejsou data. Spusť import: docker compose up.';

        return;
    }

    mapa = vytvorMapu(okres);
    mapa.on('load', () => {
        pripojInterakce();
        vykresliLegendu(okres);
    });
}

function vytvorMapu(okres) {
    const vyplnPodleDruhu = [
        'match',
        ['get', 'druh_pozemku_kod'],
        ...DRUHY_POZEMKU.flatMap(([kod, , barva]) => [kod, barva]),
        BARVA_NEZNAMEHO_DRUHU,
    ];

    const map = new maplibregl.Map({
        container: 'mapa',
        // Výchozí atribuce se na úzkém okně sbalí do ikony. Podmínky OSM chtějí, aby byla vidět.
        attributionControl: false,
        style: {
            version: 8,
            glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
            sources: {
                podklad: {
                    type: 'raster',
                    tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                    tileSize: 256,
                    maxzoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, parcely &copy; ČÚZK',
                },
                // maxzoom u vektorového zdroje říká, odkud si MapLibre dlaždice dopočítá
                // přeskalováním, takže server negeneruje stejný obsah pro vyšší zoomy znovu.
                katastralniUzemi: {
                    type: 'vector',
                    tiles: [location.origin + '/dlazdice/katastralni-uzemi/{z}/{x}/{y}.pbf'],
                    minzoom: 0,
                    maxzoom: 12,
                },
                parcely: {
                    type: 'vector',
                    tiles: [location.origin + '/dlazdice/parcely/{z}/{x}/{y}.pbf'],
                    minzoom: ZOOM_PARCELY_OD,
                    maxzoom: 16,
                },
            },
            layers: [
                { id: 'podklad', type: 'raster', source: 'podklad' },
                {
                    id: 'ku-vypln',
                    type: 'fill',
                    source: 'katastralniUzemi',
                    'source-layer': 'katastralni_uzemi',
                    maxzoom: ZOOM_PARCELY_OD,
                    paint: { 'fill-color': '#2f5d8a', 'fill-opacity': 0.08 },
                },
                {
                    id: 'ku-hranice',
                    type: 'line',
                    source: 'katastralniUzemi',
                    'source-layer': 'katastralni_uzemi',
                    paint: {
                        'line-color': '#2f5d8a',
                        'line-width': ['interpolate', ['linear'], ['zoom'], 8, 0.6, 14, 1.8],
                    },
                },
                {
                    id: 'parcely-vypln',
                    type: 'fill',
                    source: 'parcely',
                    'source-layer': 'parcely',
                    minzoom: ZOOM_PARCELY_OD,
                    paint: { 'fill-color': vyplnPodleDruhu, 'fill-opacity': 0.55 },
                },
                {
                    id: 'parcely-hranice',
                    type: 'line',
                    source: 'parcely',
                    'source-layer': 'parcely',
                    minzoom: ZOOM_PARCELY_OD,
                    paint: {
                        'line-color': '#6b625a',
                        'line-width': ['interpolate', ['linear'], ['zoom'], 14, 0.3, 18, 1.2],
                    },
                },
                {
                    id: 'parcela-vybrana',
                    type: 'line',
                    source: 'parcely',
                    'source-layer': 'parcely',
                    minzoom: ZOOM_PARCELY_OD,
                    filter: ['==', ['get', 'id'], 0],
                    paint: { 'line-color': '#c8102e', 'line-width': 3 },
                },
                {
                    id: 'ku-nazev',
                    type: 'symbol',
                    source: 'katastralniUzemi',
                    'source-layer': 'katastralni_uzemi',
                    maxzoom: ZOOM_PARCELY_OD,
                    layout: {
                        'text-field': ['get', 'nazev'],
                        'text-font': ['Noto Sans Regular'],
                        'text-size': ['interpolate', ['linear'], ['zoom'], 9, 10, 13, 13],
                    },
                    paint: { 'text-color': '#1d3a5c', 'text-halo-color': '#fff', 'text-halo-width': 1.5 },
                },
                {
                    id: 'parcela-cislo',
                    type: 'symbol',
                    source: 'parcely',
                    'source-layer': 'parcely',
                    minzoom: 17,
                    layout: {
                        'text-field': ['get', 'cislo'],
                        'text-font': ['Noto Sans Regular'],
                        'text-size': 11,
                    },
                    paint: { 'text-color': '#3d3630', 'text-halo-color': '#fff', 'text-halo-width': 1.2 },
                },
            ],
        },
    });

    map.fitBounds(okres.rozsah, { padding: 20, animate: false });
    map.addControl(new maplibregl.AttributionControl({ compact: false }), 'bottom-right');
    map.addControl(new maplibregl.NavigationControl(), 'bottom-right');
    map.addControl(new maplibregl.ScaleControl(), 'bottom-right');

    return map;
}

function pripojInterakce() {
    mapa.on('click', (udalost) => {
        const prvky = mapa.queryRenderedFeatures(udalost.point, { layers: ['parcely-vypln'] });

        if (prvky.length === 0) {
            return;
        }

        ukazDetail(prvky[0].properties.id);
    });

    mapa.on('mouseenter', 'parcely-vypln', () => {
        mapa.getCanvas().style.cursor = 'pointer';
    });
    mapa.on('mouseleave', 'parcely-vypln', () => {
        mapa.getCanvas().style.cursor = '';
    });

    document.getElementById('zavrit').addEventListener('click', zavriPanel);
    document.getElementById('formular-hledani').addEventListener('submit', hledej);
}

async function ukazDetail(id) {
    const parcela = await nactiJson('/api/parcela/' + id);

    if (!parcela || parcela.chyba) {
        return;
    }

    mapa.setFilter('parcela-vybrana', ['==', ['get', 'id'], parcela.id]);

    document.getElementById('panel-cislo').textContent = 'Parcela ' + parcela.cislo;
    document.getElementById('panel-odkaz').href = parcela.odkaz_ruian;

    const udaje = [
        ['Výměra', formatujVymeru(parcela.vymera)],
        ['Druh pozemku', parcela.druh_pozemku ?? 'neuvedeno'],
        ['Způsob využití', parcela.zpusob_vyuziti ?? 'neuvedeno'],
        ['Katastrální území', parcela.katastralni_uzemi + ' (' + parcela.katastralni_uzemi_kod + ')'],
        ['Obec', parcela.obec],
        ['ID v RÚIAN', String(parcela.id)],
    ];

    const seznam = document.getElementById('panel-udaje');
    seznam.textContent = '';

    for (const [nazev, hodnota] of udaje) {
        const dt = document.createElement('dt');
        dt.textContent = nazev;
        const dd = document.createElement('dd');
        dd.textContent = hodnota;
        seznam.append(dt, dd);
    }

    panel.hidden = false;
}

function zavriPanel() {
    panel.hidden = true;
    mapa.setFilter('parcela-vybrana', ['==', ['get', 'id'], 0]);
}

async function hledej(udalost) {
    udalost.preventDefault();

    const formular = new FormData(udalost.target);
    const parametry = new URLSearchParams({
        cislo: formular.get('cislo').trim(),
        ku: formular.get('ku').trim(),
    });

    const odpoved = await nactiJson('/api/parcely?' + parametry);
    vysledky.textContent = '';

    if (!odpoved || odpoved.chyba) {
        napovedaHledani.textContent = odpoved ? odpoved.chyba : 'Hledání se nepovedlo.';

        return;
    }

    if (odpoved.parcely.length === 0) {
        napovedaHledani.textContent = 'Žádná parcela tomu neodpovídá.';

        return;
    }

    napovedaHledani.textContent = 'Nalezeno ' + odpoved.parcely.length + ', klikni na výsledek.';

    for (const parcela of odpoved.parcely) {
        const polozka = document.createElement('li');
        const cislo = document.createElement('b');
        cislo.textContent = parcela.cislo;
        const kde = document.createElement('span');
        kde.textContent = parcela.katastralni_uzemi + ' · ' + formatujVymeru(parcela.vymera);
        polozka.append(cislo, kde);
        polozka.addEventListener('click', () => {
            mapa.flyTo({ center: [parcela.lon, parcela.lat], zoom: 18 });
            ukazDetail(parcela.id);
        });
        vysledky.append(polozka);
    }
}

function vykresliLegendu(okres) {
    const kontejner = document.getElementById('legenda-druhy');

    for (const [, nazev, barva] of DRUHY_POZEMKU) {
        const radek = document.createElement('div');
        radek.className = 'druh';
        const vzorek = document.createElement('i');
        vzorek.style.background = barva;
        const popis = document.createElement('span');
        popis.textContent = nazev;
        radek.append(vzorek, popis);
        kontejner.append(radek);
    }

    document.getElementById('legenda-stav').textContent =
        okres.pocet_parcel.toLocaleString('cs-CZ') + ' parcel ve ' + okres.pocet_ku + ' k. ú.'
        + ' · data ČÚZK k ' + okres.datum_dat
        + ' · parcely se zobrazují od zoomu ' + ZOOM_PARCELY_OD;
}

function formatujVymeru(metry) {
    if (metry === null) {
        return 'výměra neuvedena';
    }

    const zaklad = metry.toLocaleString('cs-CZ') + ' m²';

    return metry >= 10000
        ? zaklad + ' (' + (metry / 10000).toLocaleString('cs-CZ') + ' ha)'
        : zaklad;
}

async function nactiJson(adresa) {
    try {
        const odpoved = await fetch(adresa);

        return await odpoved.json();
    } catch (chyba) {
        console.error(adresa, chyba);

        return null;
    }
}

start();
