## Design Doc: Kntnt GPX Blocks

## Översikt

WordPress-plugin som tillhandahåller tre Gutenberg-block i blockgruppen **Kntnt**. Blocken visualiserar innehållet i en GPX-fil: en karta med spåret, en höjdprofil och en sammanställning av nyckeltal. De är konstruerade för att fungera tillsammans men kan också användas i mindre kombinationer.

## De tre blocken

| Namn                 | Innehåll                 |
| -------------------- | ------------------------ |
| **GPX Map**          | Karta med spåret utritat |
| **GPX Elevation**    | Höjdprofil som diagram   |
| **GPX Statistics**   | Nyckeltal för spåret     |

GPX Map är datakällan. GPX Elevation och GPX Statistics hämtar sina värden därifrån och kan därför inte användas utan GPX Map.

## Indata

GPX-filen hämtas från WordPress mediabibliotek. Användaren kan ladda upp filen direkt i GPX Map-blocket eller separat via mediabiblioteket – i båda fallen hamnar filen i biblioteket och refereras med en attachment-id, inte en fri URL.

Eftersom WordPress inte tillåter `.gpx`-uppladdningar som default registrerar pluginet `.gpx` (mimetyp `application/gpx+xml`) som tillåten typ via `upload_mimes`-filtret. XML-parsningen sker med XXE-skyddade inställningar.

En fil kan innehålla ett eller flera spår (`<trk>`), och varje spår kan ha flera segment (`<trkseg>`). När filen innehåller flera spår används det första; segmenten inom det spåret slås ihop till en sammanhängande linje.

Höjddata (`<ele>`) är formellt valfritt i GPX. Saknas det visar GPX Elevation och GPX Statistics ett tomt tillstånd för höjdrelaterade värden – pluginet hämtar inte höjddata från externa källor.

Waypoints (`<wpt>`) blir markörer på kartan. Etiketten vid hover hämtas från `<name>`.

### Konvertering och cache

Vid uppladdning – eller första gången blocket renderas med en GPX-fil som ännu inte är konverterad – parsas filen serverside och översätts till GeoJSON. Statistik (distans, lägsta och högsta höjd, total stigning och nedstigning) räknas ut samtidigt. Båda resultaten sparas som post-meta på attachment-objektet.

Konsekvenserna:

* **Frontend ser aldrig GPX.** GPX Map matar `L.geoJSON()` direkt med den cachade GeoJSON-strukturen – ingen XML-parsning i webbläsaren och inget beroende av `leaflet-gpx`.
* **GPX Statistics renderas serverside** från den cachade statistiken som ren HTML, helt utan klientberäkning.
* **Originalfilen rörs inte.** GPX-filen i mediabiblioteket är intakt. Det är den som nedladdningskontrollen i GPX Map serverar med korrekt `Content-Disposition` så att filen sparas med ett vettigt namn.

### Varför GPX in, GeoJSON internt

GPX är vad användaren faktiskt har – Garmin, Strava, Komoot, AllTrails exporterar GPX som default. GeoJSON är ett webformat som kräver konvertering för de flesta. Att låta pluginet göra konverteringen en gång på serversidan ger användaren det enkla flödet ("ladda upp filen från Strava") medan frontend får GeoJSON-fördelarna: säkrare parsning, native Leaflet-stöd, mindre JavaScript på klienten.

## GPX Map

Karta byggd på Leaflet med kakel från OpenStreetMap. Spåret ritas som en linje. Användaren kan zooma, panorera och växla mellan helskärm och blockets ursprungsläge.

Innan användaren har zoomat eller panorerat anpassar kartan automatiskt zoomnivån till containerns storlek så att hela spåret syns centrerat med en liten marginal till kanterna. Det innebär att kartan zoomar ut när containern krymper – exempelvis när sidan görs smalare eller när blocket placeras i en smal kolumn. Så snart användaren själv har zoomat eller panorerat stängs den automatiska anpassningen av.

På spåret visas en markör som är synkroniserad med markören i GPX Elevation.

Eventuella waypoints ritas som markörer på kartan. När användaren hovrar över en waypoint visas dess namn i en etikett bredvid markören.

**Kontroller (ikoner) som kan slås på och av – default är allt av:**

* Zoomknappar, in och ut (`L.Control.Zoom`, Leafletstandard)
* Skalstreck (`L.Control.Scale`, Leafletstandard)
* Helskärmsknapp (Leaflet-tillägget `Leaflet.fullscreen`, de facto-standard)
* Nedladdning av GPX-filen (egen kontroll i pluginet)

Attribution till OpenStreetMap visas alltid eftersom OSM:s licensvillkor kräver det.

**Interaktioner som kan slås på och av – default är allt av:**

* Panorering med mus eller finger (`dragging`)
* Zoom med scrollhjul (`scrollWheelZoom`)
* Zoom med pinch-gest på pekskärm (`touchZoom`)
* Dubbelklick för att zooma in (`doubleClickZoom`)
* Boxzoom – håll Shift och dra för att zooma till en rektangel (`boxZoom`)
* Tangentbord – piltangenter för panorering, `+` och `−` för zoom (`keyboard`)

**Konfigurerbart:**

* **Spår och spårmarkör:** spårets färg och spårmarkörens färg.
* **Waypoints:** markörens färg samt etikettens bakgrundsfärg, textfärg, typsnitt, storlek, vikt och stil.

## GPX Elevation

Diagram med distans från startpunkten på x-axeln och höjd över havet på y-axeln. Y-axeln bryts så att den lägsta höjden i spåret ligger strax ovanför x-axeln och maxvärdet på y-axeln ligger strax ovanför den högsta toppen. På så sätt utnyttjar höjdvariationerna hela diagrammets höjd.

På linjen finns en markör som är synkroniserad med kartmarkören i GPX Map. När markören flyttas i det ena blocket följer den i det andra med. Markören i höjdprofilen har en textruta som visar distans från start och höjd över havet vid markörens position.

**Konfigurerbart:**

* **Färger:** diagrammets bakgrund, axlarnas färg, axeltexternas färg (distans respektive höjd), diagramlinjens färg, markörens färg, textrutans bakgrund och textrutans textfärg.
* **Typografi:** typsnitt, storlek, vikt och stil – separat för axlar respektive textruta.

## GPX Statistics

Visar i text:

* Total längd
* Lägsta höjd
* Högsta höjd
* Total stigning
* Total nedstigning

**Konfigurerbart, separat för rubriker och värden:** bakgrundsfärg, textfärg, typsnitt, storlek, vikt och stil.

**Beräkningar:**

* Distans: Haversine-formeln på koordinatpar.
* Stigning och nedstigning: summeras med en konfigurerbar filtreringströskel (default 3 m) som kompenserar för GPS-brus. Utan filter ger rådata typiskt orealistiskt höga värden.
* Tal: locale-anpassad formatering via `number_format_i18n()` så att svensk locale ger t.ex. `12 345,6 m`.

## Synkronisering mellan GPX Map och GPX Elevation

GPX Map och GPX Elevation har var sin markör. Markörerna är låsta till samma punkt på spåret. När användaren flyttar markören i det ena blocket följer den i det andra med automatiskt.

## Responsivitet och placering

Blocken är konstruerade för att placeras i andra block som fungerar som container – exempelvis kolumner eller grupper. De anpassar sig till tillgänglig bredd och fungerar lika bra på desktop, på mobil och i smala kolumner.

## WordPress-native gränssnitt

Inställningarna i blocken bygger så långt som möjligt på WordPress standardkontroller – samma färgväljare, typografipanel och avståndskontroller som används av kärnblocken. Användaren ska uppleva att de tre blocken är en naturlig del av WordPress, inte ett främmande tillägg med egna kontroller.

## Prestanda

Ett längre spår kan innehålla tusentals punkter, vilket riskerar att göra rendering tung – särskilt på mobil. Pluginet hanterar det med:

* Förenkling av spåret (Douglas-Peucker) före rendering.
* Canvas-renderer i Leaflet för polylinen.
* Nedsampling av höjdprofilen till några hundra punkter.
* Lazy init via `IntersectionObserver` – kartan byggs först när blocket är på väg in i viewport.

## Tillgänglighet och fallback

* **GPX Statistics** renderas serverside som semantisk HTML (`<dl>`) och fungerar utan JavaScript.
* **GPX Map och GPX Elevation** kräver JavaScript men har `<noscript>`-fallback med relevant textinformation.
* **ARIA-labels** på markörer.
* **Textsammanfattning** av höjdprofilen för skärmläsare ("Stiger från X m vid start till Y m efter Z km …").
* **Felhantering**: vid borttagen, otillgänglig eller korrupt GPX-fil visar blocket ett tydligt felmeddelande i administratörsläge och döljer sig diskret för besökare.

## Integritet och samtycke

Kart-kakel laddas från OpenStreetMaps tile-server, vilket skickar besökarens IP-adress till tredje part. Pluginet laddar inte kakel förrän besökaren har gett samtycke, och tillhandahåller filter och action-hooks för integration med samtyckes-plugins som Real Cookie Banner. Tills samtycke ges visas en placeholder med en uppmaning att aktivera kartor.

## Defaultvärden

Alla konfigurerbara färger, typsnitt, storlekar, vikter och stilar har sunda standardvärden. Färger hämtas i första hand från det aktiva temats `theme.json`-presets (`--wp--preset--color--primary` etc.) så att blocken automatiskt följer temats palett. Hårdkodade fallback-värden används bara när presets saknas.

## Namnkonventioner

Pluginet heter **Kntnt GPX Blocks** publikt. Maskinläsbara namn använder samma stam i den form som varje område kräver:

| Område                                     | Form                                     | Exempel                                     |
| ------------------------------------------ | ---------------------------------------- | ------------------------------------------- |
| Plugin-slug, mappnamn, huvudfil, textdomän | `kntnt-gpx-blocks`                       | `kntnt-gpx-blocks/`, `kntnt-gpx-blocks.php` |
| Block-namespace                            | `kntnt`                                  | `kntnt/gpx-map`, `kntnt/gpx-elevation`      |
| CSS-klasser, JS-moduler                    | `kntnt-gpx-...`                          | `.kntnt-gpx-map`, `.kntnt-gpx-elevation`    |
| PHP-funktioner, WP-hooks, optionsnycklar   | `kntnt_gpx_blocks_...`                   | `kntnt_gpx_blocks_register()`               |
| PHP-namespace och klasser                  | `Kntnt\GpxBlocks`, `Kntnt\GpxBlocks\...` | `namespace Kntnt\GpxBlocks;`                |

Blockens fullständiga namn (namespace/blockname) blir `kntnt/gpx-map`, `kntnt/gpx-elevation` respektive `kntnt/gpx-statistics`. Blocken samlas i en egen blockkategori `kntnt` med visningsnamnet "Kntnt".

## Teknisk grund

Pluginet skrivs i modern PHP 8.4 och modern JavaScript (ES2022+). Bygget följer WordPress nuvarande best practice för blockplugins:

* **Scaffolding** med `@wordpress/create-block` som utgångspunkt.
* **Block API v3** i `block.json` – kompatibelt med iframad editor och fortsatt stöd i kommande WordPress-versioner.
* **Build-pipeline** via `@wordpress/scripts` (webpack-baserad).
* **Dynamiska block** med PHP-rendering via `render`-fältet i `block.json` där server-rendering passar bättre än `save`.
* **Editor-rendering** som full WYSIWYG: samma interaktiva karta i blockredigerarens iframe som på frontend, inte en statisk preview-bild.
* **WordPress komponentbibliotek** (`@wordpress/components`) för alla redaktörsinställningar – realiserar principen om WordPress-native gränssnitt.
* **PHP-autoloading** via Composer med PSR-4 mot namespace `Kntnt\GpxBlocks`.
* **Internationalisering** med textdomän `kntnt-gpx-blocks`.
* **Kodstandard:** WordPress Coding Standards för PHP, `@wordpress/eslint-plugin` och `@wordpress/prettier-config` för JavaScript.
* **Säkerhet:** sanitering av indata, eskeypning av utdata, nonces för redaktörsanrop och kapacitetskontroller där det är relevant.
* **Cache-vänligt:** serverrenderad utdata är fri från request-specifika data så att Cloudflare och andra edge-cachar fungerar problemfritt.

## Öppna frågor

Frågor som lämnas öppna och besvaras senare:

* **Koppling mellan blocken och flera kartor på samma sida.** Block Context (Block API v3) är den WordPress-native lösningen, men förutsätter att GPX Elevation och GPX Statistics är ättlingar till GPX Map i blockträdet – vilket motverkar målet att kunna placera dem fritt i andra containers. Alternativen är (1) en wrapper-block-modell där alla tre placeras som inner blocks i ett gemensamt parent-block, eller (2) sibling-modell där varje GPX Map får ett `mapId` och GPX Elevation/GPX Statistics har en picker. Båda har konsekvenser för redaktörens arbetsflöde.
* **Waypoint-kategorisering.** GPX-fälten `<sym>`, `<type>` och `<desc>` öppnar för typning av waypoints (start, mål, kontrollpunkt, vatten o.s.v.) med olika ikoner eller färger. Dessa bevaras som properties i den konverterade GeoJSON-strukturen. Inte med från start, men en konvention för vilket fält som bär typningen bör spikas tidigt om det blir aktuellt.